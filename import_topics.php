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
 * Topics import page.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core\output\notification;
use local_courseplanner\local\blueprints;
use local_courseplanner\local\importer;
use local_courseplanner\local\topics;

$courseid = required_param('id', PARAM_INT);
$blueprintid = required_param('blueprintid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);
require_capability('local/courseplanner:manage', $context);
$blueprint = blueprints::require_owned($blueprintid, (int)$USER->id);

$pageurl = new moodle_url('/local/courseplanner/import_topics.php', ['id' => $courseid, 'blueprintid' => $blueprintid]);

if ($action !== '' && data_submitted()) {
    require_sesskey();
    if ($action === 'seedtopics') {
        $layout = optional_param('layout', 'LLL', PARAM_ALPHA);
        if (!in_array($layout, importer::LAYOUTS, true)) {
            $layout = 'LLL';
        }
        $result = importer::seed_topics_from_html(
            required_param('importhtml', PARAM_RAW),
            $layout,
            $blueprintid,
            (int)$USER->id
        );
        redirect($pageurl, get_string('importtopicsdone', 'local_courseplanner', $result), null, notification::NOTIFY_SUCCESS);
    } else if ($action === 'deletealltopics') {
        $count = topics::delete_all($blueprintid, true);
        redirect(
            $pageurl,
            get_string('deletealltopicsdone', 'local_courseplanner', max(0, $count)),
            null,
            notification::NOTIFY_SUCCESS
        );
    }
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('importtopicspagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);

$deletelabel = get_string('forcedeletealltopicsbtn', 'local_courseplanner');
$data = [
    'manageurl' => (new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid, 'blueprintctx' => $blueprintid]))
        ->out(false),
    'sesskey' => sesskey(),
    'layouts' => array_map(static fn($layout) => [
        'value' => $layout,
        'label' => get_string('importlayout_' . strtolower($layout), 'local_courseplanner'),
    ], importer::LAYOUTS),
    'helpdanger' => $OUTPUT->help_icon('importdangerzone', 'local_courseplanner'),
    'deleteall' => [
        'action' => 'deletealltopics',
        'label' => $deletelabel,
        'btnclass' => 'btn-danger',
        'sesskey' => sesskey(),
        'confirm' => ['message' => get_string('forcedeletealltopicsconfirm', 'local_courseplanner'),
            'title' => get_string('confirm', 'core'), 'action' => $deletelabel, 'style' => 'delete'],
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importtopicspagetitle', 'local_courseplanner') . ': ' . format_string($blueprint->name));
echo $OUTPUT->render_from_template('local_courseplanner/import_page', $data);
echo $OUTPUT->footer();
