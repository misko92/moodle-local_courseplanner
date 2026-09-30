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

namespace local_courseplanner\local;

use stdClass;

/**
 * Student-facing intro texts shown above a course calendar.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_info {
    /**
     * Get or create course_info record.
     *
     * @param int $courseid
     * @return stdClass|null
     */
    public static function get(int $courseid): ?stdClass {
        global $DB;
        return $DB->get_record('local_courseplanner_courseinfo', ['courseid' => $courseid], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Create or update the course info record (intro + links panels).
     *
     * @param int $courseid Course to save info for.
     * @param string $introhtml Intro panel HTML.
     * @param string $linkshtml Links panel HTML.
     * @param int $userid User performing the save.
     * @return void
     */
    public static function save(int $courseid, string $introhtml, string $linkshtml, int $userid): void {
        global $DB;
        $now = time();
        $existing = $DB->get_record('local_courseplanner_courseinfo', ['courseid' => $courseid], '*', IGNORE_MISSING);
        if ($existing) {
            $existing->introhtml = $introhtml;
            $existing->linkshtml = $linkshtml;
            $existing->timemodified = $now;
            $existing->usermodified = $userid;
            $DB->update_record('local_courseplanner_courseinfo', $existing);
        } else {
            $DB->insert_record('local_courseplanner_courseinfo', (object)[
                'courseid' => $courseid,
                'introhtml' => $introhtml,
                'linkshtml' => $linkshtml,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }
    }
}
