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
 * Persist a new sortorder for blueprint topics (drag-and-drop).
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reorder_blueprint_topics extends external_api {
    /**
     * Parameter definition for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
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
    public static function execute(int $courseid, int $blueprintid, array $topicids): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'blueprintid' => $blueprintid,
            'topicids' => $topicids,
        ]);

        $course = get_course($params['courseid']);
        $context = \context_course::instance($course->id);
        self::validate_context($context);
        require_capability('local/courseplanner:manage', $context);

        $blueprint = \local_courseplanner\local\blueprints::require_owned((int)$params['blueprintid'], (int)$USER->id);

        $submittedids = array_values(array_map('intval', $params['topicids']));
        if (empty($submittedids)) {
            return ['status' => 'ok', 'saved' => 0];
        }
        if (count($submittedids) !== count(array_unique($submittedids))) {
            throw new \moodle_exception('invalidrequest');
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
            throw new \moodle_exception('invalidrequest');
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
     * Return definition for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'saved' => new external_value(PARAM_INT, 'Number of topics reordered'),
        ]);
    }
}
