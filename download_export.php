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
 * This file will return a Zip containing all Quiz results as individual PDFs per person
 *
 * @package    block_signed_quiz_export
 * @copyright  Simon Schniedenharn 2021
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/lib/filelib.php');
require_once($CFG->dirroot . '/lib/moodlelib.php');

$exportid = required_param('exportid', PARAM_INT);

$quiz_exports = $DB->get_records('signed_quiz_export', array('id' => $exportid));
$filepath = $CFG->dataroot . current($quiz_exports)->path;
header("Content-Type: application/zip");
$filepath_parts = explode('/', $filepath);
header("Content-Disposition: attachment; filename=\"".end($filepath_parts));
readfile($filepath);
die();