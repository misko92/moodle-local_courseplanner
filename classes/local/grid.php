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
use stdClass;

/**
 * The week-by-week grid of blocks that makes up a calendar.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grid {
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
     * Map a date to a grid cell position using the week-label structure and header days.
     *
     * @param array $blocksmap Block grid keyed [row][col] of block records.
     * @param int $maxrow Highest row number present in the grid.
     * @param int $timestamp Unix timestamp of the date to locate.
     * @return array|null ['row' => int, 'col' => int] or null if not found.
     */
    public static function date_to_cell(array $blocksmap, int $maxrow, int $timestamp): ?array {
        $daymap = ['monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3, 'friday' => 4, 'saturday' => 5, 'sunday' => 6];

        // Build header day offsets for cols 1-3.
        $coloffsets = [];
        for ($c = 1; $c <= 3; $c++) {
            $h = $blocksmap[0][$c] ?? null;
            if ($h && !empty($h->headerday)) {
                $dayname = core_text::strtolower((string)$h->headerday);
                if (isset($daymap[$dayname])) {
                    $coloffsets[$c] = $daymap[$dayname];
                }
            }
        }

        // Parse week mondays from col-0 labels.
        $rowmondays = [];
        for ($row = 1; $row <= $maxrow; $row++) {
            $cell = $blocksmap[$row][0] ?? null;
            if (!$cell) {
                continue;
            }
            $content = strip_tags((string)$cell->contenthtml);
            if (preg_match('/(\w{3})\s+(\d{1,2})/', $content, $m)) {
                $parsed = strtotime($m[1] . ' ' . $m[2] . ' ' . date('Y', $timestamp));
                if ($parsed) {
                    $rowmondays[$row] = timeline::get_week_monday($parsed);
                }
            }
        }

        $targetdate = strtotime(date('Y-m-d', $timestamp));
        $targetmonday = timeline::get_week_monday($targetdate);

        foreach ($rowmondays as $row => $monday) {
            if ($monday === $targetmonday) {
                foreach ($coloffsets as $col => $offset) {
                    $celldate = strtotime('+' . $offset . ' days', $monday);
                    if ($celldate === $targetdate) {
                        return ['row' => $row, 'col' => $col];
                    }
                }
                return ['row' => $row, 'col' => null];
            }
        }

        // Find nearest row.
        $nearestrow = null;
        $nearestdiff = PHP_INT_MAX;
        foreach ($rowmondays as $row => $monday) {
            $diff = abs($monday - $targetmonday);
            if ($diff < $nearestdiff) {
                $nearestdiff = $diff;
                $nearestrow = $row;
            }
        }

        if ($nearestrow !== null) {
            return ['row' => $nearestrow, 'col' => null, 'nearest' => true];
        }

        return null;
    }

    /**
     * Render the read-only student-facing calendar grid as an HTML string.
     *
     * Shared by the embeddable page (embed.php) and the course block so the grid
     * markup stays in one place.
     *
     * @param stdClass $calendar Calendar record.
     * @param bool $autoscroll When true, emit a script that scrolls the nearest/today row into view.
     * @return string Grid HTML, or '' when the calendar has no content.
     */
    public static function render(stdClass $calendar, bool $autoscroll = true): string {
        $alltopics = topics::get_for_blueprint((int)$calendar->blueprintid, true);
        $blocksmap = self::get_blocks_map((int)$calendar->id);
        $maxrow = 0;
        foreach (array_keys($blocksmap) as $rownum) {
            $maxrow = max($maxrow, (int)$rownum);
        }
        if ($maxrow === 0 && empty($blocksmap)) {
            return '';
        }
        $columns = self::get_columns($blocksmap);

        // Compute today/nearest cell for highlighting.
        $todaycell = self::date_to_cell($blocksmap, $maxrow, time());
        $todayrow = $todaycell ? ($todaycell['row'] ?? null) : null;
        $todaycol = $todaycell ? ($todaycell['col'] ?? null) : null;
        $nearestonly = $todaycell && !empty($todaycell['nearest']);

        $out = html_writer::start_tag('div', ['class' => 'local-courseplanner-embed']);
        $out .= html_writer::start_tag('table', [
            'class' => 'table table-bordered local-courseplanner-grid local-courseplanner-preview',
        ]);
        for ($row = 0; $row <= $maxrow; $row++) {
            $rowclasses = [];
            if ($row === $todayrow && ($nearestonly || $todaycol === null)) {
                $rowclasses[] = 'local-courseplanner-nearest-row';
            }
            $out .= html_writer::start_tag('tr', $rowclasses ? ['class' => implode(' ', $rowclasses)] : []);
            foreach ($columns as $col) {
                $cell = $blocksmap[$row][$col] ?? null;
                $content = $cell ? (string)$cell->contenthtml : '';
                $blocktype = $cell ? (string)$cell->blocktype : '';
                $cellheading = $cell ? (string)$cell->cellheading : '';
                $highlighted = $cell && (int)$cell->highlighted === 1;
                $verticallycentred = $cell && (int)$cell->verticallycentred === 1;
                $selectedtopicid = ($cell && !empty($cell->topicid)) ? (int)$cell->topicid : 0;
                $selectedtopic = $alltopics[$selectedtopicid] ?? null;

                $isblank = ($blocktype === 'BLANK');

                $tag = ($row === 0) ? 'th' : 'td';
                $cellclasses = ['local-courseplanner-grid-cell'];
                if ($isblank) {
                    $cellclasses[] = 'local-courseplanner-blank-cell';
                }
                if ($highlighted) {
                    $cellclasses[] = 'local-courseplanner-highlighted';
                }
                if ($verticallycentred) {
                    $cellclasses[] = 'local-courseplanner-vcentred';
                }
                if ($row === 0) {
                    $cellclasses[] = 'local-courseplanner-preview-header';
                }
                if ($row === $todayrow && $col === $todaycol && !$nearestonly) {
                    $cellclasses[] = 'local-courseplanner-today-cell';
                }
                $out .= html_writer::start_tag($tag, ['class' => implode(' ', $cellclasses)]);

                if ($cellheading !== '') {
                    $out .= html_writer::tag('div', format_text($cellheading, FORMAT_HTML), [
                        'class' => 'local-courseplanner-cellheading',
                    ]);
                }
                if ($isblank) {
                    $out .= html_writer::tag('div', format_text($content, FORMAT_HTML), [
                        'class' => 'local-courseplanner-blank-label',
                    ]);
                } else if ($blocktype === 'TOPIC' && $selectedtopic) {
                    $out .= topics::heading_html($selectedtopic);
                    if (!empty($selectedtopic->contenthtml)) {
                        $topichtml = format_text($selectedtopic->contenthtml, FORMAT_HTML);
                        $topichtml = preg_replace('/<a\b/', '<a target="_blank"', $topichtml);
                        $out .= html_writer::tag('div', $topichtml, ['class' => 'local-courseplanner-topic-preview']);
                    }
                } else if ($row === 0) {
                    $out .= html_writer::tag('div', format_text($content, FORMAT_HTML), [
                        'class' => 'local-courseplanner-readonly-cell',
                    ]);
                    if ($cell && !empty($cell->headerday)) {
                        $out .= html_writer::tag(
                            'div',
                            s($cell->headerday) . ($cell->headermode ? ' &middot; ' . s($cell->headermode) : ''),
                            ['class' => 'local-courseplanner-header-meta']
                        );
                    }
                } else if ($content !== '') {
                    $texthtml = format_text($content, FORMAT_HTML);
                    $texthtml = preg_replace('/<a\b/', '<a target="_blank"', $texthtml);
                    $out .= html_writer::tag('div', $texthtml, ['class' => 'local-courseplanner-text-preview']);
                }

                $out .= html_writer::end_tag($tag);
            }
            $out .= html_writer::end_tag('tr');
        }
        $out .= html_writer::end_tag('table');
        $out .= html_writer::end_tag('div');

        if ($autoscroll && $todayrow) {
            $out .= <<<'JS'
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var selector = ".local-courseplanner-today-cell,.local-courseplanner-nearest-row";
        var target = document.querySelector(selector);
        if (target) {
            target.scrollIntoView({behavior: "smooth", block: "center"});
        }
    });
    </script>
    JS;
        }

        return $out;
    }
}
