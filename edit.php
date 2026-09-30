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
 * Edit the syllabus of a course.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_syllabus\form\edit_form;
use local_syllabus\syllabus;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/syllabus:edit', $context);

$url = new moodle_url('/local/syllabus/edit.php', ['id' => $course->id]);
$viewurl = new moodle_url('/local/syllabus/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('editsyllabus', 'local_syllabus'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('syllabus', 'local_syllabus'), $viewurl);
$PAGE->navbar->add(get_string('edit'));

$record = syllabus::get_record($course->id);
$data = $record ? $record->syllabus : syllabus::defaults();
if (!$record) {
    // Pre-fill the course name for a brand new syllabus.
    $data['course_name'] = format_string($course->fullname, true, ['context' => $context]);
}

$form = new edit_form($url, ['themecount' => count($data['themes'])]);

if ($form->is_cancelled()) {
    redirect($viewurl);
} else if ($formdata = $form->get_data()) {
    $newdata = edit_form::form_to_data($formdata, $data);
    $signature = $form->get_file_content('signaturefile');
    if ($signature !== false && empty($formdata->removesignature)) {
        $info = @getimagesizefromstring($signature);
        if ($info && in_array($info['mime'], ['image/png', 'image/jpeg'])) {
            $newdata['signature'] = 'data:' . $info['mime'] . ';base64,' . base64_encode($signature);
            $newdata['isSignatureImage'] = true;
        }
    }
    syllabus::save($course->id, $newdata, !empty($formdata->published));
    redirect($viewurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$defaults = edit_form::data_to_form($data);
$defaults['courseid'] = $course->id;
$defaults['published'] = $record ? $record->published : 0;
$form->set_data($defaults);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('editsyllabus', 'local_syllabus'));
if (has_capability('local/syllabus:generate', $context)) {
    echo html_writer::div($OUTPUT->single_button(new moodle_url('/local/syllabus/generate.php', ['id' => $course->id]),
        get_string('generatefromcourse', 'local_syllabus'), 'get'), 'mb-3');
}
$form->display();
echo $OUTPUT->footer();
