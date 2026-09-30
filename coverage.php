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
 * Topic coverage report.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:manage', $context);

$calendar = local_courseplanner_require_course_calendar($calendarid, $courseid);
$blueprint = local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);

$pageurl = new moodle_url('/local/courseplanner/coverage.php', ['id' => $courseid, 'calendarid' => $calendarid]);
$builderurl = new moodle_url('/local/courseplanner/calendar.php', ['id' => $courseid, 'calendarid' => $calendarid]);

$result = local_courseplanner_coverage_check((int)$calendar->id, (int)$blueprint->id);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coveragepagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coveragepagetitle', 'local_courseplanner'));
echo html_writer::link($builderurl, get_string('backtobuilder', 'local_courseplanner'), ['class' => 'btn btn-secondary mb-3']);

echo html_writer::div(get_string('intro_coverage', 'local_courseplanner'), 'local-courseplanner-intro alert alert-info');

echo html_writer::tag(
    'h4',
    get_string('coveragefoundheading', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('coveragefoundheading', 'local_courseplanner'),
    ['class' => 'local-courseplanner-coverage-found mt-3']
);
if (empty($result['found'])) {
    echo html_writer::div(get_string('coveragenofound', 'local_courseplanner'), 'alert alert-info');
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
    echo '<tr><th>Topic</th><th>Type</th><th>Row</th><th>Col</th><th>Day</th><th>Mode</th></tr>';
    foreach ($result['found'] as $f) {
        $typebadge = html_writer::tag('span', s($f['type']), [
            'class' => 'local-courseplanner-type-badge local-courseplanner-type-' . strtolower($f['type']),
        ]);
        echo '<tr>';
        echo html_writer::tag('td', s($f['title']));
        echo html_writer::tag('td', $typebadge);
        echo html_writer::tag('td', $f['row']);
        echo html_writer::tag('td', $f['col']);
        echo html_writer::tag('td', s($f['headerday']));
        echo html_writer::tag('td', s($f['headermode']));
        echo '</tr>';
    }
    echo html_writer::end_tag('table');
}

echo html_writer::tag(
    'h4',
    get_string('coveragemissingheading', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('coveragemissingheading', 'local_courseplanner'),
    ['class' => 'local-courseplanner-coverage-missing mt-3']
);
if (empty($result['missing'])) {
    echo html_writer::div(get_string('coveragenomissing', 'local_courseplanner'), 'alert alert-success');
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
    echo '<tr><th>Topic</th><th>Type</th></tr>';
    foreach ($result['missing'] as $m) {
        $typebadge = html_writer::tag('span', s($m['type']), [
            'class' => 'local-courseplanner-type-badge local-courseplanner-type-' . strtolower($m['type']),
        ]);
        echo '<tr>';
        echo html_writer::tag('td', s($m['title']));
        echo html_writer::tag('td', $typebadge);
        echo '</tr>';
    }
    echo html_writer::end_tag('table');
}

echo html_writer::tag(
    'h4',
    get_string('coverageemptyheading', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('coverageemptyheading', 'local_courseplanner'),
    ['class' => 'local-courseplanner-coverage-empty mt-3']
);
if (empty($result['empty'])) {
    echo html_writer::div(get_string('coveragenoempty', 'local_courseplanner'), 'alert alert-success');
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
    echo '<tr><th>Row</th><th>Col</th><th>Day</th><th>Mode</th></tr>';
    foreach ($result['empty'] as $e) {
        echo '<tr>';
        echo html_writer::tag('td', $e['row']);
        echo html_writer::tag('td', $e['col']);
        echo html_writer::tag('td', s($e['headerday']));
        echo html_writer::tag('td', s($e['headermode']));
        echo '</tr>';
    }
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
