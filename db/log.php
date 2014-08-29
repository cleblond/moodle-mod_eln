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
 * Definition of log events
 *
 *
 * @package    mod_eln
 * @copyright  2012 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $DB;

$logs = array(
    array('module' => 'eln', 'action' => 'add', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'annotate', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'diff', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'edit', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'entirewiki', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'history', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'lock', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'participation', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'revert', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'search', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'unlock', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'update', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'userparticipation', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'versiondelete', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'versionundelete', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'view', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'view all', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'viewold', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'wikihistory', 'mtable' => 'eln', 'field' => 'name'),
    array('module' => 'eln', 'action' => 'wikiindex', 'mtable' => 'eln', 'field' => 'name')
);