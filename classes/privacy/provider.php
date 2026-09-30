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
 * Privacy Subsystem implementation for local_courseplanner.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_courseplanner\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider describing and serving the data stored by this plugin.
 *
 * Blueprint libraries are owned by a teacher and live in that user's context.
 * Course calendars, their grid blocks, date rules, blueprint links, apply-run
 * traces and supplementary course info live in the relevant course context.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection the collection to add metadata to
     * @return collection the updated collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_courseplanner_blueprints', [
            'owneruserid' => 'privacy:metadata:local_courseplanner_blueprints:owneruserid',
            'name' => 'privacy:metadata:local_courseplanner_blueprints:name',
            'description' => 'privacy:metadata:local_courseplanner_blueprints:description',
            'usermodified' => 'privacy:metadata:local_courseplanner_blueprints:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_blueprints:timemodified',
        ], 'privacy:metadata:local_courseplanner_blueprints');

        $collection->add_database_table('local_courseplanner_topics', [
            'title' => 'privacy:metadata:local_courseplanner_topics:title',
            'type' => 'privacy:metadata:local_courseplanner_topics:type',
            'contenthtml' => 'privacy:metadata:local_courseplanner_topics:contenthtml',
            'usermodified' => 'privacy:metadata:local_courseplanner_topics:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_topics:timemodified',
        ], 'privacy:metadata:local_courseplanner_topics');

        $collection->add_database_table('local_courseplanner_courselink', [
            'courseid' => 'privacy:metadata:local_courseplanner_courselink:courseid',
            'blueprintid' => 'privacy:metadata:local_courseplanner_courselink:blueprintid',
            'linknotes' => 'privacy:metadata:local_courseplanner_courselink:linknotes',
            'usermodified' => 'privacy:metadata:local_courseplanner_courselink:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_courselink:timemodified',
        ], 'privacy:metadata:local_courseplanner_courselink');

        $collection->add_database_table('local_courseplanner_calendars', [
            'year' => 'privacy:metadata:local_courseplanner_calendars:year',
            'semester' => 'privacy:metadata:local_courseplanner_calendars:semester',
            'title' => 'privacy:metadata:local_courseplanner_calendars:title',
            'usermodified' => 'privacy:metadata:local_courseplanner_calendars:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_calendars:timemodified',
        ], 'privacy:metadata:local_courseplanner_calendars');

        $collection->add_database_table('local_courseplanner_rules', [
            'label' => 'privacy:metadata:local_courseplanner_rules:label',
            'description' => 'privacy:metadata:local_courseplanner_rules:description',
            'ruledate' => 'privacy:metadata:local_courseplanner_rules:ruledate',
            'usermodified' => 'privacy:metadata:local_courseplanner_rules:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_rules:timemodified',
        ], 'privacy:metadata:local_courseplanner_rules');

        $collection->add_database_table('local_courseplanner_blocks', [
            'blocktype' => 'privacy:metadata:local_courseplanner_blocks:blocktype',
            'contenthtml' => 'privacy:metadata:local_courseplanner_blocks:contenthtml',
            'cellheading' => 'privacy:metadata:local_courseplanner_blocks:cellheading',
            'usermodified' => 'privacy:metadata:local_courseplanner_blocks:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_blocks:timemodified',
        ], 'privacy:metadata:local_courseplanner_blocks');

        $collection->add_database_table('local_courseplanner_ruleruns', [
            'appliedbyuserid' => 'privacy:metadata:local_courseplanner_ruleruns:appliedbyuserid',
            'summaryjson' => 'privacy:metadata:local_courseplanner_ruleruns:summaryjson',
            'timecreated' => 'privacy:metadata:local_courseplanner_ruleruns:timecreated',
        ], 'privacy:metadata:local_courseplanner_ruleruns');

        $collection->add_database_table('local_courseplanner_courseinfo', [
            'introhtml' => 'privacy:metadata:local_courseplanner_courseinfo:introhtml',
            'linkshtml' => 'privacy:metadata:local_courseplanner_courseinfo:linkshtml',
            'usermodified' => 'privacy:metadata:local_courseplanner_courseinfo:usermodified',
            'timemodified' => 'privacy:metadata:local_courseplanner_courseinfo:timemodified',
        ], 'privacy:metadata:local_courseplanner_courseinfo');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     *
     * @param int $userid the user to search
     * @return contextlist the list of contexts
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        // Blueprint libraries (and the topics within them) live in the owning
        // teacher's user context. Include the context if the user owns or has
        // edited any blueprint data.
        $userhasblueprints = self::user_has_blueprint_data($userid);
        if ($userhasblueprints) {
            $contextlist->add_user_context($userid);
        }

        // Course calendars and everything hanging off them live in the course
        // context. The user is linked through the usermodified / appliedbyuserid
        // authorship fields.
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course} c ON c.id = ctx.instanceid AND ctx.contextlevel = :courselevel
                 WHERE c.id IN (
                        SELECT courseid FROM {local_courseplanner_courselink} WHERE usermodified = :u1
                        UNION
                        SELECT courseid FROM {local_courseplanner_calendars} WHERE usermodified = :u2
                        UNION
                        SELECT courseid FROM {local_courseplanner_courseinfo} WHERE usermodified = :u3
                        UNION
                        SELECT cal.courseid
                          FROM {local_courseplanner_blocks} bl
                          JOIN {local_courseplanner_calendars} cal ON cal.id = bl.calendarid
                         WHERE bl.usermodified = :u4
                        UNION
                        SELECT cal.courseid
                          FROM {local_courseplanner_rules} r
                          JOIN {local_courseplanner_calendars} cal ON cal.id = r.calendarid
                         WHERE r.usermodified = :u5
                        UNION
                        SELECT cal.courseid
                          FROM {local_courseplanner_ruleruns} ar
                          JOIN {local_courseplanner_calendars} cal ON cal.id = ar.calendarid
                         WHERE ar.appliedbyuserid = :u6
                 )";
        $params = [
            'courselevel' => CONTEXT_COURSE,
            'u1' => $userid, 'u2' => $userid, 'u3' => $userid,
            'u4' => $userid, 'u5' => $userid, 'u6' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist the userlist to add users to
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            // Blueprint owners and the users who last edited that owner's
            // blueprints or topics.
            $params = ['uid' => $context->instanceid];
            $userlist->add_from_sql(
                'owneruserid',
                "SELECT owneruserid
                   FROM {local_courseplanner_blueprints}
                  WHERE owneruserid = :uid",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT usermodified
                   FROM {local_courseplanner_blueprints}
                  WHERE owneruserid = :uid AND usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT t.usermodified
                   FROM {local_courseplanner_topics} t
                   JOIN {local_courseplanner_blueprints} b ON b.id = t.blueprintid
                  WHERE b.owneruserid = :uid AND t.usermodified IS NOT NULL",
                $params
            );
            return;
        }

        if ($context instanceof \context_course) {
            $params = ['courseid' => $context->instanceid];

            $userlist->add_from_sql(
                'usermodified',
                "SELECT usermodified
                   FROM {local_courseplanner_courselink}
                  WHERE courseid = :courseid AND usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT usermodified
                   FROM {local_courseplanner_calendars}
                  WHERE courseid = :courseid AND usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT usermodified
                   FROM {local_courseplanner_courseinfo}
                  WHERE courseid = :courseid AND usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT bl.usermodified
                   FROM {local_courseplanner_blocks} bl
                   JOIN {local_courseplanner_calendars} cal ON cal.id = bl.calendarid
                  WHERE cal.courseid = :courseid AND bl.usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT r.usermodified
                   FROM {local_courseplanner_rules} r
                   JOIN {local_courseplanner_calendars} cal ON cal.id = r.calendarid
                  WHERE cal.courseid = :courseid AND r.usermodified IS NOT NULL",
                $params
            );
            $userlist->add_from_sql(
                'appliedbyuserid',
                "SELECT ar.appliedbyuserid
                   FROM {local_courseplanner_ruleruns} ar
                   JOIN {local_courseplanner_calendars} cal ON cal.id = ar.calendarid
                  WHERE cal.courseid = :courseid",
                $params
            );
        }
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts to export
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $userid) {
                self::export_blueprints_for_user($context, $userid);
            } else if ($context instanceof \context_course) {
                self::export_course_data_for_user($context, $userid);
            }
        }
    }

    /**
     * Export the blueprint library owned by the user in their user context.
     *
     * @param \context $context the user context
     * @param int $userid the user whose blueprints are exported
     * @return void
     */
    protected static function export_blueprints_for_user(\context $context, int $userid): void {
        global $DB;

        $blueprints = $DB->get_records('local_courseplanner_blueprints', ['owneruserid' => $userid]);
        if (empty($blueprints)) {
            return;
        }

        $data = [];
        foreach ($blueprints as $blueprint) {
            $topics = $DB->get_records(
                'local_courseplanner_topics',
                ['blueprintid' => $blueprint->id],
                'sortorder ASC'
            );
            $topicdata = [];
            foreach ($topics as $topic) {
                $topicdata[] = [
                    'title' => $topic->title,
                    'type' => $topic->type,
                    'contenthtml' => $topic->contenthtml,
                    'timemodified' => transform::datetime($topic->timemodified),
                ];
            }
            $data[] = [
                'name' => $blueprint->name,
                'description' => $blueprint->description,
                'isarchived' => transform::yesno($blueprint->isarchived),
                'timecreated' => transform::datetime($blueprint->timecreated),
                'timemodified' => transform::datetime($blueprint->timemodified),
                'topics' => $topicdata,
            ];
        }

        writer::with_context($context)->export_data(
            [get_string('privacy:blueprintspath', 'local_courseplanner')],
            (object) ['blueprints' => $data]
        );
    }

    /**
     * Export the calendar data the user authored in a course context.
     *
     * @param \context $context the course context
     * @param int $userid the user whose contributions are exported
     * @return void
     */
    protected static function export_course_data_for_user(\context $context, int $userid): void {
        global $DB;

        $courseid = $context->instanceid;
        $subcontext = [get_string('privacy:calendarspath', 'local_courseplanner')];

        $calendars = $DB->get_records('local_courseplanner_calendars', ['courseid' => $courseid]);
        $calendardata = [];
        foreach ($calendars as $calendar) {
            $blocks = $DB->get_records(
                'local_courseplanner_blocks',
                ['calendarid' => $calendar->id, 'usermodified' => $userid]
            );
            $rules = $DB->get_records(
                'local_courseplanner_rules',
                ['calendarid' => $calendar->id, 'usermodified' => $userid]
            );
            $runs = $DB->get_records(
                'local_courseplanner_ruleruns',
                ['calendarid' => $calendar->id, 'appliedbyuserid' => $userid]
            );

            $touchedcalendar = ((int) $calendar->usermodified === $userid);
            if (!$touchedcalendar && empty($blocks) && empty($rules) && empty($runs)) {
                continue;
            }

            $calendardata[] = [
                'title' => $calendar->title,
                'year' => $calendar->year,
                'semester' => $calendar->semester,
                'lastmodifiedbyyou' => transform::yesno($touchedcalendar),
                'blocksyoumodified' => count($blocks),
                'datesrulesyoumodified' => count($rules),
                'applyrunsbyyou' => count($runs),
            ];
        }

        $link = $DB->get_record(
            'local_courseplanner_courselink',
            ['courseid' => $courseid, 'usermodified' => $userid]
        );
        $info = $DB->get_record(
            'local_courseplanner_courseinfo',
            ['courseid' => $courseid, 'usermodified' => $userid]
        );

        if (empty($calendardata) && !$link && !$info) {
            return;
        }

        $export = (object) [
            'calendars' => $calendardata,
            'blueprintlinkedbyyou' => $link ? true : false,
            'courseinfoeditedbyyou' => $info ? true : false,
        ];
        writer::with_context($context)->export_data($subcontext, $export);
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context instanceof \context_user) {
            self::delete_blueprints_for_users($context, [$context->instanceid]);
            return;
        }

        if ($context instanceof \context_course) {
            self::delete_all_course_calendar_data($context->instanceid);
        }
    }

    /**
     * Delete all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts and user
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_user && $context->instanceid == $userid) {
                self::delete_blueprints_for_users($context, [$userid]);
            } else if ($context instanceof \context_course) {
                self::anonymise_course_data_for_users($context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Delete data for multiple users within a single context.
     *
     * @param approved_userlist $userlist the approved users in the context
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        if ($context instanceof \context_user) {
            // Only the owning user's own context can hold their blueprints.
            if (in_array($context->instanceid, $userids)) {
                self::delete_blueprints_for_users($context, [$context->instanceid]);
            }
        } else if ($context instanceof \context_course) {
            self::anonymise_course_data_for_users($context->instanceid, $userids);
        }
    }

    /**
     * Whether the user owns or has edited any blueprint-level data.
     *
     * @param int $userid the user to check
     * @return bool true if the user has blueprint data
     */
    protected static function user_has_blueprint_data(int $userid): bool {
        global $DB;

        if (
            $DB->record_exists_select(
                'local_courseplanner_blueprints',
                'owneruserid = :o OR usermodified = :m',
                ['o' => $userid, 'm' => $userid]
            )
        ) {
            return true;
        }
        return $DB->record_exists('local_courseplanner_topics', ['usermodified' => $userid]);
    }

    /**
     * Delete the blueprint libraries (and their topics) owned by the given users.
     *
     * @param \context $context the user context being cleared
     * @param array $userids the owning user ids whose blueprints are removed
     * @return void
     */
    protected static function delete_blueprints_for_users(\context $context, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'own');
        $blueprintids = $DB->get_fieldset_select(
            'local_courseplanner_blueprints',
            'id',
            "owneruserid $insql",
            $params
        );
        if (empty($blueprintids)) {
            return;
        }

        [$bpsql, $bpparams] = $DB->get_in_or_equal($blueprintids, SQL_PARAMS_NAMED, 'bp');
        $DB->delete_records_select('local_courseplanner_topics', "blueprintid $bpsql", $bpparams);
        $DB->delete_records_select('local_courseplanner_blueprints', "id $bpsql", $bpparams);
    }

    /**
     * Delete every calendar artifact attached to a course (used on course wipe).
     *
     * @param int $courseid the course whose calendar data is removed
     * @return void
     */
    protected static function delete_all_course_calendar_data(int $courseid): void {
        global $DB;

        $calendarids = $DB->get_fieldset_select(
            'local_courseplanner_calendars',
            'id',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );
        if (!empty($calendarids)) {
            [$calsql, $calparams] = $DB->get_in_or_equal($calendarids, SQL_PARAMS_NAMED, 'cal');
            $DB->delete_records_select('local_courseplanner_blocks', "calendarid $calsql", $calparams);
            $DB->delete_records_select('local_courseplanner_rules', "calendarid $calsql", $calparams);
            $DB->delete_records_select('local_courseplanner_ruleruns', "calendarid $calsql", $calparams);
            $DB->delete_records_select('local_courseplanner_calendars', "id $calsql", $calparams);
        }
        $DB->delete_records('local_courseplanner_courselink', ['courseid' => $courseid]);
        $DB->delete_records('local_courseplanner_courseinfo', ['courseid' => $courseid]);
    }

    /**
     * Remove the authorship link to the given users from shared course calendar
     * content, preserving the content itself for the rest of the course.
     *
     * @param int $courseid the course to clean within
     * @param array $userids the users whose authorship link is removed
     * @return void
     */
    protected static function anonymise_course_data_for_users(int $courseid, array $userids): void {
        global $DB;

        if (empty($userids)) {
            return;
        }

        $calendarids = $DB->get_fieldset_select(
            'local_courseplanner_calendars',
            'id',
            'courseid = :courseid',
            ['courseid' => $courseid]
        );

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');

        // Course-level tables keyed directly by courseid.
        $courseparams = $userparams;
        $courseparams['courseid'] = $courseid;
        $DB->set_field_select(
            'local_courseplanner_courselink',
            'usermodified',
            null,
            "courseid = :courseid AND usermodified $usersql",
            $courseparams
        );
        $DB->set_field_select(
            'local_courseplanner_courseinfo',
            'usermodified',
            null,
            "courseid = :courseid AND usermodified $usersql",
            $courseparams
        );
        $DB->set_field_select(
            'local_courseplanner_calendars',
            'usermodified',
            null,
            "courseid = :courseid AND usermodified $usersql",
            $courseparams
        );

        if (empty($calendarids)) {
            return;
        }

        // Tables keyed by calendarid.
        [$calsql, $calparams] = $DB->get_in_or_equal($calendarids, SQL_PARAMS_NAMED, 'cal');
        $blockparams = array_merge($userparams, $calparams);
        $DB->set_field_select(
            'local_courseplanner_blocks',
            'usermodified',
            null,
            "calendarid $calsql AND usermodified $usersql",
            $blockparams
        );
        $DB->set_field_select(
            'local_courseplanner_rules',
            'usermodified',
            null,
            "calendarid $calsql AND usermodified $usersql",
            $blockparams
        );

        // Apply runs require an author (NOT NULL), so the trace rows are removed.
        $DB->delete_records_select(
            'local_courseplanner_ruleruns',
            "calendarid $calsql AND appliedbyuserid $usersql",
            $blockparams
        );
    }
}
