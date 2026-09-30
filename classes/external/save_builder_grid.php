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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Batch-save calendar builder grid blocks.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_builder_grid extends external_api {
    /**
     * Parameter definition for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'calendarid' => new external_value(PARAM_INT, 'Calendar ID'),
            'blocks' => new external_multiple_structure(
                new external_single_structure([
                    'rownum' => new external_value(PARAM_INT, 'Row number'),
                    'colnum' => new external_value(PARAM_INT, 'Column number'),
                    'blocktype' => new external_value(PARAM_ALPHA, 'HEADER, TEXT, or TOPIC'),
                    'contenthtml' => new external_value(PARAM_RAW, 'HTML content', VALUE_DEFAULT, ''),
                    'topicid' => new external_value(PARAM_INT, 'Topic ID (0 for none)', VALUE_DEFAULT, 0),
                    'cellheading' => new external_value(PARAM_RAW, 'Cell heading', VALUE_DEFAULT, ''),
                    'headerday' => new external_value(PARAM_TEXT, 'Day of week for headers', VALUE_DEFAULT, ''),
                    'headermode' => new external_value(PARAM_TEXT, 'Lecture/Lab for headers', VALUE_DEFAULT, ''),
                    'highlighted' => new external_value(PARAM_INT, '1 if highlighted', VALUE_DEFAULT, 0),
                    'verticallycentred' => new external_value(PARAM_INT, '1 if vertically centred', VALUE_DEFAULT, 0),
                ])
            ),
        ]);
    }

    /**
     * Persist the builder grid blocks for the given course calendar.
     *
     * @param int $courseid Course ID the builder is running in.
     * @param int $calendarid Calendar record being edited.
     * @param array $blocks Block definitions keyed by row/column.
     * @return array{saved: int, status: string}
     */
    public static function execute(int $courseid, int $calendarid, array $blocks): array {
        global $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'calendarid' => $calendarid,
            'blocks' => $blocks,
        ]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $calendar = \local_courseplanner\local\calendars::require_in_course($params['calendarid'], (int)$course->id);
        $blueprint = \local_courseplanner\local\blueprints::require_owned((int)$calendar->blueprintid, (int)$USER->id);

        $saved = 0;
        foreach ($params['blocks'] as $block) {
            $blocktype = strtoupper(trim($block['blocktype']));
            if (!in_array($blocktype, ['HEADER', 'TEXT', 'TOPIC'], true)) {
                continue;
            }
            $topicid = null;
            if ($blocktype === 'TOPIC' && !empty($block['topicid'])) {
                $topic = \local_courseplanner\local\topics::require_owned((int)$block['topicid'], (int)$USER->id);
                if ((int)$topic->blueprintid !== (int)$blueprint->id) {
                    continue;
                }
                $topicid = (int)$block['topicid'];
            }
            $headerday = !empty($block['headerday']) ? trim($block['headerday']) : null;
            $headermode = !empty($block['headermode']) ? trim($block['headermode']) : null;
            \local_courseplanner\local\grid::upsert_block(
                (int)$calendar->id,
                (int)$block['rownum'],
                (int)$block['colnum'],
                $blocktype,
                trim($block['contenthtml']),
                (int)$USER->id,
                $headerday,
                $headermode,
                $topicid,
                trim($block['cellheading']),
                (int)$block['highlighted'],
                (int)$block['verticallycentred']
            );
            $saved++;
        }

        return ['saved' => $saved, 'status' => 'ok'];
    }

    /**
     * Return definition for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_INT, 'Number of blocks saved'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
        ]);
    }
}
