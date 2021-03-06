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
 * This script is called through AJAX. It confirms that a user is still
 * trying to edit a page that they have locked (they haven't closed
 * their browser window or something).
 *
 * @copyright &copy; 2007 The Open University
 * @author s.marshall@open.ac.uk
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package eln
 */

require_once(dirname(__FILE__) . '/../../config.php');

header('Content-Type: text/plain');

$lockid = optional_param('lockid', 0, PARAM_INT);
if (!isset($lockid) || $lockid == 0) {
    print 'noid';
    exit;
}

if ($lock = $DB->get_record('eln_locks', array('id' => $lockid))) {
    $lock->seenat = time();
    $DB->update_record('eln_locks', $lock);
    print 'ok';
} else {
    print 'cancel'; // Tells user their lock has been cancelled.
}
