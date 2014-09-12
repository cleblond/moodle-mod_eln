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
 * Admin settings.
 *
 * @package mod_eln
 * @copyright 2013 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) { // needs condition or error on login page
    $settings = new admin_settingpage(
            'mod_eln', get_string('ousearch', 'mod_eln'));
    //$ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
            'eln/remote', get_string('remote', 'mod_eln'),
            get_string('configremote', 'mod_eln'), '', PARAM_RAW));

    $settings->add(new admin_setting_configtext(
            'eln_maxterms', get_string('maxterms', 'mod_eln'),
            get_string('maxterms_desc', 'mod_eln'), '20', PARAM_INT));
}
