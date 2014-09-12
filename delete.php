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
 * [Un]Deletes a version of a page then redirects back to the history page
 * @copyright &copy; 2008 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');

$id = required_param('id', PARAM_INT);
$versionid = required_param('version', PARAM_INT);
$pagename = optional_param('page', '', PARAM_TEXT);

$url = new moodle_url('/mod/eln/view.php', array('id' => $id, 'page' => $pagename));
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

require_capability('mod/eln:deletepage', $context);

// Get the page version to be [un]deleted
$pageversion = eln_get_page_version($subwiki, $pagename, $versionid);
if (!$pageversion) {
    print_error('deleteversionerrorversion', 'eln');
}

// Note: No need to confirm deleting/undeleting page version
// Lock page
list($lockok, $lock) = eln_obtain_lock($eln, $pageversion->pageid);

// Set default action
$action = 'delete';
try {
    // [Un]Delete page version
    if (empty($pageversion->deletedat)) {

        // Flag page version as deleted
        $DB->set_field('eln_versions', 'deletedat', time(), array('id' => $versionid));

        // Check if current version has been deleted
        if ($pageversion->versionid == $pageversion->currentversionid) {

            // Current version deleted
            // Update current version to first undeleted version (or null)
            $pageversions = eln_get_page_history($pageversion->pageid, false, 0, 1);
            if (($currentpageversion = reset($pageversions))) {
                // Page version found, update page current version id
                $DB->set_field('eln_pages', 'currentversionid', $currentpageversion->versionid, array('id' => $pageversion->pageid));
            } else {
                // No page version found, reset page current version id
                $DB->set_field('eln_pages', 'currentversionid', null, array('id' => $pageversion->pageid));
            }
        }

        // Update completion status for user
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) && ($eln->completionpages || $eln->completionedits)) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE);
        }
    } else {

        // Flag page version as no longer deleted
        $action = 'undelete';
        $DB->set_field('eln_versions', 'deletedat', null, array('id' => $versionid));

        // Get first undeleted (current) page version (there must be one)
        $pageversions = eln_get_page_history($pageversion->pageid, false, 0, 1);
        $currentpageversion = reset($pageversions);
        if (!$currentpageversion) {
            throw new Exception('Error deleting/undeleting eln page version');
        }

        // Check if version that has been undeleted should be the new current version
        if ($pageversion->currentversionid != $currentpageversion->versionid) {

            // Set new current version id
            $DB->set_field('eln_pages', 'currentversionid', $currentpageversion->versionid, array('id' => $pageversion->pageid));
        }

        // Update completion status for user
        $completion = new completion_info($course);
        if ($completion->is_enabled($cm) && ($eln->completionedits || $eln->completionpages)) {
            $completion->update_state($cm, COMPLETION_COMPLETE, $pageversion->userid);
        }
    }

} catch (Exception $e) {

    // Unlock page
    eln_release_lock($pageversion->pageid);

    eln_dberror('Error deleting/undeleting eln page version '.$e);
}

// Unlock page
eln_release_lock($pageversion->pageid);

// Log delete or undelete action
$elnparamsurl = eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL);
//add_to_log($course->id, 'eln', 'version'.$action, 'delete.php?'.$elnparamsurl.'&amp;version='.$versionid, '', $cm->id);

//$redirecturl = new moodle_url('/mod/eln/history.php');


$logurl = 'delete.php?' . $ouwikiparamsurl . '&amp;version=' . $versionid;

// Add to log.
$params = array(
 'context' => $context,
 'objectid' => $pageversion->pageid,
 'other' => array('logurl' => $logurl)
);

$event = null;
if ($action == 'delete') {
 $event = \mod_eln\event\page_version_deleted::create($params);
} else {
 // Undeleting.
 $event = \mod_eln\event\page_version_undeleted::create($params);
}
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('eln', $eln);
$event->trigger();







// Redirect to view what is now the current version
$redirecturl = new moodle_url('/mod/eln/history.php');
redirect($redirecturl.'?'.$elnparamsurl);
exit;
