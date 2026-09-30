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
use moodle_exception;

/**
 * Date rules (first/last day of classes, holidays, day swaps) and applying them to the grid.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timeline {
    /**
     * Return the allowed rule type identifiers.
     *
     * @return string[]
     */
    public static function get_rule_types(): array {
        return ['START', 'END', 'NO_CLASS', 'DAY_SWAP', 'OTHER'];
    }

    /**
     * Fetch rules attached to a calendar.
     *
     * @param int $calendarid Calendar record ID.
     * @param bool $activeonly If true, only return rules with isactive=1.
     * @return array Ordered rule records.
     */
    public static function get_rules(int $calendarid, bool $activeonly = false): array {
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
     * @param string $ruletype One of the values returned by {@see self::get_rule_types()}.
     * @param int $ruledate Epoch timestamp for the rule date.
     * @param string $label Short label shown in the UI.
     * @param string $description Longer description.
     * @param string|null $fromday Day-swap source day (optional).
     * @param string|null $today Day-swap target day (optional).
     * @param int $userid User creating the rule.
     * @return int ID of the inserted rule.
     */
    public static function create_rule(
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
        if (!in_array($ruletype, self::get_rule_types(), true)) {
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
    public static function update_rule(
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
    public static function delete_rule(int $ruleid): void {
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
    public static function toggle_rule(int $ruleid, int $userid): bool {
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
    public static function get_week_monday(int $timestamp): int {
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
    public static function apply(int $calendarid, int $userid): array {
        global $DB;

        $rules = self::get_rules($calendarid, true);

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
        $ruleshash = self::compute_rules_hash($rules);

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
        $startmonday = self::get_week_monday($startdate);
        $endmonday = self::get_week_monday($enddate);

        $weekmondays = [];
        $current = $startmonday;
        while ($current <= $endmonday) {
            $weekmondays[] = $current;
            $current = strtotime('+7 days', $current);
        }
        $totalweeks = count($weekmondays);

        // Ensure header row exists.
        grid::ensure_base($calendarid, $userid);

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
            $rulemonday = self::get_week_monday((int)$rule->ruledate);
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
            $rulemonday = self::get_week_monday((int)$rule->ruledate);
            if (!isset($mondaytorow[$rulemonday])) {
                continue;
            }
            $rownum = $mondaytorow[$rulemonday];
            $note = '<div class="local-courseplanner-week-note">Note: ' . s($rule->fromday) .
                    ' is a ' . s($rule->today) . ' schedule this week</div>';
            self::append_week_label_note($calendarid, $rownum, $note, (int)$rule->id, $userid);
        }

        // Step 6: OTHER annotations on week labels.
        foreach ($otherrules as $rule) {
            $rulemonday = self::get_week_monday((int)$rule->ruledate);
            if (!isset($mondaytorow[$rulemonday])) {
                continue;
            }
            $rownum = $mondaytorow[$rulemonday];
            $note = '<div class="local-courseplanner-week-note">' . s($rule->label) . '</div>';
            self::append_week_label_note($calendarid, $rownum, $note, (int)$rule->id, $userid);
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
    protected static function append_week_label_note(
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
    protected static function compute_rules_hash(array $rules): string {
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

    /**
     * Load a rule, checking it belongs to the given calendar.
     *
     * @param int $ruleid
     * @param int $calendarid
     * @return \stdClass
     */
    public static function require_rule(int $ruleid, int $calendarid): \stdClass {
        global $DB;
        return $DB->get_record('local_courseplanner_rules', ['id' => $ruleid, 'calendarid' => $calendarid], '*', MUST_EXIST);
    }

    /**
     * Whether a calendar already has an active rule of a single-use type (START or END), other than the given one.
     *
     * @param int $calendarid
     * @param string $ruletype
     * @param int $excludeid
     * @return bool
     */
    public static function has_active_rule_of_type(int $calendarid, string $ruletype, int $excludeid = 0): bool {
        global $DB;
        return $DB->record_exists_select(
            'local_courseplanner_rules',
            'calendarid = :calendarid AND ruletype = :ruletype AND isactive = 1 AND id <> :id',
            ['calendarid' => $calendarid, 'ruletype' => $ruletype, 'id' => $excludeid]
        );
    }
}
