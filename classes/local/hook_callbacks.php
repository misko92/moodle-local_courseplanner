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

use core_course\hook\before_course_deleted;

/**
 * Hook callbacks for local_courseplanner.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Remove a course's calendars, grid, dates, blueprint link and info when the course is deleted.
     *
     * Blueprints are owned by teachers, not courses, so they are kept.
     *
     * @param before_course_deleted $hook
     */
    public static function before_course_deleted(before_course_deleted $hook): void {
        global $CFG;
        \local_courseplanner\local\calendars::delete_course_data((int)$hook->course->id);
    }
}
