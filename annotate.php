<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
/**
 * Annotate page. Allows user to add and edit wiki annotations.
 *
 * @copyright &copy; 2009 The Open University
 * @author b.j.waddington@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__).'/../../config.php');
require_once($CFG->dirroot.'/lib/ajax/ajaxlib.php');
require_once($CFG->dirroot.'/mod/eln/annotate_form.php');
require_once($CFG->dirroot.'/mod/eln/basicpage.php');

$save = optional_param('submitbutton', '', PARAM_TEXT);
$cancel = optional_param('cancel', '', PARAM_TEXT);
$deleteorphaned = optional_param('deleteorphaned', 0, PARAM_BOOL);
$lockunlock = optional_param('lockediting', false, PARAM_BOOL);

if (!empty($_POST) && !confirm_sesskey()) {
    print_error('invalidrequest');
}

$url = new moodle_url('/mod/eln/annotate.php', array('id' => $id));
$PAGE->set_url($url);

if ($id) {
    if (!$cm = get_coursemodule_from_id('eln', $id)) {
        print_error('invalidcoursemodule');
    }

    // Checking course instance
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

    if (!$eln = $DB->get_record('eln', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }

    $PAGE->set_cm($cm);
}
$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('incourse');
require_course_login($course, true, $cm);
$elnoutput = $PAGE->get_renderer('mod_eln');

// Check permission
require_capability('mod/eln:annotate', $context);
if (!$subwiki->annotation) {
    $redirect = 'view.php?'.eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL);
    print_error('You do not have permission to annotate this wiki page', 'error', $redirect);
}

// Get the current page version, creating page if needed
$pageversion = eln_get_current_page($subwiki, $pagename, OUWIKI_GETPAGE_ACCEPTNOVERSION);
$wikiformfields = eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_FORM);

// For everything except cancel we need to obtain a lock.
if (!$cancel) {
    if (!$pageversion) {
        print_error(get_string('startpagedoesnotexist', 'eln'));
    }
    // Get lock
    list($lockok, $lock) = eln_obtain_lock($eln, $pageversion->pageid);
}

// Handle save
if ($save) {
    if (!$lockok) {
        eln_release_lock($pageversion->pageid);
        print_error('cannotlockpage', 'eln', 'view.php?'.eln_display_wiki_parameters($pagename,
                $subwiki, $cm, OUWIKI_PARAMS_URL));
    }

    // Format XHTML so it matches that sent to annotation marker creation code.
    /*$pageversion->xhtml = eln_convert_content($pageversion->xhtml, $subwiki, $cm, null,
            $pageversion->xhtmlformat);*/

    $userid = !$userid ? $USER->id : $userid;
    $neednewversion = false;

    // get the form data
    $new_annotations = array();
    $edited_annotations = array();
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'edit') === 0) {
            $edited_annotations[substr($key, 4)] = optional_param($key, null, PARAM_TEXT);
        } else if (strpos($key, 'new') === 0) {
            $new_annotations[substr($key, 3)] = optional_param($key, null, PARAM_TEXT);
        }
    }

    $transaction = $DB->start_delegated_transaction();

    // get the existing annotations to check for changes
    $stored = eln_get_annotations($pageversion);
    $updated = $stored;

    // do we need to delete orphaned annotations
    $deleted_annotations = array();
    if ($deleteorphaned) {
        foreach ($stored as $annotation) {
            if ($annotation->orphaned) {
                $DB->delete_records('eln_annotations', array('id' => $annotation->id));
                $deleted_annotations[$annotation->id] = '';
            }
        }
    }

    foreach ($edited_annotations as $key => $value) {
        if ($value == '') {
            $DB->delete_records('eln_annotations', array('id' => $key));
            $deleted_annotations[$key] = '';
        } else if ($value != $stored[$key]->content) {
            $dataobject = new stdClass();
            $dataobject->id = $key;
            $dataobject->pageid = $pageversion->pageid;
            $dataobject->userid = $USER->id;
            $dataobject->timemodified = time();
            $dataobject->content = $value;
            $DB->update_record('eln_annotations', $dataobject);
        }
    }

    $updated = array_diff_key($updated, $deleted_annotations);

    // we need to work backwords through this lot in order to maintain charactor position
    krsort($new_annotations, SORT_NUMERIC);
    $prevkey = '';
    $spanlength = 0;
    foreach ($new_annotations as $key => $value) {
        if ($value != '') {
            $dataobject = new stdClass();
            $dataobject->pageid = $pageversion->pageid;
            $dataobject->userid = $USER->id;
            $dataobject->timemodified = time();
            $dataobject->content = $value;
            $newannoid = $DB->insert_record('eln_annotations', $dataobject);
            $updated[$newannoid] = '';

            // we're still going so insert the new annotation into the xhtml
            $replace = '<span id="annotation'.$newannoid.'"></span>';
            $position = $key;
            if ($key == $prevkey) {
                $position = $key + $spanlength;
            } else {
                $position = $key;
            }

            $pageversion->xhtml = substr_replace($pageversion->xhtml, $replace, $position, 0);
            $neednewversion = true;
            $spanlength = strlen($replace);
            $prevkey = $key;
        }
    }

    // if we have got this far then commit the transaction, remove any unwanted spans
    // and save a new wiki version if required
    $neednewversion = (eln_cleanup_annotation_tags($updated, $pageversion->xhtml)) ? true : $neednewversion;

    // Note: Because we didn't get data values from the form, they have not been
    // sanity-checked so we don't know if the field actually existed or not.
    // That means we need to do another lock capability check here in addition
    // to the one done when displaying the form.
    if (has_capability('mod/eln:lock', $context)) {
        eln_lock_editing($pageversion->pageid, $lockunlock);
    }

    if ($neednewversion) {
        if (strpos($pageversion->xhtml, '"view.php') !== false) {
            // Tidy up and revert converted content (links) back to original format.
            $pattern = '(<a\b[^>]*?href="view\.php[^>]*?>(.*?)<\/a>)';
            $pageversion->xhtml = preg_replace($pattern, "[[$1]]", $pageversion->xhtml);
        }
        if ($contenttag = strpos($pageversion->xhtml, '<div class="eln_content">') !== false) {
            // Strip out content tag.
            $pageversion->xhtml = substr_replace($pageversion->xhtml, '', $contenttag, 28);
            $endtag = strrpos($pageversion->xhtml, '</div>');
            if ($endtag !== false) {
                $pageversion->xhtml = substr_replace($pageversion->xhtml, '', $endtag, 6);
            }
        }
        eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $pageversion->xhtml);
    }

    $transaction->allow_commit();
}

