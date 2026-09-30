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
 * Tests for the syllabus generator.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_syllabus\generator
 */
final class generator_test extends \advanced_testcase {

    /**
     * Build a course with sections, resources and graded activities.
     *
     * @return \stdClass
     */
    protected function create_sample_course(): \stdClass {
        $gen = $this->getDataGenerator();
        $parent = $gen->create_category(['name' => 'Факультет психології']);
        $category = $gen->create_category(['name' => 'Кафедра психології', 'parent' => $parent->id]);
        $course = $gen->create_course([
            'fullname' => 'Загальна психологія',
            'summary' => '<p>Курс про основи психології.</p>',
            'category' => $category->id,
            'numsections' => 4,
            'startdate' => mktime(0, 0, 0, 9, 1, 2026),
        ]);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(1), ['name' => 'Тема 1. Вступ']);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(2), ['name' => 'Пам’ять']);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(3), ['name' => 'Мислення']);
        course_update_section($course, get_fast_modinfo($course)->get_section_info(4), ['name' => 'Емоції']);

        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Лекція 1']);
        $gen->create_module('url', ['course' => $course->id, 'section' => 2, 'name' => 'Портал психології',
            'externalurl' => 'https://example.org/psy']);
        $gen->create_module('assign', ['course' => $course->id, 'section' => 1, 'name' => 'Есе', 'grade' => 20]);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 4, 'name' => 'Тест модуля 2', 'grade' => 30]);
        $gen->create_module('quiz', ['course' => $course->id, 'section' => 4, 'name' => 'Екзамен', 'grade' => 40]);

        $teacher = $gen->create_user(['firstname' => 'Олена', 'lastname' => 'Коваль', 'email' => 'koval@example.com']);
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        return $course;
    }

    /**
     * Generation from course content.
     */
    public function test_generate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_sample_course();

        $data = (new generator($course, ['modules' => 2]))->generate();

        $this->assertSame('Загальна психологія', $data['course_name']);
        $this->assertSame('Факультет психології', $data['faculty']);
        $this->assertSame('психології', $data['department']);
        $this->assertSame('2026-2027', $data['year']);
        $this->assertStringContainsString('Коваль', $data['instructor_name']);
        $this->assertSame('koval@example.com', $data['instructor_email']);
        $this->assertStringContainsString('Курс про основи психології.', $data['annotation']);

        // Scheme: 2 modules + 4 themes, numbering prefix removed from the title.
        $types = array_column($data['themes'], 'type');
        $this->assertSame(['module', 'theme', 'theme', 'module', 'theme', 'theme'], $types);
        $this->assertSame('Вступ', $data['themes'][1]['title']);
        $this->assertStringContainsString('Лекція 1', $data['themes'][1]['description']);

        // Hours: 3 credits * 30 = 90 hours, scheme sums equal declared hours.
        $this->assertSame('3', $data['credits']);
        $this->assertSame('90', $data['total_hours']);
        $lectures = array_sum(array_map(fn($t) => (int)($t['lectures'] ?? 0), $data['themes']));
        $practicals = array_sum(array_map(fn($t) => (int)($t['practicals'] ?? 0), $data['themes']));
        $this->assertEquals($lectures, (int)$data['lecture_hours']);
        $this->assertEquals($practicals, (int)$data['practice_hours']);
        $this->assertEquals(90 - $lectures - $practicals, (int)$data['self_hours']);

        // Assessment: essay in module 1, quiz in module 2, exam goes to final control.
        $this->assertStringContainsString('Есе', $data['module1_evaluation']);
        $this->assertStringContainsString('Тест модуля 2', $data['module2_evaluation']);
        $this->assertStringNotContainsString('Екзамен', $data['module2_evaluation']);
        $this->assertStringContainsString('Екзамен', $data['final_evaluation']);
        $this->assertSame(get_string('finalcontrol_exam', 'local_syllabus'), $data['final_control']);

        // Literature.
        $this->assertStringNotContainsString('Лекція 1', $data['lit_main']);
        $this->assertStringContainsString('https://example.org/psy', $data['lit_internet']);
    }

    /**
     * Merge modes and persistence.
     */
    public function test_merge_and_save(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_sample_course();

        $existing = syllabus::defaults();
        $existing['annotation'] = 'Моя анотація';
        $generated = (new generator($course))->generate($existing);

        $merged = syllabus::merge($existing, $generated, true);
        $this->assertSame('Моя анотація', $merged['annotation']);
        $this->assertSame('Загальна психологія', $merged['course_name']);

        $merged = syllabus::merge($existing, $generated, false);
        $this->assertStringContainsString('Курс про основи психології.', $merged['annotation']);

        syllabus::save($course->id, $merged, true);
        $record = syllabus::get_record($course->id);
        $this->assertEquals(1, $record->published);
        $this->assertSame($merged['themes'], $record->syllabus['themes']);
    }

    /**
     * JSON exchange with the standalone constructor.
     */
    public function test_json_roundtrip(): void {
        $this->resetAfterTest();
        $constructorfile = json_encode([
            'metadata' => ['type' => 'syllabus_data', 'format_version' => '1.0'],
            'syllabus' => [
                'course_name' => 'ОК 18. Історія світової літератури',
                'tasks' => 'Одне завдання',
                'competencies' => [['code' => 'ЗК1', 'text' => 'Здатність вчитися']],
                'themes' => [
                    ['type' => 'module', 'title' => 'Модуль 1'],
                    ['type' => 'theme', 'title' => 'Античність', 'lectures' => '2', 'practicals' => '2',
                        'description' => 'Гомер'],
                ],
                'signature' => 'javascript:alert(1)',
                'unknownkey' => 'x',
            ],
        ]);
        $data = syllabus::import_json($constructorfile);
        $this->assertSame('ОК 18. Історія світової літератури', $data['course_name']);
        $this->assertSame(['Одне завдання'], $data['tasks']);
        $this->assertSame('ЗК1', $data['competencies'][0]['code']);
        $this->assertCount(2, $data['themes']);
        $this->assertSame('', $data['signature']);
        $this->assertArrayNotHasKey('unknownkey', $data);

        $again = syllabus::import_json(syllabus::export_json($data));
        $this->assertSame($data, $again);
    }

    /**
     * Code splitting for competencies / outcomes.
     */
    public function test_split_code(): void {
        $this->assertSame(['ЗК1', 'Здатність вчитися'], syllabus::split_code('ЗК1. Здатність вчитися'));
        $this->assertSame(['ПРН 12', 'Аналізувати'], syllabus::split_code('ПРН 12 - Аналізувати'));
        $this->assertSame(['ФК2.1', 'Текст'], syllabus::split_code('ФК2.1: Текст'));
        $this->assertSame(['', 'Просто текст без коду'], syllabus::split_code('Просто текст без коду'));
    }

    /**
     * AI response parsing.
     */
    public function test_ai_parse_response(): void {
        $parsed = ai_helper::parse_response("```json\n{\"annotation\": \"A\", \"tasks\": [\"t1\"]}\n```");
        $this->assertSame('A', $parsed['annotation']);
        $this->assertNull(ai_helper::parse_response('no json here'));
    }

    /**
     * Document rendering and PDF export.
     */
    public function test_render_and_pdf(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->create_sample_course();
        $data = (new generator($course))->generate();
        $data['annotation'] = "<script>alert(1)</script>\nhttps://example.org";

        $PAGE->set_context(\context_course::instance($course->id));
        $renderer = $PAGE->get_renderer('core');
        $html = $renderer->render_from_template('local_syllabus/document',
            (new output\document($data))->export_for_template($renderer));
        $this->assertStringContainsString('Загальна психологія', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<a href="https://example.org"', $html);

        $pdf = pdf_exporter::render($data);
        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
