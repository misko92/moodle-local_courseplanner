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
use stdClass;

/**
 * The week-by-week grid of blocks that makes up a calendar.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grid {
    /** @var string[] Weekdays a teaching-day column can be set to. */
    public const HEADER_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    /** @var string[] Session modes a teaching-day column can be set to. */
    public const HEADER_MODES = ['Lecture', 'Lab'];

    /**
     * Get calendar blocks indexed by row/col.
     *
     * @param int $calendarid
     * @return array
     */
    public static function get_blocks_map(int $calendarid): array {
        global $DB;

        $records = $DB->get_records(
            'local_courseplanner_blocks',
            ['calendarid' => $calendarid],
            'rownum ASC, colnum ASC, id ASC'
        );
        $map = [];
        foreach ($records as $record) {
            $row = (int)$record->rownum;
            $col = (int)$record->colnum;
            if (!isset($map[$row])) {
                $map[$row] = [];
            }
            $map[$row][$col] = $record;
        }
        return $map;
    }

    /**
     * Upsert a calendar block.
     *
     * @param int $calendarid
     * @param int $rownum
     * @param int $colnum
     * @param string $blocktype
     * @param string $contenthtml
     * @param int $userid
     * @param string|null $headerday
     * @param string|null $headermode
     * @param int|null $topicid
     * @param string|null $cellheading
     * @param int $highlighted
     * @param int $verticallycentred
     * @return void
     */
    public static function upsert_block(
        int $calendarid,
        int $rownum,
        int $colnum,
        string $blocktype,
        string $contenthtml,
        int $userid,
        ?string $headerday = null,
        ?string $headermode = null,
        ?int $topicid = null,
        ?string $cellheading = null,
        int $highlighted = 0,
        int $verticallycentred = 0
    ): void {
        global $DB;

        $now = time();
        $record = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => $colnum,
        ], '*', IGNORE_MISSING);

        if ($record) {
            $record->blocktype = $blocktype;
            $record->contenthtml = $contenthtml;
            $record->headerday = $headerday;
            $record->headermode = $headermode;
            $record->topicid = $topicid;
            $record->cellheading = $cellheading;
            $record->highlighted = $highlighted;
            $record->verticallycentred = $verticallycentred;
            $record->timemodified = $now;
            $record->usermodified = $userid;
            $DB->update_record('local_courseplanner_blocks', $record);
            return;
        }

        $insert = (object)[
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => $colnum,
            'blocktype' => $blocktype,
            'topicid' => $topicid,
            'contenthtml' => $contenthtml,
            'cellheading' => $cellheading,
            'headerday' => $headerday,
            'headermode' => $headermode,
            'highlighted' => $highlighted,
            'verticallycentred' => $verticallycentred,
            'generatedbyrule' => 0,
            'generatedruleid' => null,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ];
        $DB->insert_record('local_courseplanner_blocks', $insert);
    }

    /**
     * Ensure base header row exists for a calendar.
     *
     * @param int $calendarid
     * @param int $userid
     * @return void
     */
    public static function ensure_base(int $calendarid, int $userid): void {
        global $DB;

        // Only seed the default header row for a brand-new calendar. Once any header
        // cell exists we respect the current set of columns so teacher-deleted columns
        // are not silently recreated on the next page load.
        $hasheader = $DB->record_exists('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => 0,
        ]);
        if ($hasheader) {
            return;
        }

        $defaults = [
            0 => ['content' => 'Week # / Week of', 'day' => null, 'mode' => null],
            1 => ['content' => 'Day A', 'day' => 'Monday', 'mode' => 'Lecture'],
            2 => ['content' => 'Day B', 'day' => 'Wednesday', 'mode' => 'Lecture'],
            3 => ['content' => 'Day C', 'day' => 'Friday', 'mode' => 'Lecture'],
            4 => ['content' => 'Assignments and Problem Sets', 'day' => null, 'mode' => null],
        ];

        foreach ($defaults as $col => $config) {
            self::upsert_block(
                $calendarid,
                0,
                $col,
                'HEADER',
                $config['content'],
                $userid,
                $config['day'],
                $config['mode']
            );
        }
    }

    /**
     * Determine the ordered set of grid columns for a calendar based on the header row.
     *
     * The week-label column (0) is always present. Other columns exist only while a
     * header cell exists for them, so deleting a column removes it from every page.
     *
     * @param array $blocksmap Block map keyed by [rownum][colnum].
     * @return int[] Sorted list of column numbers.
     */
    public static function get_columns(array $blocksmap): array {
        if (empty($blocksmap[0]) || !is_array($blocksmap[0])) {
            return [0, 1, 2, 3, 4];
        }
        $cols = array_map('intval', array_keys($blocksmap[0]));
        if (!in_array(0, $cols, true)) {
            $cols[] = 0;
        }
        sort($cols);
        return $cols;
    }

    /**
     * Delete an entire grid column (header and all week-row cells) from a calendar.
     *
     * The week-label column (0) cannot be deleted.
     *
     * @param int $calendarid
     * @param int $colnum
     * @return bool True if the column existed and was deleted.
     */
    public static function delete_column(int $calendarid, int $colnum): bool {
        global $DB;

        if ($colnum < 1) {
            return false;
        }

        $exists = $DB->record_exists('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'colnum' => $colnum,
        ]);
        if (!$exists) {
            return false;
        }

        $DB->delete_records('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'colnum' => $colnum,
        ]);
        return true;
    }

    /**
     * Append a new week row with a default week label.
     *
     * @param int $calendarid
     * @param int $userid
     * @return int
     */
    public static function add_week_row(int $calendarid, int $userid): int {
        global $DB;

        $maxrow = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(rownum), 0) FROM {local_courseplanner_blocks} WHERE calendarid = :calendarid',
            ['calendarid' => $calendarid]
        );
        $newrow = max(1, $maxrow + 1);
        self::upsert_block($calendarid, $newrow, 0, 'TEXT', 'Week ' . $newrow, $userid);
        return $newrow;
    }

    /**
     * Remove last week row and all its blocks.
     *
     * @param int $calendarid
     * @return bool
     */
    public static function remove_last_week_row(int $calendarid): bool {
        global $DB;

        $maxrow = (int)$DB->get_field_sql(
            'SELECT COALESCE(MAX(rownum), 0) FROM {local_courseplanner_blocks} WHERE calendarid = :calendarid',
            ['calendarid' => $calendarid]
        );

        if ($maxrow <= 0) {
            return false;
        }

        $DB->delete_records('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => $maxrow,
        ]);
        return true;
    }

    /**
     * Delete all non-header blocks (rownum > 0).
     *
     * @param int $calendarid
     * @return int Number of deleted blocks.
     */
    public static function delete_non_header_blocks(int $calendarid): int {
        global $DB;
        $count = $DB->count_records_select(
            'local_courseplanner_blocks',
            'calendarid = :cid AND rownum > 0',
            ['cid' => $calendarid]
        );
        $DB->delete_records_select(
            'local_courseplanner_blocks',
            'calendarid = :cid AND rownum > 0',
            ['cid' => $calendarid]
        );
        return $count;
    }

    /**
     * Delete TOPIC blocks and "Problem Session" TEXT blocks, preserving week labels and other text.
     *
     * @param int $calendarid
     * @return int Number of deleted blocks.
     */
    public static function delete_non_header_non_text_blocks(int $calendarid): int {
        global $DB;

        $blocks = $DB->get_records_select(
            'local_courseplanner_blocks',
            'calendarid = :cid AND rownum > 0',
            ['cid' => $calendarid]
        );

        $deleted = 0;
        foreach ($blocks as $block) {
            $shoulddelete = false;
            if ((string)$block->blocktype === 'TOPIC') {
                $shoulddelete = true;
            } else if ((string)$block->blocktype === 'TEXT' && trim((string)$block->contenthtml) === 'Problem Session') {
                $shoulddelete = true;
            }
            if ($shoulddelete) {
                $DB->delete_records('local_courseplanner_blocks', ['id' => $block->id]);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Find the grid cell for a date, for "today" highlighting.
     *
     * Week rows are numbered from the first day of classes, as {@see timeline::apply()} generates them. Without a
     * first day of classes, each row's week is read from its label ("Week 3<br/>Sep 21"), trying the years either
     * side of the date so labels in a school year that spans New Year resolve correctly.
     *
     * @param array $blocksmap Blocks keyed by [row][col].
     * @param int $maxrow Last week row.
     * @param int $timestamp The date to find.
     * @param int|null $startdate First day of classes, if known.
     * @return array|null ['row' => int, 'col' => ?int] for the date's week (col when a column is that weekday), or
     *     ['row' => int, 'col' => null, 'nearest' => true] for the nearest week, or null if no row has a week.
     */
    public static function date_to_cell(array $blocksmap, int $maxrow, int $timestamp, ?int $startdate = null): ?array {
        $daymap = ['monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3, 'friday' => 4, 'saturday' => 5,
            'sunday' => 6];

        // Weekday offset of each teaching-day column.
        $coloffsets = [];
        foreach ($blocksmap[0] ?? [] as $col => $header) {
            $dayname = core_text::strtolower((string)($header->headerday ?? ''));
            if (isset($daymap[$dayname])) {
                $coloffsets[$col] = $daymap[$dayname];
            }
        }

        $targetdate = strtotime(date('Y-m-d', $timestamp));
        $targetmonday = timeline::get_week_monday($targetdate);

        $rowmondays = [];
        if ($startdate) {
            $firstmonday = timeline::get_week_monday($startdate);
            for ($row = 1; $row <= $maxrow; $row++) {
                $rowmondays[$row] = strtotime('+' . ($row - 1) . ' weeks', $firstmonday);
            }
        } else {
            $year = (int)date('Y', $timestamp);
            for ($row = 1; $row <= $maxrow; $row++) {
                $label = strip_tags((string)($blocksmap[$row][0]->contenthtml ?? ''));
                if (!preg_match('/([A-Z][a-z]{2})\s+(\d{1,2})/', $label, $m)) {
                    continue;
                }
                $best = null;
                foreach ([$year - 1, $year, $year + 1] as $candidate) {
                    $parsed = strtotime($m[1] . ' ' . $m[2] . ' ' . $candidate);
                    if ($parsed && ($best === null || abs($parsed - $targetdate) < abs($best - $targetdate))) {
                        $best = $parsed;
                    }
                }
                if ($best) {
                    $rowmondays[$row] = timeline::get_week_monday($best);
                }
            }
        }

        foreach ($rowmondays as $row => $monday) {
            if ($monday === $targetmonday) {
                foreach ($coloffsets as $col => $offset) {
                    if (strtotime('+' . $offset . ' days', $monday) === $targetdate) {
                        return ['row' => $row, 'col' => $col];
                    }
                }
                return ['row' => $row, 'col' => null];
            }
        }

        $nearestrow = null;
        $nearestdiff = PHP_INT_MAX;
        foreach ($rowmondays as $row => $monday) {
            $diff = abs($monday - $targetmonday);
            if ($diff < $nearestdiff) {
                $nearestdiff = $diff;
                $nearestrow = $row;
            }
        }
        return $nearestrow === null ? null : ['row' => $nearestrow, 'col' => null, 'nearest' => true];
    }
}
