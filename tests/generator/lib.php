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

use local_courseplanner\local\course_link;
use local_courseplanner\local\timeline;
use local_courseplanner\local\topics;

/**
 * Test data generator for local_courseplanner.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_courseplanner_generator extends component_generator_base {
    /**
     * Create a blueprint.
     *
     * @param array $record Needs 'owneruserid'; optional 'name', 'description', 'isarchived'.
     * @return stdClass
     */
    public function create_blueprint(array $record): stdClass {
        global $DB;
        static $count = 0;
        $count++;
        $now = time();
        $record = (object)array_merge([
            'name' => 'Blueprint ' . $count,
            'description' => '',
            'isarchived' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $record['owneruserid'],
        ], $record);
        $record->id = $DB->insert_record('local_courseplanner_blueprints', $record);
        return $record;
    }

    /**
     * Create a topic at the end of a blueprint.
     *
     * @param array $record Needs 'blueprintid'; optional 'title', 'type', 'contenthtml', 'isactive'.
     * @return stdClass
     */
    public function create_topic(array $record): stdClass {
        global $CFG, $DB;
        static $count = 0;
        $count++;
        $now = time();
        $record = (object)array_merge([
            'title' => 'Topic ' . $count,
            'type' => 'LECTURE',
            'contenthtml' => '',
            'isactive' => 1,
            'sortorder' => topics::next_sortorder((int)$record['blueprintid']),
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => null,
        ], $record);
        $record->id = $DB->insert_record('local_courseplanner_topics', $record);
        return $record;
    }

    /**
     * Create a calendar for a course and link the course to the blueprint.
     *
     * @param array $record Needs 'courseid' and 'blueprintid'; optional 'title', 'isactive', 'startdate', 'enddate'
     *     (dates become START/END rules).
     * @return stdClass
     */
    public function create_calendar(array $record): stdClass {
        global $CFG, $DB;
        $now = time();
        $startdate = $record['startdate'] ?? null;
        $enddate = $record['enddate'] ?? null;
        unset($record['startdate'], $record['enddate']);
        $blueprint = $DB->get_record('local_courseplanner_blueprints', ['id' => $record['blueprintid']], '*', MUST_EXIST);
        $record = (object)array_merge([
            'title' => '2026-27',
            'isactive' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $blueprint->owneruserid,
        ], $record);
        if ($record->isactive) {
            $DB->set_field('local_courseplanner_calendars', 'isactive', 0, ['courseid' => $record->courseid]);
        }
        $record->id = $DB->insert_record('local_courseplanner_calendars', $record);
        if (!course_link::get((int)$record->courseid)) {
            course_link::upsert(
                (int)$record->courseid,
                (int)$blueprint->id,
                'MANUAL',
                null,
                '',
                (int)$blueprint->owneruserid
            );
        }
        if ($startdate) {
            timeline::create_rule(
                (int)$record->id,
                'START',
                (int)$startdate,
                '',
                '',
                null,
                null,
                (int)$blueprint->owneruserid
            );
        }
        if ($enddate) {
            timeline::create_rule(
                (int)$record->id,
                'END',
                (int)$enddate,
                '',
                '',
                null,
                null,
                (int)$blueprint->owneruserid
            );
        }
        return $record;
    }
}
