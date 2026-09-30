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
 * Library callbacks for local_syllabus.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add a "Syllabus" link to the course navigation (appears under "More" in Moodle 4.x).
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_syllabus_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context) {
    if (!has_capability('local/syllabus:view', $context)) {
        return;
    }
    if (!has_capability('local/syllabus:edit', $context)) {
        $record = \local_syllabus\syllabus::get_record($course->id);
        if (!$record || !$record->published) {
            return;
        }
    }
    $navigation->add(
        get_string('syllabus', 'local_syllabus'),
        new moodle_url('/local/syllabus/index.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_syllabus',
        new pix_icon('i/report', '')
    );
}

/**
 * Remove the syllabus when a course is deleted.
 *
 * @param stdClass $course
 */
function local_syllabus_pre_course_delete(stdClass $course) {
    \local_syllabus\syllabus::delete($course->id);
}
