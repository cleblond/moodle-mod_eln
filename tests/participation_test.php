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
 * OUWiki unit tests - test locallib functions
 *
 * @package    mod_eln
 * @copyright  2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}
global $CFG;

require_once($CFG->dirroot . '/mod/eln/locallib.php');

class eln_participation_test extends advanced_testcase {

    public function test_canview_course_wiki() {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $eln = $this->get_new_eln($course->id, OUWIKI_SUBWIKIS_SINGLE);
        $cm = get_coursemodule_from_instance('eln', $eln->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);
        $subwiki = eln_get_subwiki($course, $eln, $cm,
                $context, 0, $USER->id, true);

        // Can view all user participation.
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_USER_PARTICIPATION, $canview);

        $teacher = $this->get_new_user('teacher', $course->id);
        // Test teacher by passing id.
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm, $teacher->id);
        $this->assertEquals(OUWIKI_USER_PARTICIPATION, $canview);
        // Test teacher as cur user.
        $this->setUser($teacher);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_USER_PARTICIPATION, $canview);

        // Can only view own participation.
        $student = $this->get_new_user('student', $course->id);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm, $student->id);
        $this->assertEquals(OUWIKI_MY_PARTICIPATION, $canview);
        // Test student as current user.
        $this->setUser($student);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_MY_PARTICIPATION, $canview);

        // Can't view anything.
        $this->setGuestUser();
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_NO_PARTICIPATION, $canview);
    }

    public function test_canview_group_wiki() {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(array('groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1));

        $eln = $this->get_new_eln($course->id, OUWIKI_SUBWIKIS_GROUPS);
        $cm = get_coursemodule_from_instance('eln', $eln->id);
        $this->assertNotEmpty($cm);

        $context = context_module::instance($cm->instance);

        $group = $this->get_new_group($course->id);
        $subwiki = eln_get_subwiki($course, $eln, $cm, $context, $group->id, $USER->id, true);

        // Can view all user participation.
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_USER_PARTICIPATION, $canview);

        // Can only view own participation.
        $student = $this->get_new_user('student', $course->id);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm, $student->id);
        $this->assertEquals(OUWIKI_NO_PARTICIPATION, $canview);

        $this->setUser($student);
        // Testing when logged in a s student.
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_NO_PARTICIPATION, $canview);

        // Check when student is a member of the group.
        $this->get_new_group_member($group->id, $student->id);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm, $student->id);
        $this->assertEquals(OUWIKI_MY_PARTICIPATION, $canview);
        $canview = eln_can_view_participation($course, $eln, $subwiki, $cm);
        $this->assertEquals(OUWIKI_MY_PARTICIPATION, $canview);
    }

    public function test_participation() {
        global $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $eln = $this->get_new_eln($course->id, OUWIKI_SUBWIKIS_SINGLE);
        $cm = get_coursemodule_from_instance('eln', $eln->id);
        $this->assertNotEmpty($cm);

        $context = context_module::instance($cm->id);
        $subwiki = eln_get_subwiki($course, $eln, $cm, $context, 0, $USER->id, true);
        $pageversion = eln_get_current_page($subwiki, 'TEST PAGE', OUWIKI_GETPAGE_CREATE);
        $user = $this->get_new_user('student', $course->id);

        $content = 'content';
        $plus = ' plus';
        for ($i = 1; $i <= 5; $i++) {
            $content .= $plus . $i;
            $wordcount = eln_count_words($content);
            $this->save_new_version($pageversion->pageid, $content, $user->id, $wordcount);
        }
        // Remove one word.
        $content = str_replace('plus3', '', $content);
        $wordcount = eln_count_words($content);
        $this->save_new_version($pageversion->pageid, $content, $user->id, $wordcount);

        // User participation.
        list($returneduser, $participation) = eln_get_user_participation($user->id, $subwiki);
        $this->assertEquals($user->id, $returneduser->id);
        $this->assertNotNull($participation);
        $this->assertEquals(6, count($participation));

        // All participation.
        $participation = eln_get_participation($eln, $subwiki, $context, 0);
        $this->assertNotNull($participation);
        $userexists = array_key_exists($user->id, $participation);
        $this->assertTrue($userexists);
        $this->assertEquals(1, count($participation));
        $this->assertEquals(6, $participation[$user->id]->pageedits);
        $this->assertEquals(25, $participation[$user->id]->wordsadded);
        $this->assertEquals(0, $participation[$user->id]->wordsdeleted);
        $this->assertEquals(0, $participation[$user->id]->pagecreates);

        $user2 = $this->get_new_user('student', $course->id);
        $participation = eln_get_participation($eln, $subwiki, $context, 0);

        // A user who is enrolled, but with no contribution.
        $userexists = array_key_exists($user2->id, $participation);
        $this->assertTrue($userexists);
        $this->assertEquals(fullname($user2), fullname($participation[$user2->id]));
    }

    /**
     * Creates a new user and enrols them on course with role specified (optional)
     * @param string $rolename role shortname if enrolment required
     * @param int $courseid course id to enrol on
     * @return stdClass user
     */
    public function get_new_user($rolename = null, $courseid = null) {
        global $DB;
        $user = $this->getDataGenerator()->create_user();

        // Assign role if required.
        if ($rolename && $courseid) {
            $role = $DB->get_record('role', array('shortname' => $rolename));
            $this->getDataGenerator()->enrol_user($user->id, $courseid, $role->id);
        }

        return $user;
    }

    public function get_new_eln($courseid, $subwikis = 0) {
        $eln = new stdClass();
        $eln->course = $courseid;
        $eln->name = 'Test eln';
        $eln->subwikis = $subwikis;
        $eln->timeout = null;
        $eln->template = null;
        $eln->editbegin = null;
        $eln->editend = null;
        $eln->completionpages = 0;
        $eln->completionedits = 0;
        $eln->annotation = 0;
        $eln->introformat = 0;
        $eln->wordcount = 1;
        $eln->grade = 100;

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_eln');
        return $generator->create_instance($eln);
    }

    public function save_new_version($pageid, $xhtml, $userid, $wordcount) {
        global $DB;
        $version = new stdClass();
        $version->pageid = $pageid;
        $version->xhtml = $xhtml;
        $version->xhtmlformat = 1;
        $version->timecreated = time();
        $version->userid = $userid;
        $version->wordcount = $wordcount;
        $version->id = $DB->insert_record('eln_versions', $version);
        return $version;
    }

    public function get_new_group($courseid) {
        $group = new stdClass();
        $group->courseid = $courseid;
        $group->name = 'test group';
        return $this->getDataGenerator()->create_group($group);
    }

    public function get_new_group_member($groupid, $userid) {
        $member = new stdClass();
        $member->groupid = $groupid;
        $member->userid = $userid;
        return $this->getDataGenerator()->create_group_member($member);
    }
}
