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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use local_courseplanner\local\populate;
use local_courseplanner\local\timeline;

/**
 * Behat steps and named pages for local_courseplanner.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_courseplanner extends behat_base {
    /**
     * Named pages. Use with "I am on the \"<identifier>\" \"local_courseplanner > <type>\" page".
     *
     * Types taking a course shortname: Setup, Student view.
     * Types taking a calendar title: Builder, Dates, Coverage, Preview.
     *
     * @param string $type
     * @param string $identifier
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;
        $courseid = static fn(string $shortname): int =>
            (int)$DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        switch (strtolower($type)) {
            case 'setup':
                return new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid($identifier)]);
            case 'student view':
                return new moodle_url('/local/courseplanner/student.php', ['id' => $courseid($identifier)]);
        }
        $calendar = $DB->get_record('local_courseplanner_calendars', ['title' => $identifier], '*', MUST_EXIST);
        $params = ['id' => $calendar->courseid, 'calendarid' => $calendar->id];
        switch (strtolower($type)) {
            case 'builder':
                return new moodle_url('/local/courseplanner/calendar.php', $params);
            case 'dates':
                return new moodle_url('/local/courseplanner/rules.php', $params);
            case 'coverage':
                return new moodle_url('/local/courseplanner/coverage.php', $params);
            case 'preview':
                return new moodle_url('/local/courseplanner/view.php', $params);
        }
        throw new Exception('Unrecognised local_courseplanner page type "' . $type . '"');
    }

    /**
     * Apply a calendar's dates and auto-populate it from its blueprint, as the blueprint owner would.
     *
     * @Given the :title course planner calendar has been built
     * @param string $title Calendar title.
     */
    public function the_course_planner_calendar_has_been_built(string $title): void {
        global $CFG, $DB;
        $calendar = $DB->get_record('local_courseplanner_calendars', ['title' => $title], '*', MUST_EXIST);
        $ownerid = (int)$DB->get_field('local_courseplanner_blueprints', 'owneruserid', ['id' => $calendar->blueprintid]);
        timeline::apply((int)$calendar->id, $ownerid);
        populate::auto_populate((int)$calendar->id, (int)$calendar->blueprintid, $ownerid);
    }
}
