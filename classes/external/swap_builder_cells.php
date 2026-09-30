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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Swap or move two calendar builder cells.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class swap_builder_cells extends external_api {
    /**
     * Parameter definition for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'calendarid' => new external_value(PARAM_INT, 'Calendar ID'),
            'fromrow' => new external_value(PARAM_INT, 'Source row'),
            'fromcol' => new external_value(PARAM_INT, 'Source column'),
            'torow' => new external_value(PARAM_INT, 'Destination row'),
            'tocol' => new external_value(PARAM_INT, 'Destination column'),
        ]);
    }

    /**
     * Swap the contents of two builder cells within the same course calendar.
     *
     * @param int $courseid Course ID for capability checks.
     * @param int $calendarid Calendar being edited.
     * @param int $fromrow Source row number.
     * @param int $fromcol Source column number.
     * @param int $torow Destination row number.
     * @param int $tocol Destination column number.
     * @return array{status: string, message: string}
     */
    public static function execute(
        int $courseid,
        int $calendarid,
        int $fromrow,
        int $fromcol,
        int $torow,
        int $tocol
    ): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'calendarid' => $calendarid,
            'fromrow' => $fromrow, 'fromcol' => $fromcol,
            'torow' => $torow, 'tocol' => $tocol,
        ]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $calendar = \local_courseplanner\local\calendars::require_in_course($params['calendarid'], (int)$course->id);
        \local_courseplanner\local\blueprints::require_owned((int)$calendar->blueprintid, (int)$USER->id);

        if (
            $params['fromrow'] <= 0 || $params['torow'] <= 0 ||
            $params['fromcol'] < 1 || $params['fromcol'] > 4 ||
            $params['tocol'] < 1 || $params['tocol'] > 4
        ) {
            return ['status' => 'error', 'message' => 'Invalid cell coordinates'];
        }

        $calid = (int)$calendar->id;
        $blocka = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calid, 'rownum' => $params['fromrow'], 'colnum' => $params['fromcol'],
        ], '*', IGNORE_MISSING);
        $blockb = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calid, 'rownum' => $params['torow'], 'colnum' => $params['tocol'],
        ], '*', IGNORE_MISSING);

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        if ($blocka && $blockb) {
            // Cells are unique per calendar/row/column, so park A on a free row before B takes its place.
            $DB->set_field('local_courseplanner_blocks', 'rownum', -1, ['id' => $blocka->id]);
            $tmprow = $blocka->rownum;
            $tmpcol = $blocka->colnum;
            $blockb->rownum = $tmprow;
            $blockb->colnum = $tmpcol;
            $blockb->timemodified = $now;
            $blockb->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_blocks', $blockb);
            $blocka->rownum = $params['torow'];
            $blocka->colnum = $params['tocol'];
            $blocka->timemodified = $now;
            $blocka->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_blocks', $blocka);
        } else if ($blocka) {
            $blocka->rownum = $params['torow'];
            $blocka->colnum = $params['tocol'];
            $blocka->timemodified = $now;
            $blocka->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_blocks', $blocka);
        } else if ($blockb) {
            $blockb->rownum = $params['fromrow'];
            $blockb->colnum = $params['fromcol'];
            $blockb->timemodified = $now;
            $blockb->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_blocks', $blockb);
        }
        $transaction->allow_commit();

        return ['status' => 'ok', 'message' => 'Cells swapped'];
    }

    /**
     * Return definition for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
