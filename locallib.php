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
 * Helper library.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return all blueprints owned by a teacher.
 *
 * @param int $userid
 * @param bool $includearchived
 * @return array
 */
function local_courseplanner_get_teacher_blueprints(int $userid, bool $includearchived = true): array {
    global $DB;

    $conditions = ['owneruserid' => $userid];
    if (!$includearchived) {
        $conditions['isarchived'] = 0;
    }

    return $DB->get_records(
        'local_courseplanner_blueprints',
        $conditions,
        'isarchived ASC, name ASC, id ASC'
    );
}

/**
 * Return blueprint link record for a course.
 *
 * @param int $courseid
 * @return stdClass|null
 */
function local_courseplanner_get_course_link_record(int $courseid): ?stdClass {
    global $DB;

    $record = $DB->get_record('local_courseplanner_courselink', ['courseid' => $courseid], '*', IGNORE_MISSING);
    return $record ?: null;
}

/**
 * Ensure a blueprint belongs to this user.
 *
 * @param int $blueprintid
 * @param int $userid
 * @return stdClass
 */
function local_courseplanner_require_owned_blueprint(int $blueprintid, int $userid): stdClass {
    global $DB;

    $blueprint = $DB->get_record('local_courseplanner_blueprints', ['id' => $blueprintid], '*', MUST_EXIST);
    if ((int)$blueprint->owneruserid !== $userid) {
        throw new moodle_exception('invalidblueprintownership', 'local_courseplanner');
    }

    return $blueprint;
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
function local_courseplanner_upsert_course_blueprint_link(
    int $courseid,
    int $blueprintid,
    string $mode,
    ?int $confidence,
    string $notes,
    int $userid
): void {
    global $DB;

    $now = time();
    $existing = local_courseplanner_get_course_link_record($courseid);
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
function local_courseplanner_get_autolink_suggestion(stdClass $course, int $userid): ?array {
    $blueprints = local_courseplanner_get_teacher_blueprints($userid, false);
    if (empty($blueprints)) {
        return null;
    }

    $coursematchtext = local_courseplanner_get_course_match_text($course);
    $scored = [];
    foreach ($blueprints as $blueprint) {
        $score = local_courseplanner_score_blueprint_match($blueprint, $coursematchtext);
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
function local_courseplanner_get_course_match_text(stdClass $course): string {
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
 * @param string $coursematchtext Uppercased course metadata (see {@see local_courseplanner_course_match_text()}).
 * @return int Confidence score from 0-100.
 */
function local_courseplanner_score_blueprint_match(stdClass $blueprint, string $coursematchtext): int {
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

/**
 * Valid topic types for blueprint topics.
 *
 * @return string[]
 */
function local_courseplanner_get_topic_types(): array {
    return ['LECTURE', 'LAB', 'ELESSON', 'TEST', 'HOMEWORK'];
}

/**
 * Validate and normalize topic type.
 *
 * @param string $type
 * @return string
 */
function local_courseplanner_normalise_topic_type(string $type): string {
    $type = core_text::strtoupper(trim($type));
    if (!in_array($type, local_courseplanner_get_topic_types(), true)) {
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
function local_courseplanner_topic_heading_is_hidden(string $type): bool {
    return in_array(core_text::strtoupper($type), ['LECTURE', 'LAB'], true);
}

/**
 * Build the heading line (type badge + title) shown above a placed topic's content.
 *
 * Returns an empty string for topic types whose heading is hidden (see
 * {@see local_courseplanner_topic_heading_is_hidden()}), unless a $suffix is
 * supplied (e.g. an "inactive" flag in the builder) which must always be shown.
 *
 * @param stdClass $topic Topic record (uses ->type and ->title).
 * @param string $suffix Optional trailing HTML always rendered when present.
 * @return string HTML for the heading div, or '' when nothing should be shown.
 */
function local_courseplanner_topic_heading_html(stdClass $topic, string $suffix = ''): string {
    $type = (string)$topic->type;

    if (local_courseplanner_topic_heading_is_hidden($type)) {
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
function local_courseplanner_get_blueprint_topics(int $blueprintid, bool $includeinactive = true): array {
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
function local_courseplanner_require_owned_topic(int $topicid, int $userid): stdClass {
    global $DB;

    $topic = $DB->get_record('local_courseplanner_topics', ['id' => $topicid], '*', MUST_EXIST);
    local_courseplanner_require_owned_blueprint((int)$topic->blueprintid, $userid);
    return $topic;
}

/**
 * Keep sortorder contiguous for a blueprint.
 *
 * @param int $blueprintid
 * @return void
 */
function local_courseplanner_normalise_topic_sortorder(int $blueprintid): void {
    global $DB;

    $topics = local_courseplanner_get_blueprint_topics($blueprintid, true);
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
function local_courseplanner_move_topic(stdClass $topic, int $direction): bool {
    global $DB;

    $topics = array_values(local_courseplanner_get_blueprint_topics((int)$topic->blueprintid, true));
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
function local_courseplanner_get_topic_usage_rows(int $topicid): array {
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
 * Suggest a title for a new calendar: the school year containing the given time.
 *
 * School years are assumed to start in July, so September 2026 and March 2027
 * both give "2026-27".
 *
 * @param int $time Unix timestamp.
 * @return string
 */
function local_courseplanner_suggest_calendar_title(int $time): string {
    $date = new DateTime('@' . $time);
    $date->setTimezone(core_date::get_user_timezone_object());
    $year = (int)$date->format('Y');
    if ((int)$date->format('n') < 7) {
        $year--;
    }
    return $year . '-' . substr((string)($year + 1), -2);
}

/**
 * Display label for a calendar.
 *
 * @param stdClass $calendar Calendar record.
 * @return string Formatted, HTML-safe label.
 */
function local_courseplanner_calendar_label(stdClass $calendar): string {
    $title = trim((string)$calendar->title);
    if ($title === '') {
        return get_string('calendarfallbacktitle', 'local_courseplanner', (int)$calendar->id);
    }
    return format_string($title, true, ['context' => context_course::instance((int)$calendar->courseid)]);
}

/**
 * Return all calendars for a course, newest first.
 *
 * @param int $courseid
 * @return array
 */
function local_courseplanner_get_course_calendars(int $courseid): array {
    global $DB;

    return $DB->get_records(
        'local_courseplanner_calendars',
        ['courseid' => $courseid],
        'timecreated DESC, id DESC'
    );
}

/**
 * Order a course's calendars so the one a teacher most likely wants comes first.
 *
 * Preference, strongest first: the active calendar; one whose class dates contain
 * the course start date; one whose class dates contain today; the nearest upcoming
 * one; then the newest.
 *
 * @param stdClass $course Course record (uses startdate).
 * @param stdClass[] $calendars Calendar records keyed by id.
 * @param int $now Current time.
 * @return array [stdClass[] $sorted, string $reasonkey] Reason string key for the first calendar ('' if none).
 */
function local_courseplanner_rank_calendars(stdClass $course, array $calendars, int $now): array {
    if (empty($calendars)) {
        return [[], ''];
    }

    $coursestart = (int)($course->startdate ?? 0);
    $scores = [];
    foreach ($calendars as $calendar) {
        [$startdate, $enddate] = local_courseplanner_get_calendar_date_range((int)$calendar->id);

        // Each tier outweighs all lower tiers combined.
        $score = 0;
        $reasonkey = 'calendarrecommend_reason_newest';
        if ($startdate && $startdate > $now) {
            $score += max(0, 1000 - (int)(($startdate - $now) / DAYSECS));
            $reasonkey = 'calendarrecommend_reason_upcoming';
        }
        if ($startdate && $enddate && $now >= $startdate && $now <= $enddate) {
            $score += 2000;
            $reasonkey = 'calendarrecommend_reason_currentdate';
        }
        // Course start dates are usually set a few days before classes begin.
        if (
            $coursestart && $startdate && $enddate
                && $coursestart >= $startdate - 14 * DAYSECS && $coursestart <= $enddate
        ) {
            $score += 4000;
            $reasonkey = 'calendarrecommend_reason_courseconfig';
        }
        if ((int)$calendar->isactive === 1) {
            $score += 8000;
            $reasonkey = 'calendarrecommend_reason_active';
        }
        $scores[(int)$calendar->id] = ['score' => $score, 'reasonkey' => $reasonkey];
    }

    uasort($calendars, static function (stdClass $left, stdClass $right) use ($scores): int {
        $leftscore = $scores[(int)$left->id]['score'];
        $rightscore = $scores[(int)$right->id]['score'];
        if ($leftscore === $rightscore) {
            return [(int)$right->timecreated, (int)$right->id] <=> [(int)$left->timecreated, (int)$left->id];
        }
        return $rightscore <=> $leftscore;
    });

    $first = reset($calendars);
    return [$calendars, $scores[(int)$first->id]['reasonkey']];
}

/**
 * First and last day of classes for a calendar, from its active START/END rules.
 *
 * @param int $calendarid
 * @return array [?int $startdate, ?int $enddate]
 */
function local_courseplanner_get_calendar_date_range(int $calendarid): array {
    $startdate = null;
    $enddate = null;
    foreach (local_courseplanner_get_calendar_rules($calendarid, true) as $rule) {
        if ($rule->ruletype === 'START') {
            $startdate = (int)$rule->ruledate;
        } else if ($rule->ruletype === 'END') {
            $enddate = (int)$rule->ruledate;
        }
    }
    return [$startdate, $enddate];
}

/**
 * Require a calendar belonging to this course.
 *
 * @param int $calendarid
 * @param int $courseid
 * @return stdClass
 */
function local_courseplanner_require_course_calendar(int $calendarid, int $courseid): stdClass {
    global $DB;

    $calendar = $DB->get_record('local_courseplanner_calendars', ['id' => $calendarid], '*', MUST_EXIST);
    if ((int)$calendar->courseid !== $courseid) {
        throw new moodle_exception('invalidcalendarcontext', 'local_courseplanner');
    }

    return $calendar;
}

/**
 * Get calendar blocks indexed by row/col.
 *
 * @param int $calendarid
 * @return array
 */
function local_courseplanner_get_blocks_map(int $calendarid): array {
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
function local_courseplanner_upsert_block(
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
function local_courseplanner_ensure_base_grid(int $calendarid, int $userid): void {
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
        local_courseplanner_upsert_block(
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
function local_courseplanner_get_grid_columns(array $blocksmap): array {
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
function local_courseplanner_delete_column(int $calendarid, int $colnum): bool {
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
function local_courseplanner_add_week_row(int $calendarid, int $userid): int {
    global $DB;

    $maxrow = (int)$DB->get_field_sql(
        'SELECT COALESCE(MAX(rownum), 0) FROM {local_courseplanner_blocks} WHERE calendarid = :calendarid',
        ['calendarid' => $calendarid]
    );
    $newrow = max(1, $maxrow + 1);
    local_courseplanner_upsert_block($calendarid, $newrow, 0, 'TEXT', 'Week ' . $newrow, $userid);
    return $newrow;
}

/**
 * Remove last week row and all its blocks.
 *
 * @param int $calendarid
 * @return bool
 */
function local_courseplanner_remove_last_week_row(int $calendarid): bool {
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

// Timeline exception rules.

/**
 * Return the allowed rule type identifiers.
 *
 * @return string[]
 */
function local_courseplanner_get_rule_types(): array {
    return ['START', 'END', 'NO_CLASS', 'DAY_SWAP', 'OTHER'];
}

/**
 * Fetch rules attached to a calendar.
 *
 * @param int $calendarid Calendar record ID.
 * @param bool $activeonly If true, only return rules with isactive=1.
 * @return array Ordered rule records.
 */
function local_courseplanner_get_calendar_rules(int $calendarid, bool $activeonly = false): array {
    global $DB;
    $conditions = ['calendarid' => $calendarid];
    if ($activeonly) {
        $conditions['isactive'] = 1;
    }
    return $DB->get_records(
        'local_courseplanner_rules',
        $conditions,
        'ruledate ASC, sortorder ASC, id ASC'
    );
}

/**
 * Create a new timeline exception rule.
 *
 * @param int $calendarid Calendar the rule belongs to.
 * @param string $ruletype One of the values returned by {@see local_courseplanner_get_rule_types()}.
 * @param int $ruledate Epoch timestamp for the rule date.
 * @param string $label Short label shown in the UI.
 * @param string $description Longer description.
 * @param string|null $fromday Day-swap source day (optional).
 * @param string|null $today Day-swap target day (optional).
 * @param int $userid User creating the rule.
 * @return int ID of the inserted rule.
 */
function local_courseplanner_create_rule(
    int $calendarid,
    string $ruletype,
    int $ruledate,
    string $label,
    string $description,
    ?string $fromday,
    ?string $today,
    int $userid
): int {
    global $DB;
    $ruletype = core_text::strtoupper(trim($ruletype));
    if (!in_array($ruletype, local_courseplanner_get_rule_types(), true)) {
        throw new moodle_exception('invalidruletype', 'local_courseplanner');
    }
    $now = time();
    $record = (object)[
        'calendarid' => $calendarid,
        'ruletype' => $ruletype,
        'ruledate' => $ruledate,
        'label' => trim($label),
        'description' => trim($description),
        'fromday' => $fromday ? trim($fromday) : null,
        'today' => $today ? trim($today) : null,
        'isactive' => 1,
        'sortorder' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
        'usermodified' => $userid,
    ];
    return $DB->insert_record('local_courseplanner_rules', $record);
}

/**
 * Update an existing timeline exception rule.
 *
 * @param int $ruleid Rule ID to update.
 * @param int $ruledate New epoch timestamp for the rule date.
 * @param string $label New label.
 * @param string $description New description.
 * @param string|null $fromday Day-swap source day (optional).
 * @param string|null $today Day-swap target day (optional).
 * @param int $userid User making the change.
 * @return void
 */
function local_courseplanner_update_rule(
    int $ruleid,
    int $ruledate,
    string $label,
    string $description,
    ?string $fromday,
    ?string $today,
    int $userid
): void {
    global $DB;
    $record = $DB->get_record('local_courseplanner_rules', ['id' => $ruleid], '*', MUST_EXIST);
    $record->ruledate = $ruledate;
    $record->label = trim($label);
    $record->description = trim($description);
    $record->fromday = $fromday ? trim($fromday) : null;
    $record->today = $today ? trim($today) : null;
    $record->timemodified = time();
    $record->usermodified = $userid;
    $DB->update_record('local_courseplanner_rules', $record);
}

/**
 * Delete a timeline exception rule.
 *
 * @param int $ruleid Rule ID to delete.
 * @return void
 */
function local_courseplanner_delete_rule(int $ruleid): void {
    global $DB;
    $DB->delete_records('local_courseplanner_rules', ['id' => $ruleid]);
}

/**
 * Toggle a rule between active/inactive and return the new state.
 *
 * @param int $ruleid Rule ID to toggle.
 * @param int $userid User performing the toggle.
 * @return bool New active state.
 */
function local_courseplanner_toggle_rule(int $ruleid, int $userid): bool {
    global $DB;
    $record = $DB->get_record('local_courseplanner_rules', ['id' => $ruleid], '*', MUST_EXIST);
    $record->isactive = (int)$record->isactive === 1 ? 0 : 1;
    $record->timemodified = time();
    $record->usermodified = $userid;
    $DB->update_record('local_courseplanner_rules', $record);
    return (int)$record->isactive === 1;
}

/**
 * Get the Monday of the week containing the given timestamp.
 *
 * @param int $timestamp Unix timestamp.
 * @return int Unix timestamp of that week's Monday (midnight local time).
 */
function local_courseplanner_get_week_monday(int $timestamp): int {
    // PHP date('N'): 1 = Monday ... 7 = Sunday.
    $dow = (int)date('N', $timestamp);
    return strtotime('-' . ($dow - 1) . ' days', strtotime(date('Y-m-d', $timestamp)));
}

/**
 * Apply rules to a calendar -- the core rules engine.
 *
 * @param int $calendarid
 * @param int $userid
 * @return array Summary of the apply run.
 */
function local_courseplanner_apply_rules(int $calendarid, int $userid): array {
    global $DB;

    $rules = local_courseplanner_get_calendar_rules($calendarid, true);

    $startdate = null;
    $enddate = null;
    $noclassrules = [];
    $dayswaprules = [];
    $otherrules = [];

    foreach ($rules as $rule) {
        switch ($rule->ruletype) {
            case 'START':
                $startdate = (int)$rule->ruledate;
                break;
            case 'END':
                $enddate = (int)$rule->ruledate;
                break;
            case 'NO_CLASS':
                $noclassrules[] = $rule;
                break;
            case 'DAY_SWAP':
                $dayswaprules[] = $rule;
                break;
            case 'OTHER':
                $otherrules[] = $rule;
                break;
        }
    }

    if (!$startdate || !$enddate) {
        throw new moodle_exception('errorrulesmissingstartend', 'local_courseplanner');
    }
    if ($enddate <= $startdate) {
        throw new moodle_exception('errorrulesendbeforestart', 'local_courseplanner');
    }

    // Compute run hash for idempotency check.
    $ruleshash = local_courseplanner_compute_rules_hash($rules);

    // Step 1: Delete all blocks where generatedbyrule = 1.
    $deleted = $DB->count_records('local_courseplanner_blocks', [
        'calendarid' => $calendarid,
        'generatedbyrule' => 1,
    ]);
    $DB->delete_records('local_courseplanner_blocks', [
        'calendarid' => $calendarid,
        'generatedbyrule' => 1,
    ]);

    // Step 2: Generate week rows.
    $startmonday = local_courseplanner_get_week_monday($startdate);
    $endmonday = local_courseplanner_get_week_monday($enddate);

    $weekmondays = [];
    $current = $startmonday;
    while ($current <= $endmonday) {
        $weekmondays[] = $current;
        $current = strtotime('+7 days', $current);
    }
    $totalweeks = count($weekmondays);

    // Ensure header row exists.
    local_courseplanner_ensure_base_grid($calendarid, $userid);

    // Build a lookup of week monday -> row number, and annotations per row.
    $mondaytorow = [];
    $rowannotations = [];
    $now = time();
    $inserted = 0;

    for ($i = 0; $i < $totalweeks; $i++) {
        $rownum = $i + 1;
        $monday = $weekmondays[$i];
        $mondaytorow[$monday] = $rownum;
        $rowannotations[$rownum] = [];

        $monthday = date('M j', $monday);
        $label = 'Week ' . $rownum . '<br/>' . $monthday;

        if ($i === 0) {
            $startdatefmt = date('M j', $startdate);
            $label .= '<div class="local-courseplanner-week-note">Classes begin ' . $startdatefmt . '</div>';
        }
        if ($i === $totalweeks - 1) {
            $enddatefmt = date('M j', $enddate);
            $label .= '<div class="local-courseplanner-week-note">Last day of classes is ' . $enddatefmt . '</div>';
        }

        // Check if a non-rule-generated block already exists at col 0.
        $existing = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => 0,
        ], 'id, generatedbyrule', IGNORE_MISSING);

        if ($existing && (int)$existing->generatedbyrule === 0) {
            // Manually edited - skip.
            continue;
        }

        $block = (object)[
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => 0,
            'blocktype' => 'TEXT',
            'contenthtml' => $label,
            'generatedbyrule' => 1,
            'generatedruleid' => null,
            'highlighted' => 0,
            'verticallycentred' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ];
        if ($existing) {
            $block->id = $existing->id;
            $DB->update_record('local_courseplanner_blocks', $block);
        } else {
            $DB->insert_record('local_courseplanner_blocks', $block);
        }
        $inserted++;
    }

    // Remove excess rows beyond total weeks.
    $maxrow = (int)$DB->get_field_sql(
        'SELECT COALESCE(MAX(rownum), 0) FROM {local_courseplanner_blocks} WHERE calendarid = :calendarid',
        ['calendarid' => $calendarid]
    );
    for ($r = $totalweeks + 1; $r <= $maxrow; $r++) {
        $DB->delete_records('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => $r,
        ]);
    }

    // Step 3: get header day mappings for NO_CLASS column matching.
    // Weekday name => colnum.
    $headerdaymap = [];
    for ($c = 1; $c <= 3; $c++) {
        $header = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => 0,
            'colnum' => $c,
        ], 'headerday', IGNORE_MISSING);
        if ($header && !empty($header->headerday)) {
            $headerdaymap[core_text::strtolower($header->headerday)] = $c;
        }
    }

    // Step 4: NO_CLASS markers.
    $noclassplaced = 0;
    foreach ($noclassrules as $rule) {
        $rulemonday = local_courseplanner_get_week_monday((int)$rule->ruledate);
        if (!isset($mondaytorow[$rulemonday])) {
            continue;
        }
        $rownum = $mondaytorow[$rulemonday];
        $weekday = core_text::strtolower(date('l', (int)$rule->ruledate));
        if (!isset($headerdaymap[$weekday])) {
            continue;
        }
        $colnum = $headerdaymap[$weekday];

        // Only place if no manual block exists there.
        $existingcell = $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => $colnum,
        ], 'id, generatedbyrule', IGNORE_MISSING);

        if ($existingcell && (int)$existingcell->generatedbyrule === 0) {
            continue;
        }

        $cellblock = (object)[
            'calendarid' => $calendarid,
            'rownum' => $rownum,
            'colnum' => $colnum,
            'blocktype' => 'TEXT',
            'contenthtml' => s($rule->label),
            'verticallycentred' => 1,
            'highlighted' => 0,
            'generatedbyrule' => 1,
            'generatedruleid' => (int)$rule->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ];
        if ($existingcell) {
            $cellblock->id = $existingcell->id;
            $DB->update_record('local_courseplanner_blocks', $cellblock);
        } else {
            $DB->insert_record('local_courseplanner_blocks', $cellblock);
        }
        $noclassplaced++;
    }

    // Step 4b: Grey out day cells that fall outside the teaching term -- the
    // days before the first day of classes in week 1, and the days after the
    // last day of classes in the final week. These BLANK cells are rule-generated (so they are
    // refreshed on every apply) and, because they occupy the cell, they make
    // auto-populate skip non-teaching days automatically.
    $daytooffset = [
        'monday' => 0, 'tuesday' => 1, 'wednesday' => 2, 'thursday' => 3,
        'friday' => 4, 'saturday' => 5, 'sunday' => 6,
    ];
    // Reuse the (weekday name => column) map built in Step 3 for NO_CLASS,
    // inverting it to (column => weekday offset from Monday).
    $coloffsets = [];
    foreach ($headerdaymap as $dayname => $colnum) {
        if (isset($daytooffset[$dayname])) {
            $coloffsets[$colnum] = $daytooffset[$dayname];
        }
    }
    $startmidnight = strtotime(date('Y-m-d', $startdate));
    $endmidnight = strtotime(date('Y-m-d', $enddate));
    $blankplaced = 0;
    $blankweeks = [1 => $startmonday];
    $blankweeks[$totalweeks] = $endmonday;
    foreach ($blankweeks as $rownum => $monday) {
        foreach ($coloffsets as $colnum => $offset) {
            $celldate = strtotime('+' . $offset . ' days', $monday);
            $beforestart = ($rownum === 1 && $celldate < $startmidnight);
            $afterend = ($rownum === $totalweeks && $celldate > $endmidnight);
            if (!$beforestart && !$afterend) {
                continue;
            }

            // Never overwrite a teacher-placed block.
            $existingcell = $DB->get_record('local_courseplanner_blocks', [
                'calendarid' => $calendarid,
                'rownum' => $rownum,
                'colnum' => $colnum,
            ], 'id, generatedbyrule', IGNORE_MISSING);
            if ($existingcell && (int)$existingcell->generatedbyrule === 0) {
                continue;
            }

            $labelkey = $beforestart ? 'blankbeforestart' : 'blankafterend';
            $blankblock = (object)[
                'calendarid' => $calendarid,
                'rownum' => $rownum,
                'colnum' => $colnum,
                'blocktype' => 'BLANK',
                'contenthtml' => get_string($labelkey, 'local_courseplanner'),
                'verticallycentred' => 1,
                'highlighted' => 0,
                'generatedbyrule' => 1,
                'generatedruleid' => null,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ];
            if ($existingcell) {
                $blankblock->id = $existingcell->id;
                $DB->update_record('local_courseplanner_blocks', $blankblock);
            } else {
                $DB->insert_record('local_courseplanner_blocks', $blankblock);
            }
            $blankplaced++;
        }
    }

    // Step 5: DAY_SWAP annotations on week labels.
    foreach ($dayswaprules as $rule) {
        $rulemonday = local_courseplanner_get_week_monday((int)$rule->ruledate);
        if (!isset($mondaytorow[$rulemonday])) {
            continue;
        }
        $rownum = $mondaytorow[$rulemonday];
        $note = '<div class="local-courseplanner-week-note">Note: ' . s($rule->fromday) .
                ' is a ' . s($rule->today) . ' schedule this week</div>';
        local_courseplanner_append_week_label_note($calendarid, $rownum, $note, (int)$rule->id, $userid);
    }

    // Step 6: OTHER annotations on week labels.
    foreach ($otherrules as $rule) {
        $rulemonday = local_courseplanner_get_week_monday((int)$rule->ruledate);
        if (!isset($mondaytorow[$rulemonday])) {
            continue;
        }
        $rownum = $mondaytorow[$rulemonday];
        $note = '<div class="local-courseplanner-week-note">' . s($rule->label) . '</div>';
        local_courseplanner_append_week_label_note($calendarid, $rownum, $note, (int)$rule->id, $userid);
    }

    // Step 7: Record apply run.
    $summary = [
        'deleted_rule_blocks' => $deleted,
        'week_labels_generated' => $inserted,
        'total_weeks' => $totalweeks,
        'noclass_placed' => $noclassplaced,
        'blank_placed' => $blankplaced,
        'dayswap_rules' => count($dayswaprules),
        'other_rules' => count($otherrules),
    ];
    $DB->insert_record('local_courseplanner_ruleruns', (object)[
        'calendarid' => $calendarid,
        'appliedbyuserid' => $userid,
        'runhash' => $ruleshash,
        'summaryjson' => json_encode($summary),
        'timecreated' => $now,
    ]);

    return $summary;
}

/**
 * Append an annotation note to an existing week label block.
 *
 * @param int $calendarid
 * @param int $rownum
 * @param string $note
 * @param int $ruleid
 * @param int $userid
 * @return void
 */
function local_courseplanner_append_week_label_note(
    int $calendarid,
    int $rownum,
    string $note,
    int $ruleid,
    int $userid
): void {
    global $DB;
    $block = $DB->get_record('local_courseplanner_blocks', [
        'calendarid' => $calendarid,
        'rownum' => $rownum,
        'colnum' => 0,
    ], '*', IGNORE_MISSING);

    if (!$block) {
        return;
    }

    // Only append to rule-generated blocks.
    if ((int)$block->generatedbyrule === 0) {
        return;
    }

    $block->contenthtml .= $note;
    $block->timemodified = time();
    $block->usermodified = $userid;
    $DB->update_record('local_courseplanner_blocks', $block);
}

/**
 * Compute a deterministic hash of active rules for idempotency.
 *
 * @param array $rules
 * @return string SHA-256 hex digest.
 */
function local_courseplanner_compute_rules_hash(array $rules): string {
    $data = [];
    foreach ($rules as $rule) {
        $data[] = [
            'id' => (int)$rule->id,
            'type' => $rule->ruletype,
            'date' => (int)$rule->ruledate,
            'label' => $rule->label,
            'fromday' => $rule->fromday,
            'today' => $rule->today,
        ];
    }
    usort($data, function ($a, $b) {
        return $a['id'] <=> $b['id'];
    });
    return hash('sha256', json_encode($data));
}

// Topic placement automation and coverage tools.

/**
 * Auto-populate a calendar grid with topics from the linked blueprint.
 *
 * @param int $calendarid
 * @param int $blueprintid
 * @param int $userid
 * @return array Summary with placed counts.
 */
function local_courseplanner_auto_populate(int $calendarid, int $blueprintid, int $userid): array {
    global $DB;

    $blocksmap = local_courseplanner_get_blocks_map($calendarid);
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
            local_courseplanner_upsert_block(
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
                local_courseplanner_upsert_block(
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
        local_courseplanner_upsert_block(
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
function local_courseplanner_fill_problem_sessions(int $calendarid, int $userid): int {
    global $DB;

    $blocksmap = local_courseplanner_get_blocks_map($calendarid);
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
            local_courseplanner_upsert_block(
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
function local_courseplanner_coverage_check(int $calendarid, int $blueprintid): array {
    global $DB;

    $blocksmap = local_courseplanner_get_blocks_map($calendarid);
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

/**
 * Delete all non-header blocks (rownum > 0).
 *
 * @param int $calendarid
 * @return int Number of deleted blocks.
 */
function local_courseplanner_delete_non_header_blocks(int $calendarid): int {
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
function local_courseplanner_delete_non_header_non_text_blocks(int $calendarid): int {
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

// Course info and date-to-cell helpers.

/**
 * Get or create course_info record.
 *
 * @param int $courseid
 * @return stdClass|null
 */
function local_courseplanner_get_course_info(int $courseid): ?stdClass {
    global $DB;
    return $DB->get_record('local_courseplanner_courseinfo', ['courseid' => $courseid], '*', IGNORE_MISSING) ?: null;
}

/**
 * Create or update the course info record (intro + links panels).
 *
 * @param int $courseid Course to save info for.
 * @param string $introhtml Intro panel HTML.
 * @param string $linkshtml Links panel HTML.
 * @param int $userid User performing the save.
 * @return void
 */
function local_courseplanner_save_course_info(int $courseid, string $introhtml, string $linkshtml, int $userid): void {
    global $DB;
    $now = time();
    $existing = $DB->get_record('local_courseplanner_courseinfo', ['courseid' => $courseid], '*', IGNORE_MISSING);
    if ($existing) {
        $existing->introhtml = $introhtml;
        $existing->linkshtml = $linkshtml;
        $existing->timemodified = $now;
        $existing->usermodified = $userid;
        $DB->update_record('local_courseplanner_courseinfo', $existing);
    } else {
        $DB->insert_record('local_courseplanner_courseinfo', (object)[
            'courseid' => $courseid,
            'introhtml' => $introhtml,
            'linkshtml' => $linkshtml,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);
    }
}

/**
 * Map a date to a grid cell position using the week-label structure and header days.
 *
 * @param array $blocksmap Block grid keyed [row][col] of block records.
 * @param int $maxrow Highest row number present in the grid.
 * @param int $timestamp Unix timestamp of the date to locate.
 * @return array|null ['row' => int, 'col' => int] or null if not found.
 */
function local_courseplanner_date_to_cell(array $blocksmap, int $maxrow, int $timestamp): ?array {
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
                $rowmondays[$row] = local_courseplanner_get_week_monday($parsed);
            }
        }
    }

    $targetdate = strtotime(date('Y-m-d', $timestamp));
    $targetmonday = local_courseplanner_get_week_monday($targetdate);

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
 * Return the active calendar for a course, if any.
 *
 * @param int $courseid
 * @return stdClass|null The active calendar record, or null when none is active.
 */
function local_courseplanner_get_active_course_calendar(int $courseid): ?stdClass {
    $calendars = local_courseplanner_get_course_calendars($courseid);
    foreach ($calendars as $calendar) {
        if ((int)$calendar->isactive === 1) {
            return $calendar;
        }
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
function local_courseplanner_render_calendar_grid(stdClass $calendar, bool $autoscroll = true): string {
    $alltopics = local_courseplanner_get_blueprint_topics((int)$calendar->blueprintid, true);
    $blocksmap = local_courseplanner_get_blocks_map((int)$calendar->id);
    $maxrow = 0;
    foreach (array_keys($blocksmap) as $rownum) {
        $maxrow = max($maxrow, (int)$rownum);
    }
    if ($maxrow === 0 && empty($blocksmap)) {
        return '';
    }
    $columns = local_courseplanner_get_grid_columns($blocksmap);

    // Compute today/nearest cell for highlighting.
    $todaycell = local_courseplanner_date_to_cell($blocksmap, $maxrow, time());
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
            $selectedtopic = ($selectedtopicid > 0 && isset($alltopics[$selectedtopicid])) ? $alltopics[$selectedtopicid] : null;

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
                $out .= local_courseplanner_topic_heading_html($selectedtopic);
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

// Migration helpers.

/**
 * Parse pasted HTML table into blueprint topics.
 *
 * @param string $html Raw HTML table content.
 * @param string $layout Column layout: 'LLL', 'LLB', 'LBL', 'BLL'
 * @param int $blueprintid
 * @param int $userid
 * @return array ['created' => int, 'skipped' => int]
 */
function local_courseplanner_seed_topics_from_html(string $html, string $layout, int $blueprintid, int $userid): array {
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
    $sortorder = (int)local_courseplanner_next_topic_sortorder($blueprintid);
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
        $weeklines = preg_split('/\n+/', local_courseplanner_cell_plaintext($dom, $cells->item(0))) ?: [];
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
            $text = local_courseplanner_cell_plaintext($dom, $cell);
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
            $type = local_courseplanner_detect_topic_type($text, $colmode, $ci, $contentcols);

            $firstlinktext = local_courseplanner_cell_first_link_text($cell);
            $title = local_courseplanner_extract_topic_title($text, $type, $weeklabel, $firstlinktext);
            if ($title === '') {
                $title = local_courseplanner_clip_title($weeklabel !== '' ? $weeklabel : $text);
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
function local_courseplanner_cell_plaintext(DOMDocument $dom, ?DOMNode $cell): string {
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
function local_courseplanner_cell_first_link_text(?DOMNode $cell): string {
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
function local_courseplanner_clip_title(string $title): string {
    $title = trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $title));
    return core_text::substr($title, 0, 120);
}

/**
 * Return the next available sort order for a topic in the given blueprint.
 *
 * @param int $blueprintid Blueprint ID.
 * @return int Next sortorder to use.
 */
function local_courseplanner_next_topic_sortorder(int $blueprintid): int {
    global $DB;
    $max = (int)$DB->get_field_sql(
        'SELECT COALESCE(MAX(sortorder), -1) FROM {local_courseplanner_topics} WHERE blueprintid = :bpid',
        ['bpid' => $blueprintid]
    );
    return $max + 1;
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
function local_courseplanner_detect_topic_type(string $text, string $colmode, int $colindex, int $totalcols): string {
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
 * @param string $type Detected topic type (as returned by {@see local_courseplanner_detect_topic_type()}).
 * @param string $weeklabel Week/date label for the row (used only as a last-resort fallback).
 * @param string $firstlinktext Text of the first hyperlink in the cell, if any.
 * @return string Short title suitable for storing on the topic record.
 */
function local_courseplanner_extract_topic_title(
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
            return local_courseplanner_clip_title($lines[0] ?? $week);

        case 'LAB':
            // Prefer the "Lab N - <name>" line (an eLab banner may precede it).
            foreach ($lines as $line) {
                if (preg_match('/^lab\b/i', $line)) {
                    return local_courseplanner_clip_title($line);
                }
            }
            return local_courseplanner_clip_title($lines[0] ?? ($firstlinktext ?: $week));

        case 'HOMEWORK':
            return local_courseplanner_clip_title(
                $firstlinktext !== '' ? $firstlinktext : ($firstcontentline ?: ($lines[0] ?? ''))
            );

        case 'ELESSON':
            // The lesson name is the link, not the "Do not come to class" banner.
            return local_courseplanner_clip_title(
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
            return local_courseplanner_clip_title($base);
    }
}

/**
 * Bulk update eLesson links in topic content.
 *
 * @param string $html HTML containing eLesson links.
 * @param int $blueprintid
 * @return array ['updated' => int, 'notfound' => int]
 */
function local_courseplanner_bulk_update_elesson_links(string $html, int $blueprintid): array {
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

/**
 * Delete all topics for a blueprint (with optional force flag to bypass calendar reference check).
 *
 * @param int $blueprintid
 * @param bool $force
 * @return int Number of deleted topics.
 */
function local_courseplanner_delete_all_topics(int $blueprintid, bool $force = false): int {
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

/**
 * Look up a tour's numeric ID by its configured name.
 *
 * Returns null if tool_usertours is not installed or the tour does not exist.
 *
 * @param string $name The tour name (matches tool_usertours_tours.name).
 * @return int|null
 */
function local_courseplanner_get_tour_id_by_name(string $name): ?int {
    global $DB;
    if (!class_exists('\\tool_usertours\\manager')) {
        return null;
    }
    $id = $DB->get_field('tool_usertours_tours', 'id', ['name' => $name], IGNORE_MISSING);
    return $id ? (int)$id : null;
}

/**
 * List of user tours shipped with this plugin.
 *
 * Map of JSON filename (in local/courseplanner/tours/) to a version integer.
 * Bump the version when the JSON changes so the seeder re-imports it.
 *
 * @return array<string, int>
 */
function local_courseplanner_shipped_tours(): array {
    return [
        'teacher_setup_tour.json'   => 4,
        'teacher_builder_tour.json' => 3,
        'teacher_rules_tour.json'   => 3,
    ];
}

/**
 * Install or refresh the plugin's shipped Moodle user tours.
 *
 * No-op if tool_usertours is not installed. Idempotent: it imports any
 * shipped tour whose version is newer than what's recorded in the DB,
 * removing the stale copy first. Uses the same configdata keys that
 * Moodle core uses for its own shipped tours so admins see the "shipped"
 * badge and warning on tours they might edit.
 *
 * Safe to call from both db/install.php and db/upgrade.php.
 */
function local_courseplanner_install_user_tours(): void {
    global $DB, $CFG;

    if (!class_exists('\\tool_usertours\\manager')) {
        return;
    }

    $shipped = local_courseplanner_shipped_tours();
    $tourdir = $CFG->dirroot . '/local/courseplanner/tours/';

    $existingrecords = $DB->get_recordset('tool_usertours_tours');
    foreach ($existingrecords as $record) {
        $tour = \tool_usertours\tour::load_from_record($record);
        $filename = $tour->get_config('local_courseplanner_filename');
        if (empty($filename) || !isset($shipped[$filename])) {
            continue;
        }
        $installedversion = (int)$tour->get_config('local_courseplanner_version');
        if ($installedversion < $shipped[$filename]) {
            $tour->remove();
        } else {
            unset($shipped[$filename]);
        }
    }
    $existingrecords->close();

    if (class_exists('\\tool_usertours\\helper')) {
        \tool_usertours\helper::reset_tour_sortorder();
    }

    foreach ($shipped as $filename => $version) {
        $filepath = $tourdir . $filename;
        if (!is_readable($filepath)) {
            continue;
        }
        $tourjson = file_get_contents($filepath);
        if ($tourjson === false) {
            continue;
        }
        try {
            $tour = \tool_usertours\manager::import_tour_from_json($tourjson);
        } catch (\Throwable $e) {
            debugging('local_courseplanner: failed to import user tour ' . $filename . ': ' . $e->getMessage());
            continue;
        }

        $tour->set_config('local_courseplanner_filename', $filename);
        $tour->set_config('local_courseplanner_version', $version);
        $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_TOUR, true);
        $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_FILENAME, $filename);
        $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_VERSION, $version);
        $tour->persist();

        if (defined('BEHAT_SITE_RUNNING') || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
            $tour->set_enabled(\tool_usertours\tour::DISABLED);
            $tour->persist();
        }
    }
}

/**
 * Delete everything stored for a course: calendars with their grid, dates and
 * apply history, the blueprint link and course info. Blueprints are kept.
 *
 * @param int $courseid
 */
function local_courseplanner_delete_course_data(int $courseid): void {
    global $DB;

    $calendarids = $DB->get_fieldset_select(
        'local_courseplanner_calendars',
        'id',
        'courseid = :courseid',
        ['courseid' => $courseid]
    );
    if (!empty($calendarids)) {
        [$calsql, $calparams] = $DB->get_in_or_equal($calendarids, SQL_PARAMS_NAMED, 'cal');
        $DB->delete_records_select('local_courseplanner_blocks', "calendarid $calsql", $calparams);
        $DB->delete_records_select('local_courseplanner_rules', "calendarid $calsql", $calparams);
        $DB->delete_records_select('local_courseplanner_ruleruns', "calendarid $calsql", $calparams);
        $DB->delete_records_select('local_courseplanner_calendars', "id $calsql", $calparams);
    }
    $DB->delete_records('local_courseplanner_courselink', ['courseid' => $courseid]);
    $DB->delete_records('local_courseplanner_courseinfo', ['courseid' => $courseid]);
}
