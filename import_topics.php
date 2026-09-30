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
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('id', PARAM_INT);
$blueprintid = required_param('blueprintid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:manage', $context);

$blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);

$pageurl = new moodle_url('/local/courseplanner/import_topics.php', ['id' => $courseid, 'blueprintid' => $blueprintid]);
$manageurl = new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid, 'blueprintctx' => $blueprintid]);

$layoutoptions = [
    'LLL' => 'Lecture - Lecture - Lecture',
    'LLB' => 'Lecture - Lecture - Lab',
    'LBL' => 'Lecture - Lab - Lecture',
    'BLL' => 'Lab - Lecture - Lecture',
];

if ($action !== '' && data_submitted()) {
    require_sesskey();

    switch ($action) {
        case 'seedtopics':
            $html = required_param('importhtml', PARAM_RAW);
            $layout = required_param('layout', PARAM_ALPHA);
            if (!isset($layoutoptions[$layout])) {
                $layout = 'LLL';
            }
            $result = local_courseplanner_seed_topics_from_html($html, $layout, $blueprintid, (int)$USER->id);
            redirect(
                $pageurl,
                get_string('importtopicsdone', 'local_courseplanner', $result),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'updateelessonlinks':
            $html = required_param('linkshtml', PARAM_RAW);
            $result = local_courseplanner_bulk_update_elesson_links($html, $blueprintid);
            redirect(
                $pageurl,
                get_string('importelessonlinksdone', 'local_courseplanner', $result),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'deletealltopics':
            $count = local_courseplanner_delete_all_topics($blueprintid, true);
            if ($count < 0) {
                redirect(
                    $pageurl,
                    get_string('deletealltopicsblocked', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            redirect(
                $pageurl,
                get_string('deletealltopicsdone', 'local_courseplanner', $count),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('importtopicspagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importtopicspagetitle', 'local_courseplanner'));
echo html_writer::link($manageurl, get_string('backtomanage', 'local_courseplanner'), ['class' => 'btn btn-secondary mb-3']);

echo html_writer::div(get_string('intro_importtopics', 'local_courseplanner'), 'local-courseplanner-intro alert alert-info');

echo html_writer::tag(
    'h4',
    get_string('importtopicsfromhtml', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('importtopicsfromhtml', 'local_courseplanner'),
    ['class' => 'mt-3']
);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'local-courseplanner-card']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => $blueprintid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'seedtopics']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::tag('label', get_string('importtopicshtmllabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::tag('textarea', '', [
    'name' => 'importhtml',
    'class' => 'form-control mb-2',
    'rows' => 8,
    'required' => 'required',
]);

echo html_writer::tag('label', get_string('importtopicslayoutlabel', 'local_courseplanner'), ['class' => 'font-weight-bold']);
echo html_writer::start_tag('select', ['name' => 'layout', 'class' => 'custom-select mb-2']);
foreach ($layoutoptions as $key => $label) {
    echo html_writer::tag('option', $label, ['value' => $key]);
}
echo html_writer::end_tag('select');

echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('importtopicssubmit', 'local_courseplanner')]
);
echo html_writer::end_tag('form');

echo html_writer::tag(
    'h4',
    get_string('importdangerzone', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('importdangerzone', 'local_courseplanner'),
    ['class' => 'mt-4 text-danger']
);
$deletealltopicslabel = get_string('deletealltopicsbtn', 'local_courseplanner');
echo html_writer::start_tag('form', [
    'method' => 'post',
    'class' => 'local-courseplanner-card',
    'data-cc-confirm' => get_string('deletealltopicsconfirm', 'local_courseplanner'),
    'data-cc-confirm-title' => get_string('confirm', 'core'),
    'data-cc-confirm-action' => $deletealltopicslabel,
    'data-cc-confirm-style' => 'delete',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => $blueprintid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'deletealltopics']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-danger', 'value' => $deletealltopicslabel]
);
echo html_writer::end_tag('form');

$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);

echo $OUTPUT->footer();
