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

namespace local_courseplanner\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Privacy provider tests.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_courseplanner\privacy\provider::class)]
final class provider_test extends provider_testcase {
    public function test_export_and_delete(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/courseplanner/locallib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $other = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $blueprint = $plugingen->create_blueprint(['owneruserid' => $teacher->id, 'name' => 'Mine']);
        $plugingen->create_topic(['blueprintid' => $blueprint->id, 'usermodified' => $teacher->id]);
        $plugingen->create_blueprint(['owneruserid' => $other->id, 'name' => 'Theirs']);
        $calendar = $plugingen->create_calendar(['courseid' => $course->id, 'blueprintid' => $blueprint->id,
            'startdate' => strtotime('2026-09-08'), 'enddate' => strtotime('2026-12-18')]);
        local_courseplanner_apply_rules($calendar->id, $teacher->id);

        $coursecontext = \context_course::instance($course->id);
        $usercontext = \context_user::instance($teacher->id);
        $contextids = provider::get_contexts_for_userid($teacher->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$coursecontext->id, $usercontext->id], $contextids);

        $userlist = new userlist($coursecontext, 'local_courseplanner');
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$teacher->id, $userlist->get_userids());

        $this->export_context_data_for_user($teacher->id, $usercontext, 'local_courseplanner');
        $this->assertTrue(writer::with_context($usercontext)->has_any_data());
        $this->export_context_data_for_user($teacher->id, $coursecontext, 'local_courseplanner');
        $this->assertTrue(writer::with_context($coursecontext)->has_any_data());

        // Deleting the teacher's data removes their blueprints and anonymises the shared course calendar.
        provider::delete_data_for_user(new approved_contextlist($teacher, 'local_courseplanner', $contextids));
        $this->assertFalse($DB->record_exists('local_courseplanner_blueprints', ['owneruserid' => $teacher->id]));
        $this->assertTrue($DB->record_exists('local_courseplanner_blueprints', ['owneruserid' => $other->id]));
        $this->assertTrue($DB->record_exists('local_courseplanner_calendars', ['id' => $calendar->id]));
        $this->assertFalse($DB->record_exists('local_courseplanner_blocks', ['usermodified' => $teacher->id]));

        // Deleting everyone in the course context removes the course's planner data.
        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertFalse($DB->record_exists('local_courseplanner_calendars', ['courseid' => $course->id]));
    }

    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $teacher = $generator->create_user();
        $plugingen->create_blueprint(['owneruserid' => $teacher->id]);
        $usercontext = \context_user::instance($teacher->id);
        provider::delete_data_for_users(new approved_userlist($usercontext, 'local_courseplanner', [$teacher->id]));
        $this->assertFalse($DB->record_exists('local_courseplanner_blueprints', ['owneruserid' => $teacher->id]));
    }
}
