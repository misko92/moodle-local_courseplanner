<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Embeddable calendar view.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_courseplanner\local\calendars;
use local_courseplanner\output\calendar_grid;

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);
require_capability('local/courseplanner:view', $context);

$calendar = calendars::require_in_course($calendarid, $courseid);

$PAGE->set_url(new moodle_url('/local/courseplanner/embed.php', ['id' => $courseid, 'calendarid' => $calendarid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('embedpagetitle', 'local_courseplanner'));
$PAGE->requires->js_call_amd('local_courseplanner/calendar_view', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'local_courseplanner/calendar_grid',
    (new calendar_grid($calendar))->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
