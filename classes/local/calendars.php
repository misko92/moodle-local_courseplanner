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

use DateTime;
use context_course;
use core_date;
use moodle_exception;
use stdClass;

/**
 * Course calendars: lookup, labels, ranking and deletion.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendars {
    /**
     * Suggest a title for a new calendar: the school year containing the given time.
     *
     * School years are assumed to start in July, so September 2026 and March 2027
     * both give "2026-27".
     *
     * @param int $time Unix timestamp.
     * @return string
     */
    public static function suggest_title(int $time): string {
        $date = new DateTime('@' . $time);
        $date->setTimezone(core_date::get_user_timezone_object());
        $year = (int)$date->format('Y');
        if ((int)$date->format('n') < 7) {
            $year--;
        }
        return $year . '-' . substr((string)($year + 1), -2);
    }

    /**
     * Display label for a calendar.
     *
     * @param stdClass $calendar Calendar record.
     * @return string Formatted, HTML-safe label.
     */
    public static function label(stdClass $calendar): string {
        $title = trim((string)$calendar->title);
        if ($title === '') {
            return get_string('calendarfallbacktitle', 'local_courseplanner', (int)$calendar->id);
        }
        return format_string($title, true, ['context' => context_course::instance((int)$calendar->courseid)]);
    }

    /**
     * Return all calendars for a course, newest first.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_for_course(int $courseid): array {
        global $DB;

        return $DB->get_records(
            'local_courseplanner_calendars',
            ['courseid' => $courseid],
            'timecreated DESC, id DESC'
        );
    }

    /**
     * Order a course's calendars so the one a teacher most likely wants comes first.
     *
     * Preference, strongest first: the active calendar; one whose class dates contain
     * the course start date; one whose class dates contain today; the nearest upcoming
     * one; then the newest.
     *
     * @param stdClass $course Course record (uses startdate).
     * @param stdClass[] $calendars Calendar records keyed by id.
     * @param int $now Current time.
     * @return array [stdClass[] $sorted, string $reasonkey] Reason string key for the first calendar ('' if none).
     */
    public static function rank(stdClass $course, array $calendars, int $now): array {
        if (empty($calendars)) {
            return [[], ''];
        }

        $coursestart = (int)($course->startdate ?? 0);
        $scores = [];
        foreach ($calendars as $calendar) {
            [$startdate, $enddate] = self::get_date_range((int)$calendar->id);

            // Each tier outweighs all lower tiers combined.
            $score = 0;
            $reasonkey = 'calendarrecommend_reason_newest';
            if ($startdate && $startdate > $now) {
                $score += max(0, 1000 - (int)(($startdate - $now) / DAYSECS));
                $reasonkey = 'calendarrecommend_reason_upcoming';
            }
            if ($startdate && $enddate && $now >= $startdate && $now <= $enddate) {
                $score += 2000;
                $reasonkey = 'calendarrecommend_reason_currentdate';
            }
            // Course start dates are usually set a few days before classes begin.
            if (
                $coursestart && $startdate && $enddate
                    && $coursestart >= $startdate - 14 * DAYSECS && $coursestart <= $enddate
            ) {
                $score += 4000;
                $reasonkey = 'calendarrecommend_reason_courseconfig';
            }
            if ((int)$calendar->isactive === 1) {
                $score += 8000;
                $reasonkey = 'calendarrecommend_reason_active';
            }
            $scores[(int)$calendar->id] = ['score' => $score, 'reasonkey' => $reasonkey];
        }

        uasort($calendars, static function (stdClass $left, stdClass $right) use ($scores): int {
            $leftscore = $scores[(int)$left->id]['score'];
            $rightscore = $scores[(int)$right->id]['score'];
            if ($leftscore === $rightscore) {
                return [(int)$right->timecreated, (int)$right->id] <=> [(int)$left->timecreated, (int)$left->id];
            }
            return $rightscore <=> $leftscore;
        });

        $first = reset($calendars);
        return [$calendars, $scores[(int)$first->id]['reasonkey']];
    }

    /**
     * First and last day of classes for a calendar, from its active START/END rules.
     *
     * @param int $calendarid
     * @return array [?int $startdate, ?int $enddate]
     */
    public static function get_date_range(int $calendarid): array {
        $startdate = null;
        $enddate = null;
        foreach (timeline::get_rules($calendarid, true) as $rule) {
            if ($rule->ruletype === 'START') {
                $startdate = (int)$rule->ruledate;
            } else if ($rule->ruletype === 'END') {
                $enddate = (int)$rule->ruledate;
            }
        }
        return [$startdate, $enddate];
    }

    /**
     * Require a calendar belonging to this course.
     *
     * @param int $calendarid
     * @param int $courseid
     * @return stdClass
     */
    public static function require_in_course(int $calendarid, int $courseid): stdClass {
        global $DB;

        $calendar = $DB->get_record('local_courseplanner_calendars', ['id' => $calendarid], '*', MUST_EXIST);
        if ((int)$calendar->courseid !== $courseid) {
            throw new moodle_exception('invalidcalendarcontext', 'local_courseplanner');
        }

        return $calendar;
    }

    /**
     * Return the active calendar for a course, if any.
     *
     * @param int $courseid
     * @return stdClass|null The active calendar record, or null when none is active.
     */
    public static function get_active(int $courseid): ?stdClass {
        $calendars = self::get_for_course($courseid);
        foreach ($calendars as $calendar) {
            if ((int)$calendar->isactive === 1) {
                return $calendar;
            }
        }
        return null;
    }

    /**
     * Delete everything stored for a course: calendars with their grid, dates and
     * apply history, the blueprint link and course info. Blueprints are kept.
     *
     * @param int $courseid
     */
    public static function delete_course_data(int $courseid): void {
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
}
