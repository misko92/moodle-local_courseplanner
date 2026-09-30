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

/**
 * External web services.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External functions for local_courseplanner.
 */
class local_courseplanner_external extends external_api {
    // Builder batch-save.

    /**
     * Parameter definition for {@see save_builder_grid()}.
     *
     * @return external_function_parameters
     */
    public static function save_builder_grid_parameters(): external_function_parameters {
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
    public static function save_builder_grid(int $courseid, int $calendarid, array $blocks): array {
        global $USER;
        require_once(__DIR__ . '/locallib.php');

        $params = self::validate_parameters(self::save_builder_grid_parameters(), [
            'courseid' => $courseid,
            'calendarid' => $calendarid,
            'blocks' => $blocks,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        require_login($course);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $calendar = local_courseplanner_require_course_calendar($params['calendarid'], (int)$course->id);
        $blueprint = local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);

        $saved = 0;
        foreach ($params['blocks'] as $block) {
            $blocktype = strtoupper(trim($block['blocktype']));
            if (!in_array($blocktype, ['HEADER', 'TEXT', 'TOPIC'], true)) {
                continue;
            }
            $topicid = null;
            if ($blocktype === 'TOPIC' && !empty($block['topicid'])) {
                $topic = local_courseplanner_require_owned_topic((int)$block['topicid'], (int)$USER->id);
                if ((int)$topic->blueprintid !== (int)$blueprint->id) {
                    continue;
                }
                $topicid = (int)$block['topicid'];
            }
            $headerday = !empty($block['headerday']) ? trim($block['headerday']) : null;
            $headermode = !empty($block['headermode']) ? trim($block['headermode']) : null;
            local_courseplanner_upsert_block(
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
     * Return definition for {@see save_builder_grid()}.
     *
     * @return external_single_structure
     */
    public static function save_builder_grid_returns(): external_single_structure {
        return new external_single_structure([
            'saved' => new external_value(PARAM_INT, 'Number of blocks saved'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
        ]);
    }

    // Swap two cells.

    /**
     * Parameter definition for {@see swap_builder_cells()}.
     *
     * @return external_function_parameters
     */
    public static function swap_builder_cells_parameters(): external_function_parameters {
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
    public static function swap_builder_cells(
        int $courseid,
        int $calendarid,
        int $fromrow,
        int $fromcol,
        int $torow,
        int $tocol
    ): array {
        global $DB, $USER;
        require_once(__DIR__ . '/locallib.php');

        $params = self::validate_parameters(self::swap_builder_cells_parameters(), [
            'courseid' => $courseid, 'calendarid' => $calendarid,
            'fromrow' => $fromrow, 'fromcol' => $fromcol,
            'torow' => $torow, 'tocol' => $tocol,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        require_login($course);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $calendar = local_courseplanner_require_course_calendar($params['calendarid'], (int)$course->id);
        local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);

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
        if ($blocka && $blockb) {
            $tmprow = $blocka->rownum;
            $tmpcol = $blocka->colnum;
            $blocka->rownum = $blockb->rownum;
            $blocka->colnum = $blockb->colnum;
            $blocka->timemodified = $now;
            $blocka->usermodified = (int)$USER->id;
            $blockb->rownum = $tmprow;
            $blockb->colnum = $tmpcol;
            $blockb->timemodified = $now;
            $blockb->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_blocks', $blocka);
            $DB->update_record('local_courseplanner_blocks', $blockb);
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

        return ['status' => 'ok', 'message' => 'Cells swapped'];
    }

    /**
     * Return definition for {@see swap_builder_cells()}.
     *
     * @return external_single_structure
     */
    public static function swap_builder_cells_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }

    // Reorder blueprint topics (drag-and-drop).

    /**
     * Parameter definition for {@see reorder_blueprint_topics()}.
     *
     * @return external_function_parameters
     */
    public static function reorder_blueprint_topics_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID the request is being made from'),
            'blueprintid' => new external_value(PARAM_INT, 'Blueprint whose topics are being reordered'),
            'topicids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Topic ID'),
                'Topic IDs in their new order (first = sortorder 1)'
            ),
        ]);
    }

    /**
     * Persist a new sortorder for the given blueprint topics.
     *
     * @param int $courseid Course context the UI is running in (for capability checks).
     * @param int $blueprintid Blueprint the topics belong to.
     * @param int[] $topicids Topic IDs in the desired new order.
     * @return array{status: string, saved: int}
     */
    public static function reorder_blueprint_topics(int $courseid, int $blueprintid, array $topicids): array {
        global $DB, $USER;
        require_once(__DIR__ . '/locallib.php');

        $params = self::validate_parameters(self::reorder_blueprint_topics_parameters(), [
            'courseid' => $courseid,
            'blueprintid' => $blueprintid,
            'topicids' => $topicids,
        ]);

        $course = get_course($params['courseid']);
        $context = context_course::instance($course->id);
        require_login($course);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $blueprint = local_courseplanner_require_owned_blueprint((int)$params['blueprintid'], (int)$USER->id);

        $submittedids = array_values(array_map('intval', $params['topicids']));
        if (empty($submittedids)) {
            return ['status' => 'ok', 'saved' => 0];
        }
        if (count($submittedids) !== count(array_unique($submittedids))) {
            throw new moodle_exception('invalidrequest');
        }

        $existing = $DB->get_records(
            'local_courseplanner_topics',
            ['blueprintid' => (int)$blueprint->id],
            'sortorder ASC',
            'id, sortorder'
        );
        $existingids = array_map('intval', array_keys($existing));
        sort($existingids);
        $sortedsubmitted = $submittedids;
        sort($sortedsubmitted);
        if ($existingids !== $sortedsubmitted) {
            throw new moodle_exception('invalidrequest');
        }

        $transaction = $DB->start_delegated_transaction();
        $now = time();
        $order = 1;
        foreach ($submittedids as $topicid) {
            $DB->update_record('local_courseplanner_topics', (object)[
                'id' => $topicid,
                'sortorder' => $order,
                'timemodified' => $now,
                'usermodified' => (int)$USER->id,
            ]);
            $order++;
        }
        $transaction->allow_commit();

        return ['status' => 'ok', 'saved' => count($submittedids)];
    }

    /**
     * Return definition for {@see reorder_blueprint_topics()}.
     *
     * @return external_single_structure
     */
    public static function reorder_blueprint_topics_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'saved' => new external_value(PARAM_INT, 'Number of topics reordered'),
        ]);
    }
}
