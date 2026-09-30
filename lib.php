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
 * Core library callbacks.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add local_courseplanner links to course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_courseplanner_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    if (has_capability('local/courseplanner:manage', $context)) {
        $manageurl = new moodle_url('/local/courseplanner/manage.php', ['id' => $course->id]);
        $navigation->add(
            get_string('managecourseplanner', 'local_courseplanner'),
            $manageurl,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_courseplanner_manage'
        );
    }

    if (has_capability('local/courseplanner:view', $context)) {
        $studenturl = new moodle_url('/local/courseplanner/student.php', ['id' => $course->id]);
        $navigation->add(
            get_string('viewcourseplanner', 'local_courseplanner'),
            $studenturl,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_courseplanner_student'
        );
    }
}
