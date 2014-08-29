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
 * 'Wiki changes' page. Displays a list of recent changes to the wiki. You
 * can choose to view all changes or only new pages.
 *
 * @copyright &copy; 2007 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 *//** */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/mod/eln/basicpage.php');
require_once($CFG->dirroot.'/mod/eln/locallib.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$newpages = optional_param('type', '', PARAM_ALPHA) == 'pages';
$from = optional_param('from', '', PARAM_INT);

$url = new moodle_url('/mod/eln/wikihistory.php', array('id' => $id));
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

// Get basic wiki parameters
$wikiparams = eln_display_wiki_parameters('', $subwiki, $cm);
$tabparams = $newpages ? $wikiparams.'&amp;type=pages' : $wikiparams;

// Get changes
if ($newpages) {
    $changes = eln_get_subwiki_recentpages($subwiki->id, $from, OUWIKI_PAGESIZE+1);
} else {
    $changes = eln_get_subwiki_recentchanges($subwiki->id, $from, OUWIKI_PAGESIZE+1);
}

// Check to see whether any change has been overwritten by being imported.
$overwritten = false;
foreach ($changes as $change) {
    if (!empty($change->importversionid)) {
        $overwritten = true;
        break;
    }
}

// Do header
$atomurl = $CFG->wwwroot.'/mod/eln/feed-wikihistory.php?'.$wikiparams.
    ($newpages?'&amp;type=pages' : '').'&amp;magic='.$subwiki->magic;
$rssurl = $CFG->wwwroot.'/mod/eln/feed-wikihistory.php?'.$wikiparams.
    ($newpages?'&amp;type=pages' : '').'&amp;magic='.$subwiki->magic.'&amp;format=rss';
$meta = '<link rel="alternate" type="application/atom+xml" title="Atom feed" '.
    'href="'.$atomurl.'" />';

// bug #3542
$wikiname = format_string(htmlspecialchars($eln->name));
$title = $wikiname.' - '.get_string('wikirecentchanges', 'eln');

echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki,
    $from > 0
        ? get_string('wikirecentchanges_from', 'eln', (int)($from/OUWIKI_PAGESIZE) + 1)
        : get_string('wikirecentchanges', 'eln'),
    $context, null, false, false, $meta, $title);

// Print tabs for selecting all changes/new pages
$tabrow = array();
$tabrow[] = new tabobject('changes', 'wikihistory.php?'.$wikiparams,
    get_string('tab_index_changes', 'eln'));
$tabrow[] = new tabobject('pages', 'wikihistory.php?'.$wikiparams.'&amp;type=pages',
    get_string('tab_index_pages', 'eln'));
$tabs = array();
$tabs[] = $tabrow;
print_tabs($tabs, $newpages ? 'pages' : 'changes');
print '<div id="eln_belowtabs">';

if ($newpages) {
    $pagetabname = get_string('tab_index_pages', 'eln');
} else {
    $pagetabname = get_string('tab_index_changes', 'eln');
}
print get_accesshide($pagetabname, 'h1');

// On first page, show information
if (!$from) {
    print get_string('advice_wikirecentchanges_'
        .($newpages ? 'pages' : 'changes'
        .(!empty($CFG->elnenablecurrentpagehighlight) ? '' : '_nohighlight')), 'eln').'</p>';
}

$strdate = get_string('date');
$strtime = get_string('time');
$strpage = get_string('page', 'eln');
$strperson = get_string('changedby', 'eln');
$strview = get_string('view');

$strimport = '';
if ($overwritten) {
    $strimport = get_string('importedfrom', 'eln');
}

print "
<table>
<tr><th scope='col'>$strdate</th><th scope='col'>$strtime</th><th scope='col'>$strpage</th>".
($newpages?'':'<th><span class="accesshide">'.$strview.'</span></th>');
if ($eln->enablewordcount) {
    print "<th scope='col'>".get_string('words', 'eln')."</th>";
}
if ($overwritten) {
    print '<th scope="col">' . $strimport . '</th>';
}
print "
  <th scope='col'>$strperson</th></tr>
";

