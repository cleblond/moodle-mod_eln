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
 * View page. Displays wiki pages.
 *
 * @copyright &copy; 2007 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/lib/ajax/ajaxlib.php');

require($CFG->dirroot.'/mod/eln/basicpage.php');

$url = new moodle_url('/mod/eln/view.php', array('id' => $id, 'page' => $pagename));
$PAGE->set_url($url);
$PAGE->set_cm($cm);

$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('incourse');
require_course_login($course, true, $cm);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$elnoutput = $PAGE->get_renderer('mod_eln');

//echo "subwiki=".print_object($subwiki);

echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context);

// Check consistency in setting subwikis and group mode
$courselink = new moodle_url('/course/view.php?id=', array('id' =>  $cm->course));
if (($cm->groupmode == 0) && isset($subwiki->groupid)) {
    print_error("Sub-wikis is set to 'One wiki per group'.
        Please change Group mode to 'Separate groups' or 'Visible groups'.", 'error', $courselink);
}
if (($cm->groupmode > 0) && !isset($subwiki->groupid)) {
    print_error("Sub-wikis is NOT set to 'One wiki per group'.
        Please change Group mode to 'No groups'.", 'error', $courselink);
}

// Get the current page version
$pageversion = eln_get_current_page($subwiki, $pagename);
//print_object($pageversion);

$locked = ($pageversion) ? $pageversion->locked : false;

eln_print_tabs('view', $pagename, $subwiki, $cm, $context, $pageversion ? true : false, $locked);

if (($pagename === '' || $pagename === null) && strlen(preg_replace('/\s|<br\s*\/?>|<p>|<\/p>/',
        '', $eln->intro)) > 0) {
    $intro = file_rewrite_pluginfile_urls($eln->intro, 'pluginfile.php', $context->id,
            'mod_eln', 'intro', null);
    $intro = format_text($intro);
    print '<div class="ouw_intro">' . $intro . '</div>';
}

if ($pageversion) {
    // Print warning if page is large (more than 75KB)
    if (strlen($pageversion->xhtml) > 75 * 1024) {
        print '<div class="eln-sizewarning"><img src="' . $OUTPUT->pix_url('warning', 'eln') .
                '" alt="" />' . get_string('sizewarning', 'eln') .
                '</div>';
    }
    // Print page content
    $hideannotations = get_user_preferences(OUWIKI_PREF_HIDEANNOTATIONS, 0);
    $data = $elnoutput->eln_print_page($subwiki, $cm, $pageversion, true, 'view',
            $eln->enablewordcount, (bool)$hideannotations);
    echo $data[0];
    if ($subwiki->canedit && $pageversion->locked != '1') {
        print eln_display_create_page_form($subwiki, $cm, $pageversion);
        //print eln_display_clone_page_form($subwiki, $cm, $pageversion);
    }
    if (has_capability('mod/eln:lock', $context)) {
        print eln_display_lock_page_form($pageversion, $id);
    }
} else {
    // Page does not exist
    if($pagename) {
        print '<p>' . get_string('thisexperiment', 'eln') . ' "'. $pagename. '" ' . get_string('pagedoesnotexist', 'eln');
    } else {
        print '<p>' . get_string('startpagedoesnotexist', 'eln').'</p>';
    }
    
    //print get_string($pagename ? 'pagedoesnotexist' : 'startpagedoesnotexist', 'eln').'</p>';
    if ($subwiki->canedit) {
        print '<p>'.get_string('wouldyouliketocreate', 'eln').'</p>';
        print "<form method='get' action='edit.php'>";
        print eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_FORM);
        print "<input type='submit' value='".get_string('createpage', 'eln')."' /></form>";
    }
}

if ($timelocked = eln_timelocked($subwiki, $eln, $context)) {
    print '<div class="ouw_timelocked">'.$timelocked.'</div>';
}

// init JS module
$stringlist[] = array('typeinsectionname', 'eln');
$stringlist[] = array('typeinpagename', 'eln');
$stringlist[] = array('typeinclonepagename', 'eln');
$stringlist[] = array('collapseannotation', 'eln');
$stringlist[] = array('expandannotation', 'eln');
$jsmodule = array('name'     => 'mod_eln_view',
                  'fullpath' => '/mod/eln/module.js',
                  'requires' => array('base', 'event', 'io', 'node', 'anim', 'panel'),
                  'strings'  => $stringlist
                 );
$PAGE->requires->js_init_call('M.mod_eln_view.init', array(), true, $jsmodule);

// Footer
eln_print_footer($course, $cm, $subwiki, $pagename);
