<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.


/**
 * Download the syllabus as PDF or JSON (constructor-compatible format).
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_syllabus\pdf_exporter;
use local_syllabus\syllabus;

$courseid = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/syllabus:view', $context);

$PAGE->set_url(new moodle_url('/local/syllabus/export.php', ['id' => $course->id, 'format' => $format]));
$PAGE->set_context($context);

$record = syllabus::get_record($course->id);
$canedit = has_capability('local/syllabus:edit', $context);
if (!$record || (!$record->published && !$canedit)) {
    throw new moodle_exception('nosyllabus', 'local_syllabus');
}

if ($format === 'json') {
    require_capability('local/syllabus:edit', $context);
    send_file(syllabus::export_json($record->syllabus), pdf_exporter::filename($record->syllabus, 'json'),
        0, 0, true, true, 'application/json');
} else if ($format === 'pdf') {
    send_file(pdf_exporter::render($record->syllabus), pdf_exporter::filename($record->syllabus, 'pdf'),
        0, 0, true, true, 'application/pdf');
} else {
    throw new moodle_exception('invalidparameter', 'debug');
}