$strchanges = get_string('changes', 'eln');
$strview = get_string('view');
$lastdate = '';
$count = 0;
foreach ($changes as $change) {
    $count++;
    if ($count > OUWIKI_PAGESIZE) {
        break;
    }

    $pageparams = eln_display_wiki_parameters($change->title, $subwiki, $cm);

    $date = userdate($change->timecreated, get_string('strftimedate'));
    if ($date == $lastdate) {
        $date = '';
    } else {
        $lastdate = $date;
    }
    $time = eln_recent_span($change->timecreated).userdate($change->timecreated, get_string('strftimetime')).'</span>';

    $page = $change->title ? htmlspecialchars($change->title) : get_string('startpage', 'eln');
    if (!empty($change->previousversionid)) {
        $changelink = " <small>(<a href='diff.php?$pageparams&amp;v2={$change->versionid}&amp;v1={$change->previousversionid}'>$strchanges</a>)</small>";
    } else {
        $changelink = ' <small>('.get_string('newpage', 'eln').')</small>';
    }

    $current = '';
    if ($change->versionid == $change->currentversionid || $newpages) {
        $viewlink = "view.php?$pageparams";
        if (!$newpages && !empty($CFG->elnenablecurrentpagehighlight)) {
            $current =' class="current"';
        }
    } else {
        $viewlink = "viewold.php?$pageparams&amp;version={$change->versionid}";
    }

    $change->id = $change->userid;
    if ($change->id) {
        $userlink = eln_display_user($change, $course->id);
    } else {
        $userlink = '';
    }

    if ($newpages) {
        $actions = '';
        $page = "<a href='$viewlink'>$page</a>";
    } else {
        $actions = "<td class='actions'><a href='$viewlink'>$strview</a>$changelink</td>";
    }

    // see bug #3611
    if (!empty($current) && !empty($CFG->elnenablecurrentpagehighlight)) {
        // current page so add accessibility stuff
        $accessiblityhide = '<span class="accesshide">'.get_string('currentversionof', 'eln').'</span>';
        $dummy = $page;
        $page = $accessiblityhide.$dummy;
    }

    print "
<tr$current>
  <td class='ouw_leftcol'>$date</td><td>$time</td><td>$page</td>
  $actions";
    if ($eln->enablewordcount) {
        if (isset($change->previouswordcount)) {
            $wordcountchanges = eln_wordcount_difference($change->wordcount,
                    $change->previouswordcount, true);
        } else {
            // first page
            $wordcountchanges = eln_wordcount_difference($change->wordcount, 0, false);
        }
        print "<td>$wordcountchanges</td>";
    }
    if ($overwritten) {
        if (!empty($change->importversionid)) {
            $selectedeln = eln_get_wiki_details($change->importversionid);
            print '<td>';
            if ($selectedeln->courseshortname) {
                print $selectedeln->courseshortname. '<br/>';
            }
            print $selectedeln->name;
            if ($selectedeln->group) {
                print '<br/>';
                print '[[' .$selectedeln->group. ']]';
            } else if ($selectedeln->user) {
                print '<br/>';
                print '[[' .$selectedeln->user. ']]';
            }
            print '</td>';
        } else {
            print '<td></td>';
        }
    }
    print "
  <td class='ouw_rightcol'>$userlink</td>
</tr>";
}

print '</table>';

if (empty($changes)) {
    echo get_string('nowikipages', 'eln');
}

if ($count > OUWIKI_PAGESIZE || $from > 0) {
    print '<div class="ouw_paging"><div class="ouw_paging_prev">&nbsp;';
    if ($from > 0) {
        $jump = $from - OUWIKI_PAGESIZE;
        if ($jump < 0) {
            $jump = 0;
        }
        print link_arrow_left(get_string('previous', 'eln'),
            'wikihistory.php?'.$tabparams. ($jump > 0 ? '&amp;from='.$jump : ''));
    }
    print '</div><div class="ouw_paging_next">';
    if ($count > OUWIKI_PAGESIZE) {
        $jump = $from + OUWIKI_PAGESIZE;
        print link_arrow_right(get_string('next', 'eln'),
            'wikihistory.php?'.$tabparams. ($jump > 0 ? '&amp;from='.$jump : ''));
    }
    print '&nbsp;</div></div>';
}

$a = new stdClass();
$a->atom = $atomurl;
$a->rss = $rssurl;
print '<p class="ouw_subscribe"><a href="'.$atomurl.'" title="'.get_string('feedalt', 'eln').
    '"><img src="'.$OUTPUT->pix_url('rss', 'eln').'" alt=""/></a> <span>'.
    get_string('feedsubscribe', 'eln', $a).'</span></p>';

// Footer
eln_print_footer($course, $cm, $subwiki);
