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
 * Course planner setup page: blueprints, topics, course link and calendars.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core\output\notification;
use local_courseplanner\local\blueprints;
use local_courseplanner\local\calendars;
use local_courseplanner\local\course_link;
use local_courseplanner\local\topics;
use local_courseplanner\local\tours;
use local_courseplanner\output\manage_page;

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:manage', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$selectedblueprintid = optional_param('blueprintctx', 0, PARAM_INT);
$topicfilter = core_text::strtoupper(optional_param('topicfilter', 'ALL', PARAM_ALPHANUMEXT));
if (!in_array($topicfilter, array_merge(['ALL'], topics::get_types()), true)) {
    $topicfilter = 'ALL';
}

if ($action !== '' && data_submitted()) {
    require_sesskey();
    $userid = (int)$USER->id;
    $redirecturl = new moodle_url('/local/courseplanner/manage.php', array_filter([
        'id' => $courseid,
        'blueprintctx' => $selectedblueprintid ?: null,
        'topicfilter' => $topicfilter !== 'ALL' ? $topicfilter : null,
    ]));
    $done = static function (string $message, bool $ok = true, ?int $blueprintid = null) use ($redirecturl): void {
        if ($blueprintid) {
            $redirecturl->param('blueprintctx', $blueprintid);
        }
        redirect($redirecturl, $message, null, $ok ? notification::NOTIFY_SUCCESS : notification::NOTIFY_ERROR);
    };
    $str = static fn(string $key, $a = null): string => get_string($key, 'local_courseplanner', $a);

    switch ($action) {
        case 'createblueprint':
        case 'updateblueprint':
            $name = trim(optional_param('name', '', PARAM_TEXT));
            $description = trim(optional_param('description', '', PARAM_TEXT));
            $blueprint = $action === 'updateblueprint'
                ? blueprints::require_owned(required_param('blueprintid', PARAM_INT), $userid) : null;
            if ($name === '') {
                $done($str('errorblueprintnamerequired'), false);
            }
            if (blueprints::name_taken($userid, $name, (int)($blueprint->id ?? 0))) {
                $done($str('errorblueprintduplicate'), false);
            }
            if ($blueprint) {
                blueprints::update($blueprint, $name, $description, $userid);
                $done($str('blueprintupdated'), true, (int)$blueprint->id);
            }
            $done($str('blueprintcreated'), true, blueprints::create($userid, $name, $description));
            break;

        case 'togglearchive':
            $blueprint = blueprints::require_owned(required_param('blueprintid', PARAM_INT), $userid);
            $archived = blueprints::toggle_archived($blueprint, $userid);
            $done($str($archived ? 'blueprintarchived' : 'blueprintunarchived'), true, (int)$blueprint->id);
            break;

        case 'linkblueprint':
            $blueprint = blueprints::require_owned(required_param('blueprintid', PARAM_INT), $userid);
            if ($blueprint->isarchived) {
                $done($str('errorarchivedblueprintlink'), false);
            }
            course_link::upsert($courseid, (int)$blueprint->id, 'MANUAL', null, '', $userid);
            $done($str('courselinkupdated'), true, (int)$blueprint->id);
            break;

        case 'unlinkblueprint':
            $DB->delete_records('local_courseplanner_courselink', ['courseid' => $courseid]);
            $done($str('courselinkremoved'));
            break;

        case 'createcalendar':
            $blueprint = blueprints::require_owned(required_param('blueprintid', PARAM_INT), $userid);
            $title = trim(optional_param('title', '', PARAM_TEXT));
            if ($title === '') {
                $done($str('calendartitlerequired'), false);
            }
            calendars::create($courseid, (int)$blueprint->id, $title, $userid);
            $done($str('calendarcreated'));
            break;

        case 'updatecalendar':
        case 'togglecalendaractive':
        case 'deletecalendar':
            calendars::require_in_course(required_param('calendarid', PARAM_INT), $courseid);
            [$calendar] = calendars::require_editable(required_param('calendarid', PARAM_INT));
            if ($action === 'updatecalendar') {
                $title = trim(optional_param('title', '', PARAM_TEXT));
                if ($title === '') {
                    $done($str('calendartitlerequired'), false);
                }
                calendars::rename($calendar, $title, $userid);
                $done($str('calendarupdated'));
            } else if ($action === 'togglecalendaractive') {
                $done($str(calendars::toggle_active($calendar, $userid) ? 'calendaractivated' : 'calendardeactivated'));
            }
            calendars::delete($calendar);
            $done($str('calendardeleted'));
            break;

        case 'toggletopicactive':
            $topic = topics::require_owned(required_param('topicid', PARAM_INT), $userid);
            $active = topics::toggle_active($topic, $userid);
            $done($str($active ? 'topicactivated' : 'topicdeactivated'), true, (int)$topic->blueprintid);
            break;

        case 'deletetopic':
            $topic = topics::require_owned(required_param('topicid', PARAM_INT), $userid);
            $usage = array_values(topics::get_usage_rows((int)$topic->id));
            if ($usage) {
                $examples = array_map(
                    static fn($row) => '#' . $row->id . ' (' . calendars::label($row) . ')',
                    array_slice($usage, 0, 3)
                );
                $done(
                    $str('errortopicinuse', (object)['count' => count($usage), 'calendars' => implode(', ', $examples)]),
                    false,
                    (int)$topic->blueprintid
                );
            }
            topics::delete($topic);
            $done($str('topicdeleted'), true, (int)$topic->blueprintid);
            break;

        case 'deletealltopics':
        case 'forcedeletealltopics':
            $blueprint = blueprints::require_owned(required_param('blueprintid', PARAM_INT), $userid);
            $deleted = topics::delete_all((int)$blueprint->id, $action === 'forcedeletealltopics');
            if ($deleted < 0) {
                $done($str('deletealltopicsblocked'), false, (int)$blueprint->id);
            }
            $done($str('deletealltopicsdone', $deleted), true, (int)$blueprint->id);
            break;
    }
}

$PAGE->set_url(new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('managepageheading', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));

$page = new manage_page($course, (int)$USER->id, $selectedblueprintid, $topicfilter);
$data = $page->export_for_template($PAGE->get_renderer('core'));

$PAGE->requires->js_call_amd('local_courseplanner/manage', 'init', ['#local-courseplanner-manage']);
$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);
$PAGE->requires->js_call_amd('local_courseplanner/showtour', 'init', [
    tours::get_id_by_name('local_courseplanner_setup'),
    '#local-courseplanner-showtour',
]);
if (!empty($data['topics']['sortable'])) {
    $PAGE->requires->js_call_amd('local_courseplanner/topicreorder', 'init', [
        $courseid,
        (int)$data['topics']['blueprintid'],
        '#local-courseplanner-topiclist',
    ]);
    $PAGE->requires->strings_for_js(['topicreordersaved'], 'local_courseplanner');
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_courseplanner/page_header', [
    'heading' => get_string('managepageheading', 'local_courseplanner'),
    'tourname' => 'local_courseplanner_setup',
]);
echo $OUTPUT->render_from_template('local_courseplanner/manage_page', $data);
echo $OUTPUT->footer();
