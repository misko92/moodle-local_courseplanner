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
 * Published course calendar view (student-facing).
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_courseplanner\local\calendars;
use local_courseplanner\local\course_info;
use local_courseplanner\local\grid;
use local_courseplanner\local\topics;

$courseid = required_param('id', PARAM_INT);
$calendarid = required_param('calendarid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/courseplanner:view', $context);

$calendar = calendars::require_in_course($calendarid, $courseid);
$blueprintid = (int)$calendar->blueprintid;
$alltopics = topics::get_for_blueprint($blueprintid, true);

$blocksmap = grid::get_blocks_map((int)$calendar->id);
$maxrow = 0;
foreach (array_keys($blocksmap) as $rownum) {
    $maxrow = max($maxrow, (int)$rownum);
}
$columns = grid::get_columns($blocksmap);

$pageurl = new moodle_url('/local/courseplanner/view.php', ['id' => $courseid, 'calendarid' => $calendarid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('studentviewheading', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

// Compute today cell.
$todaycell = grid::date_to_cell($blocksmap, $maxrow, time());
$todayrow = $todaycell ? ($todaycell['row'] ?? null) : null;
$todaycol = $todaycell ? ($todaycell['col'] ?? null) : null;
$nearestonly = $todaycell && !empty($todaycell['nearest']);

echo $OUTPUT->header();

$calendarlabel = calendars::label($calendar);
echo $OUTPUT->heading(get_string('studentviewheading', 'local_courseplanner'));
echo html_writer::div($calendarlabel, 'local-courseplanner-shell mb-3');

// Course info section.
$courseinfo = course_info::get($courseid);
if ($courseinfo) {
    $introleft = trim((string)$courseinfo->introhtml);
    $introright = trim((string)$courseinfo->linkshtml);
    if ($introleft !== '' || $introright !== '') {
        echo html_writer::start_tag('div', ['class' => 'local-courseplanner-course-intro local-courseplanner-course-intro-grid']);
        if ($introleft !== '') {
            echo html_writer::start_div('local-courseplanner-course-intro-panel');
            echo format_text($introleft, FORMAT_HTML);
            echo html_writer::end_div();
        }
        if ($introright !== '') {
            echo html_writer::start_div('local-courseplanner-course-intro-panel');
            $righthtml = format_text($introright, FORMAT_HTML);
            $righthtml = preg_replace('/<a\b/', '<a target="_blank"', $righthtml);
            echo $righthtml;
            echo html_writer::end_div();
        }
        echo html_writer::end_tag('div');
    }
}

if ($maxrow === 0 && empty($blocksmap)) {
    echo $OUTPUT->notification(get_string('previewempty', 'local_courseplanner'), 'notifyinfo');
    echo $OUTPUT->footer();
    die;
}

echo html_writer::start_tag('table', ['class' => 'table table-bordered local-courseplanner-grid local-courseplanner-preview']);
for ($row = 0; $row <= $maxrow; $row++) {
    $rowclasses = [];
    if ($row === $todayrow && ($nearestonly || $todaycol === null)) {
        $rowclasses[] = 'local-courseplanner-nearest-row';
    }
    $rowattrs = ['id' => 'cc-row-' . $row];
    if ($rowclasses) {
        $rowattrs['class'] = implode(' ', $rowclasses);
    }
    echo html_writer::start_tag('tr', $rowattrs);
    foreach ($columns as $col) {
        $cell = $blocksmap[$row][$col] ?? null;
        $content = $cell ? (string)$cell->contenthtml : '';
        $blocktype = $cell ? (string)$cell->blocktype : '';
        $cellheading = $cell ? (string)$cell->cellheading : '';
        $highlighted = $cell && (int)$cell->highlighted === 1;
        $verticallycentred = $cell && (int)$cell->verticallycentred === 1;
        $selectedtopicid = ($cell && !empty($cell->topicid)) ? (int)$cell->topicid : 0;
        $selectedtopic = ($selectedtopicid > 0 && isset($alltopics[$selectedtopicid])) ? $alltopics[$selectedtopicid] : null;

        $tag = ($row === 0) ? 'th' : 'td';
        $cellclasses = ['local-courseplanner-grid-cell'];
        if ($highlighted) {
            $cellclasses[] = 'local-courseplanner-highlighted';
        }
        if ($verticallycentred) {
            $cellclasses[] = 'local-courseplanner-vcentred';
        }
        if ($row === 0) {
            $cellclasses[] = 'local-courseplanner-preview-header';
        }
        if ($row === $todayrow && $col === $todaycol && !$nearestonly) {
            $cellclasses[] = 'local-courseplanner-today-cell';
        }
        echo html_writer::start_tag($tag, ['class' => implode(' ', $cellclasses)]);

        if ($cellheading !== '') {
            echo html_writer::tag('div', format_text($cellheading, FORMAT_HTML), ['class' => 'local-courseplanner-cellheading']);
        }

        if ($blocktype === 'TOPIC' && $selectedtopic) {
            echo topics::heading_html($selectedtopic);
            if (!empty($selectedtopic->contenthtml)) {
                $topichtml = format_text($selectedtopic->contenthtml, FORMAT_HTML);
                $topichtml = preg_replace('/<a\b/', '<a target="_blank"', $topichtml);
                echo html_writer::tag('div', $topichtml, ['class' => 'local-courseplanner-topic-preview']);
            }
        } else if ($row === 0) {
            echo html_writer::tag('div', format_text($content, FORMAT_HTML), ['class' => 'local-courseplanner-readonly-cell']);
            if ($cell && !empty($cell->headerday)) {
                echo html_writer::tag('div', s($cell->headerday) . ($cell->headermode ? ' &middot; ' . s($cell->headermode) : ''), [
                    'class' => 'local-courseplanner-header-meta',
                ]);
            }
        } else if ($content !== '') {
            $texthtml = format_text($content, FORMAT_HTML);
            $texthtml = preg_replace('/<a\b/', '<a target="_blank"', $texthtml);
            echo html_writer::tag('div', $texthtml, ['class' => 'local-courseplanner-text-preview']);
        }

        echo html_writer::end_tag($tag);
    }
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('table');

// Auto-scroll to nearest row.
if ($todayrow) {
    $rowid = 'cc-row-' . (int)$todayrow;
    $scrollscript = <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var target = document.getElementById("$rowid");
    if (target) {
        target.scrollIntoView({behavior: "smooth", block: "center"});
    }
});
</script>
JS;
    echo $scrollscript;
}

echo $OUTPUT->footer();
