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
 * Generate a syllabus from the course content (or import a constructor JSON file).
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_syllabus\ai_helper;
use local_syllabus\form\generate_form;
use local_syllabus\generator;
use local_syllabus\syllabus;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/syllabus:generate', $context);

$url = new moodle_url('/local/syllabus/generate.php', ['id' => $course->id]);
$viewurl = new moodle_url('/local/syllabus/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('generatefromcourse', 'local_syllabus'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('syllabus', 'local_syllabus'), $viewurl);
$PAGE->navbar->add(get_string('generate', 'local_syllabus'));

$record = syllabus::get_record($course->id);
$aiavailable = ai_helper::is_available($context);
$form = new generate_form($url, [
    'aiavailable' => $aiavailable,
    'needpolicy' => $aiavailable && !ai_helper::policy_accepted($USER->id),
    'hasexisting' => (bool)$record,
]);

if ($form->is_cancelled()) {
    redirect($viewurl);
} else if ($formdata = $form->get_data()) {
    core_php_time_limit::raise(300);
    $existing = $record ? $record->syllabus : syllabus::defaults();

    if ($formdata->source === 'json') {
        $generated = syllabus::import_json((string)$form->get_file_content('jsonfile'));
        // Imported files may carry a signature image; keep it.
        $keepsignature = $generated['signature'];
    } else {
        $useai = !empty($formdata->useai) && $aiavailable;
        if ($useai && !empty($formdata->acceptpolicy)) {
            ai_helper::accept_policy($USER->id, $context);
        }
        $generator = new generator($course, ['modules' => (int)$formdata->modules, 'useai' => $useai]);
        $generated = $generator->generate($existing);
        $keepsignature = '';
    }

    $merged = syllabus::merge($existing, $generated, $formdata->mode === 'empty');
    if ($keepsignature !== '' && ($formdata->mode !== 'empty' || $merged['signature'] === '')) {
        $merged['signature'] = $keepsignature;
        $merged['isSignatureImage'] = true;
    }
    syllabus::save($course->id, $merged, $record ? (bool)$record->published : false);
    redirect(new moodle_url('/local/syllabus/edit.php', ['id' => $course->id]),
        get_string('generated', 'local_syllabus'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$form->set_data(['courseid' => $course->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('generatefromcourse', 'local_syllabus'));
$form->display();
echo $OUTPUT->footer();
