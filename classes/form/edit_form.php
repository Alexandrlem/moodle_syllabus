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

namespace local_syllabus\form;

use local_syllabus\syllabus;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Syllabus edit form.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $themecount = (int)($this->_customdata['themecount'] ?? 0);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        // 01. General information.
        $mform->addElement('header', 'hdr_general', get_string('sec_general', 'local_syllabus'));
        $mform->setExpanded('hdr_general');
        $this->text('course_name', true);
        $this->text('faculty');
        $this->text('educational_program');
        $this->text('specialty');
        $this->text('education_level');
        $this->text('field_of_study');
        $this->text('instructor_name');
        $this->text('instructor_position');
        $this->text('instructor_department');

        // 02. Approval.
        $mform->addElement('header', 'hdr_approval', get_string('sec_approval', 'local_syllabus'));
        $this->text('department');
        $this->text('dept_head');
        $this->text('protocol_num');
        $this->text('protocol_date');
        $this->text('year');
        $this->text('city');
        $mform->addElement('filepicker', 'signaturefile', get_string('signaturefile', 'local_syllabus'), null,
            ['accepted_types' => ['.png', '.jpg', '.jpeg'], 'maxbytes' => 512000]);
        $mform->addHelpButton('signaturefile', 'signaturefile', 'local_syllabus');
        $mform->addElement('advcheckbox', 'removesignature', '', get_string('removesignature', 'local_syllabus'));

        // 03. Contacts.
        $mform->addElement('header', 'hdr_contacts', get_string('sec_contacts', 'local_syllabus'));
        $this->text('instructor_email');
        $this->text('instructor_phone');
        $this->text('site_link');
        $this->text('ksu_online_link');
        $mform->addElement('select', 'final_control', get_string('f_final_control', 'local_syllabus'), [
            '' => get_string('choosedots'),
            get_string('finalcontrol_exam', 'local_syllabus') => get_string('finalcontrol_exam', 'local_syllabus'),
            get_string('finalcontrol_credit', 'local_syllabus') => get_string('finalcontrol_credit', 'local_syllabus'),
        ]);
        $this->text('conference_service');
        $this->area('consultation_schedule', 2);

        // 04. Annotation and goals.
        $mform->addElement('header', 'hdr_annotation', get_string('sec_annotation', 'local_syllabus'));
        $this->area('annotation', 6);
        $this->area('goals', 4);
        $this->area('tasks', 5, true);

        // 05. Competencies and outcomes.
        $mform->addElement('header', 'hdr_outcomes', get_string('sec_outcomes', 'local_syllabus'));
        $this->area('competencies', 6, true);
        $this->area('outcomes', 6, true);

        // 06. Volume and attributes.
        $mform->addElement('header', 'hdr_attributes', get_string('sec_attributes', 'local_syllabus'));
        foreach (['credits', 'total_hours', 'lecture_hours', 'practice_hours', 'self_hours'] as $field) {
            $this->text($field, false, 10);
        }
        $years = [];
        for ($i = 1; $i <= 6; $i++) {
            $years[(string)$i] = $i;
        }
        $semesters = [];
        for ($i = 1; $i <= 12; $i++) {
            $semesters[(string)$i] = $i;
        }
        $mform->addElement('select', 'teach_year', get_string('f_teach_year', 'local_syllabus'), $years);
        $mform->addElement('select', 'semester', get_string('f_semester', 'local_syllabus'), $semesters);
        $mform->addElement('select', 'course_type', get_string('f_course_type', 'local_syllabus'), [
            'mandatory' => get_string('coursetype_mandatory', 'local_syllabus'),
            'elective' => get_string('coursetype_elective', 'local_syllabus'),
        ]);

        // 07-09. Policy, technical requirements, prerequisites.
        $mform->addElement('header', 'hdr_policy', get_string('sec_policy', 'local_syllabus'));
        $this->area('policy', 8);
        $this->area('tech_requirements', 4);
        $this->area('prerequisites', 4, true);

        // 10. Course scheme.
        $mform->addElement('header', 'hdr_scheme', get_string('sec_scheme', 'local_syllabus'));
        $mform->addElement('static', 'schemehelp', '', get_string('schemehelp', 'local_syllabus'));
        $repeat = [
            $mform->createElement('select', 'theme_type', get_string('f_theme_type', 'local_syllabus'), [
                'theme' => get_string('type_theme', 'local_syllabus'),
                'module' => get_string('type_module', 'local_syllabus'),
            ]),
            $mform->createElement('text', 'theme_title', get_string('f_theme_title', 'local_syllabus'), ['size' => 70]),
            $mform->createElement('text', 'theme_lectures', get_string('f_theme_lectures', 'local_syllabus'), ['size' => 4]),
            $mform->createElement('text', 'theme_practicals', get_string('f_theme_practicals', 'local_syllabus'),
                ['size' => 4]),
            $mform->createElement('textarea', 'theme_description', get_string('f_theme_description', 'local_syllabus'),
                ['rows' => 3, 'cols' => 70]),
            $mform->createElement('static', 'theme_separator', '', '<hr>'),
        ];
        $options = [
            'theme_title' => ['type' => PARAM_TEXT],
            'theme_lectures' => ['type' => PARAM_RAW_TRIMMED, 'hideif' => ['theme_type', 'eq', 'module']],
            'theme_practicals' => ['type' => PARAM_RAW_TRIMMED, 'hideif' => ['theme_type', 'eq', 'module']],
            'theme_description' => ['type' => PARAM_RAW, 'hideif' => ['theme_type', 'eq', 'module']],
        ];
        $this->repeat_elements($repeat, max(1, $themecount), $options, 'theme_repeats', 'theme_add', 1,
            get_string('addtheme', 'local_syllabus'), true);

        // 11. Assessment.
        $mform->addElement('header', 'hdr_assessment', get_string('sec_assessment', 'local_syllabus'));
        $this->area('module1_evaluation', 5);
        $this->area('module2_evaluation', 5);
        $this->area('final_evaluation', 5);

        // 12. Literature.
        $mform->addElement('header', 'hdr_literature', get_string('sec_literature', 'local_syllabus'));
        $this->area('lit_main', 6);
        $this->area('lit_extra', 6);
        $this->area('lit_internet', 6);

        // Publishing.
        $mform->addElement('header', 'hdr_publish', get_string('sec_publish', 'local_syllabus'));
        $mform->addElement('advcheckbox', 'published', get_string('published', 'local_syllabus'));
        $mform->addHelpButton('published', 'published', 'local_syllabus');

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Add a text input for a syllabus field.
     *
     * @param string $name
     * @param bool $required
     * @param int $size
     */
    protected function text(string $name, bool $required = false, int $size = 70): void {
        $mform = $this->_form;
        $mform->addElement('text', $name, get_string('f_' . $name, 'local_syllabus'), ['size' => $size]);
        $mform->setType($name, PARAM_TEXT);
        if ($required) {
            $mform->addRule($name, null, 'required', null, 'client');
        }
    }

    /**
     * Add a textarea for a syllabus field.
     *
     * @param string $name
     * @param int $rows
     * @param bool $help Add help button (for list fields: one item per line).
     */
    protected function area(string $name, int $rows, bool $help = false): void {
        $mform = $this->_form;
        $mform->addElement('textarea', $name, get_string('f_' . $name, 'local_syllabus'), ['rows' => $rows, 'cols' => 80]);
        $mform->setType($name, PARAM_RAW);
        if ($help) {
            $mform->addHelpButton($name, 'f_' . $name, 'local_syllabus');
        }
    }

    /**
     * Convert syllabus data into form default values.
     *
     * @param array $data
     * @return array
     */
    public static function data_to_form(array $data): array {
        $values = [];
        foreach (syllabus::TEXT_FIELDS as $field) {
            if ($field !== 'signature') {
                $values[$field] = $data[$field];
            }
        }
        foreach (syllabus::STRING_LIST_FIELDS as $field) {
            $values[$field] = implode("\n", $data[$field]);
        }
        foreach (syllabus::CODED_LIST_FIELDS as $field) {
            $values[$field] = syllabus::coded_list_to_text($data[$field]);
        }
        foreach (['type', 'title', 'lectures', 'practicals', 'description'] as $key) {
            $values['theme_' . $key] = array_map(fn($item) => $item[$key] ?? '', array_values($data['themes']));
        }
        return $values;
    }

    /**
     * Convert submitted form data into syllabus data.
     *
     * @param \stdClass $formdata
     * @param array $existing Existing syllabus data (for signature).
     * @return array
     */
    public static function form_to_data(\stdClass $formdata, array $existing): array {
        $data = [];
        foreach (syllabus::TEXT_FIELDS as $field) {
            if (isset($formdata->$field)) {
                $data[$field] = $formdata->$field;
            }
        }
        foreach (syllabus::STRING_LIST_FIELDS as $field) {
            $data[$field] = syllabus::lines_to_list((string)($formdata->$field ?? ''));
        }
        foreach (syllabus::CODED_LIST_FIELDS as $field) {
            $data[$field] = array_map([syllabus::class, 'split_code'],
                syllabus::lines_to_list((string)($formdata->$field ?? '')));
            $data[$field] = array_map(fn($p) => ['code' => $p[0], 'text' => $p[1]], $data[$field]);
        }
        $data['themes'] = [];
        foreach ((array)($formdata->theme_title ?? []) as $i => $title) {
            $title = trim((string)$title);
            $description = trim((string)($formdata->theme_description[$i] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }
            $data['themes'][] = [
                'type' => $formdata->theme_type[$i] ?? 'theme',
                'title' => $title,
                'lectures' => $formdata->theme_lectures[$i] ?? '',
                'practicals' => $formdata->theme_practicals[$i] ?? '',
                'description' => $description,
            ];
        }
        $data['signature'] = empty($formdata->removesignature) ? $existing['signature'] : '';
        $data['isSignatureImage'] = $data['signature'] !== '' && !empty($existing['isSignatureImage']);
        return $data;
    }

    /**
     * Validation: hours in the scheme must match declared totals.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        foreach (['credits', 'total_hours', 'lecture_hours', 'practice_hours', 'self_hours'] as $field) {
            $value = str_replace(',', '.', trim((string)($data[$field] ?? '')));
            if ($value !== '' && !is_numeric($value)) {
                $errors[$field] = get_string('err_numeric', 'local_syllabus');
            }
        }
        return $errors;
    }
}
