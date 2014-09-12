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
 * Lock editing page. Allows user to lock or unlock the editing of a wiki page
 *
 * @copyright &copy; 2009 The Open University
 * @author b.j.waddington@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');

$id = required_param('id', PARAM_INT);           // Course Module ID that defines wiki

// check we are using the annotation system
$action = required_param('ouw_lock', PARAM_RAW);
$pageid = required_param('ouw_pageid', PARAM_INT);

// Get the current page version, creating page if needed
$pageversion = eln_get_current_page($subwiki, $pagename, OUWIKI_GETPAGE_ACCEPTNOVERSION);
$wikiformfields = eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_FORM);
$sectionfields = '';

// get the context and check user has the required capability
require_capability('mod/eln:lock', $context);
$elnoutput = $PAGE->get_renderer('mod_eln');

// Get an editing lock
list($lockok, $lock) = eln_obtain_lock($eln, $pageversion->pageid);

// Handle case where page is locked by someone else
if (!$lockok) {
    // Print header etc
    echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context);

    $details = new StdClass;
    $lockholder = $DB->get_record('user', array('id' => $lock->userid));
    $details->name = fullname($lockholder);
    $details->lockedat = eln_nice_date($lock->lockedat);
    $details->seenat = eln_nice_date($lock->seenat);
    $pagelockedtitle = get_string('pagelockedtitle', 'eln');
    $pagelockedtimeout = '';
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
  <input type='hidden' name='redirpage' value='view'>
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
      $sectionfields
      <input type='submit' value='$tryagain' />
    </form>
    <form action='view.php' method='get'>
      $wikiformfields
      <input type='submit' value='$cancel' />
    </form>
    $overridebutton
  </div>
</div>";
    print_footer($course);
    exit;
}

// The page is now locked to us!
// To have got this far everything checks out so lock or unlock the page as requested
if ($action == get_string('lockpage', 'eln')) {
    eln_lock_editing($pageid, true);
    $event = 'lock';
} else if ($action == get_string('unlockpage', 'eln')) {
    eln_lock_editing($pageid, false);
    $event = 'unlock';
}

// all done - release the editing lock...
eln_release_lock($pageversion->pageid);

// add to moodle log...
$url = 'lock.php';
$url .= (strpos($url, '?')===false ? '?' : '&').'id='.$cm->id;
if ($subwiki->groupid) {
    $url .= '&group='.$subwiki->groupid;
}
if ($subwiki->userid) {
    $url .= '&user='.$subwiki->userid;
}
if ($pagename) {
    $url .= '&page='.urlencode($pagename);
    $info = $pagename;
} else {
    $info = '';
}
add_to_log($course->id, 'eln', $event, $url, $info, $cm->id);

// redirect back to the view page.
redirect('view.php?id='.$id);
