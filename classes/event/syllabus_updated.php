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


namespace local_syllabus\event;

/**
 * Event triggered when a syllabus is saved.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class syllabus_updated extends \core\event\base {

    /**
     * Init.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_syllabus';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsyllabusupdated', 'local_syllabus');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' updated the syllabus of the course with id '{$this->courseid}'.";
    }

    /**
     * Related URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/syllabus/index.php', ['id' => $this->courseid]);
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_syllabus', 'restore' => \core\event\base::NOT_MAPPED];
    }
}
