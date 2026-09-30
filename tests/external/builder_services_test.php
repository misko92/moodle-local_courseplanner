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

namespace local_courseplanner\external;

use core_external\external_api;

/**
 * Tests for the builder web services.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_courseplanner\external\save_builder_grid::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_courseplanner\external\swap_builder_cells::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_courseplanner\external\reorder_blueprint_topics::class)]
final class builder_services_test extends \advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;
    /** @var \stdClass */
    private \stdClass $teacher;
    /** @var \stdClass */
    private \stdClass $blueprint;
    /** @var \stdClass */
    private \stdClass $calendar;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/courseplanner/locallib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->blueprint = $plugingen->create_blueprint(['owneruserid' => $this->teacher->id]);
        $this->calendar = $plugingen->create_calendar(['courseid' => $this->course->id,
            'blueprintid' => $this->blueprint->id]);
        $this->setUser($this->teacher);
    }

    /**
     * Put a TEXT block in a cell.
     *
     * @param int $row
     * @param int $col
     * @param string $text
     */
    private function put(int $row, int $col, string $text): void {
        local_courseplanner_upsert_block((int)$this->calendar->id, $row, $col, 'TEXT', $text, (int)$this->teacher->id);
    }

    /**
     * Text in a cell, or null if empty.
     *
     * @param int $row
     * @param int $col
     * @return string|null
     */
    private function cell(int $row, int $col): ?string {
        global $DB;
        $text = $DB->get_field(
            'local_courseplanner_blocks',
            'contenthtml',
            ['calendarid' => $this->calendar->id, 'rownum' => $row, 'colnum' => $col]
        );
        return $text === false ? null : $text;
    }

    public function test_save_builder_grid(): void {
        $result = save_builder_grid::execute($this->course->id, $this->calendar->id, [
            ['rownum' => 1, 'colnum' => 1, 'blocktype' => 'TEXT', 'contenthtml' => 'Hello'],
            ['rownum' => 1, 'colnum' => 2, 'blocktype' => 'BOGUS', 'contenthtml' => 'Ignored'],
        ]);
        $result = external_api::clean_returnvalue(save_builder_grid::execute_returns(), $result);
        $this->assertSame(1, $result['saved']);
        $this->assertSame('Hello', $this->cell(1, 1));
        $this->assertNull($this->cell(1, 2));
    }

    public function test_swap_two_occupied_cells(): void {
        $this->put(1, 1, 'A');
        $this->put(2, 3, 'B');
        $result = swap_builder_cells::execute($this->course->id, $this->calendar->id, 1, 1, 2, 3);
        $result = external_api::clean_returnvalue(swap_builder_cells::execute_returns(), $result);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('B', $this->cell(1, 1));
        $this->assertSame('A', $this->cell(2, 3));
    }

    public function test_move_into_empty_cell(): void {
        $this->put(1, 1, 'A');
        swap_builder_cells::execute($this->course->id, $this->calendar->id, 1, 1, 3, 2);
        $this->assertNull($this->cell(1, 1));
        $this->assertSame('A', $this->cell(3, 2));
    }

    public function test_reorder_blueprint_topics(): void {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_courseplanner');
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = (int)$plugingen->create_topic(['blueprintid' => $this->blueprint->id])->id;
        }
        // Drag the first topic down one place.
        $neworder = [$ids[1], $ids[0], $ids[2], $ids[3]];
        $result = reorder_blueprint_topics::execute($this->course->id, $this->blueprint->id, $neworder);
        $result = external_api::clean_returnvalue(reorder_blueprint_topics::execute_returns(), $result);
        $this->assertSame(4, $result['saved']);
        $this->assertSame(
            $neworder,
            array_map('intval', array_keys(local_courseplanner_get_blueprint_topics((int)$this->blueprint->id)))
        );

        // A list that doesn't match the blueprint's topics is refused.
        $this->expectException(\moodle_exception::class);
        reorder_blueprint_topics::execute($this->course->id, $this->blueprint->id, [$ids[0], $ids[1]]);
    }

    public function test_students_cannot_edit(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        save_builder_grid::execute($this->course->id, $this->calendar->id, []);
    }

    public function test_other_teachers_cannot_edit_blueprint_they_do_not_own(): void {
        $coteacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($coteacher);
        $this->expectException(\moodle_exception::class);
        swap_builder_cells::execute($this->course->id, $this->calendar->id, 1, 1, 2, 2);
    }
}
