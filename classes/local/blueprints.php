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
}
