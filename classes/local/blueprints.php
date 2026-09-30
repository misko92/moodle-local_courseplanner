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

use moodle_exception;
use stdClass;

/**
 * Teacher-owned blueprints (reusable topic libraries).
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class blueprints {
    /**
     * Return all blueprints owned by a teacher.
     *
     * @param int $userid
     * @param bool $includearchived
     * @return array
     */
    public static function get_for_teacher(int $userid, bool $includearchived = true): array {
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
     * Ensure a blueprint belongs to this user.
     *
     * @param int $blueprintid
     * @param int $userid
     * @return stdClass
     */
    public static function require_owned(int $blueprintid, int $userid): stdClass {
        global $DB;

        $blueprint = $DB->get_record('local_courseplanner_blueprints', ['id' => $blueprintid], '*', MUST_EXIST);
        if ((int)$blueprint->owneruserid !== $userid) {
            throw new moodle_exception('invalidblueprintownership', 'local_courseplanner');
        }

        return $blueprint;
    }

    /**
     * Whether the teacher already has another blueprint with this name.
     *
     * @param int $userid
     * @param string $name
     * @param int $excludeid Blueprint to ignore (when renaming).
     * @return bool
     */
    public static function name_taken(int $userid, string $name, int $excludeid = 0): bool {
        global $DB;
        return $DB->record_exists_select(
            'local_courseplanner_blueprints',
            'owneruserid = :owneruserid AND name = :name AND id <> :id',
            ['owneruserid' => $userid, 'name' => $name, 'id' => $excludeid]
        );
    }

    /**
     * Create a blueprint for a teacher.
     *
     * @param int $userid Owner.
     * @param string $name
     * @param string $description
     * @return int New blueprint id.
     */
    public static function create(int $userid, string $name, string $description): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_courseplanner_blueprints', (object)[
            'owneruserid' => $userid,
            'name' => $name,
            'description' => $description,
            'isarchived' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $userid,
        ]);
    }

    /**
     * Rename a blueprint and/or change its description.
     *
     * @param stdClass $blueprint
     * @param string $name
     * @param string $description
     * @param int $userid
     */
    public static function update(stdClass $blueprint, string $name, string $description, int $userid): void {
        global $DB;
        $DB->update_record('local_courseplanner_blueprints', (object)[
            'id' => $blueprint->id, 'name' => $name, 'description' => $description,
            'timemodified' => time(), 'usermodified' => $userid,
        ]);
    }

    /**
     * Archive or unarchive a blueprint.
     *
     * @param stdClass $blueprint
     * @param int $userid
     * @return bool True if the blueprint is now archived.
     */
    public static function toggle_archived(stdClass $blueprint, int $userid): bool {
        global $DB;
        $archived = $blueprint->isarchived ? 0 : 1;
        $DB->update_record('local_courseplanner_blueprints', (object)[
            'id' => $blueprint->id, 'isarchived' => $archived, 'timemodified' => time(), 'usermodified' => $userid,
        ]);
        return (bool)$archived;
    }
}
