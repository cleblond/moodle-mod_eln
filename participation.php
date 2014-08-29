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
 * Page for viewing all user participation
 *
 * @package mod
 * @subpackage eln
 * @copyright 2011 The Open University
 * @author Stacey Walker <stacey@catalyst-eu.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');
require_once($CFG->libdir.'/gradelib.php');

$id         = required_param('id', PARAM_INT); // Course Module ID
$groupid    = optional_param('group', 0, PARAM_INT);
$pagename   = optional_param('pagename', '', PARAM_TEXT);
$download   = optional_param('download', '', PARAM_TEXT);
$page       = optional_param('page', 0, PARAM_INT); // flexible_table page

$params = array(
    'id'        => $id,
    'group'     => $groupid,
    'pagename'  => $pagename,
    'download'  => $download,
    'page'      => $page,
);
$url = new moodle_url('/mod/eln/participation.php', $params);
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('eln', $id)) {
    print_error('invalidcoursemodule');
}

// Checking course instance
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

if (!$eln = $DB->get_record('eln', array('id' => $cm->instance))) {
    print_error('invalidcoursemodule');
}

$PAGE->set_cm($cm);
$context = context_module::instance($cm->id);
$PAGE->set_pagelayout('incourse');
require_course_login($course, true, $cm);

// participation capability check
$canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
if ($canview != OUWIKI_USER_PARTICIPATION) {
    print_error('nopermissiontoshow');
}
$viewfullnames = has_capability('moodle/site:viewfullnames', $context);

$groupname = '';
if ($groupid) {
    $groupname = $DB->get_field('groups', 'name', array('id' => $groupid));
}

// all enrolled users for table pagination
$coursecontext = context_course::instance($course->id);
$participation = eln_get_participation($eln, $subwiki, $context, $groupid);

// is grading enabled and available for the current user
$grading_info = array();
if ($eln->grade != 0 && has_capability('mod/eln:grade', $context) &&
        (!$groupid || ($groupid && has_capability('moodle/site:accessallgroups', $context)
                || ($groupid && groups_is_member($groupid))))) {
    $grading_info = grade_get_grades($course->id, 'mod',
        'eln', $eln->id, array_keys($participation));
}

$elnoutput = $PAGE->get_renderer('mod_eln');

// Headers
if (empty($download)) {
    echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, get_string('userparticipation', 'eln'), $context);

    // gets a message after grades updated
    if (isset($SESSION->elngradesupdated)) {
        $message = $SESSION->elngradesupdated;
        unset($SESSION->elngradesupdated);
        echo $OUTPUT->notification($message, 'notifysuccess');
    }
}

$elnoutput->eln_render_participation_list($cm, $course, $pagename, $groupid, $eln,
    $subwiki, $download, $page, $grading_info, $participation, $coursecontext, $viewfullnames,
    $groupname);

// Footer
if (empty($download)) {
    eln_print_footer($course, $cm, $subwiki, $pagename, null, 'view');
}
