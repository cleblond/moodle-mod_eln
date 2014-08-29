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
 * PHPUnit data generator tests
 *
 * @package    mod_eln
 * @copyright  2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * PHPUnit data generator testcase
 *
 * @package    mod_eln
 * @copyright  2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_eln_generator_testcase extends advanced_testcase {

    public function test_eln_generator() {
        global $DB;

        $this->resetAfterTest(true);

        $this->assertEquals(0, $DB->count_records('eln'));

        $course = $this->getDataGenerator()->create_course();

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_eln');
        $this->assertInstanceOf('mod_eln_generator', $generator);
        $this->assertEquals('eln', $generator->get_modulename());

        $firstwiki = $generator->create_instance(array('course' => $course->id));
        $this->assertEquals($firstwiki->name, 'OUWIKI1');

        $wiki = $generator->create_instance(array('course' => $course->id,
                'subwikis' => 1, 'name' => 'TEST'));

        // Test general wiki creation.
        $this->assertEquals(2, $DB->count_records('eln'));

        $cm = get_coursemodule_from_instance('eln', $wiki->id);
        $this->assertEquals($wiki->id, $cm->instance);
        $this->assertEquals('eln', $cm->modname);
        $this->assertEquals($course->id, $cm->course);

        $context = context_module::instance($cm->id);
        $this->assertEquals($wiki->cmid, $context->instanceid);

        // Test options pulled through.
        $this->assertEquals('TEST', $wiki->name);
        $this->assertEquals(1, $wiki->subwikis);
    }

    public function test_eln_create_content() {
        global $DB;

        $this->resetAfterTest(true);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_eln');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id);

        $wiki = $generator->create_instance(array('course' => $course->id));

        // Test create_content() without subwiki or page/edit.
        $newpage = $generator->create_content($wiki);
        $this->assertInstanceOf('stdClass', $newpage);
        $this->assertEquals('OU Wiki Test Page1', $newpage->title);
        $this->assertEquals(1, $newpage->currentversionid);
        $this->assertTrue($newpage->currentversionid == $newpage->firstversionid &&
                $newpage->currentversionid == $newpage->versionid);
        $this->assertEquals('Test content', $newpage->xhtml);
        $this->assertEquals(2, $newpage->wordcount);

        // Test create_content() with subwiki sent + update version.
        $record = array();
        $record['subwiki'] = $DB->get_record('eln_subwikis', array('wikiid' => $wiki->id));
        $record['newversion'] = (object) array('content' => 'NEW');
        $updatever = $generator->create_content($wiki, $record);
        $updatever = $DB->get_record('eln_versions', array('id' => $updatever));
        $this->assertInstanceOf('stdClass', $updatever);
        $this->assertEquals('NEW', $updatever->xhtml);
    }

}
