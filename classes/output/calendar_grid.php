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

namespace local_courseplanner\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use local_courseplanner\local\calendars;
use local_courseplanner\local\grid;
use local_courseplanner\local\topics;
use stdClass;

/**
 * A calendar's week-by-week grid, either read-only (students) or with edit controls (builder).
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_grid implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $calendar Calendar record.
     * @param bool $editable Show builder controls.
     * @param int|null $now Time used for "today" highlighting (read-only grids only).
     */
    public function __construct(
        /** @var stdClass Calendar record. */
        protected stdClass $calendar,
        /** @var bool Show builder controls. */
        protected bool $editable = false,
        /** @var int|null Time used for "today" highlighting. */
        protected ?int $now = null,
    ) {
    }

    /**
     * Format stored HTML for display; links open in a new tab so the calendar stays put.
     *
     * @param string $html
     * @return string
     */
    protected function format(string $html): string {
        if (trim($html) === '') {
            return '';
        }
        $html = format_text($html, FORMAT_HTML, ['context' => \context_course::instance((int)$this->calendar->courseid)]);
        return preg_replace('/<a\b(?![^>]*\btarget=)/', '<a target="_blank"', $html);
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $calendarid = (int)$this->calendar->id;
        $blocks = grid::get_blocks_map($calendarid);
        $topics = topics::get_for_blueprint((int)$this->calendar->blueprintid, true);
        $maxrow = $blocks ? max(array_keys($blocks)) : 0;
        $columns = grid::get_columns($blocks);

        $today = null;
        if (!$this->editable) {
            [$startdate] = calendars::get_date_range($calendarid);
            $today = grid::date_to_cell($blocks, $maxrow, $this->now ?? time(), $startdate);
        }

        $rows = [];
        for ($row = 0; $row <= $maxrow; $row++) {
            $cells = [];
            foreach ($columns as $col) {
                $cells[] = $this->export_cell($row, $col, $blocks[$row][$col] ?? null, $topics, $today);
            }
            $nearest = $today && $today['row'] === $row && $today['col'] === null;
            $rows[] = ['isheader' => $row === 0, 'cells' => $cells, 'nearest' => $nearest];
        }
        return [
            'calendarid' => $calendarid,
            'editable' => $this->editable,
            'rows' => $rows,
            'hasrows' => $maxrow > 0 || !empty($blocks),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Template data for one cell.
     *
     * @param int $row
     * @param int $col
     * @param stdClass|null $block
     * @param stdClass[] $topics Blueprint topics keyed by id.
     * @param array|null $today Result of {@see grid::date_to_cell()}.
     * @return array
     */
    protected function export_cell(int $row, int $col, ?stdClass $block, array $topics, ?array $today): array {
        $type = (string)($block->blocktype ?? '');
        $cell = [
            'row' => $row,
            'col' => $col,
            'isheader' => $row === 0,
            'isweeklabel' => $row > 0 && $col === 0,
            'isblank' => $type === 'BLANK',
            'highlighted' => !empty($block->highlighted),
            'vcentred' => !empty($block->verticallycentred),
            'today' => $today && $today['row'] === $row && $today['col'] === $col,
            'cellheading' => $this->format((string)($block->cellheading ?? '')),
            'html' => '',
            'meta' => '',
            'editcell' => false,
            'editheader' => false,
            'cellargs' => json_encode(['calendarid' => (int)$this->calendar->id, 'rownum' => $row, 'colnum' => $col]),
            'headerargs' => json_encode(['calendarid' => (int)$this->calendar->id, 'colnum' => $col]),
            'topicargs' => '',
            'topicid' => 0,
            'istopic' => false,
            'topicheading' => '',
        ];

        if ($row === 0) {
            $cell['html'] = $this->format((string)($block->contenthtml ?? ''));
            if (!empty($block->headerday)) {
                $cell['meta'] = $block->headerday . (!empty($block->headermode) ? ' · ' . $block->headermode : '');
            }
            $cell['editheader'] = $this->editable && $col > 0;
            return $cell;
        }

        $topic = $type === 'TOPIC' ? ($topics[(int)$block->topicid] ?? null) : null;
        if ($topic) {
            $suffix = ($this->editable && !$topic->isactive)
                ? ' <span class="badge bg-warning text-dark">' . get_string('topicinactive', 'local_courseplanner') . '</span>'
                : '';
            $cell['topicheading'] = topics::heading_html($topic, $suffix);
            $cell['html'] = $this->format((string)$topic->contenthtml);
            $cell['topicid'] = (int)$topic->id;
            $cell['topicargs'] = json_encode(['courseid' => (int)$this->calendar->courseid, 'topicid' => (int)$topic->id,
                'shared' => 1]);
            $cell['istopic'] = true;
        } else {
            $cell['html'] = $this->format((string)($block->contenthtml ?? ''));
        }
        $cell['editcell'] = $this->editable && $col > 0 && $type !== 'BLANK';
        return $cell;
    }
}
