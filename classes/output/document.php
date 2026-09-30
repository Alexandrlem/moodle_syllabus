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

namespace local_syllabus\output;

use local_syllabus\syllabus;

/**
 * Renderable syllabus document (used for the web view and for PDF export).
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document implements \renderable, \templatable {

    /** @var array Syllabus data. */
    protected $data;

    /** @var bool Rendering for PDF (TCPDF) instead of the browser. */
    protected $forpdf;

    /**
     * Constructor.
     *
     * @param array $data
     * @param bool $forpdf
     */
    public function __construct(array $data, bool $forpdf = false) {
        $this->data = syllabus::normalise($data);
        $this->forpdf = $forpdf;
    }

    /**
     * Export for template.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $d = $this->data;
        $blank = '________________';
        $e = fn($v, $default = '') => s($v !== '' ? $v : $default);

        // Scheme: modules and numbered themes.
        $scheme = [];
        $num = 0;
        $sumlectures = 0;
        $sumpractice = 0;
        foreach ($d['themes'] as $item) {
            if ($item['type'] === 'module') {
                $scheme[] = ['ismodule' => true, 'title' => s($item['title'])];
                continue;
            }
            $num++;
            $sumlectures += (float)$item['lectures'];
            $sumpractice += (float)$item['practicals'];
            $hours = '';
            if ($item['lectures'] !== '' || $item['practicals'] !== '') {
                $hours = get_string('doc_themehours', 'local_syllabus', [
                    'lectures' => s($item['lectures'] ?: 0),
                    'practicals' => s($item['practicals'] ?: 0),
                ]);
            }
            $scheme[] = [
                'ismodule' => false,
                'number' => $num,
                'title' => s($item['title']),
                'hours' => $hours,
                'description' => self::text($item['description'], ''),
            ];
        }

        $warnings = [];
        if (!$this->forpdf && $num > 0) {
            if ($d['lecture_hours'] !== '' && (float)$d['lecture_hours'] != $sumlectures) {
                $warnings[] = get_string('warn_lecturehours', 'local_syllabus',
                    ['scheme' => $sumlectures, 'declared' => s($d['lecture_hours'])]);
            }
            if ($d['practice_hours'] !== '' && (float)$d['practice_hours'] != $sumpractice) {
                $warnings[] = get_string('warn_practicehours', 'local_syllabus',
                    ['scheme' => $sumpractice, 'declared' => s($d['practice_hours'])]);
            }
        }

        $instructor = implode(', ', array_filter([$d['instructor_name'], $d['instructor_position'],
            $d['instructor_department']], 'strlen'));

        $signature = '';
        if ($d['signature'] !== '') {
            // TCPDF understands "@<base64>" as inline image data.
            $signature = $this->forpdf ? '@' . substr($d['signature'], strpos($d['signature'], ',') + 1) : $d['signature'];
        }

        return [
            'forpdf' => $this->forpdf,
            'warnings' => $warnings,
            'ministry' => s(\core_text::strtoupper((string)get_config('local_syllabus', 'ministry'))),
            'university' => s(\core_text::strtoupper((string)get_config('local_syllabus', 'university'))),
            'faculty' => s(\core_text::strtoupper($d['faculty'] !== '' ? $d['faculty'] : $blank)),
            'approvedmeeting' => get_string('doc_approved_meeting', 'local_syllabus', $e($d['department'], '________')),
            'protocol' => get_string('doc_protocol', 'local_syllabus', [
                'num' => $e($d['protocol_num'], '___'),
                'date' => $e($d['protocol_date'], '_________'),
            ]),
            'deptheadline' => get_string('doc_depthead', 'local_syllabus', $e($d['department'], '________')),
            'dept_head' => $e($d['dept_head'], $blank),
            'signature' => $signature,
            'course_name' => $e($d['course_name'], get_string('doc_coursename_placeholder', 'local_syllabus')),
            'educational_program' => $e($d['educational_program'], $blank),
            'specialty' => $e($d['specialty'], $blank),
            'field_of_study' => $e($d['field_of_study'], $blank),
            'education_level' => $e($d['education_level']),
            'cityyear' => s(trim($d['city'] . ' ' . $d['year'])),

            'instructor' => s($instructor),
            'site_link' => self::links(s($d['site_link'])),
            'ksu_online_link' => self::links(s($d['ksu_online_link'])),
            'instructor_phone' => $e($d['instructor_phone']),
            'instructor_email' => self::links(s($d['instructor_email']), true),
            'consultation_schedule' => self::text($d['consultation_schedule'], ''),
            'conference_service' => $e($d['conference_service']),
            'final_control' => $e($d['final_control']),

            'annotation' => self::text($d['annotation'], ''),
            'hasannotation' => $d['annotation'] !== '',
            'goals' => self::text($d['goals'], ''),
            'tasks' => array_map('s', $d['tasks']),
            'hasgoals' => $d['goals'] !== '' || $d['tasks'],
            'competencies' => self::coded($d['competencies']),
            'outcomes' => self::coded($d['outcomes']),
            'hasoutcomes' => $d['competencies'] || $d['outcomes'],

            'credits' => $e($d['credits'], '0'),
            'total_hours' => $e($d['total_hours'], '0'),
            'lecture_hours' => $e($d['lecture_hours'], '0'),
            'practice_hours' => $e($d['practice_hours'], '0'),
            'self_hours' => $e($d['self_hours'], '0'),
            'startyear' => s(explode('-', $d['year'])[0]),
            'semester' => $e($d['semester']),
            'teach_year' => $e($d['teach_year']),
            'course_type' => get_string('coursetype_' . $d['course_type'], 'local_syllabus'),

            'tech_requirements' => self::text($d['tech_requirements']),
            'policy' => self::text($d['policy'], ''),
            'haspolicy' => $d['policy'] !== '',
            'prerequisites' => array_map('s', $d['prerequisites']),
            'hasprerequisites' => (bool)$d['prerequisites'],
            'scheme' => $scheme,
            'hasscheme' => (bool)$scheme,

            'module1_evaluation' => self::text($d['module1_evaluation'], ''),
            'module2_evaluation' => self::text($d['module2_evaluation'], ''),
            'final_evaluation' => self::text($d['final_evaluation'], ''),
            'hasmodule1' => $d['module1_evaluation'] !== '',
            'hasmodule2' => $d['module2_evaluation'] !== '',
            'hasfinal' => $d['final_evaluation'] !== '',
            'gradescale' => self::grade_scale($d['final_control']),

            'lit_main' => self::text($d['lit_main'], ''),
            'lit_extra' => self::text($d['lit_extra'], ''),
            'lit_internet' => self::text($d['lit_internet'], ''),
            'hasliterature' => $d['lit_main'] !== '' || $d['lit_extra'] !== '' || $d['lit_internet'] !== '',
        ];
    }

    /**
     * ECTS / national grading scale table rows.
     *
     * @param string $finalcontrol
     * @return array
     */
    protected static function grade_scale(string $finalcontrol): array {
        $rows = [
            ['points' => '90-100', 'ects' => 'A', 'mark' => get_string('scale_excellent', 'local_syllabus'), 'rowspan' => 1],
            ['points' => '82-89', 'ects' => 'B', 'mark' => get_string('scale_good', 'local_syllabus'), 'rowspan' => 2],
            ['points' => '74-81', 'ects' => 'C', 'mark' => null],
            ['points' => '64-73', 'ects' => 'D', 'mark' => get_string('scale_satisfactory', 'local_syllabus'),
                'rowspan' => 2],
            ['points' => '60-63', 'ects' => 'E', 'mark' => null],
            ['points' => '35-59', 'ects' => 'FX', 'mark' => get_string('scale_fx', 'local_syllabus'), 'rowspan' => 1],
            ['points' => '0-34', 'ects' => 'F', 'mark' => get_string('scale_f', 'local_syllabus'), 'rowspan' => 1],
        ];
        foreach ($rows as &$row) {
            $row['hasmark'] = $row['mark'] !== null;
        }
        return [
            'header' => $finalcontrol !== '' ? s($finalcontrol) : get_string('finalcontrol_credit', 'local_syllabus'),
            'rows' => $rows,
        ];
    }

    /**
     * Coded list for template.
     *
     * @param array $items
     * @return array
     */
    protected static function coded(array $items): array {
        return array_map(fn($i) => ['code' => s($i['code']), 'text' => s($i['text'])], $items);
    }

    /**
     * Escaped multi-line text with clickable links.
     *
     * @param string $text
     * @param string $default
     * @return string HTML
     */
    public static function text(string $text, string $default = '________________'): string {
        if (trim($text) === '') {
            return s($default);
        }
        $lines = preg_split('/\R/u', trim($text));
        $html = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $html[] = $line === '' ? '<br>' : '<div>' . self::links(s($line)) . '</div>';
        }
        return implode('', $html);
    }

    /**
     * Turn URLs (and optionally e-mails) in already escaped text into links.
     *
     * @param string $escaped
     * @param bool $emails
     * @return string
     */
    public static function links(string $escaped, bool $emails = false): string {
        $escaped = preg_replace_callback('~(https?://[^\s<]+|www\.[^\s<]+)~u', function($m) {
            $url = $m[1];
            $suffix = '';
            if (preg_match('/[.,!?;:)]+$/', $url, $p)) {
                $suffix = $p[0];
                $url = substr($url, 0, -strlen($suffix));
            }
            $href = strpos($url, 'www.') === 0 ? 'http://' . $url : $url;
            return '<a href="' . $href . '" target="_blank" rel="noopener">' . $url . '</a>' . $suffix;
        }, $escaped);
        if ($emails) {
            $escaped = preg_replace('/([\w.+-]+@[\w-]+\.[\w.-]+)/u', '<a href="mailto:$1">$1</a>', $escaped);
        }
        return $escaped;
    }
}
