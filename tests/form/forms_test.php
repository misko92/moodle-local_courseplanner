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

namespace local_courseplanner\form;

use local_courseplanner\local\grid;
use local_courseplanner\local\timeline;

/**
 * Tests for the pop-up forms, which are AJAX entry points and must check access themselves.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(cell_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(header_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(topic_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rule_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(course_info_form::class)]
final class forms_test extends \advanced_testcase {
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
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $plugingen = $generator->get_plugin_generator('local_courseplanner');
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->blueprint = $plugingen->create_blueprint(['owneruserid' => $this->teacher->id]);
        $this->calendar = $plugingen->create_calendar(['courseid' => $this->course->id, 'blueprintid' => $this->blueprint->id,
            'startdate' => strtotime('2026-09-08'), 'enddate' => strtotime('2026-12-18')]);
    }

    /**
     * Submit a dynamic form as the current user.
     *
     * @param string $class Form class.
     * @param array $data Submitted data (also the form arguments).
     * @return mixed Result of process_dynamic_submission().
     */
    private function submit(string $class, array $data) {
        $submitted = $class::mock_ajax_submit($data);
        $form = new $class(null, null, 'post', '', null, true, $submitted, true);
        $form->set_data_for_dynamic_submission();
        $this->assertTrue($form->is_validated(), json_encode($form->validation($data, [])));
        return $form->process_dynamic_submission();
    }

    public function test_cell_form_saves_and_clears(): void {
        global $DB;
        $this->setUser($this->teacher);
        $cell = ['calendarid' => $this->calendar->id, 'rownum' => 2, 'colnum' => 1];
        $this->submit(cell_form::class, $cell + ['blocktype' => 'TEXT', 'topicid' => 0,
            'content' => ['text' => '<p>Quiz</p>', 'format' => FORMAT_HTML], 'cellheading' => '', 'highlighted' => 1,
            'verticallycentred' => 0, 'clear' => 0]);
        $block = $DB->get_record('local_courseplanner_blocks', $cell);
        $this->assertSame('<p>Quiz</p>', $block->contenthtml);
        $this->assertEquals(1, $block->highlighted);

        $this->submit(cell_form::class, $cell + ['blocktype' => 'TEXT', 'topicid' => 0,
            'content' => ['text' => '<p>Quiz</p>', 'format' => FORMAT_HTML], 'cellheading' => '', 'highlighted' => 0,
            'verticallycentred' => 0, 'clear' => 1]);
        $this->assertFalse($DB->record_exists('local_courseplanner_blocks', $cell));
    }

    public function test_cell_form_rejects_another_teachers_topic(): void {
        $other = $this->getDataGenerator()->create_user();
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_courseplanner');
        $othertopic = $plugingen->create_topic(['blueprintid' => $plugingen->create_blueprint(['owneruserid' => $other->id])->id]);
        $this->setUser($this->teacher);
        $data = ['calendarid' => $this->calendar->id, 'rownum' => 2, 'colnum' => 1, 'blocktype' => 'TOPIC',
            'topicid' => $othertopic->id, 'cellheading' => '', 'highlighted' => 0, 'verticallycentred' => 0, 'clear' => 0];
        $this->expectException(\moodle_exception::class);
        $this->submit(cell_form::class, $data);
    }

    public function test_forms_refuse_other_teachers(): void {
        $coteacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($coteacher);
        $cases = [
            [cell_form::class, ['calendarid' => $this->calendar->id, 'rownum' => 1, 'colnum' => 1]],
            [header_form::class, ['calendarid' => $this->calendar->id, 'colnum' => 1]],
            [rule_form::class, ['calendarid' => $this->calendar->id]],
            [topic_form::class, ['courseid' => $this->course->id, 'blueprintid' => $this->blueprint->id]],
        ];
        foreach ($cases as [$class, $args]) {
            try {
                $form = new $class(null, null, 'post', '', null, true, $args, true);
                $form->set_data_for_dynamic_submission();
                $this->fail($class . ' allowed a teacher who does not own the blueprint');
            } catch (\moodle_exception $e) {
                $this->assertInstanceOf(\moodle_exception::class, $e);
            }
        }
    }

    public function test_student_cannot_edit_intro_texts(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        $this->submit(course_info_form::class, ['courseid' => $this->course->id,
            'intro' => ['text' => 'x', 'format' => FORMAT_HTML], 'links' => ['text' => '', 'format' => FORMAT_HTML]]);
    }

    public function test_header_form_sets_day_and_mode(): void {
        $this->setUser($this->teacher);
        $this->submit(header_form::class, ['calendarid' => $this->calendar->id, 'colnum' => 2,
            'content' => ['text' => 'Day B', 'format' => FORMAT_HTML], 'headerday' => 'Thursday', 'headermode' => 'Lab']);
        $header = grid::get_blocks_map((int)$this->calendar->id)[0][2];
        $this->assertSame('Thursday', $header->headerday);
        $this->assertSame('Lab', $header->headermode);
    }

    public function test_rule_form_refuses_rule_from_another_calendar(): void {
        $plugingen = $this->getDataGenerator()->get_plugin_generator('local_courseplanner');
        $othercourse = $this->getDataGenerator()->create_course();
        $other = $plugingen->create_calendar(['courseid' => $othercourse->id, 'blueprintid' => $this->blueprint->id,
            'startdate' => strtotime('2026-09-08')]);
        $otherrule = array_values(timeline::get_rules((int)$other->id))[0];
        $this->setUser($this->teacher);
        $this->expectException(\dml_missing_record_exception::class);
        $form = new rule_form(
            null,
            null,
            'post',
            '',
            null,
            true,
            ['calendarid' => $this->calendar->id, 'ruleid' => $otherrule->id],
            true
        );
        $form->set_data_for_dynamic_submission();
    }

    public function test_rule_form_stores_server_midnight_and_refuses_second_start(): void {
        $this->setUser($this->teacher);
        $this->submit(rule_form::class, ['calendarid' => $this->calendar->id, 'ruleid' => 0, 'ruletype' => 'NO_CLASS',
            'ruledate' => ['day' => 12, 'month' => 10, 'year' => 2026], 'label' => 'Thanksgiving', 'description' => '',
            'fromday' => 'Monday', 'today' => 'Monday']);
        $dates = array_map(static fn($r) => date('Y-m-d H:i', (int)$r->ruledate), timeline::get_rules((int)$this->calendar->id));
        $this->assertContains('2026-10-12 00:00', $dates);

        $data = rule_form::mock_ajax_submit(['calendarid' => $this->calendar->id, 'ruleid' => 0, 'ruletype' => 'START',
            'ruledate' => ['day' => 1, 'month' => 9, 'year' => 2026], 'label' => '', 'description' => '',
            'fromday' => 'Monday', 'today' => 'Monday']);
        $form = new rule_form(null, null, 'post', '', null, true, $data, true);
        $form->set_data_for_dynamic_submission();
        $this->assertFalse($form->is_validated());
    }
}