// Redirect for save or cancel
if ($save || $cancel) {
    eln_release_lock($pageversion->pageid);
    redirect('view.php?'.eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL), '', 0);
}
// OK, not redirecting...

// Handle case where page is locked by someone else
if (!$lockok) {
    // Print header etc
    echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context);

    $lockholder = $DB->get_record('user', array('id' => $lock->userid));
    $pagelockedtitle = get_string('pagelockedtitle', 'eln');
    $pagelockedtimeout = '';

    $details = new StdClass;
    $details->name = fullname($lockholder);
    $details->lockedat = eln_nice_date($lock->lockedat);
    $details->seenat = eln_nice_date($lock->seenat);

    if ($lock->seenat > time()) {
        // When the 'seen at' value is greater than current time, that means
        // their lock has been automatically confirmed in advance because they
        // don't have JavaScript support.
        $details->nojs = eln_nice_date($lock->seenat + OUWIKI_LOCK_PERSISTENCE);
        $pagelockeddetails = get_string('pagelockeddetailsnojs', 'eln', $details);
    } else {
        $pagelockeddetails = get_string('pagelockeddetails', 'eln', $details);
        if ($lock->expiresat) {
            $pagelockedtimeout = get_string('pagelockedtimeout', 'eln', userdate($lock->expiresat));
        }
    }
    $canoverride = has_capability('mod/eln:overridelock', $context);
    $pagelockedoverride = $canoverride ? '<p>'.get_string('pagelockedoverride', 'eln').'</p>' : '';
    $overridelock = get_string('overridelock', 'eln');
    $overridebutton = $canoverride ? "
<form class='eln_overridelock' action='override.php' method='post'>
  <input type='hidden' name='redirpage' value='annotate' />
  $wikiformfields
  <input type='submit' value='$overridelock' />
</form>
" : '';
    $cancel = get_string('cancel');
    $tryagain = get_string('tryagain', 'eln');
    print "
