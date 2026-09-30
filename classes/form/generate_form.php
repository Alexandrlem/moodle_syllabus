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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Options for generating a syllabus from course content, and JSON import.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $aiavailable = !empty($this->_customdata['aiavailable']);
        $needpolicy = !empty($this->_customdata['needpolicy']);
        $hasexisting = !empty($this->_customdata['hasexisting']);

        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement('header', 'hdr_generate', get_string('generatefromcourse', 'local_syllabus'));
        $mform->addElement('static', 'generatehelp', '', get_string('generatehelp', 'local_syllabus'));

        $mform->addElement('select', 'source', get_string('source', 'local_syllabus'), [
            'course' => get_string('source_course', 'local_syllabus'),
            'json' => get_string('source_json', 'local_syllabus'),
        ]);

        $mform->addElement('filepicker', 'jsonfile', get_string('jsonfile', 'local_syllabus'), null,
            ['accepted_types' => ['.json'], 'maxbytes' => 5 * 1024 * 1024]);
        $mform->addHelpButton('jsonfile', 'jsonfile', 'local_syllabus');
        $mform->hideIf('jsonfile', 'source', 'neq', 'json');

        $mform->addElement('select', 'modules', get_string('modulecount', 'local_syllabus'), [
            0 => get_string('modulecount_none', 'local_syllabus'), 1 => 1, 2 => 2, 3 => 3, 4 => 4,
        ]);
        $mform->setDefault('modules', 2);
        $mform->addHelpButton('modules', 'modulecount', 'local_syllabus');
        $mform->hideIf('modules', 'source', 'neq', 'course');

        if ($aiavailable) {
            $mform->addElement('advcheckbox', 'useai', get_string('useai', 'local_syllabus'));
            $mform->addHelpButton('useai', 'useai', 'local_syllabus');
            $mform->hideIf('useai', 'source', 'neq', 'course');
            if ($needpolicy) {
                $policy = get_string_manager()->string_exists('userpolicy', 'core_ai')
                    ? get_string('userpolicy', 'core_ai') : '';
                $mform->addElement('static', 'aipolicy', get_string('aipolicy', 'local_syllabus'), $policy);
                $mform->addElement('advcheckbox', 'acceptpolicy', '', get_string('acceptaipolicy', 'local_syllabus'));
                $mform->hideIf('aipolicy', 'useai', 'notchecked');
                $mform->hideIf('acceptpolicy', 'useai', 'notchecked');
            }
        } else {
            $mform->addElement('hidden', 'useai', 0);
            $mform->setType('useai', PARAM_BOOL);
        }

        if ($hasexisting) {
            $mform->addElement('select', 'mode', get_string('mergemode', 'local_syllabus'), [
                'empty' => get_string('mergemode_empty', 'local_syllabus'),
                'overwrite' => get_string('mergemode_overwrite', 'local_syllabus'),
            ]);
            $mform->addHelpButton('mode', 'mergemode', 'local_syllabus');
        } else {
            $mform->addElement('hidden', 'mode', 'overwrite');
            $mform->setType('mode', PARAM_ALPHA);
        }

        $this->add_action_buttons(true, get_string('generate', 'local_syllabus'));
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['source'] ?? '') === 'json') {
            $draft = file_get_drafarea_files($data['jsonfile'] ?? 0);
            if (empty($draft->list)) {
                $errors['jsonfile'] = get_string('required');
            }
        }
        if (!empty($data['useai']) && !empty($this->_customdata['needpolicy']) && empty($data['acceptpolicy'])) {
            $errors['acceptpolicy'] = get_string('acceptaipolicy_required', 'local_syllabus');
        }
        return $errors;
    }
}
