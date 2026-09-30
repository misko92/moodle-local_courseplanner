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

/**
 * Placing blueprint topics onto the grid automatically, and checking coverage.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class populate {
    /**
     * Auto-populate a calendar grid with topics from the linked blueprint.
     *
     * @param int $calendarid
     * @param int $blueprintid
     * @param int $userid
     * @return array Summary with placed counts.
     */
    public static function auto_populate(int $calendarid, int $blueprintid, int $userid): array {
        global $DB;

        $blocksmap = grid::get_blocks_map($calendarid);
        $maxrow = 0;
        foreach (array_keys($blocksmap) as $r) {
            $maxrow = max($maxrow, (int)$r);
        }
        if ($maxrow < 1) {
            return ['lectures' => 0, 'labs' => 0, 'homework' => 0, 'error' => 'noweekrows'];
        }

        // Determine column modes from header row.
        $lecturecols = [];
        $labcols = [];
        for ($c = 1; $c <= 3; $c++) {
            $header = $blocksmap[0][$c] ?? null;
            if ($header && core_text::strtolower((string)$header->headermode) === 'lecture') {
                $lecturecols[] = $c;
            } else if ($header && core_text::strtolower((string)$header->headermode) === 'lab') {
                $labcols[] = $c;
            }
        }

        $now = time();
        $lecturesplaced = 0;
        $labsplaced = 0;
        $homeworkplaced = 0;

        // Step 1: Place LECTURE, ELESSON, TEST into Lecture-mode columns.
        $lecturetopics = $DB->get_records_select(
            'local_courseplanner_topics',
            "blueprintid = :bpid AND type IN ('LECTURE', 'ELESSON', 'TEST') AND isactive = 1",
            ['bpid' => $blueprintid],
            'sortorder ASC'
        );

        // Map topic id to the row/col position it was placed at.
        $placedpositions = [];
        $topicqueue = array_values($lecturetopics);
        $tqi = 0;

        for ($row = 1; $row <= $maxrow && $tqi < count($topicqueue); $row++) {
            foreach ($lecturecols as $col) {
                if ($tqi >= count($topicqueue)) {
                    break;
                }
                if (isset($blocksmap[$row][$col])) {
                    continue;
                }
                $topic = $topicqueue[$tqi];
                $cellheading = '';
                $highlighted = 0;
                $vcentred = 0;
                if ($topic->type === 'TEST') {
                    $highlighted = 1;
                    $vcentred = 1;
                }
                grid::upsert_block(
                    $calendarid,
                    $row,
                    $col,
                    'TOPIC',
                    '',
                    $userid,
                    null,
                    null,
                    (int)$topic->id,
                    $cellheading,
                    $highlighted,
                    $vcentred
                );
                $placedpositions[(int)$topic->id] = ['row' => $row, 'col' => $col];
                $blocksmap[$row][$col] = true;
                $lecturesplaced++;
                $tqi++;
            }
        }

        // Step 2: Place LAB topics after their prerequisite lecture row.
        $labtopics = $DB->get_records_select(
            'local_courseplanner_topics',
            "blueprintid = :bpid AND type = 'LAB' AND isactive = 1",
            ['bpid' => $blueprintid],
            'sortorder ASC'
        );
        $allsorted = $DB->get_records(
            'local_courseplanner_topics',
            ['blueprintid' => $blueprintid, 'isactive' => 1],
            'sortorder ASC'
        );
        $sortedids = array_keys($allsorted);

        // Build a chronological rank for each cell so a lab can be ordered against
        // the lecture it follows: same week when the lab's weekday is later than the
        // lecture's, otherwise a following week. Weekday offsets are 0-6, so a rank
        // of row*10 + offset keeps weeks strictly ordered.
        $daytooffset = [
            'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
            'friday' => 4, 'saturday' => 5, 'sunday' => 6,
        ];
        $coldayoffset = [];
        for ($c = 1; $c <= 3; $c++) {
            $header = $blocksmap[0][$c] ?? null;
            if ($header && !empty($header->headerday)) {
                $dn = core_text::strtolower((string)$header->headerday);
                if (isset($daytooffset[$dn])) {
                    $coldayoffset[$c] = $daytooffset[$dn];
                }
            }
        }
        $cellrank = function (int $row, int $col) use ($coldayoffset): int {
            return $row * 10 + ($coldayoffset[$col] ?? 0);
        };

        foreach ($labtopics as $lab) {
            // Find the lecture/eLesson/test immediately preceding this lab in the
            // blueprint order, and the cell it actually landed in.
            $prereqrank = 0;
            $labpos = array_search((int)$lab->id, $sortedids);
            if ($labpos !== false) {
                for ($pi = $labpos - 1; $pi >= 0; $pi--) {
                    $prereqid = $sortedids[$pi];
                    $prereqtopic = $allsorted[$prereqid] ?? null;
                    if ($prereqtopic && in_array($prereqtopic->type, ['LECTURE', 'ELESSON', 'TEST'], true)) {
                        if (isset($placedpositions[(int)$prereqid])) {
                            $pos = $placedpositions[(int)$prereqid];
                            $prereqrank = $cellrank((int)$pos['row'], (int)$pos['col']);
                        }
                        break;
                    }
                }
            }

            // Place the lab in the earliest empty lab cell that occurs after the
            // prerequisite lecture. Blanked (out-of-term) cells are already present
            // in the block map, so they are skipped here automatically.
            $placed = false;
            for ($row = 1; $row <= $maxrow && !$placed; $row++) {
                foreach ($labcols as $col) {
                    if (isset($blocksmap[$row][$col])) {
                        continue;
                    }
                    if ($cellrank($row, $col) <= $prereqrank) {
                        continue;
                    }
                    grid::upsert_block(
                        $calendarid,
                        $row,
                        $col,
                        'TOPIC',
                        '',
                        $userid,
                        null,
                        null,
                        (int)$lab->id
                    );
                    $blocksmap[$row][$col] = true;
                    $labsplaced++;
                    $placed = true;
                    break;
                }
            }
        }

        // Step 3: Place HOMEWORK topics into column 4.
        $homeworktopics = $DB->get_records_select(
            'local_courseplanner_topics',
            "blueprintid = :bpid AND type = 'HOMEWORK' AND isactive = 1",
            ['bpid' => $blueprintid],
            'sortorder ASC'
        );
        $hwqueue = array_values($homeworktopics);
        $hwi = 0;
        for ($row = 1; $row <= $maxrow && $hwi < count($hwqueue); $row++) {
            if (isset($blocksmap[$row][4])) {
                continue;
            }
            $hw = $hwqueue[$hwi];
            grid::upsert_block(
                $calendarid,
                $row,
                4,
                'TOPIC',
                '',
                $userid,
                null,
                null,
                (int)$hw->id
            );
            $blocksmap[$row][4] = true;
            $homeworkplaced++;
            $hwi++;
        }

        return ['lectures' => $lecturesplaced, 'labs' => $labsplaced, 'homework' => $homeworkplaced];
    }

    /**
     * Fill empty Lab-mode cells with "Problem Session" TEXT blocks.
     *
     * @param int $calendarid
     * @param int $userid
     * @return int Number of cells filled.
     */
    public static function fill_problem_sessions(int $calendarid, int $userid): int {
        global $DB;

        $blocksmap = grid::get_blocks_map($calendarid);
        $maxrow = 0;
        foreach (array_keys($blocksmap) as $r) {
            $maxrow = max($maxrow, (int)$r);
        }

        $labcols = [];
        for ($c = 1; $c <= 3; $c++) {
            $header = $blocksmap[0][$c] ?? null;
            if ($header && core_text::strtolower((string)$header->headermode) === 'lab') {
                $labcols[] = $c;
            }
        }

        $filled = 0;
        for ($row = 1; $row <= $maxrow; $row++) {
            foreach ($labcols as $col) {
                if (isset($blocksmap[$row][$col])) {
                    continue;
                }
                grid::upsert_block(
                    $calendarid,
                    $row,
                    $col,
                    'TEXT',
                    'Problem Session',
                    $userid,
                    null,
                    null,
                    null,
                    '',
                    0,
                    1
                );
                $filled++;
            }
        }
        return $filled;
    }

    /**
     * Run coverage check on a calendar. Returns found/missing/empty arrays.
     *
     * @param int $calendarid
     * @param int $blueprintid
     * @return array
     */
    public static function coverage_check(int $calendarid, int $blueprintid): array {
        global $DB;

        $blocksmap = grid::get_blocks_map($calendarid);
        $maxrow = 0;
        foreach (array_keys($blocksmap) as $r) {
            $maxrow = max($maxrow, (int)$r);
        }

        $activetopics = $DB->get_records('local_courseplanner_topics', [
            'blueprintid' => $blueprintid,
            'isactive' => 1,
        ], 'sortorder ASC');

        // Build header info.
        $headerinfo = [];
        for ($c = 0; $c <= 4; $c++) {
            $h = $blocksmap[0][$c] ?? null;
            $headerinfo[$c] = [
                'day' => $h ? (string)$h->headerday : '',
                'mode' => $h ? (string)$h->headermode : '',
            ];
        }

        // Find placed topic IDs.
        $placedtopicids = [];
        $found = [];
        for ($row = 1; $row <= $maxrow; $row++) {
            for ($col = 0; $col <= 4; $col++) {
                $cell = $blocksmap[$row][$col] ?? null;
                if ($cell && (string)$cell->blocktype === 'TOPIC' && !empty($cell->topicid)) {
                    $tid = (int)$cell->topicid;
                    $placedtopicids[$tid] = true;
                    $topic = $activetopics[$tid] ?? null;
                    $found[] = [
                        'topicid' => $tid,
                        'title' => $topic ? $topic->title : '(unknown)',
                        'type' => $topic ? $topic->type : '',
                        'row' => $row,
                        'col' => $col,
                        'headerday' => $headerinfo[$col]['day'] ?? '',
                        'headermode' => $headerinfo[$col]['mode'] ?? '',
                    ];
                }
            }
        }

        $missing = [];
        foreach ($activetopics as $topic) {
            if (!isset($placedtopicids[(int)$topic->id])) {
                $missing[] = [
                    'topicid' => (int)$topic->id,
                    'title' => $topic->title,
                    'type' => $topic->type,
                ];
            }
        }

        $emptyslots = [];
        for ($row = 1; $row <= $maxrow; $row++) {
            for ($col = 1; $col <= 3; $col++) {
                if (!isset($blocksmap[$row][$col])) {
                    $emptyslots[] = [
                        'row' => $row,
                        'col' => $col,
                        'headerday' => $headerinfo[$col]['day'] ?? '',
                        'headermode' => $headerinfo[$col]['mode'] ?? '',
                    ];
                }
            }
        }

        return ['found' => $found, 'missing' => $missing, 'empty' => $emptyslots];
    }
}
