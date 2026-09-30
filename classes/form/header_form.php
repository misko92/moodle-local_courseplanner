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

use context;
use core_form\dynamic_form;
use local_courseplanner\local\calendars;
use local_courseplanner\local\grid;
use moodle_url;

/**
 * Pop-up form for editing a column heading in the calendar grid.
 *
 * Teaching-day columns (1-3) also choose a weekday and Lecture/Lab mode; other columns are a plain heading.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class header_form extends dynamic_form {
    /**
     * Whether the column being edited is a teaching-day column.
     *
     * @return bool
     */
    protected function is_day_column(): bool {
        $col = $this->optional_param('colnum', 0, PARAM_INT);
        return $col >= 1 && $col <= 3;
    }

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['calendarid', 'colnum'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement(
            'editor',
            'content',
            get_string('columnheading', 'local_courseplanner'),
            ['rows' => 3],
            cell_form::editor_options($this->get_context_for_dynamic_submission())
        );
        $mform->setType('content', PARAM_RAW);
        if ($this->is_day_column()) {
            $mform->addElement(
                'select',
                'headerday',
                get_string('columnday', 'local_courseplanner'),
                array_combine(grid::HEADER_DAYS, grid::HEADER_DAYS)
            );
            $mform->addElement(
                'select',
                'headermode',
                get_string('columnmode', 'local_courseplanner'),
                array_combine(grid::HEADER_MODES, grid::HEADER_MODES)
            );
        }
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        [, , $context] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        return $context;
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        global $DB;
        $calendarid = $this->optional_param('calendarid', 0, PARAM_INT);
        $colnum = $this->optional_param('colnum', 0, PARAM_INT);
        $block = $DB->get_record('local_courseplanner_blocks', ['calendarid' => $calendarid, 'rownum' => 0, 'colnum' => $colnum]);
        $this->set_data([
            'calendarid' => $calendarid,
            'colnum' => $colnum,
            'content' => ['text' => (string)($block->contenthtml ?? ''), 'format' => FORMAT_HTML],
            'headerday' => $block->headerday ?? ([1 => 'Monday', 2 => 'Wednesday', 3 => 'Friday'][$colnum] ?? 'Monday'),
            'headermode' => $block->headermode ?? 'Lecture',
        ]);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        [$calendar] = calendars::require_editable((int)$data->calendarid);
        $isday = $this->is_day_column();
        grid::upsert_block(
            (int)$calendar->id,
            0,
            (int)$data->colnum,
            'HEADER',
            trim($data->content['text']),
            (int)$USER->id,
            $isday ? $data->headerday : null,
            $isday ? $data->headermode : null
        );
        return ['message' => get_string('headercellsaved', 'local_courseplanner')];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        [$calendar] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        return new moodle_url('/local/courseplanner/calendar.php', ['id' => $calendar->courseid, 'calendarid' => $calendar->id]);
    }
}
