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

/**
 * Syllabus persistence and data helpers.
 *
 * The data structure mirrors the one used by the standalone
 * "Syllabus constructor" web tool, so JSON files can be exchanged
 * between the tool and Moodle.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus {

    /** @var string Database table. */
    const TABLE = 'local_syllabus';

    /** @var string[] Plain text (single or multi-line) fields. */
    const TEXT_FIELDS = [
        'course_name', 'faculty', 'educational_program', 'specialty', 'education_level', 'field_of_study',
        'instructor_name', 'instructor_position', 'instructor_department',
        'department', 'dept_head', 'protocol_num', 'protocol_date', 'year', 'city',
        'instructor_email', 'instructor_phone', 'site_link', 'ksu_online_link', 'conference_service',
        'consultation_schedule', 'final_control',
        'annotation', 'goals',
        'credits', 'total_hours', 'lecture_hours', 'practice_hours', 'self_hours',
        'teach_year', 'semester', 'course_type', 'tech_requirements',
        'module1_evaluation', 'module2_evaluation', 'final_evaluation',
        'policy', 'lit_main', 'lit_extra', 'lit_internet',
        'signature',
    ];

    /** @var string[] Lists of plain strings. */
    const STRING_LIST_FIELDS = ['tasks', 'prerequisites'];

    /** @var string[] Lists of {code, text} pairs. */
    const CODED_LIST_FIELDS = ['competencies', 'outcomes'];

    /**
     * Default (empty) syllabus data.
     *
     * @return array
     */
    public static function defaults(): array {
        $data = array_fill_keys(self::TEXT_FIELDS, '');
        foreach (array_merge(self::STRING_LIST_FIELDS, self::CODED_LIST_FIELDS) as $field) {
            $data[$field] = [];
        }
        $data['themes'] = [];
        $data['isSignatureImage'] = false;
        $data['year'] = self::academic_year(time());
        $data['city'] = (string)get_config('local_syllabus', 'city');
        $data['teach_year'] = '1';
        $data['semester'] = '1';
        $data['course_type'] = 'mandatory';
        return $data;
    }

    /**
     * Academic year string ("2026-2027") for a timestamp.
     *
     * @param int $time
     * @return string
     */
    public static function academic_year(int $time): string {
        $year = (int)userdate($time, '%Y', 99, false, false);
        $month = (int)userdate($time, '%m', 99, false, false);
        if ($month < 8) {
            $year--;
        }
        return $year . '-' . ($year + 1);
    }

    /**
     * Load the syllabus record of a course.
     *
     * @param int $courseid
     * @return \stdClass|null Record with decoded ->syllabus array, or null.
     */
    public static function get_record(int $courseid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        if (!$record) {
            return null;
        }
        $record->syllabus = self::normalise(json_decode((string)$record->data, true) ?: []);
        return $record;
    }

    /**
     * Load syllabus data of a course (defaults when none saved).
     *
     * @param int $courseid
     * @return array
     */
    public static function get_data(int $courseid): array {
        $record = self::get_record($courseid);
        return $record ? $record->syllabus : self::defaults();
    }

    /**
     * Save syllabus data for a course.
     *
     * @param int $courseid
     * @param array $data
     * @param bool|null $published Null keeps the current value.
     * @return \stdClass Saved record.
     */
    public static function save(int $courseid, array $data, ?bool $published = null): \stdClass {
        global $DB, $USER;
        $now = time();
        $json = json_encode(self::normalise($data), JSON_UNESCAPED_UNICODE);
        $record = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        if ($record) {
            $record->data = $json;
            if ($published !== null) {
                $record->published = (int)$published;
            }
            $record->timemodified = $now;
            $record->usermodified = $USER->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record = (object)[
                'courseid' => $courseid,
                'data' => $json,
                'published' => (int)$published,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $USER->id,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
        }

        $event = event\syllabus_updated::create([
            'objectid' => $record->id,
            'context' => \context_course::instance($courseid),
        ]);
        $event->trigger();

        return $record;
    }

    /**
     * Delete the syllabus of a course.
     *
     * @param int $courseid
     */
    public static function delete(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }

    /**
     * Normalise arbitrary (e.g. imported) data into the known structure.
     *
     * Unknown keys are dropped, scalars are cast to strings, lists are cleaned.
     *
     * @param array $input
     * @return array
     */
    public static function normalise(array $input): array {
        $data = self::defaults();

        // Legacy keys used by older versions of the constructor.
        if (!empty($input['instructor']) && empty($input['instructor_name'])) {
            $input['instructor_name'] = $input['instructor'];
        }
        if (isset($input['tasks']) && is_string($input['tasks'])) {
            $input['tasks'] = self::lines_to_list($input['tasks']);
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (isset($input[$field]) && is_scalar($input[$field])) {
                $data[$field] = trim((string)$input[$field]);
            }
        }
        foreach (self::STRING_LIST_FIELDS as $field) {
            if (!empty($input[$field]) && is_array($input[$field])) {
                $data[$field] = array_values(array_filter(array_map(
                    fn($v) => is_scalar($v) ? trim((string)$v) : '', $input[$field]), 'strlen'));
            }
        }
        foreach (self::CODED_LIST_FIELDS as $field) {
            if (!empty($input[$field]) && is_array($input[$field])) {
                $items = [];
                foreach ($input[$field] as $item) {
                    if (is_array($item)) {
                        $code = trim((string)($item['code'] ?? ''));
                        $text = trim((string)($item['text'] ?? ''));
                    } else if (is_scalar($item)) {
                        [$code, $text] = self::split_code((string)$item);
                    } else {
                        continue;
                    }
                    if ($code !== '' || $text !== '') {
                        $items[] = ['code' => $code, 'text' => $text];
                    }
                }
                $data[$field] = $items;
            }
        }
        if (!empty($input['themes']) && is_array($input['themes'])) {
            $themes = [];
            foreach ($input['themes'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = trim((string)($item['title'] ?? ''));
                if (($item['type'] ?? 'theme') === 'module') {
                    $themes[] = ['type' => 'module', 'title' => $title];
                } else {
                    $themes[] = [
                        'type' => 'theme',
                        'title' => $title,
                        'lectures' => trim((string)($item['lectures'] ?? '')),
                        'practicals' => trim((string)($item['practicals'] ?? '')),
                        'description' => trim((string)($item['description'] ?? '')),
                    ];
                }
            }
            $data['themes'] = $themes;
        }

        // Only accept embedded raster images as signature.
        if (!preg_match('~^data:image/(png|jpe?g);base64,[A-Za-z0-9+/=]+$~', $data['signature'])) {
            $data['signature'] = '';
        }
        $data['isSignatureImage'] = !empty($input['isSignatureImage']);
        if (!in_array($data['course_type'], ['mandatory', 'elective'])) {
            $data['course_type'] = 'mandatory';
        }

        return $data;
    }

    /**
     * Merge generated data into existing data.
     *
     * @param array $existing
     * @param array $generated
     * @param bool $onlyempty When true, only empty fields of $existing are filled.
     * @return array
     */
    public static function merge(array $existing, array $generated, bool $onlyempty): array {
        $existing = self::normalise($existing);
        $generated = self::normalise($generated);
        $defaults = self::defaults();
        foreach ($generated as $key => $value) {
            if ($key === 'signature' || $key === 'isSignatureImage') {
                continue;
            }
            if ($value === '' || $value === []) {
                continue;
            }
            $current = $existing[$key] ?? '';
            $isempty = $current === '' || $current === [] || $current === $defaults[$key];
            if (!$onlyempty || $isempty) {
                $existing[$key] = $value;
            }
        }
        return $existing;
    }

    /**
     * Build the JSON export in the constructor file format.
     *
     * @param array $data
     * @return string
     */
    public static function export_json(array $data): string {
        $export = [
            'metadata' => [
                'university' => (string)get_config('local_syllabus', 'university'),
                'export_date' => date('c'),
                'format_version' => '1.0',
                'type' => 'syllabus_data',
                'source' => 'moodle/local_syllabus',
            ],
            'syllabus' => self::normalise($data),
        ];
        return json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Parse a JSON file produced by the constructor (or by export_json()).
     *
     * @param string $json
     * @return array
     * @throws \moodle_exception
     */
    public static function import_json(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('importinvalid', 'local_syllabus');
        }
        $payload = isset($decoded['syllabus']) && is_array($decoded['syllabus']) ? $decoded['syllabus'] : $decoded;
        return self::normalise($payload);
    }

    /**
     * Split a multi-line string into a list of non-empty trimmed lines.
     *
     * @param string $text
     * @return string[]
     */
    public static function lines_to_list(string $text): array {
        $lines = preg_split('/\R/u', $text);
        $lines = array_map(fn($l) => trim(preg_replace('/^\s*[-–—•*]\s*/u', '', $l)), $lines);
        return array_values(array_filter($lines, 'strlen'));
    }

    /**
     * Split "ЗК1. Text" / "ФК 2 - text" / "PLO3: text" into code and text.
     *
     * @param string $line
     * @return string[] [code, text]
     */
    public static function split_code(string $line): array {
        $line = trim($line);
        if (preg_match('/^([\p{L}]{1,6}\s?\d+(?:\.\d+)*)\s*[.:)\-–—]?\s+(.+)$/u', $line, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return ['', $line];
    }

    /**
     * Convert a coded list to text, one "CODE. text" item per line.
     *
     * @param array $items
     * @return string
     */
    public static function coded_list_to_text(array $items): string {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = $item['code'] !== '' ? $item['code'] . '. ' . $item['text'] : $item['text'];
        }
        return implode("\n", $lines);
    }
}
