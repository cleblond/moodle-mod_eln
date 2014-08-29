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
 * Confirms reverting to previous version
 * when confirmed, reverts to previous version then redirects back to that page.
 * @copyright &copy; 2008 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');
require($CFG->dirroot.'/mod/eln/basicpage.php');

$id = required_param('id', PARAM_INT);
$versionid = required_param('version', PARAM_INT);
$confirmed = optional_param('confirm', null, PARAM_ALPHA);
$cancelled = optional_param('cancel', null, PARAM_ALPHA);

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
$elnoutput = $PAGE->get_renderer('mod_eln');

// Get the page version to be reverted back to (must not be deleted page version)
$pageversion = eln_get_page_version($subwiki, $pagename, $versionid);
if (!$pageversion || !empty($pageversion->deletedat)) {
    print_error('reverterrorversion', 'eln');
}

// Check for cancel
if (isset($cancelled)) {
    redirect('history.php?'.eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL));
    exit;
}

// Check permission - Allow anyone with edit capability to revert to a previous version
$canrevert = has_capability('mod/eln:edit', $context);
if (!$canrevert) {
    print_error('reverterrorcapability', 'eln');
}

// Check if reverting to previous version has been confirmed
if ($confirmed) {

    // Lock something - but maybe this should be the current version
    list($lockok, $lock) = eln_obtain_lock($eln, $pageversion->pageid);

    // Revert to previous version
    eln_save_new_version($course, $cm, $eln, $subwiki, $pagename, $pageversion->xhtml, -1, -1, -1, null, null, $pageversion->versionid);

    // Unlock whatever we locked
    eln_release_lock($pageversion->pageid);

    // Redirect to view what is now the current version
    redirect('view.php?'.eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_URL));
    exit;

} else {
    // Display confirm form
    $nav = get_string('revertversion', 'eln');
    echo $elnoutput->eln_print_start($eln, $cm, $course, $subwiki, $pagename, $context, array(array('name' => $nav, 'link' => null)), true, true);

    $date = eln_nice_date($pageversion->timecreated);
    print get_string('revertversionconfirm', 'eln', $date);
    print '<form action="revert.php" method="post">';
    print eln_display_wiki_parameters($pagename, $subwiki, $cm, OUWIKI_PARAMS_FORM);
    print
        '<input type="hidden" name="version" value="'.$versionid.'" />'.
        '<input type="submit" name="confirm" value="'.get_string('revertversion', 'eln').'"/> '.
        '<input type="submit" name="cancel" value="'.get_string('cancel').'"/>';
    print '</form>';

    // Footer
    eln_print_footer($course, $cm, $subwiki, $pagename);
}
