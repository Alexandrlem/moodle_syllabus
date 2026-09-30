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


namespace local_syllabus\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider.
 *
 * Syllabi are course documents; the only personal data stored is the id of the
 * user who last modified the syllabus.
 *
 * @package    local_syllabus
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_syllabus', [
            'courseid' => 'privacy:metadata:local_syllabus:courseid',
            'usermodified' => 'privacy:metadata:local_syllabus:usermodified',
            'timemodified' => 'privacy:metadata:local_syllabus:timemodified',
        ], 'privacy:metadata:local_syllabus');
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:core_ai');
        return $collection;
    }

    /**
     * Contexts with user data.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql("SELECT ctx.id
                                      FROM {local_syllabus} ls
                                      JOIN {context} ctx ON ctx.instanceid = ls.courseid AND ctx.contextlevel = :level
                                     WHERE ls.usermodified = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $userlist->add_from_sql('usermodified', "SELECT usermodified FROM {local_syllabus} WHERE courseid = :courseid",
            ['courseid' => $context->instanceid]);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $record = $DB->get_record('local_syllabus', ['courseid' => $context->instanceid, 'usermodified' => $userid]);
            if ($record) {
                writer::with_context($context)->export_data([get_string('pluginname', 'local_syllabus')], (object)[
                    'timemodified' => \core_privacy\local\request\transform::datetime($record->timemodified),
                    'lastmodifiedbyyou' => \core_privacy\local\request\transform::yesno(true),
                ]);
            }
        }
    }

    /**
     * Delete data for all users in a context (the syllabus belongs to the course, so only anonymise).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->set_field('local_syllabus', 'usermodified', 0, ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete data for a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $DB->set_field('local_syllabus', 'usermodified', 0,
                    ['courseid' => $context->instanceid, 'usermodified' => $userid]);
            }
        }
    }

    /**
     * Delete data for users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            $DB->set_field('local_syllabus', 'usermodified', 0,
                ['courseid' => $context->instanceid, 'usermodified' => $userid]);
        }
    }
}
