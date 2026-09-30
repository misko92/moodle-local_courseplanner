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

use local_courseplanner\local\calendars;
use local_courseplanner\local\populate;

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);

$course = get_course($courseid);
require_login($course);
calendars::require_in_course($calendarid, $courseid);
[$calendar, $blueprint, $context] = calendars::require_editable($calendarid);

$PAGE->set_url(new moodle_url('/local/courseplanner/coverage.php', ['id' => $courseid, 'calendarid' => $calendarid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coveragepagetitle', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));

$result = populate::coverage_check((int)$calendar->id, (int)$blueprint->id);
$topic = static fn(array $item): array => [
    'title' => format_string($item['title']),
    'type' => $item['type'],
    'typeclass' => strtolower($item['type']),
];
$slot = static fn(array $item): array => [
    'week' => $item['row'],
    'day' => $item['headerday'] ?? '',
    'mode' => $item['headermode'] ?? '',
];
$found = array_map(static fn($item) => $topic($item) + $slot($item), $result['found']);
$missing = array_map($topic, $result['missing']);
$empty = array_map($slot, $result['empty']);
$data = [
    'builderurl' => (new moodle_url('/local/courseplanner/calendar.php', ['id' => $courseid, 'calendarid' => $calendarid]))
        ->out(false),
    'found' => $found,
    'hasfound' => !empty($found),
    'missing' => $missing,
    'hasmissing' => !empty($missing),
    'empty' => $empty,
    'hasempty' => !empty($empty),
    'helpfound' => $OUTPUT->help_icon('coveragefoundheading', 'local_courseplanner'),
    'helpmissing' => $OUTPUT->help_icon('coveragemissingheading', 'local_courseplanner'),
    'helpempty' => $OUTPUT->help_icon('coverageemptyheading', 'local_courseplanner'),
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('coveragepagetitle', 'local_courseplanner'));
echo $OUTPUT->render_from_template('local_courseplanner/coverage_page', $data);
echo $OUTPUT->footer();
