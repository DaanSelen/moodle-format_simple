<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Privacy Subsystem implementation for format_simple.
 *
 * @package    format_simple
 * @copyright  2025 South African Theological Seminary <ict@sats.ac.za>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace format_simple\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\writer;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for format_simple.
 *
 * The only personal data this plugin stores is the user's own dark mode
 * preference, kept as standard Moodle user preferences.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the user preferences stored by this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'format_simple_progress',
            [
                'courseid' => 'privacy:metadata:table:courseid',
                'userid' => 'privacy:metadata:table:userid',
                'sectionid' => 'privacy:metadata:table:sectionid',
                'timecompleted' => 'privacy:metadata:table:timecompleted',
            ],
            'privacy:metadata:table'
        );
        $collection->add_user_preference(
            'format_simple_darktheme',
            'privacy:metadata:preference:format_simple_darktheme'
        );
        $collection->add_user_preference(
            'format_simple_darkstart',
            'privacy:metadata:preference:format_simple_darkstart'
        );
        $collection->add_user_preference(
            'format_simple_darkend',
            'privacy:metadata:preference:format_simple_darkend'
        );

        return $collection;
    }

    /**
     * Export all user preferences for the given user.
     *
     * @param int $userid The user whose preferences should be exported.
     */
    public static function export_user_preferences(int $userid): void {
        $preferences = [
            'format_simple_darktheme' => 'privacy:metadata:preference:format_simple_darktheme',
            'format_simple_darkstart' => 'privacy:metadata:preference:format_simple_darkstart',
            'format_simple_darkend' => 'privacy:metadata:preference:format_simple_darkend',
        ];

        foreach ($preferences as $name => $reason) {
            $value = get_user_preferences($name, null, $userid);
            if ($value === null) {
                continue;
            }
            writer::export_user_preference(
                'format_simple',
                $name,
                $value,
                get_string($reason, 'format_simple')
            );
        }
    }

    /**
     * Add course contexts containing this user's unit acknowledgements.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {format_simple_progress} p ON p.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel AND p.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'courselevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Export the user's unit acknowledgements.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            if ($context->contextlevel !== CONTEXT_COURSE) {
                continue;
            }
            $records = $DB->get_records('format_simple_progress', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            foreach ($records as $record) {
                $section = $DB->get_record('course_sections', ['id' => $record->sectionid]);
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'format_simple'), get_string('progress_complete', 'format_simple')],
                    (object) [
                        'unit' => $section ? get_section_name($context->instanceid, $section) : $record->sectionid,
                        'timecompleted' => \core_date::timestamp_to_date_string($record->timecompleted),
                    ]
                );
            }
        }
    }

    /**
     * Delete acknowledgement data for all users in a course context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel === CONTEXT_COURSE) {
            $DB->delete_records('format_simple_progress', ['courseid' => $context->instanceid]);
        }
    }

    /**
     * Delete acknowledgement data for one user in approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            if ($context->contextlevel === CONTEXT_COURSE) {
                $DB->delete_records('format_simple_progress', [
                    'courseid' => $context->instanceid,
                    'userid' => $userid,
                ]);
            }
        }
    }

    /**
     * Add course contexts containing users to a bulk privacy request.
     */
    public static function get_users_in_context(userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT userid FROM {format_simple_progress} WHERE courseid = :courseid";
        $userlist->add_users($DB->get_fieldset_sql($sql, ['courseid' => $context->instanceid]));
    }

    /**
     * Delete acknowledgement data for a set of users in one course.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }
        list($insql, $params) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['courseid'] = $context->instanceid;
        $DB->execute(
            "DELETE FROM {format_simple_progress} WHERE courseid = :courseid AND userid $insql",
            $params
        );
    }
}
