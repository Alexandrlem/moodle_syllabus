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
 * View the syllabus of a course.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_syllabus\syllabus;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/syllabus:view', $context);

$canedit = has_capability('local/syllabus:edit', $context);
$cangenerate = has_capability('local/syllabus:generate', $context);
$record = syllabus::get_record($course->id);

$url = new moodle_url('/local/syllabus/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('syllabus', 'local_syllabus') . ': ' . format_string($course->shortname));
$PAGE->set_heading(format_string($course->fullname));

$output = $PAGE->get_renderer('core');
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('syllabus', 'local_syllabus'));

if (!$record || (!$record->published && !$canedit)) {
    echo $OUTPUT->notification(get_string('nosyllabus', 'local_syllabus'), 'info');
    if ($cangenerate) {
        echo $OUTPUT->box(get_string('nosyllabus_teacher', 'local_syllabus'));
        echo html_writer::div(
            $OUTPUT->single_button(new moodle_url('/local/syllabus/generate.php', ['id' => $course->id]),
                get_string('generatefromcourse', 'local_syllabus'), 'get', ['primary' => true]) .
            ($canedit ? $OUTPUT->single_button(new moodle_url('/local/syllabus/edit.php', ['id' => $course->id]),
                get_string('createmanually', 'local_syllabus'), 'get') : ''),
            'd-flex flex-wrap gap-2 mb-3');
    }
    echo $OUTPUT->footer();
    exit;
}

$event = \local_syllabus\event\syllabus_viewed::create(['context' => $context]);
$event->trigger();

$buttons = [];
if ($canedit) {
    $buttons[] = $OUTPUT->single_button(new moodle_url('/local/syllabus/edit.php', ['id' => $course->id]),
        get_string('edit'), 'get', ['primary' => true]);
}
if ($cangenerate) {
    $buttons[] = $OUTPUT->single_button(new moodle_url('/local/syllabus/generate.php', ['id' => $course->id]),
        get_string('generatefromcourse', 'local_syllabus'), 'get');
}
$buttons[] = $OUTPUT->single_button(new moodle_url('/local/syllabus/export.php',
    ['id' => $course->id, 'format' => 'pdf']), get_string('downloadpdf', 'local_syllabus'), 'get');
if ($canedit) {
    $buttons[] = $OUTPUT->single_button(new moodle_url('/local/syllabus/export.php',
        ['id' => $course->id, 'format' => 'json']), get_string('downloadjson', 'local_syllabus'), 'get');
}
echo html_writer::div(implode('', $buttons), 'local-syllabus-actions d-flex flex-wrap gap-2 mb-3');

if ($canedit && !$record->published) {
    echo $OUTPUT->notification(get_string('notpublished', 'local_syllabus'), 'warning');
}

echo $output->render_from_template('local_syllabus/document',
    (new \local_syllabus\output\document($record->syllabus))->export_for_template($output));

echo $OUTPUT->footer();
