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

namespace local_syllabus;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Builds syllabus data from the content of a Moodle course.
 *
 * Mapping:
 *  - course full name / summary / category / dates  -> general info, annotation, year
 *  - editing teachers                               -> instructor, contacts
 *  - course sections                                -> course scheme (themes), split into modules
 *  - activities inside sections                     -> theme descriptions, hours estimate
 *  - gradable activities (gradebook)                -> assessment per module, final control
 *  - assignment / quiz deadlines                    -> policy (deadlines)
 *  - URL / file / book / page resources             -> literature and internet resources
 *  - course competencies                            -> competencies and learning outcomes
 *  - course completion "other courses" criteria     -> prerequisites
 *  - used tools (BigBlueButton, Zoom, H5P, ...)     -> technical requirements
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {

    /** @var string[] Module types treated as "lecture" material. */
    const LECTURE_MODULES = ['page', 'book', 'resource', 'url', 'folder', 'lesson', 'label', 'imscp', 'scorm', 'h5pactivity',
        'bigbluebuttonbn', 'zoom'];

    /** @var string[] Module types treated as "practical" work. */
    const PRACTICE_MODULES = ['assign', 'quiz', 'workshop', 'forum', 'glossary', 'wiki', 'data', 'choice', 'feedback',
        'lti', 'hvp'];

    /** @var string[] Module types whose items are literature sources. */
    const LITERATURE_MODULES = ['resource', 'book', 'folder', 'imscp'];

    /** @var \stdClass */
    protected $course;

    /** @var \course_modinfo */
    protected $modinfo;

    /** @var \context_course */
    protected $context;

    /** @var array Generation options. */
    protected $options;

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param array $options Keys: modules (int, number of content modules, 0 = none), useai (bool).
     */
    public function __construct(\stdClass $course, array $options = []) {
        $this->course = $course;
        $this->modinfo = get_fast_modinfo($course);
        $this->context = \context_course::instance($course->id);
        $this->options = $options + ['modules' => 2, 'useai' => false];
    }

    /**
     * Generate syllabus data.
     *
     * @param array $base Existing data (used for credits, final control etc. when already known).
     * @return array Syllabus data.
     */
    public function generate(array $base = []): array {
        $base = syllabus::normalise($base);
        $data = syllabus::defaults();

        $this->fill_general($data);
        $this->fill_instructors($data);
        $this->fill_annotation($data);
        $this->fill_volume($data, $base);
        $sections = $this->collect_sections();
        $this->fill_scheme($data, $sections);
        $this->fill_assessment($data, $sections, $base);
        $this->fill_literature($data);
        $this->fill_competencies($data);
        $this->fill_prerequisites($data);
        $this->fill_tech($data);
        $this->fill_policy($data);

        if (!empty($this->options['useai']) && ai_helper::is_available($this->context)) {
            $data = ai_helper::enrich($data, $this->context);
        }

        return syllabus::normalise($data);
    }

    /**
     * General information from course and category.
     *
     * @param array $data
     */
    protected function fill_general(array &$data): void {
        $data['course_name'] = format_string($this->course->fullname, true, ['context' => $this->context]);
        $data['year'] = syllabus::academic_year((int)($this->course->startdate ?: time()));
        $data['site_link'] = (new \moodle_url('/course/view.php', ['id' => $this->course->id]))->out(false);
        $data['ksu_online_link'] = $data['site_link'];

        // Category path: top category ~ faculty, direct category ~ department.
        $category = \core_course_category::get($this->course->category, IGNORE_MISSING, true);
        if ($category) {
            $parents = $category->get_parents();
            $top = $parents ? \core_course_category::get(reset($parents), IGNORE_MISSING, true) : $category;
            if ($top) {
                $data['faculty'] = $top->get_formatted_name();
            }
            if ($top && $top->id != $category->id) {
                // The document prints "кафедри <name>", so drop a leading "Кафедра"/"Department of".
                $data['department'] = trim(preg_replace('/^(кафедра|department of)\s+/iu', '',
                    $category->get_formatted_name()));
                $data['instructor_department'] = $category->get_formatted_name();
            }
        }

        // Course custom fields with matching short names (e.g. "specialty", "credits").
        foreach ($this->get_custom_fields() as $shortname => $value) {
            if (in_array($shortname, syllabus::TEXT_FIELDS) && $shortname !== 'signature' && $value !== '') {
                $data[$shortname] = $value;
            }
        }
    }

    /**
     * Course custom field values keyed by short name.
     *
     * @return array
     */
    protected function get_custom_fields(): array {
        if (!class_exists('\core_course\customfield\course_handler')) {
            return [];
        }
        $result = [];
        $handler = \core_course\customfield\course_handler::create();
        foreach ($handler->get_instance_data($this->course->id, true) as $fielddata) {
            $value = $fielddata->export_value();
            $result[$fielddata->get_field()->get('shortname')] = is_scalar($value) ? trim(strip_tags((string)$value)) : '';
        }
        return $result;
    }

    /**
     * Instructor information from course teachers.
     *
     * @param array $data
     */
    protected function fill_instructors(array &$data): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        if (!$roleid) {
            return;
        }
        $fields = 'u.id, u.email, u.phone1, u.phone2, u.maildisplay, u.institution, u.department, ' .
            implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $teachers = get_role_users($roleid, $this->context, false, $fields, 'u.lastname, u.firstname');
        if (!$teachers) {
            return;
        }
        $names = array_map(fn($u) => fullname($u), $teachers);
        $data['instructor_name'] = implode(', ', $names);
        $first = reset($teachers);
        $data['instructor_email'] = $first->email;
        $data['instructor_phone'] = $first->phone1 ?: $first->phone2;
        if (!empty($first->department)) {
            $data['instructor_department'] = $first->department;
        }
    }

    /**
     * Annotation, goals and tasks.
     *
     * @param array $data
     */
    protected function fill_annotation(array &$data): void {
        $summary = $this->plain(format_text($this->course->summary, $this->course->summaryformat,
            ['context' => $this->context]));
        $data['annotation'] = $summary !== '' ? $summary :
            get_string('tpl_annotation', 'local_syllabus', $data['course_name']);
        $data['goals'] = get_string('tpl_goals', 'local_syllabus', $data['course_name']);
        $data['tasks'] = syllabus::lines_to_list(get_string('tpl_tasks', 'local_syllabus'));
    }

    /**
     * Course volume (credits and hours) – taken from existing data or settings.
     *
     * @param array $data
     * @param array $base
     */
    protected function fill_volume(array &$data, array $base): void {
        $credits = (float)str_replace(',', '.', $data['credits'] ?: $base['credits']);
        if ($credits <= 0) {
            $credits = (float)(get_config('local_syllabus', 'defaultcredits') ?: 3);
        }
        $hourspercredit = (int)(get_config('local_syllabus', 'hourspercredit') ?: 30);
        $data['credits'] = self::num($credits);
        $data['total_hours'] = self::num($credits * $hourspercredit);
    }

    /**
     * Collect visible course sections with their activities.
     *
     * @return array List of ['section' => section_info, 'name' => string, 'summary' => string, 'cms' => cm_info[]].
     */
    protected function collect_sections(): array {
        $result = [];
        foreach ($this->modinfo->get_section_info_all() as $section) {
            if ($section->section == 0 || !$section->uservisible) {
                continue;
            }
            // Sections that are delegated (e.g. subsections) are shown inside their parent.
            if (method_exists($section, 'is_delegated') && $section->is_delegated()) {
                continue;
            }
            $cms = $this->get_section_cms($section);
            $name = get_section_name($this->course, $section);
            $summary = $this->plain(format_text($section->summary, $section->summaryformat,
                ['context' => $this->context, 'noclean' => false]));
            if (!$cms && $summary === '' && $section->name === null) {
                continue; // Empty default section.
            }
            $result[] = ['section' => $section, 'name' => $name, 'summary' => $summary, 'cms' => $cms];
        }
        return $result;
    }

    /**
     * Visible activities of a section (including those in its subsections).
     *
     * @param \section_info $section
     * @return \cm_info[]
     */
    protected function get_section_cms(\section_info $section): array {
        $cms = [];
        foreach ($this->modinfo->sections[$section->section] ?? [] as $cmid) {
            $cm = $this->modinfo->get_cm($cmid);
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }
            if ($cm->modname === 'subsection') {
                $delegated = $cm->get_delegated_section_info();
                if ($delegated) {
                    $cms = array_merge($cms, $this->get_section_cms($delegated));
                }
                continue;
            }
            $cms[] = $cm;
        }
        return $cms;
    }

    /**
     * Course scheme: modules and themes with hours.
     *
     * @param array $data
     * @param array $sections
     */
    protected function fill_scheme(array &$data, array $sections): void {
        if (!$sections) {
            return;
        }
        $count = count($sections);
        $modulecount = (int)$this->options['modules'];
        $modulecount = max(0, min($modulecount, $count));

        // Contact hours: ~1/3 of the total, split equally between lectures and practice, even numbers per theme.
        $total = (float)$data['total_hours'];
        $contact = $total > 0 ? $total / 3 : $count * 4;
        $lectureperthemes = self::distribute((int)round($contact / 2), $count, $sections, 'lecture');
        $practiceperthemes = self::distribute((int)round($contact / 2), $count, $sections, 'practice');

        $themes = [];
        $perModule = $modulecount ? (int)ceil($count / $modulecount) : 0;
        $modulenum = 0;
        foreach (array_values($sections) as $i => $info) {
            if ($perModule && $i % $perModule === 0) {
                $modulenum++;
                $themes[] = [
                    'type' => 'module',
                    'title' => get_string('modulename', 'local_syllabus', $modulenum),
                ];
            }
            $themes[] = [
                'type' => 'theme',
                'title' => $this->clean_theme_title($info['name']),
                'lectures' => (string)$lectureperthemes[$i],
                'practicals' => (string)$practiceperthemes[$i],
                'description' => $this->describe_section($info),
                'module' => $modulenum,
            ];
        }
        $data['themes'] = $themes;
        $data['lecture_hours'] = (string)array_sum($lectureperthemes);
        $data['practice_hours'] = (string)array_sum($practiceperthemes);
        if ($total > 0) {
            $data['self_hours'] = self::num(max(0, $total - array_sum($lectureperthemes) - array_sum($practiceperthemes)));
        }
    }

    /**
     * Distribute hours across themes in steps of 2, weighted by activity type counts.
     *
     * @param int $hours
     * @param int $count
     * @param array $sections
     * @param string $kind 'lecture' or 'practice'
     * @return int[]
     */
    protected static function distribute(int $hours, int $count, array $sections, string $kind): array {
        $types = $kind === 'lecture' ? self::LECTURE_MODULES : self::PRACTICE_MODULES;
        $weights = [];
        foreach (array_values($sections) as $info) {
            $n = 0;
            foreach ($info['cms'] as $cm) {
                if (in_array($cm->modname, $types)) {
                    $n++;
                }
            }
            $weights[] = $n > 0 ? 1 + min($n, 4) / 4 : 1;
        }
        $pairs = max($count, (int)round($hours / 2));
        $sum = array_sum($weights);
        $result = [];
        foreach ($weights as $w) {
            $result[] = max(1, (int)floor($pairs * $w / $sum));
        }
        // Hand out remaining pairs to the heaviest themes.
        $rest = $pairs - array_sum($result);
        arsort($weights);
        foreach (array_keys($weights) as $idx) {
            if ($rest <= 0) {
                break;
            }
            $result[$idx]++;
            $rest--;
        }
        return array_map(fn($p) => $p * 2, $result);
    }

    /**
     * Remove leading "Тема 1." / "Topic 1:" from a section name (the document adds numbering).
     *
     * @param string $name
     * @return string
     */
    protected function clean_theme_title(string $name): string {
        $clean = preg_replace('/^\s*(тема|topic|розділ|section|week|тиждень|лекція|lecture)\s*\d+\s*[.:)\-–—]?\s*/iu', '',
            $name);
        return trim($clean) !== '' ? trim($clean) : trim($name);
    }

    /**
     * Theme description from section summary and activity list.
     *
     * @param array $info
     * @return string
     */
    protected function describe_section(array $info): string {
        $lines = [];
        if ($info['summary'] !== '') {
            $lines[] = \core_text::substr($info['summary'], 0, 1000);
        }
        $materials = [];
        $tasks = [];
        foreach ($info['cms'] as $cm) {
            if ($cm->modname === 'label') {
                continue;
            }
            $name = format_string($cm->name, true, ['context' => $cm->context]);
            if (in_array($cm->modname, self::PRACTICE_MODULES)) {
                $tasks[] = $name . ' (' . $this->modname($cm->modname) . ')';
            } else {
                $materials[] = $name;
            }
        }
        if ($materials) {
            $lines[] = get_string('gen_materials', 'local_syllabus') . ' ' . implode('; ', $materials) . '.';
        }
        if ($tasks) {
            $lines[] = get_string('gen_tasks', 'local_syllabus') . ' ' . implode('; ', $tasks) . '.';
        }
        return implode("\n", $lines);
    }

    /**
     * Assessment: gradable activities per module, final control form.
     *
     * @param array $data
     * @param array $sections
     * @param array $base
     */
    protected function fill_assessment(array &$data, array $sections, array $base): void {
        // Map section number -> content module number (as assigned in fill_scheme()).
        $sectionmodule = [];
        $themeindex = 0;
        foreach ($data['themes'] as $item) {
            if ($item['type'] === 'theme') {
                $sectionmodule[$sections[$themeindex]['section']->section] = $item['module'] ?? 0;
                $themeindex++;
            }
        }

        $items = \grade_item::fetch_all(['courseid' => $this->course->id, 'itemtype' => 'mod']) ?: [];
        $bymodule = [1 => [], 2 => []];
        $final = [];
        $finalregex = '/екзамен|іспит|залік|exam|final|підсумков/iu';
        foreach ($items as $gi) {
            if ($gi->hidden || empty($this->modinfo->instances[$gi->itemmodule][$gi->iteminstance])) {
                continue;
            }
            $cm = $this->modinfo->instances[$gi->itemmodule][$gi->iteminstance];
            if (!$cm->uservisible) {
                continue;
            }
            $name = format_string($cm->name, true, ['context' => $cm->context]);
            $line = '– ' . $name . ' (' . $this->modname($cm->modname) . ') — ' .
                get_string('gen_maxpoints', 'local_syllabus', self::num((float)$gi->grademax));
            if (preg_match($finalregex, $name)) {
                $final[] = ['line' => $line, 'name' => $name];
                continue;
            }
            $module = $sectionmodule[$cm->sectionnum] ?? 0;
            // Without module split everything goes to module 1; modules beyond 2 go to module 2.
            $module = max(1, min(2, $module ?: 1));
            $bymodule[$module][] = ['line' => $line, 'max' => (float)$gi->grademax];
        }

        foreach ([1, 2] as $num) {
            if ($bymodule[$num]) {
                $total = array_sum(array_column($bymodule[$num], 'max'));
                $data['module' . $num . '_evaluation'] = implode("\n", array_column($bymodule[$num], 'line')) . "\n" .
                    get_string('gen_totalpoints', 'local_syllabus', self::num($total));
            }
        }

        $finalcontrol = $base['final_control'];
        if ($finalcontrol === '') {
            $finalcontrol = get_string('finalcontrol_credit', 'local_syllabus');
            foreach ($final as $f) {
                if (preg_match('/екзамен|іспит|exam/iu', $f['name'])) {
                    $finalcontrol = get_string('finalcontrol_exam', 'local_syllabus');
                }
            }
        }
        $data['final_control'] = $finalcontrol;

        $finaltext = [];
        if ($final) {
            $finaltext[] = get_string('gen_finalform', 'local_syllabus', $finalcontrol);
            $finaltext = array_merge($finaltext, array_column($final, 'line'));
        }
        $default = trim((string)get_config('local_syllabus', 'defaultfinalevaluation'));
        if ($default !== '') {
            $finaltext[] = $default;
        }
        $data['final_evaluation'] = implode("\n", $finaltext);
    }

    /**
     * Literature from files/books/pages and internet resources from URL activities.
     *
     * @param array $data
     */
    protected function fill_literature(array &$data): void {
        $main = [];
        $internet = [];
        $n = 0;
        foreach ($this->modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || $cm->deletioninprogress) {
                continue;
            }
            $name = format_string($cm->name, true, ['context' => $cm->context]);
            if ($cm->modname === 'url') {
                $url = $this->get_url_address((int)$cm->instance);
                $internet[] = $name . ($url ? ' – ' . $url : '');
            } else if (in_array($cm->modname, self::LITERATURE_MODULES)) {
                $main[] = $name;
            }
        }
        // Continuous numbering across the lists (as required by the template).
        $data['lit_main'] = implode("\n", array_map(function($l) use (&$n) {
            return (++$n) . '. ' . $l;
        }, array_unique($main)));
        $data['lit_internet'] = implode("\n", array_map(function($l) use (&$n) {
            return (++$n) . '. ' . $l;
        }, array_unique($internet)));
    }

    /**
     * External URL of a URL activity.
     *
     * @param int $instance
     * @return string
     */
    protected function get_url_address(int $instance): string {
        global $DB;
        return (string)$DB->get_field('url', 'externalurl', ['id' => $instance]);
    }

    /**
     * Competencies and learning outcomes from linked course competencies.
     *
     * @param array $data
     */
    protected function fill_competencies(array &$data): void {
        if (!class_exists('\core_competency\api') || !\core_competency\api::is_enabled()) {
            return;
        }
        try {
            $list = \core_competency\api::list_course_competencies($this->course->id);
        } catch (\Throwable $e) {
            return;
        }
        foreach ($list as $item) {
            $competency = $item['competency'];
            $code = trim((string)$competency->get('idnumber'));
            $text = format_string($competency->get('shortname'), true, ['context' => $this->context]);
            $entry = ['code' => $code, 'text' => $text];
            if (preg_match('/^(ПРН|PRN|PLO|LO|РН)/iu', $code)) {
                $data['outcomes'][] = $entry;
            } else {
                $data['competencies'][] = $entry;
            }
        }
    }

    /**
     * Prerequisites from course completion criteria ("completion of other courses").
     *
     * @param array $data
     */
    protected function fill_prerequisites(array &$data): void {
        global $DB;
        $sql = "SELECT DISTINCT c.id, c.fullname
                  FROM {course_completion_criteria} cc
                  JOIN {course} c ON c.id = cc.courseinstance
                 WHERE cc.course = :courseid AND cc.criteriatype = :type";
        $courses = $DB->get_records_sql($sql, ['courseid' => $this->course->id, 'type' => COMPLETION_CRITERIA_TYPE_COURSE]);
        foreach ($courses as $c) {
            $data['prerequisites'][] = format_string($c->fullname);
        }
    }

    /**
     * Technical requirements and conference service from used tools.
     *
     * @param array $data
     */
    protected function fill_tech(array &$data): void {
        global $SITE;
        $used = [];
        foreach ($this->modinfo->get_cms() as $cm) {
            if ($cm->uservisible) {
                $used[$cm->modname] = $this->modname($cm->modname);
            }
        }
        $lines = [get_string('gen_tech_lms', 'local_syllabus', format_string($SITE->fullname))];
        $conference = [];
        foreach (['bigbluebuttonbn', 'zoom', 'jitsi', 'googlemeet', 'msteams'] as $mod) {
            if (isset($used[$mod])) {
                $conference[] = $used[$mod];
            }
        }
        if ($conference) {
            $data['conference_service'] = implode(', ', $conference);
            $lines[] = get_string('gen_tech_conference', 'local_syllabus', implode(', ', $conference));
        }
        $default = trim((string)get_config('local_syllabus', 'defaulttech'));
        if ($default !== '') {
            $lines[] = $default;
        }
        $data['tech_requirements'] = implode("\n", $lines);
    }

    /**
     * Course policy: default template plus activity deadlines.
     *
     * @param array $data
     */
    protected function fill_policy(array &$data): void {
        $lines = [];
        $default = trim((string)get_config('local_syllabus', 'defaultpolicy'));
        if ($default !== '') {
            $lines[] = $default;
        }
        $deadlines = [];
        foreach ($this->modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $custom = is_array($cm->customdata) ? $cm->customdata : [];
            $due = $custom['duedate'] ?? $custom['timeclose'] ?? 0;
            if ($due) {
                $deadlines[(int)$due . '-' . $cm->id] = '– ' . format_string($cm->name, true, ['context' => $cm->context]) .
                    ': ' . userdate((int)$due, get_string('strftimedatefullshort', 'langconfig'));
            }
        }
        if ($deadlines) {
            ksort($deadlines, SORT_NATURAL);
            $lines[] = get_string('gen_deadlines', 'local_syllabus');
            $lines = array_merge($lines, array_values($deadlines));
        }
        $data['policy'] = implode("\n", $lines);
    }

    /**
     * Localised module name.
     *
     * @param string $modname
     * @return string
     */
    protected function modname(string $modname): string {
        return get_string_manager()->string_exists('modulename', 'mod_' . $modname)
            ? get_string('modulename', 'mod_' . $modname) : $modname;
    }

    /**
     * HTML to trimmed plain text.
     *
     * @param string $html
     * @return string
     */
    protected function plain(string $html): string {
        $text = html_to_text($html, 0, false);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    /**
     * Number to string without trailing zeros.
     *
     * @param float $n
     * @return string
     */
    public static function num(float $n): string {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }
}
