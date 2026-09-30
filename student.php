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
 * Student-facing page.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:view', $context);

$calendars = local_courseplanner_get_course_calendars($courseid);
$activecalendar = null;
foreach ($calendars as $calendar) {
    if ((int)$calendar->isactive === 1) {
        $activecalendar = $calendar;
        break;
    }
}

if ($activecalendar) {
    redirect(new moodle_url('/local/courseplanner/view.php', [
        'id' => $courseid,
        'calendarid' => (int)$activecalendar->id,
    ]));
}

$url = new moodle_url('/local/courseplanner/student.php', ['id' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('studentpageheading', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('studentpageheading', 'local_courseplanner'));
echo $OUTPUT->notification(get_string('studentnoactivecalendar', 'local_courseplanner'), 'notifyinfo');
echo $OUTPUT->footer();
