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

use core_text;
use html_writer;
use moodle_exception;
use stdClass;

/**
 * Topics within a blueprint: types, ordering and lookups.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topics {
    /**
     * Valid topic types for blueprint topics.
     *
     * @return string[]
     */
    public static function get_types(): array {
        return ['LECTURE', 'LAB', 'ELESSON', 'TEST', 'HOMEWORK'];
    }

    /**
     * Validate and normalize topic type.
     *
     * @param string $type
     * @return string
     */
    public static function normalise_type(string $type): string {
        $type = core_text::strtoupper(trim($type));
        if (!in_array($type, self::get_types(), true)) {
            throw new moodle_exception('invalidtopictype', 'local_courseplanner');
        }
        return $type;
    }

    /**
     * Topic types whose title/badge heading is hidden in the calendar display.
     *
     * Lectures and labs are shown as their content only (matching the clean weekly
     * grid). Their content already leads with the relevant heading ("Pre-class
     * reading", "Lab N - ...") so a separate badge + title is redundant noise.
     *
     * @param string $type Topic type code.
     * @return bool True when the heading should be suppressed.
     */
    public static function heading_is_hidden(string $type): bool {
        return in_array(core_text::strtoupper($type), ['LECTURE', 'LAB'], true);
    }

    /**
     * Build the heading line (type badge + title) shown above a placed topic's content.
     *
     * Returns an empty string for topic types whose heading is hidden (see
     * {@see self::heading_is_hidden()}), unless a $suffix is
     * supplied (e.g. an "inactive" flag in the builder) which must always be shown.
     *
     * @param stdClass $topic Topic record (uses ->type and ->title).
     * @param string $suffix Optional trailing HTML always rendered when present.
     * @return string HTML for the heading div, or '' when nothing should be shown.
     */
    public static function heading_html(stdClass $topic, string $suffix = ''): string {
        $type = (string)$topic->type;

        // Lecture/lab content normally carries its own title; show the title only when there is no content.
        if (self::heading_is_hidden($type) && trim((string)($topic->contenthtml ?? '')) !== '') {
            if (trim($suffix) === '') {
                return '';
            }
            return html_writer::tag('div', $suffix, ['class' => 'local-courseplanner-topic-display']);
        }

        $badge = html_writer::tag('span', s($type), [
            'class' => 'local-courseplanner-type-badge local-courseplanner-type-' . strtolower($type),
        ]);
        return html_writer::tag(
            'div',
            $badge . ' ' . format_string($topic->title) . $suffix,
            ['class' => 'local-courseplanner-topic-display']
        );
    }

    /**
     * Get ordered topics for a blueprint.
     *
     * @param int $blueprintid
     * @param bool $includeinactive
     * @return array
     */
    public static function get_for_blueprint(int $blueprintid, bool $includeinactive = true): array {
        global $DB;

        $conditions = ['blueprintid' => $blueprintid];
        if (!$includeinactive) {
            $conditions['isactive'] = 1;
        }

        return $DB->get_records(
            'local_courseplanner_topics',
            $conditions,
            'sortorder ASC, id ASC'
        );
    }

    /**
     * Ensure topic belongs to a blueprint owned by the user.
     *
     * @param int $topicid
     * @param int $userid
     * @return stdClass
     */
    public static function require_owned(int $topicid, int $userid): stdClass {
        global $DB;

        $topic = $DB->get_record('local_courseplanner_topics', ['id' => $topicid], '*', MUST_EXIST);
        blueprints::require_owned((int)$topic->blueprintid, $userid);
        return $topic;
    }

    /**
     * Keep sortorder contiguous for a blueprint.
     *
     * @param int $blueprintid
     * @return void
     */
    public static function normalise_sortorder(int $blueprintid): void {
        global $DB;

        $topics = self::get_for_blueprint($blueprintid, true);
        $changed = [];
        $sort = 1;
        foreach ($topics as $topic) {
            if ((int)$topic->sortorder !== $sort) {
                $changed[] = [
                    'topic' => $topic,
                    'sortorder' => $sort,
                ];
            }
            $sort++;
        }

        if (empty($changed)) {
            return;
        }

        foreach ($changed as $index => $item) {
            $item['topic']->sortorder = 100000 + $index;
            $DB->update_record('local_courseplanner_topics', $item['topic']);
        }

        foreach ($changed as $item) {
            $item['topic']->sortorder = $item['sortorder'];
            $DB->update_record('local_courseplanner_topics', $item['topic']);
        }
    }

    /**
     * Move topic by one step within blueprint ordering.
     *
     * @param stdClass $topic
     * @param int $direction -1 for up, +1 for down
     * @return bool
     */
    public static function move(stdClass $topic, int $direction): bool {
        global $DB;

        $topics = array_values(self::get_for_blueprint((int)$topic->blueprintid, true));
        $index = null;
        foreach ($topics as $i => $item) {
            if ((int)$item->id === (int)$topic->id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return false;
        }

        $targetindex = $index + $direction;
        if ($targetindex < 0 || $targetindex >= count($topics)) {
            return false;
        }

        $tmp = $topics[$index];
        $topics[$index] = $topics[$targetindex];
        $topics[$targetindex] = $tmp;

        $changed = [];
        $sort = 1;
        foreach ($topics as $item) {
            if ((int)$item->sortorder !== $sort) {
                $changed[] = [
                    'topic' => $item,
                    'sortorder' => $sort,
                ];
            }
            $sort++;
        }

        if (empty($changed)) {
            return true;
        }

        foreach ($changed as $i => $item) {
            $item['topic']->sortorder = 100000 + $i;
            $DB->update_record('local_courseplanner_topics', $item['topic']);
        }

        foreach ($changed as $item) {
            $item['topic']->sortorder = $item['sortorder'];
            $DB->update_record('local_courseplanner_topics', $item['topic']);
        }

        return true;
    }

    /**
     * Return calendar usage rows for a topic.
     *
     * @param int $topicid
     * @return array
     */
    public static function get_usage_rows(int $topicid): array {
        global $DB;

        $sql = "SELECT sc.id, sc.courseid, sc.title
                  FROM {local_courseplanner_blocks} cb
                  JOIN {local_courseplanner_calendars} sc
                    ON sc.id = cb.calendarid
                 WHERE cb.topicid = :topicid
              GROUP BY sc.id, sc.courseid, sc.title
              ORDER BY sc.id DESC";

        return $DB->get_records_sql($sql, ['topicid' => $topicid]);
    }

    /**
     * Return the next available sort order for a topic in the given blueprint.
     *
     * @param int $blueprintid Blueprint ID.
     * @return int Next sortorder to use.
     */
    public static function next_sortorder(int $blueprintid): int {
        global $DB;
        $max = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), -1) FROM {local_courseplanner_topics} WHERE blueprintid = :bpid',
            ['bpid' => $blueprintid]
        );
        return $max + 1;
    }

    /**
     * Delete all topics for a blueprint (with optional force flag to bypass calendar reference check).
     *
     * @param int $blueprintid
     * @param bool $force
     * @return int Number of deleted topics.
     */
    public static function delete_all(int $blueprintid, bool $force = false): int {
        global $DB;
        if (!$force) {
            $referenced = $DB->count_records_select(
                'local_courseplanner_blocks',
                "topicid IN (SELECT id FROM {local_courseplanner_topics} WHERE blueprintid = :bpid) AND blocktype = 'TOPIC'",
                ['bpid' => $blueprintid]
            );
            if ($referenced > 0) {
                return -1;
            }
        }
        $count = $DB->count_records('local_courseplanner_topics', ['blueprintid' => $blueprintid]);
        $DB->delete_records('local_courseplanner_topics', ['blueprintid' => $blueprintid]);
        return $count;
    }
}