<div id='eln_lockinfo'>
  <h2>$pagelockedtitle</h2>
  <p>$pagelockeddetails $pagelockedtimeout</p>
  $pagelockedoverride
  <div class='eln_lockinfobuttons'>
    <form action='edit.php' method='get'>
      $wikiformfields
      <input type='submit' value='$tryagain' />
    </form>
    <form action='view.php' method='get'>
      $wikiformfields
      <input type='submit' value='$cancel' />
    </form>
    $overridebutton
  </div>
  </div><div>";

    eln_print_footer($course, $cm, $subwiki, $pagename);
    exit;
}
// The page is now locked to us! Go ahead and print edit form

// get title of the page
$title = get_string('annotatingpage', 'eln');
$wikiname = format_string(htmlspecialchars($eln->name));
$name = $pagename;
if ($pagename) {
    $title = $wikiname.' - '.$title.' : '.$pagename;
} else {
    $title = $wikiname.' - '.$title.' : '.get_string('startpage', 'eln');
}

// Print header
echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context,
    array(array('name' => get_string('annotatingpage', 'eln'), 'link' => null)),
    false, false, '', $title);

// Tabs
eln_print_tabs('annotate', $pagename, $subwiki, $cm, $context, $pageversion->versionid ? true : false, $pageversion->locked);

// prints the div that contains a message when js is disabled in the browser so cannot annotate.
print '<div id="eln_belowtabs_annotate_nojs"><p>'.get_string('jsnotenabled', 'eln').'</p>'.
        '<div class="eln_jsrequired"><p>'.get_string('jsajaxrequired', 'eln').'</p></div></div>';

// opens the annotate specific div for when js is enabled in the browser, user can annotate.
print '<div id="eln_belowtabs_annotate">';

eln_print_editlock($lock, $eln);

if ($eln->timeout) {
    $countdowntext = get_string('countdowntext', 'eln', $eln->timeout/60);
    print "<script type='text/javascript'>
document.write('<p><div id=\"ouw_countdown\"></div>$countdowntext<span id=\"ouw_countdownurgent\"></span></p>');
</script>";
}

print get_string('advice_annotate', 'eln');
$data = $elnoutput->eln_print_page($subwiki, $cm, $pageversion, false, 'annotate', $eln->enablewordcount);
echo $data[0];
$annotations = $data[1];

$customdata[0] = $annotations;
$customdata[1] = $pageversion;
$customdata[2] = $pagename;
$customdata[3] = $userid;
$customdata[4] = has_capability('mod/eln:lock', $context);
echo html_writer::tag('h2', get_string('annotations', 'eln'));

$annotateform = new mod_eln_annotate_form('annotate.php?id='.$id, $customdata);
$annotateform->display();

$usedannotations = array();
foreach ($annotations as $annotation) {
    if (!$annotation->orphaned) {
        $usedannotations[$annotation->id] = $annotation;
    }
}
echo '<div id="annotationcount" style="display:none;">'.count($usedannotations).'</div>';

echo '<div class="yui-skin-sam">';
echo '    <div id="annotationdialog" class="yui-pe-content">';
echo '        <div class="hd">'.get_string('addannotation', 'eln').'</div>';
echo '        <div class="bd">';
echo '            <form method="POST" action="post.php">';
echo '                <label for="annotationtext">'.get_string('addannotation', 'eln').':</label>';
echo '                <textarea name="annotationtext" id="annotationtext" rows="4" cols="30"></textarea>';
echo '            </form>';
echo '        </div>';
echo '    </div>';
echo '</div>';

// init JS module
$stringlist[] = array('add', 'eln');
$stringlist[] = array('cancel', 'eln');
$jsmodule = array('name'     => 'mod_eln_annotate',
                  'fullpath' => '/mod/eln/module.js',
                  'requires' => array('base', 'event', 'io', 'node', 'anim', 'panel',
                                      'yui2-container', 'yui2-dragdrop'),
                  'strings'  => $stringlist
                 );
$PAGE->requires->js_init_call('M.mod_eln_annotate.init', array(), true, $jsmodule);

// close <div id="#eln_belowtabs_annotate">
print '</div>';
// Footer
eln_print_footer($course, $cm, $subwiki, $pagename);
