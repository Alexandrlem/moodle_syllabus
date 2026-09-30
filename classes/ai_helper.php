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
 * Optional text enrichment through the Moodle AI subsystem (Moodle 4.5+).
 *
 * The AI is asked to write the annotation, goals, tasks and theme descriptions
 * based on the structure extracted from the course. Everything else stays factual.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_helper {

    /**
     * Whether AI generation can be used in this context.
     *
     * @param \context $context
     * @return bool
     */
    public static function is_available(\context $context): bool {
        if (!get_config('local_syllabus', 'enableai')) {
            return false;
        }
        if (!class_exists('\core_ai\manager') || !class_exists('\core_ai\aiactions\generate_text')
                || !class_exists('\core\di')) {
            return false;
        }
        try {
            $manager = \core\di::get(\core_ai\manager::class);
            if (method_exists($manager, 'is_action_available')) {
                return (bool)$manager->is_action_available(\core_ai\aiactions\generate_text::class);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the user has accepted the AI usage policy.
     *
     * @param int $userid
     * @return bool
     */
    public static function policy_accepted(int $userid): bool {
        if (!method_exists('\core_ai\manager', 'get_user_policy_status')) {
            return true;
        }
        return (bool)\core_ai\manager::get_user_policy_status($userid);
    }

    /**
     * Record acceptance of the AI usage policy.
     *
     * @param int $userid
     * @param \context $context
     */
    public static function accept_policy(int $userid, \context $context): void {
        if (method_exists('\core_ai\manager', 'user_policy_accepted')) {
            \core_ai\manager::user_policy_accepted($userid, $context->id);
        }
    }

    /**
     * Ask the AI provider for texts and merge them into the data.
     *
     * Failures are silent: the factual data is returned unchanged.
     *
     * @param array $data
     * @param \context $context
     * @return array
     */
    public static function enrich(array $data, \context $context): array {
        $response = self::generate(self::build_prompt($data), $context);
        if ($response === null) {
            return $data;
        }
        $result = self::parse_response($response);
        if (!$result) {
            return $data;
        }

        foreach (['annotation', 'goals'] as $field) {
            if (!empty($result[$field]) && is_string($result[$field])) {
                $data[$field] = trim($result[$field]);
            }
        }
        if (!empty($result['tasks']) && is_array($result['tasks'])) {
            $data['tasks'] = array_values(array_filter(array_map('strval', $result['tasks']), 'strlen'));
        }
        if (!empty($result['themes']) && is_array($result['themes'])) {
            $i = 0;
            foreach ($data['themes'] as $key => $item) {
                if ($item['type'] !== 'theme') {
                    continue;
                }
                $aitext = $result['themes'][$i] ?? null;
                if (is_string($aitext) && trim($aitext) !== '') {
                    // Keep the factual list of activities under the AI-written content.
                    $data['themes'][$key]['description'] = trim($aitext) . "\n" . self::activities_only($item['description']);
                }
                $i++;
            }
        }
        return $data;
    }

    /**
     * Build the prompt.
     *
     * @param array $data
     * @return string
     */
    public static function build_prompt(array $data): string {
        $lang = current_language();
        $themes = [];
        $n = 0;
        foreach ($data['themes'] as $item) {
            if ($item['type'] === 'module') {
                $themes[] = $item['title'];
            } else {
                $themes[] = '  ' . (++$n) . '. ' . $item['title'] . ($item['description'] !== '' ?
                    ' | ' . str_replace("\n", ' ', \core_text::substr($item['description'], 0, 400)) : '');
            }
        }
        $prompt = "You are an experienced university methodologist. Write parts of a course syllabus " .
            "for a higher education institution in Ukraine, following the requirements for syllabi of " .
            "educational components.\n" .
            "Output language: {$lang} (use Ukrainian if 'uk').\n" .
            "Course: {$data['course_name']}\n" .
            ($data['educational_program'] ? "Educational programme: {$data['educational_program']}\n" : '') .
            ($data['specialty'] ? "Specialty: {$data['specialty']}\n" : '') .
            "Existing course description: " . \core_text::substr($data['annotation'], 0, 1500) . "\n" .
            "Course topics (in order, with materials and tasks from the LMS course):\n" . implode("\n", $themes) . "\n\n" .
            "Return ONLY a JSON object, without markdown, with keys:\n" .
            "\"annotation\": string, 4-6 sentences describing the course content;\n" .
            "\"goals\": string, 1-3 sentences starting like 'Метою вивчення навчальної дисципліни є ...';\n" .
            "\"tasks\": array of 3-6 strings, course tasks;\n" .
            "\"themes\": array of strings, exactly {$n} items, one per topic in the same order, each a concise " .
            "list of key questions of the topic (2-4 sentences). Do not invent hours or grades.";
        return $prompt;
    }

    /**
     * Call the AI subsystem.
     *
     * @param string $prompt
     * @param \context $context
     * @return string|null
     */
    protected static function generate(string $prompt, \context $context): ?string {
        global $USER;
        try {
            // Positional arguments: contextid, userid, prompttext (keeps the file parseable on PHP 7.4).
            $action = new \core_ai\aiactions\generate_text($context->id, $USER->id, $prompt);
            $manager = \core\di::get(\core_ai\manager::class);
            $response = $manager->process_action($action);
            if (!$response->get_success()) {
                debugging('local_syllabus AI error: ' . $response->get_errormessage(), DEBUG_DEVELOPER);
                return null;
            }
            $responsedata = $response->get_response_data();
            return (string)($responsedata['generatedcontent'] ?? '');
        } catch (\Throwable $e) {
            debugging('local_syllabus AI exception: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Extract the JSON object from an AI response (tolerating code fences and extra text).
     *
     * @param string $response
     * @return array|null
     */
    public static function parse_response(string $response): ?array {
        $response = trim($response);
        $response = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $response);
        $start = strpos($response, '{');
        $end = strrpos($response, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($response, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Keep only generated "Materials:" / "Tasks:" lines from a theme description.
     *
     * @param string $description
     * @return string
     */
    protected static function activities_only(string $description): string {
        $prefixes = [get_string('gen_materials', 'local_syllabus'), get_string('gen_tasks', 'local_syllabus')];
        $lines = array_filter(explode("\n", $description), function($line) use ($prefixes) {
            foreach ($prefixes as $prefix) {
                if (strpos($line, $prefix) === 0) {
                    return true;
                }
            }
            return false;
        });
        return implode("\n", $lines);
    }
}
