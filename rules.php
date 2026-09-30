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
 * Timeline exception rules management page.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core\output\notification;
use local_courseplanner\local\calendars;
use local_courseplanner\local\timeline;
use local_courseplanner\local\tours;
use local_courseplanner\output\rules_page;

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);
calendars::require_in_course($calendarid, $courseid);
[$calendar, , $context] = calendars::require_editable($calendarid);

$pageurl = new moodle_url('/local/courseplanner/rules.php', ['id' => $courseid, 'calendarid' => $calendarid]);

if ($action !== '' && data_submitted()) {
    require_sesskey();
    $str = static fn(string $key, $a = null): string => get_string($key, 'local_courseplanner', $a);
    switch ($action) {
        case 'deleterule':
            $rule = timeline::require_rule(required_param('ruleid', PARAM_INT), (int)$calendar->id);
            timeline::delete_rule((int)$rule->id);
            redirect($pageurl, $str('ruledeleted'), null, notification::NOTIFY_SUCCESS);
            break;

        case 'togglerule':
            $rule = timeline::require_rule(required_param('ruleid', PARAM_INT), (int)$calendar->id);
            if (
                !$rule->isactive && in_array($rule->ruletype, ['START', 'END'], true)
                    && timeline::has_active_rule_of_type((int)$calendar->id, $rule->ruletype, (int)$rule->id)
            ) {
                redirect($pageurl, $str('errorrulestartendexists'), null, notification::NOTIFY_ERROR);
            }
            $active = timeline::toggle_rule((int)$rule->id, (int)$USER->id);
            redirect($pageurl, $str($active ? 'ruleactivated' : 'ruledeactivated'), null, notification::NOTIFY_SUCCESS);
            break;

        case 'applyrules':
            try {
                $summary = timeline::apply((int)$calendar->id, (int)$USER->id);
            } catch (moodle_exception $e) {
                redirect($pageurl, $e->getMessage(), null, notification::NOTIFY_ERROR);
            }
            redirect(
                new moodle_url('/local/courseplanner/calendar.php', ['id' => $courseid, 'calendarid' => $calendarid]),
                $str('rulesapplied', $summary['total_weeks']),
                null,
                notification::NOTIFY_SUCCESS
            );
            break;
    }
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('rulespagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('local_courseplanner/modal_forms', 'init', ['#local-courseplanner-rules']);
$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);
$PAGE->requires->js_call_amd('local_courseplanner/showtour', 'init', [
    tours::get_id_by_name('local_courseplanner_rules'),
    '#local-courseplanner-showtour',
]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_courseplanner/page_header', [
    'heading' => get_string('rulespagetitle', 'local_courseplanner'),
    'tourname' => 'local_courseplanner_rules',
]);
echo $OUTPUT->render_from_template('local_courseplanner/rules_page', (new rules_page($calendar))->export_for_template($OUTPUT));
echo $OUTPUT->footer();
