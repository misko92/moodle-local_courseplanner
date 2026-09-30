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

use DOMDocument;
use DOMElement;
use DOMNode;
use core_text;

/**
 * Importing blueprint topics from a pasted HTML table.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /**
     * Parse pasted HTML table into blueprint topics.
     *
     * @param string $html Raw HTML table content.
     * @param string $layout Column layout: 'LLL', 'LLB', 'LBL', 'BLL'
     * @param int $blueprintid
     * @param int $userid
     * @return array ['created' => int, 'skipped' => int]
     */
    public static function seed_topics_from_html(string $html, string $layout, int $blueprintid, int $userid): array {
        global $DB;

        $skippatterns = [
            '/^\s*problem\s+session/i',
            '/college\s+closed/i',
            '/^\s*no\s+class/i',
            '/^\s*thanksgiving/i',
            '/^\s*labou?r\s+day/i',
            '/^\s*spring\s+break/i',
            '/^\s*reading\s+week/i',
            '/(semester|classes)\s+(hasn.?t\s+started|has\s+ended|haven.?t\s+started|have\s+ended)/i',
        ];

        // The layout describes the lecture/lab content columns only. In the pasted
        // table the very first column is the "Week N / date" label and the final
        // column is homework, so content columns are 1..count($chars).
        $colmodes = [];
        $chars = str_split(strtoupper($layout));
        foreach ($chars as $i => $ch) {
            $colmodes[$i + 1] = ($ch === 'L') ? 'Lecture' : 'Lab';
        }
        $contentcols = count($chars);

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $rows = $dom->getElementsByTagName('tr');

        $created = 0;
        $skipped = 0;
        $sortorder = (int)topics::next_sortorder($blueprintid);
        $now = time();

        for ($ri = 0; $ri < $rows->length; $ri++) {
            $row = $rows->item($ri);
            $cells = $row->getElementsByTagName('td');
            if ($cells->length === 0) {
                $cells = $row->getElementsByTagName('th');
            }
            if ($cells->length === 0) {
                continue;
            }

            // First column is the week/date label - context only, never imported as a topic.
            $weeklines = preg_split('/\n+/', self::cell_plaintext($dom, $cells->item(0))) ?: [];
            $weeklines = array_values(array_filter(array_map('trim', $weeklines), static function (string $l): bool {
                return $l !== '';
            }));
            $weeklabel = trim(implode(' ', array_slice($weeklines, 0, 2)));

            for ($ci = 1; $ci < $cells->length; $ci++) {
                $cell = $cells->item($ci);
                $innerhtml = '';
                foreach ($cell->childNodes as $child) {
                    $innerhtml .= $dom->saveHTML($child);
                }
                $text = self::cell_plaintext($dom, $cell);
                if ($text === '') {
                    continue;
                }

                foreach ($skippatterns as $pat) {
                    if (preg_match($pat, $text)) {
                        $skipped++;
                        continue 2;
                    }
                }

                // Content columns are 1..$contentcols; the column right after is homework.
                $colmode = $colmodes[$ci] ?? 'Lecture';
                $type = self::detect_topic_type($text, $colmode, $ci, $contentcols);

                $firstlinktext = self::cell_first_link_text($cell);
                $title = self::extract_topic_title($text, $type, $weeklabel, $firstlinktext);
                if ($title === '') {
                    $title = self::clip_title($weeklabel !== '' ? $weeklabel : $text);
                }

                $DB->insert_record('local_courseplanner_topics', (object)[
                    'blueprintid' => $blueprintid,
                    'title' => $title,
                    'type' => $type,
                    'contenthtml' => trim($innerhtml),
                    'sortorder' => $sortorder,
                    'isactive' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => $userid,
                ]);
                $sortorder++;
                $created++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Extract readable plain text from a table cell, preserving line breaks between
     * block-level elements so list items and paragraphs don't run together.
     *
     * @param DOMDocument $dom The owning document (for saveHTML()).
     * @param DOMNode|null $cell The cell node.
     * @return string Cleaned, newline-separated plain text.
     */
    protected static function cell_plaintext(DOMDocument $dom, ?DOMNode $cell): string {
        if ($cell === null) {
            return '';
        }
        $innerhtml = '';
        foreach ($cell->childNodes as $child) {
            $innerhtml .= $dom->saveHTML($child);
        }
        // Mark block-level boundaries (open or close) with a sentinel so visually
        // separate lines stay separate, then drop all remaining markup. Source
        // whitespace (including wrap newlines inside a link) is collapsed first so
        // it never gets mistaken for a real line break.
        $sentinel = "\x01";
        $marked = preg_replace(
            '/<\/?\s*(p|div|li|ul|ol|br|h[1-6]|span|tr|td|table)\b[^>]*>/i',
            $sentinel,
            $innerhtml
        );
        $textonly = html_entity_decode(strip_tags((string)$marked), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textonly = preg_replace('/[\s\x{00a0}]+/u', ' ', $textonly);
        $clean = [];
        foreach (explode($sentinel, $textonly) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode("\n", $clean);
    }

    /**
     * Return the text of the first hyperlink inside a cell, if any.
     *
     * @param DOMNode|null $cell The cell node.
     * @return string First link text, or '' when there is no link.
     */
    protected static function cell_first_link_text(?DOMNode $cell): string {
        if (!($cell instanceof DOMElement)) {
            return '';
        }
        $links = $cell->getElementsByTagName('a');
        if ($links->length === 0) {
            return '';
        }
        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $links->item(0)->textContent));
    }

    /**
     * Normalise and clip a candidate title to a sensible length.
     *
     * @param string $title Raw title text.
     * @return string Cleaned title.
     */
    protected static function clip_title(string $title): string {
        $title = trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $title));
        return core_text::substr($title, 0, 120);
    }

    /**
     * Detect a topic type (LECTURE, LAB, TEST, ELESSON, HOMEWORK) from pasted cell text.
     *
     * @param string $text Cell text content.
     * @param string $colmode Column mode ("Lecture" or "Lab").
     * @param int $colindex 1-based column index.
     * @param int $totalcols Total number of primary columns (excluding homework column).
     * @return string Detected topic type code.
     */
    protected static function detect_topic_type(string $text, string $colmode, int $colindex, int $totalcols): string {
        if (preg_match('/^test|^exam|^midterm|^final\s+exam/i', $text)) {
            return 'TEST';
        }
        if (preg_match('/^\s*e-?lab\b/im', $text)) {
            return 'LAB';
        }
        if (preg_match('/^\s*lab\b/im', $text)) {
            return 'LAB';
        }
        // Only treat as an eLesson when a line actually starts with "eLesson"
        // (the banner or "eLesson (required)" heading) - not when the word merely
        // appears inside another title such as "SHM eLesson followup".
        if (preg_match('/^\s*e-?lesson\b/im', $text)) {
            return 'ELESSON';
        }
        if ($colindex === $totalcols + 1 || preg_match('/homework|assignment|problem\s+set|hw\s*\d/i', $text)) {
            return 'HOMEWORK';
        }
        if (core_text::strtolower($colmode) === 'lab') {
            return 'LAB';
        }
        return 'LECTURE';
    }

    /**
     * Extract a clean topic title from pasted cell text.
     *
     * Titles are mainly used to identify a topic when placing it in the builder.
     * Lectures and labs are not shown with a title in the calendar itself (only
     * their content is); we derive the title from the cell's own substance (slide
     * name, first link, or first real line) rather than the week label.
     *
     * @param string $text Cleaned, newline-separated cell text.
     * @param string $type Detected topic type (as returned by {@see self::detect_topic_type()}).
     * @param string $weeklabel Week/date label for the row (used only as a last-resort fallback).
     * @param string $firstlinktext Text of the first hyperlink in the cell, if any.
     * @return string Short title suitable for storing on the topic record.
     */
    protected static function extract_topic_title(
        string $text,
        string $type,
        string $weeklabel = '',
        string $firstlinktext = ''
    ): string {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\n+/', trim($text)) ?: []),
            static function (string $l): bool {
                return $l !== '';
            }
        ));

        $islabelline = static function (string $line): bool {
            return (bool)preg_match(
                '/^(pre-?\s*class\s+reading|class\s+slides?|slides?|reading(\s*\(optional\))?'
                    . '|simulations?|recorded\s+lecture|videos?|e-?lesson(\s*\(required\))?'
                    . '|e-?lab|do\s+not\s+come\s+to\s+class)/i',
                $line
            );
        };

        $firstcontentline = '';
        foreach ($lines as $line) {
            if (!$islabelline($line)) {
                $firstcontentline = $line;
                break;
            }
        }

        $week = trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $weeklabel));

        switch ($type) {
            case 'TEST':
                return self::clip_title($lines[0] ?? $week);

            case 'LAB':
                // Prefer the "Lab N - <name>" line (an eLab banner may precede it).
                foreach ($lines as $line) {
                    if (preg_match('/^lab\b/i', $line)) {
                        return self::clip_title($line);
                    }
                }
                return self::clip_title($lines[0] ?? ($firstlinktext ?: $week));

            case 'HOMEWORK':
                return self::clip_title(
                    $firstlinktext !== '' ? $firstlinktext : ($firstcontentline ?: ($lines[0] ?? ''))
                );

            case 'ELESSON':
                // The lesson name is the link, not the "Do not come to class" banner.
                return self::clip_title(
                    $firstlinktext !== '' ? $firstlinktext : ($firstcontentline ?: ($lines[0] ?? 'eLesson'))
                );

            case 'LECTURE':
            default:
                // Prefer the "Class slides" name, then the first link, then any real line.
                $slidename = '';
                $afterslides = false;
                foreach ($lines as $line) {
                    if ($afterslides) {
                        $slidename = $line;
                        break;
                    }
                    if (preg_match('/^class\s+slides?/i', $line)) {
                        $afterslides = true;
                    }
                }
                $base = $slidename;
                if ($base === '') {
                    $base = $firstlinktext !== '' ? $firstlinktext : ($firstcontentline ?: 'Lecture');
                }
                return self::clip_title($base);
        }
    }

    /**
     * Bulk update eLesson links in topic content.
     *
     * @param string $html HTML containing eLesson links.
     * @param int $blueprintid
     * @return array ['updated' => int, 'notfound' => int]
     */
    public static function bulk_update_elesson_links(string $html, int $blueprintid): array {
        global $DB;

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $links = $dom->getElementsByTagName('a');

        $linklist = [];
        for ($i = 0; $i < $links->length; $i++) {
            $a = $links->item($i);
            $href = $a->getAttribute('href');
            $text = trim($a->textContent);
            if ($href && $text) {
                $linklist[] = ['href' => $href, 'text' => $text];
            }
        }

        $elessons = $DB->get_records_select(
            'local_courseplanner_topics',
            "blueprintid = :bpid AND type = 'ELESSON'",
            ['bpid' => $blueprintid],
            'sortorder ASC'
        );

        $updated = 0;
        $notfound = 0;

        foreach ($linklist as $link) {
            $matched = false;
            foreach ($elessons as $topic) {
                $contenttext = strip_tags((string)$topic->contenthtml);
                $firstbullet = '';
                if (preg_match('/(?:^|\n)\s*(?:[-•*]|\d+[.\)])\s*(.+?)(?:\n|$)/', $contenttext, $m)) {
                    $firstbullet = trim($m[1]);
                } else {
                    $lines = preg_split('/[\r\n]+/', $contenttext, 2);
                    $firstbullet = trim($lines[0] ?? '');
                }

                if (
                    $firstbullet !== '' && (
                    stripos($link['text'], $firstbullet) !== false ||
                    stripos($firstbullet, $link['text']) !== false
                    )
                ) {
                    $newcontent = preg_replace(
                        '/<a\b[^>]*>.*?' . preg_quote(htmlspecialchars($link['text']), '/') . '.*?<\/a>/i',
                        '<a href="' . s($link['href']) . '">' . s($link['text']) . '</a>',
                        (string)$topic->contenthtml,
                        1,
                        $count
                    );
                    if ($count === 0) {
                        $newcontent = (string)$topic->contenthtml;
                        if (strpos($newcontent, $link['text']) !== false) {
                            $newcontent = str_replace(
                                $link['text'],
                                '<a href="' . s($link['href']) . '">' . s($link['text']) . '</a>',
                                $newcontent
                            );
                            $count = 1;
                        }
                    }
                    if ($count > 0) {
                        $topic->contenthtml = $newcontent;
                        $topic->timemodified = time();
                        $DB->update_record('local_courseplanner_topics', $topic);
                        $updated++;
                        $matched = true;
                        break;
                    }
                }
            }
            if (!$matched) {
                $notfound++;
            }
        }

        return ['updated' => $updated, 'notfound' => $notfound];
    }
}
