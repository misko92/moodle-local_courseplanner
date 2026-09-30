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

use core_course_category;
use core_text;
use stdClass;

/**
 * The link between a course and the blueprint it builds calendars from.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_link {
    /**
     * Return blueprint link record for a course.
     *
     * @param int $courseid
     * @return stdClass|null
     */
    public static function get(int $courseid): ?stdClass {
        global $DB;

        $record = $DB->get_record('local_courseplanner_courselink', ['courseid' => $courseid], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Insert/update the one-blueprint-per-course link.
     *
     * @param int $courseid
     * @param int $blueprintid
     * @param string $mode
     * @param int|null $confidence
     * @param string $notes
     * @param int $userid
     * @return void
     */
    public static function upsert(
        int $courseid,
        int $blueprintid,
        string $mode,
        ?int $confidence,
        string $notes,
        int $userid
    ): void {
        global $DB;

        $now = time();
        $existing = self::get($courseid);
        if ($existing) {
            $existing->blueprintid = $blueprintid;
            $existing->linkmode = $mode;
            $existing->linkconfidence = $confidence;
            $existing->linknotes = $notes;
            $existing->timemodified = $now;
            $existing->usermodified = $userid;
            $DB->update_record('local_courseplanner_courselink', $existing);
            return;
        }

        $record = new stdClass();
        $record->courseid = $courseid;
        $record->blueprintid = $blueprintid;
        $record->linkmode = $mode;
        $record->linkconfidence = $confidence;
        $record->linknotes = $notes;
        $record->timecreated = $now;
        $record->timemodified = $now;
        $record->usermodified = $userid;
        $DB->insert_record('local_courseplanner_courselink', $record);
    }

    /**
     * Return a best-effort auto-link suggestion for this course.
     *
     * @param stdClass $course
     * @param int $userid
     * @return array|null
     */
    public static function get_autolink_suggestion(stdClass $course, int $userid): ?array {
        $blueprints = blueprints::get_for_teacher($userid, false);
        if (empty($blueprints)) {
            return null;
        }

        $coursematchtext = self::get_course_match_text($course);
        $scored = [];
        foreach ($blueprints as $blueprint) {
            $score = self::score_blueprint_match($blueprint, $coursematchtext);
            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'blueprint' => $blueprint,
                'confidence' => $score,
            ];
        }

        if (empty($scored)) {
            return null;
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['confidence'] <=> $a['confidence'];
        });

        $best = $scored[0];
        if ($best['confidence'] < 40) {
            return null;
        }

        $ambiguous = false;
        if (count($scored) > 1) {
            $gap = $best['confidence'] - $scored[1]['confidence'];
            $ambiguous = $gap < 15;
        }

        return [
            'best' => $best,
            'ambiguous' => $ambiguous,
            'candidates' => array_slice($scored, 0, 3),
        ];
    }

    /**
     * Build comparison text from course metadata.
     *
     * @param stdClass $course
     * @return string
     */
    protected static function get_course_match_text(stdClass $course): string {
        $parts = [
            (string)$course->shortname,
            (string)$course->idnumber,
            (string)$course->fullname,
        ];

        if (!empty($course->category)) {
            $category = core_course_category::get((int)$course->category, IGNORE_MISSING);
            if ($category) {
                $parts[] = $category->get_nested_name(false);
            }
        }

        return core_text::strtoupper(trim(implode(' ', $parts)));
    }

    /**
     * Score how likely a blueprint matches course metadata, based on its name.
     *
     * A full-name match against the course text is the strongest signal; failing
     * that, the name's individual words are matched so a blueprint like
     * "Physics NYC SN3" still scores well against a course whose code contains
     * "SN3".
     *
     * @param stdClass $blueprint
     * @param string $coursematchtext Uppercased course metadata (see {@see self::get_course_match_text()}).
     * @return int Confidence score from 0-100.
     */
    protected static function score_blueprint_match(stdClass $blueprint, string $coursematchtext): int {
        $name = core_text::strtoupper(trim((string)$blueprint->name));
        if ($name === '' || trim($coursematchtext) === '') {
            return 0;
        }

        $score = 0;

        // Strongest signal: the whole blueprint name appears in the course metadata.
        if (str_contains($coursematchtext, $name)) {
            $score += 80;
        }

        // Otherwise reward individual name words that match course metadata.
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static function (string $t): bool {
            return core_text::strlen($t) >= 2;
        })));
        if ($tokens) {
            $matched = 0.0;
            foreach ($tokens as $token) {
                $quoted = preg_quote($token, '/');
                if (preg_match('/\b' . $quoted . '\b/u', $coursematchtext)) {
                    $matched += 1.0;
                } else if (str_contains($coursematchtext, $token)) {
                    $matched += 0.5;
                }
            }
            $score += (int)round(60 * ($matched / count($tokens)));
        }

        return min($score, 100);
    }
}
