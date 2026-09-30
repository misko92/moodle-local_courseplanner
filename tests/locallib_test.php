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

namespace local_courseplanner;

/**
 * Tests for the calendar logic in locallib.php.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('local_courseplanner_suggest_calendar_title')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_courseplanner_apply_rules')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_courseplanner_auto_populate')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_courseplanner_rank_calendars')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_courseplanner_delete_course_data')]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_courseplanner\local\hook_callbacks::class)]
final class locallib_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/local/courseplanner/locallib.php');
    }

    /**
     * Timestamp for midnight on a date in the server timezone, as rules.php stores dates.
     *
     * @param string $date Y-m-d
     * @return int
     */
    private static function day(string $date): int {
        return strtotime($date);
    }

    /**
     * Data for {@see test_suggest_calendar_title()}.
     *
     * @return array
     */
    public static function suggest_calendar_title_provider(): array {
        return [
            'September' => ['2026-09-15', '2026-27'],
            'March' => ['2027-03-01', '2026-27'],
            'June' => ['2027-06-30', '2026-27'],
            'July' => ['2027-07-01', '2027-28'],
            'Century rollover' => ['2099-10-01', '2099-00'],
        ];
    }

    /**
     * School-year title suggestion.
     *
     * @param string $date
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('suggest_calendar_title_provider')]
    public function test_suggest_calendar_title(string $date, string $expected): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame($expected, local_courseplanner_suggest_calendar_title(strtotime($date . ' 12:00')));
    }

    /**
     * A full school year builds a week row for every week and auto-populate fills it.
     */
    public function test_full_year_workflow(): void {
        global $PAGE;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        /** @var \local_courseplanner_generator $plugingen */
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $blueprint = $plugingen->create_blueprint(['owneruserid' => $teacher->id]);
        for ($i = 0; $i < 60; $i++) {
            $plugingen->create_topic(['blueprintid' => $blueprint->id, 'type' => $i % 4 === 3 ? 'LAB' : 'LECTURE']);
        }
        // Tuesday 8 Sep 2026 to Friday 25 Jun 2027.
        $calendar = $plugingen->create_calendar([
            'courseid' => $course->id,
            'blueprintid' => $blueprint->id,
            'startdate' => self::day('2026-09-08'),
            'enddate' => self::day('2027-06-25'),
        ]);
        // Make Friday a lab day.
        local_courseplanner_ensure_base_grid($calendar->id, $teacher->id);
        local_courseplanner_upsert_block($calendar->id, 0, 3, 'HEADER', 'Day C', $teacher->id, 'Friday', 'Lab');
        local_courseplanner_create_rule(
            $calendar->id,
            'NO_CLASS',
            self::day('2026-10-12'),
            'Thanksgiving',
            '',
            null,
            null,
            $teacher->id
        );
        local_courseplanner_create_rule(
            $calendar->id,
            'DAY_SWAP',
            self::day('2026-10-14'),
            '',
            '',
            'Wednesday',
            'Monday',
            $teacher->id
        );
        local_courseplanner_create_rule(
            $calendar->id,
            'OTHER',
            self::day('2027-01-20'),
            'Midterm',
            '',
            null,
            null,
            $teacher->id
        );

        $summary = local_courseplanner_apply_rules($calendar->id, $teacher->id);
        // Week of Mon 7 Sep 2026 to week of Mon 21 Jun 2027 inclusive.
        $this->assertSame(42, $summary['total_weeks']);
        $this->assertSame(1, $summary['noclass_placed']);

        $placed = local_courseplanner_auto_populate($calendar->id, $blueprint->id, $teacher->id);
        $this->assertGreaterThan(0, $placed['lectures']);
        $this->assertGreaterThan(0, $placed['labs']);

        $coverage = local_courseplanner_coverage_check($calendar->id, $blueprint->id);
        $this->assertNotEmpty($coverage['found']);

        $PAGE->set_url(new \moodle_url('/local/courseplanner/view.php'));
        $PAGE->set_context(\context_course::instance($course->id));
        $html = local_courseplanner_render_calendar_grid($calendar);
        $this->assertStringContainsString('Thanksgiving', $html);

        // Applying requires both ends of the year.
        $other = $plugingen->create_calendar(['courseid' => $course->id, 'blueprintid' => $blueprint->id,
            'startdate' => self::day('2027-09-07')]);
        $this->expectException(\moodle_exception::class);
        local_courseplanner_apply_rules($other->id, $teacher->id);
    }

    /**
     * The active calendar is recommended, then one covering the course start date, then the newest.
     */
    public function test_rank_calendars(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $teacher = $generator->create_user();
        $course = $generator->create_course(['startdate' => self::day('2026-09-01')]);
        $blueprint = $plugingen->create_blueprint(['owneruserid' => $teacher->id]);
        $lastyear = $plugingen->create_calendar(['courseid' => $course->id, 'blueprintid' => $blueprint->id,
            'title' => '2025-26', 'startdate' => self::day('2025-09-02'), 'enddate' => self::day('2026-06-26')]);
        $thisyear = $plugingen->create_calendar(['courseid' => $course->id, 'blueprintid' => $blueprint->id,
            'title' => '2026-27', 'startdate' => self::day('2026-09-08'), 'enddate' => self::day('2027-06-25')]);
        $draft = $plugingen->create_calendar(['courseid' => $course->id, 'blueprintid' => $blueprint->id,
            'title' => 'Draft', 'isactive' => 0]);
        $now = self::day('2027-02-01');

        // Only lastyear is active.
        $DB->set_field('local_courseplanner_calendars', 'isactive', 0, ['courseid' => $course->id]);
        $DB->set_field('local_courseplanner_calendars', 'isactive', 1, ['id' => $lastyear->id]);
        $calendars = local_courseplanner_get_course_calendars($course->id);
        [$sorted, $reason] = local_courseplanner_rank_calendars($course, $calendars, $now);
        $this->assertSame((int)$lastyear->id, (int)reset($sorted)->id);
        $this->assertSame('calendarrecommend_reason_active', $reason);

        // Nothing active: the one covering the course start date wins.
        $DB->set_field('local_courseplanner_calendars', 'isactive', 0, ['courseid' => $course->id]);
        $calendars = local_courseplanner_get_course_calendars($course->id);
        [$sorted, $reason] = local_courseplanner_rank_calendars($course, $calendars, $now);
        $this->assertSame((int)$thisyear->id, (int)reset($sorted)->id);
        $this->assertSame('calendarrecommend_reason_courseconfig', $reason);
        $this->assertCount(3, $sorted);

        // No dates anywhere: newest wins.
        $course->startdate = 0;
        $DB->delete_records('local_courseplanner_rules');
        $calendars = local_courseplanner_get_course_calendars($course->id);
        [$sorted, $reason] = local_courseplanner_rank_calendars($course, $calendars, $now);
        $this->assertSame((int)$draft->id, (int)reset($sorted)->id);
        $this->assertSame('calendarrecommend_reason_newest', $reason);

        $this->assertSame([[], ''], local_courseplanner_rank_calendars($course, [], $now));
    }

    /**
     * Deleting a course removes its planner data but keeps the teacher's blueprint.
     */
    public function test_course_deletion_cleans_up(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $teacher = $generator->create_user();
        $course = $generator->create_course();
        $keep = $generator->create_course();
        $blueprint = $plugingen->create_blueprint(['owneruserid' => $teacher->id]);
        $plugingen->create_topic(['blueprintid' => $blueprint->id]);
        foreach ([$course, $keep] as $c) {
            $calendar = $plugingen->create_calendar(['courseid' => $c->id, 'blueprintid' => $blueprint->id,
                'startdate' => self::day('2026-09-08'), 'enddate' => self::day('2026-12-18')]);
            local_courseplanner_apply_rules($calendar->id, $teacher->id);
            local_courseplanner_save_course_info($c->id, 'intro', 'links', $teacher->id);
        }

        delete_course($course, false);

        $this->assertFalse($DB->record_exists('local_courseplanner_calendars', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_courseplanner_courselink', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_courseplanner_courseinfo', ['courseid' => $course->id]));
        $this->assertSame(1, $DB->count_records('local_courseplanner_calendars'));
        $keepid = $DB->get_field('local_courseplanner_calendars', 'id', ['courseid' => $keep->id]);
        $this->assertSame(
            $DB->count_records('local_courseplanner_blocks'),
            $DB->count_records('local_courseplanner_blocks', ['calendarid' => $keepid])
        );
        $this->assertSame(
            $DB->count_records('local_courseplanner_rules'),
            $DB->count_records('local_courseplanner_rules', ['calendarid' => $keepid])
        );
        $this->assertTrue($DB->record_exists('local_courseplanner_blueprints', ['id' => $blueprint->id]));
    }
}
