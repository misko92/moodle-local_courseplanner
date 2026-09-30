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
 * Calendar builder page.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core\output\notification;
use local_courseplanner\local\calendars;
use local_courseplanner\local\grid;
use local_courseplanner\local\populate;
use local_courseplanner\local\tours;
use local_courseplanner\output\builder_page;

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);
calendars::require_in_course($calendarid, $courseid);
[$calendar, $blueprint, $context] = calendars::require_editable($calendarid);

$pageurl = new moodle_url('/local/courseplanner/calendar.php', ['id' => $courseid, 'calendarid' => $calendarid]);

if ($action !== '' && data_submitted()) {
    require_sesskey();
    $calid = (int)$calendar->id;
    $message = null;
    $type = notification::NOTIFY_SUCCESS;
    switch ($action) {
        case 'addweekrow':
            grid::ensure_base($calid, (int)$USER->id);
            grid::add_week_row($calid, (int)$USER->id);
            $message = get_string('weekrowadded', 'local_courseplanner');
            break;
        case 'removelastweekrow':
            if (grid::remove_last_week_row($calid)) {
                $message = get_string('weekrowremoved', 'local_courseplanner');
            } else {
                [$message, $type] = [get_string('errornoweekrowstoremove', 'local_courseplanner'), notification::NOTIFY_ERROR];
            }
            break;
        case 'deletecolumn':
            $colnum = required_param('colnum', PARAM_INT);
            if ($colnum >= 1 && grid::delete_column($calid, $colnum)) {
                $message = get_string('columndeleted', 'local_courseplanner');
            } else {
                [$message, $type] = [get_string('errorcannotdeletecolumn', 'local_courseplanner'), notification::NOTIFY_ERROR];
            }
            break;
        case 'autopopulate':
            $result = populate::auto_populate($calid, (int)$blueprint->id, (int)$USER->id);
            $message = get_string('autopopulatedone', 'local_courseplanner', $result);
            break;
        case 'fillproblemsessions':
            $filled = populate::fill_problem_sessions($calid, (int)$USER->id);
            $message = get_string('problemsessionsfilled', 'local_courseplanner', $filled);
            break;
        case 'deletenonheader':
            $deleted = grid::delete_non_header_blocks($calid);
            $message = get_string('nonheaderdeleted', 'local_courseplanner', $deleted);
            break;
        case 'deletenonheadernontext':
            $deleted = grid::delete_non_header_non_text_blocks($calid);
            $message = get_string('nonheadernontextdeleted', 'local_courseplanner', $deleted);
            break;
    }
    redirect($pageurl, $message, null, $type);
}

grid::ensure_base((int)$calendar->id, (int)$USER->id);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('builderpageheading', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('local_courseplanner/builder', 'init', ['#local-courseplanner-builder']);
$PAGE->requires->js_call_amd('local_courseplanner/modal_forms', 'init', ['#local-courseplanner-builder']);
$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);
$PAGE->requires->js_call_amd('local_courseplanner/showtour', 'init', [
    tours::get_id_by_name('local_courseplanner_builder'),
    '#local-courseplanner-showtour',
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_courseplanner/page_header', [
    'heading' => get_string('builderpageheading', 'local_courseplanner'),
    'tourname' => 'local_courseplanner_builder',
]);
echo $OUTPUT->render_from_template(
    'local_courseplanner/builder_page',
    (new builder_page($calendar))->export_for_template($OUTPUT)
);
echo $OUTPUT->footer();
