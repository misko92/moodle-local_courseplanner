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
 * Builder management page.
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
require_capability('local/courseplanner:manage', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$selectedblueprintid = optional_param('blueprintctx', 0, PARAM_INT);
$topicfilter = core_text::strtoupper(optional_param('topicfilter', 'ALL', PARAM_ALPHANUMEXT));
if (!in_array($topicfilter, array_merge(['ALL'], local_courseplanner_get_topic_types()), true)) {
    $topicfilter = 'ALL';
}

if ($action !== '' && data_submitted()) {
    require_sesskey();
    $redirectparams = ['id' => $courseid];
    if ($selectedblueprintid > 0) {
        $redirectparams['blueprintctx'] = $selectedblueprintid;
    }
    if ($topicfilter !== 'ALL') {
        $redirectparams['topicfilter'] = $topicfilter;
    }
    $redirecturl = new moodle_url('/local/courseplanner/manage.php', $redirectparams);

    switch ($action) {
        case 'createblueprint':
            $name = trim(optional_param('name', '', PARAM_TEXT));
            $description = trim(optional_param('description', '', PARAM_TEXT));

            if ($name === '') {
                redirect(
                    $redirecturl,
                    get_string('errorblueprintnamerequired', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            if ($DB->record_exists('local_courseplanner_blueprints', ['owneruserid' => $USER->id, 'name' => $name])) {
                redirect(
                    $redirecturl,
                    get_string('errorblueprintduplicate', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $now = time();
            $record = (object)[
                'owneruserid' => $USER->id,
                'name' => $name,
                'description' => $description,
                'isarchived' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $USER->id,
            ];
            $newblueprintid = (int)$DB->insert_record('local_courseplanner_blueprints', $record);
            $redirecturl->param('blueprintctx', $newblueprintid);
            redirect(
                $redirecturl,
                get_string('blueprintcreated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'updateblueprint':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);

            $name = trim(optional_param('name', '', PARAM_TEXT));
            $description = trim(optional_param('description', '', PARAM_TEXT));
            if ($name === '') {
                redirect(
                    $redirecturl,
                    get_string('errorblueprintnamerequired', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            if (
                $DB->record_exists_select(
                    'local_courseplanner_blueprints',
                    'owneruserid = :owneruserid AND name = :name AND id <> :id',
                    ['owneruserid' => $USER->id, 'name' => $name, 'id' => $blueprint->id]
                )
            ) {
                redirect(
                    $redirecturl,
                    get_string('errorblueprintduplicate', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $blueprint->name = $name;
            $blueprint->description = $description;
            $blueprint->timemodified = time();
            $blueprint->usermodified = $USER->id;
            $DB->update_record('local_courseplanner_blueprints', $blueprint);
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            redirect(
                $redirecturl,
                get_string('blueprintupdated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'togglearchive':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            $blueprint->isarchived = $blueprint->isarchived ? 0 : 1;
            $blueprint->timemodified = time();
            $blueprint->usermodified = $USER->id;
            $DB->update_record('local_courseplanner_blueprints', $blueprint);
            $messagekey = $blueprint->isarchived ? 'blueprintarchived' : 'blueprintunarchived';
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            redirect(
                $redirecturl,
                get_string($messagekey, 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'linkblueprint':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            if ((int)$blueprint->isarchived === 1) {
                redirect(
                    $redirecturl,
                    get_string('errorarchivedblueprintlink', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $linknotes = trim(optional_param('linknotes', '', PARAM_TEXT));
            local_courseplanner_upsert_course_blueprint_link(
                $courseid,
                (int)$blueprint->id,
                'MANUAL',
                null,
                $linknotes,
                (int)$USER->id
            );
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            redirect(
                $redirecturl,
                get_string('courselinkupdated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'autolinkcourse':
            $suggestion = local_courseplanner_get_autolink_suggestion($course, (int)$USER->id);
            if (!$suggestion || !empty($suggestion['ambiguous'])) {
                redirect(
                    $redirecturl,
                    get_string('noautosuggestion', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $best = $suggestion['best'];
            local_courseplanner_upsert_course_blueprint_link(
                $courseid,
                (int)$best['blueprint']->id,
                'AUTO',
                (int)$best['confidence'],
                get_string('autolinknotes', 'local_courseplanner'),
                (int)$USER->id
            );
            $redirecturl->param('blueprintctx', (int)$best['blueprint']->id);
            redirect(
                $redirecturl,
                get_string('courseautolinked', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'unlinkblueprint':
            $DB->delete_records('local_courseplanner_courselink', ['courseid' => $courseid]);
            redirect(
                $redirecturl,
                get_string('courselinkremoved', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'createcalendar':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            $title = trim(optional_param('title', '', PARAM_TEXT));

            if ($title === '') {
                redirect(
                    $redirecturl,
                    get_string('calendartitlerequired', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $now = time();
            $record = (object)[
                'courseid' => $courseid,
                'blueprintid' => (int)$blueprint->id,
                'title' => $title,
                'isactive' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int)$USER->id,
            ];
            $DB->set_field('local_courseplanner_calendars', 'isactive', 0, ['courseid' => $courseid]);
            $DB->insert_record('local_courseplanner_calendars', $record);
            redirect(
                $redirecturl,
                get_string('calendarcreated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'updatecalendar':
            $calendarid = required_param('calendarid', PARAM_INT);
            $calendar = local_courseplanner_require_course_calendar($calendarid, $courseid);
            local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);
            $calendar->title = trim(optional_param('title', '', PARAM_TEXT));
            $calendar->timemodified = time();
            $calendar->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_calendars', $calendar);
            redirect(
                $redirecturl,
                get_string('calendarupdated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'togglecalendaractive':
            $calendarid = required_param('calendarid', PARAM_INT);
            $calendar = local_courseplanner_require_course_calendar($calendarid, $courseid);
            local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);
            $activating = (int)$calendar->isactive !== 1;
            if ($activating) {
                $DB->set_field('local_courseplanner_calendars', 'isactive', 0, ['courseid' => $courseid]);
            }
            $calendar->isactive = $activating ? 1 : 0;
            $calendar->timemodified = time();
            $calendar->usermodified = (int)$USER->id;
            $DB->update_record('local_courseplanner_calendars', $calendar);
            $messagekey = $activating ? 'calendaractivated' : 'calendardeactivated';
            redirect(
                $redirecturl,
                get_string($messagekey, 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'deletecalendar':
            $calendarid = required_param('calendarid', PARAM_INT);
            $calendar = local_courseplanner_require_course_calendar($calendarid, $courseid);
            local_courseplanner_require_owned_blueprint((int)$calendar->blueprintid, (int)$USER->id);
            $DB->delete_records('local_courseplanner_ruleruns', ['calendarid' => $calendar->id]);
            $DB->delete_records('local_courseplanner_blocks', ['calendarid' => $calendar->id]);
            $DB->delete_records('local_courseplanner_rules', ['calendarid' => $calendar->id]);
            $DB->delete_records('local_courseplanner_calendars', ['id' => $calendar->id]);
            redirect(
                $redirecturl,
                get_string('calendardeleted', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'createtopic':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            $title = trim(required_param('title', PARAM_TEXT));
            $type = local_courseplanner_normalise_topic_type(required_param('type', PARAM_ALPHANUMEXT));
            $contenthtml = trim(optional_param('contenthtml', '', PARAM_RAW));

            if ($title === '') {
                $redirecturl->param('blueprintctx', (int)$blueprint->id);
                redirect(
                    $redirecturl,
                    get_string('errortopictitlerequired', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $sortorder = (int)$DB->count_records('local_courseplanner_topics', ['blueprintid' => $blueprint->id]) + 1;
            $now = time();
            $record = (object)[
                'blueprintid' => $blueprint->id,
                'title' => $title,
                'type' => $type,
                'contenthtml' => $contenthtml,
                'sortorder' => $sortorder,
                'isactive' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $USER->id,
            ];
            $DB->insert_record('local_courseplanner_topics', $record);
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            redirect(
                $redirecturl,
                get_string('topiccreated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'updatetopic':
            $topicid = required_param('topicid', PARAM_INT);
            $topic = local_courseplanner_require_owned_topic($topicid, (int)$USER->id);
            $topic->title = trim(required_param('title', PARAM_TEXT));
            $topic->type = local_courseplanner_normalise_topic_type(required_param('type', PARAM_ALPHANUMEXT));
            $topic->contenthtml = trim(optional_param('contenthtml', '', PARAM_RAW));
            if ($topic->title === '') {
                $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
                redirect(
                    $redirecturl,
                    get_string('errortopictitlerequired', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            $topic->timemodified = time();
            $topic->usermodified = $USER->id;
            $DB->update_record('local_courseplanner_topics', $topic);
            $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
            redirect(
                $redirecturl,
                get_string('topicupdated', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'toggletopicactive':
            $topicid = required_param('topicid', PARAM_INT);
            $topic = local_courseplanner_require_owned_topic($topicid, (int)$USER->id);
            $topic->isactive = $topic->isactive ? 0 : 1;
            $topic->timemodified = time();
            $topic->usermodified = $USER->id;
            $DB->update_record('local_courseplanner_topics', $topic);
            $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
            $messagekey = $topic->isactive ? 'topicactivated' : 'topicdeactivated';
            redirect(
                $redirecturl,
                get_string($messagekey, 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'movetopicup':
        case 'movetopicdown':
            $topicid = required_param('topicid', PARAM_INT);
            $topic = local_courseplanner_require_owned_topic($topicid, (int)$USER->id);
            $direction = ($action === 'movetopicup') ? -1 : 1;
            local_courseplanner_move_topic($topic, $direction);
            $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
            redirect(
                $redirecturl,
                get_string('topicreordered', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'deletetopic':
            $topicid = required_param('topicid', PARAM_INT);
            $topic = local_courseplanner_require_owned_topic($topicid, (int)$USER->id);
            $usagerows = local_courseplanner_get_topic_usage_rows((int)$topic->id);
            if (!empty($usagerows)) {
                $examples = [];
                foreach (array_slice(array_values($usagerows), 0, 3) as $row) {
                    $examples[] = '#' . (int)$row->id . ' (' . local_courseplanner_calendar_label($row) . ')';
                }
                $detail = implode(', ', $examples);
                $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
                redirect(
                    $redirecturl,
                    get_string('errortopicinuse', 'local_courseplanner', (object)[
                        'count' => count($usagerows),
                        'calendars' => $detail,
                    ]),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }

            $DB->delete_records('local_courseplanner_topics', ['id' => $topic->id]);
            local_courseplanner_normalise_topic_sortorder((int)$topic->blueprintid);
            $redirecturl->param('blueprintctx', (int)$topic->blueprintid);
            redirect(
                $redirecturl,
                get_string('topicdeleted', 'local_courseplanner'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'deletealltopics':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            $deleted = local_courseplanner_delete_all_topics((int)$blueprint->id, false);
            if ($deleted < 0) {
                redirect(
                    $redirecturl,
                    get_string('deletealltopicsblocked', 'local_courseplanner'),
                    null,
                    \core\output\notification::NOTIFY_ERROR
                );
            }
            redirect(
                $redirecturl,
                get_string('deletealltopicsdone', 'local_courseplanner', $deleted),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        case 'forcedeletealltopics':
            $blueprintid = required_param('blueprintid', PARAM_INT);
            $blueprint = local_courseplanner_require_owned_blueprint($blueprintid, (int)$USER->id);
            $redirecturl->param('blueprintctx', (int)$blueprint->id);
            $deleted = local_courseplanner_delete_all_topics((int)$blueprint->id, true);
            redirect(
                $redirecturl,
                get_string('deletealltopicsdone', 'local_courseplanner', max(0, $deleted)),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;
    }
}

$url = new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('managepageheading', 'local_courseplanner'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css(new moodle_url('/local/courseplanner/styles.css'));

$allblueprints = local_courseplanner_get_teacher_blueprints((int)$USER->id, true);
$activeblueprints = array_filter($allblueprints, static function (stdClass $record): bool {
    return (int)$record->isarchived === 0;
});

$linkrecord = local_courseplanner_get_course_link_record($courseid);
$linkedblueprint = null;
if ($linkrecord) {
    $linkedblueprint = $DB->get_record('local_courseplanner_blueprints', ['id' => $linkrecord->blueprintid], '*', IGNORE_MISSING);
    if ($linkedblueprint && (int)$linkedblueprint->owneruserid !== (int)$USER->id) {
        $linkedblueprint = null;
    }
}

$suggestion = local_courseplanner_get_autolink_suggestion($course, (int)$USER->id);
$calendars = local_courseplanner_get_course_calendars($courseid);

if ($selectedblueprintid <= 0) {
    if ($linkedblueprint) {
        $selectedblueprintid = (int)$linkedblueprint->id;
    } else if (!empty($allblueprints)) {
        $firstblueprint = reset($allblueprints);
        $selectedblueprintid = (int)$firstblueprint->id;
    }
}

$selectedblueprint = null;
if ($selectedblueprintid > 0) {
    $selectedblueprint = $DB->get_record('local_courseplanner_blueprints', ['id' => $selectedblueprintid], '*', IGNORE_MISSING);
    if ($selectedblueprint && (int)$selectedblueprint->owneruserid !== (int)$USER->id) {
        $selectedblueprint = null;
    }
}

$topics = [];
if ($selectedblueprint) {
    $topics = array_values(local_courseplanner_get_blueprint_topics((int)$selectedblueprint->id, true));
    if ($topicfilter !== 'ALL') {
        $topics = array_values(array_filter($topics, static function (stdClass $topic) use ($topicfilter): bool {
            return $topic->type === $topicfilter;
        }));
    }
}

$suggestedblueprintid = 0;
if (!$linkedblueprint && !empty($suggestion) && empty($suggestion['ambiguous']) && !empty($suggestion['best'])) {
    $suggestedblueprintid = (int)$suggestion['best']['blueprint']->id;
}

$hasblueprints = !empty($allblueprints);
$hasactiveblueprints = !empty($activeblueprints);

[$calendars, $recommendedreasonkey] = local_courseplanner_rank_calendars($course, $calendars, time());
$recommendedcalendar = reset($calendars) ?: null;

$linkedtopiccount = null;
if ($linkedblueprint) {
    $linkedtopics = local_courseplanner_get_blueprint_topics((int)$linkedblueprint->id, true);
    $linkedtopiccount = count(array_filter($linkedtopics, static function (stdClass $topic): bool {
        return (int)$topic->isactive === 1;
    }));
}

editors_head_setup();
$topiceditor = editors_get_preferred_editor(FORMAT_HTML);
$topiceditoroptions = [
    'context' => $context,
    'autosave' => false,
    'enable_filemanagement' => false,
];

$rendercreateblueprintform = static function (bool $open = false) use ($courseid): void {
    $detailsattrs = [
        'class' => 'local-courseplanner-card local-courseplanner-create-blueprint',
        'id' => 'local-courseplanner-createblueprint',
    ];
    if ($open) {
        $detailsattrs['open'] = 'open';
    }

    echo html_writer::start_tag('details', $detailsattrs);
    echo html_writer::tag(
        'summary',
        get_string('createblueprintbutton', 'local_courseplanner'),
        ['class' => 'local-courseplanner-disclosure-summary local-courseplanner-disclosure-summary--primary']
    );
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'id' => 'local-courseplanner-createblueprint-form',
        'class' => 'local-courseplanner-disclosure-body',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'createblueprint']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('mb-2');
    echo html_writer::tag(
        'label',
        get_string('blueprintnamelabel', 'local_courseplanner'),
        ['for' => 'local-courseplanner-name-new']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'local-courseplanner-name-new',
        'name' => 'name',
        'class' => 'form-control',
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('mb-2');
    echo html_writer::tag(
        'label',
        get_string('blueprintdescriptionlabel', 'local_courseplanner'),
        ['for' => 'local-courseplanner-description-new']
    );
    echo html_writer::tag('textarea', '', [
        'id' => 'local-courseplanner-description-new',
        'name' => 'description',
        'rows' => 3,
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-primary',
        'value' => get_string('createblueprintsubmit', 'local_courseplanner'),
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
};

echo $OUTPUT->header();
echo html_writer::start_tag('div', ['class' => 'local-courseplanner-pageheader']);
echo $OUTPUT->heading(get_string('managepageheading', 'local_courseplanner'));
echo html_writer::tag('button', get_string('showtourbtn', 'local_courseplanner'), [
    'type' => 'button',
    'id' => 'local-courseplanner-showtour',
    'class' => 'btn btn-sm btn-outline-info local-courseplanner-showtour',
    'data-tour-name' => 'local_courseplanner_setup',
]);
echo html_writer::end_tag('div');

$nextsteptitle = '';
$nextstepbody = '';
$nextstepaction = '';
$nextstepbutton = '';
$nextstepcomplete = false;
if (!$hasblueprints) {
    $nextsteptitle = get_string('setupnext_createblueprint_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_createblueprint_body', 'local_courseplanner');
    $nextstepaction = '#local-courseplanner-createblueprint';
    $nextstepbutton = get_string('setupnext_createblueprint_action', 'local_courseplanner');
} else if (!$hasactiveblueprints) {
    $nextsteptitle = get_string('setupnext_restoreblueprint_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_restoreblueprint_body', 'local_courseplanner');
    $nextstepaction = '#local-courseplanner-section-blueprints';
    $nextstepbutton = get_string('setupnext_restoreblueprint_action', 'local_courseplanner');
} else if (!$linkedblueprint) {
    $nextsteptitle = get_string('setupnext_linkcourse_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_linkcourse_body', 'local_courseplanner');
    $nextstepaction = '#local-courseplanner-section-linkcourse';
    $nextstepbutton = get_string('setupnext_linkcourse_action', 'local_courseplanner');
} else if ($linkedtopiccount === 0) {
    $nextsteptitle = get_string('setupnext_addtopics_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_addtopics_body', 'local_courseplanner', format_string($linkedblueprint->name));
    $nextstepaction = '#local-courseplanner-section-topics';
    $nextstepbutton = get_string('setupnext_addtopics_action', 'local_courseplanner');
} else if (empty($calendars)) {
    $nextsteptitle = get_string('setupnext_createcalendar_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_createcalendar_body', 'local_courseplanner');
    $nextstepaction = '#local-courseplanner-createcalendar';
    $nextstepbutton = get_string('setupnext_createcalendar_action', 'local_courseplanner');
} else {
    $nextsteptitle = get_string('setupnext_opencalendar_title', 'local_courseplanner');
    $nextstepbody = get_string('setupnext_opencalendar_body', 'local_courseplanner');
    $nextstepaction = new moodle_url('/local/courseplanner/calendar.php', [
        'id' => $courseid,
        'calendarid' => (int)$recommendedcalendar->id,
    ]);
    $nextstepbutton = get_string('setupnext_opencalendar_action', 'local_courseplanner');
    $nextstepcomplete = true;
}

echo html_writer::start_div($nextstepcomplete
    ? 'local-courseplanner-nextstep-card local-courseplanner-nextstep-card--complete'
    : 'local-courseplanner-nextstep-card');
echo html_writer::div(get_string('setupnext_label', 'local_courseplanner'), 'local-courseplanner-nextstep-label');
echo html_writer::tag('h3', $nextsteptitle, ['class' => 'local-courseplanner-nextstep-title']);
echo html_writer::tag('p', $nextstepbody, ['class' => 'local-courseplanner-nextstep-body']);
if ($nextstepcomplete && $recommendedcalendar && $recommendedreasonkey !== '') {
    echo html_writer::div(get_string($recommendedreasonkey, 'local_courseplanner'), 'local-courseplanner-recommendation-reason');
}
echo html_writer::link($nextstepaction, $nextstepbutton, [
    'class' => 'btn btn-primary local-courseplanner-nextstep-action',
]);
echo html_writer::end_div();

if (!$hasblueprints) {
    echo $OUTPUT->heading(
        get_string('section_blueprintlibrary', 'local_courseplanner')
        . ' ' . $OUTPUT->help_icon('section_blueprintlibrary', 'local_courseplanner'),
        3,
        '',
        'local-courseplanner-section-blueprints'
    );
    $rendercreateblueprintform(true);
    echo $OUTPUT->footer();
    return;
}

echo $OUTPUT->heading(
    get_string('section_linkcourse', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_linkcourse', 'local_courseplanner'),
    3,
    '',
    'local-courseplanner-section-linkcourse'
);
if ($linkedblueprint) {
    $linkmode = strtoupper((string)($linkrecord->linkmode ?? ''));
    echo html_writer::start_tag('div', ['class' => 'local-courseplanner-linked-blueprint-row']);
    echo html_writer::start_div('local-courseplanner-blueprint-summary-main');
    echo html_writer::tag('span', format_string($linkedblueprint->name), ['class' => 'local-courseplanner-blueprint-name']);
    if ($linkedtopiccount !== null) {
        echo html_writer::tag(
            'span',
            get_string('blueprinttopiccount', 'local_courseplanner', $linkedtopiccount),
            ['class' => 'local-courseplanner-blueprint-shortcode']
        );
    }
    echo html_writer::tag(
        'span',
        get_string('courseblueprintlinkedbadge', 'local_courseplanner'),
        ['class' => 'local-courseplanner-badge local-courseplanner-badge--active']
    );
    if ($linkmode === 'AUTO' && $linkrecord->linkconfidence !== null) {
        echo html_writer::tag(
            'span',
            get_string('courseblueprintautobadge', 'local_courseplanner', (int)$linkrecord->linkconfidence),
            ['class' => 'local-courseplanner-blueprint-shortcode']
        );
    }
    echo html_writer::end_div();

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'local-courseplanner-row-action-form',
        'data-cc-confirm' => get_string('unlinkconfirm', 'local_courseplanner'),
        'data-cc-confirm-title' => get_string('confirm', 'core'),
        'data-cc-confirm-action' => get_string('unlinksubmit', 'local_courseplanner'),
        'data-cc-confirm-style' => 'delete',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'unlinkblueprint']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-outline-secondary btn-sm',
        'value' => get_string('unlinksubmit', 'local_courseplanner'),
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_tag('div');
} else {
    echo $OUTPUT->notification(get_string('courselinknone', 'local_courseplanner'), 'notifywarning');
}

if (!empty($activeblueprints) && !$linkedblueprint) {
    echo html_writer::start_tag(
        'form',
        ['method' => 'post', 'class' => 'local-courseplanner-card', 'id' => 'local-courseplanner-manuallink-form']
    );
    echo html_writer::tag('h4', get_string('manuallinkheading', 'local_courseplanner')
        . ' ' . $OUTPUT->help_icon('manuallinkheading', 'local_courseplanner'));
    if ($suggestedblueprintid > 0) {
        echo html_writer::tag('p', get_string('choseblueprintautohint', 'local_courseplanner'), [
            'class' => 'local-courseplanner-form-hint text-muted',
        ]);
    }
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'linkblueprint']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('mb-2');
    echo html_writer::tag(
        'label',
        get_string('blueprintlabel', 'local_courseplanner'),
        ['for' => 'local-courseplanner-blueprintid']
    );
    echo html_writer::start_tag(
        'select',
        ['id' => 'local-courseplanner-blueprintid', 'name' => 'blueprintid', 'class' => 'form-select']
    );
    foreach ($activeblueprints as $blueprint) {
        $attrs = ['value' => $blueprint->id];
        if ($linkedblueprint && (int)$linkedblueprint->id === (int)$blueprint->id) {
            $attrs['selected'] = 'selected';
        } else if (!$linkedblueprint && $suggestedblueprintid === (int)$blueprint->id) {
            $attrs['selected'] = 'selected';
        }
        echo html_writer::tag('option', format_string($blueprint->name), $attrs);
    }
    echo html_writer::end_tag('select');
    echo html_writer::end_div();

    echo html_writer::empty_tag(
        'input',
        ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('manuallinksubmit', 'local_courseplanner')]
    );
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(
    get_string('section_calendars', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_calendars', 'local_courseplanner'),
    3,
    '',
    'local-courseplanner-section-calendars'
);
if (!$linkedblueprint) {
    echo $OUTPUT->notification(get_string('calendarneedslink', 'local_courseplanner'), 'notifywarning');
} else {
    $createcalendarhtml = '';
    if ($linkedtopiccount === 0) {
        echo $OUTPUT->notification(get_string('calendarneedstopics', 'local_courseplanner'), 'notifyinfo');
    } else {
        ob_start();
        echo html_writer::start_tag('details', [
            'class' => 'local-courseplanner-card local-courseplanner-create-blueprint',
            'id' => 'local-courseplanner-createcalendar',
        ]);
        echo html_writer::tag(
            'summary',
            get_string('createcalendarbutton', 'local_courseplanner'),
            ['class' => 'local-courseplanner-disclosure-summary local-courseplanner-disclosure-summary--primary']
        );
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'id' => 'local-courseplanner-createcalendar-form',
            'class' => 'local-courseplanner-disclosure-body',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'createcalendar']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => (int)$linkedblueprint->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => $selectedblueprintid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_div('mb-2');
        echo html_writer::tag(
            'label',
            get_string('calendartitlelabel', 'local_courseplanner'),
            ['for' => 'local-courseplanner-title-new']
        );
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'local-courseplanner-title-new',
            'name' => 'title',
            'class' => 'form-control',
            'maxlength' => 255,
            'value' => local_courseplanner_suggest_calendar_title(time()),
            'placeholder' => get_string('calendartitleplaceholder', 'local_courseplanner'),
            'required' => 'required',
        ]);
        echo html_writer::end_div();

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('createcalendarsubmit', 'local_courseplanner'),
        ]);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('details');
        $createcalendarhtml = ob_get_clean();
    }

    if (empty($calendars)) {
        echo $OUTPUT->notification(get_string('nocalendars', 'local_courseplanner'), 'notifyinfo');
    } else {
        echo html_writer::start_tag('ul', ['class' => 'local-courseplanner-blueprint-list']);
        foreach ($calendars as $calendar) {
            $isactive = ((int)$calendar->isactive === 1);
            $badgekey = $isactive ? 'calendarbadgeactive' : 'calendarbadgeinactive';
            $badgeclass = $isactive ? 'local-courseplanner-badge--active' : 'local-courseplanner-badge--archived';
            $isrecommended = $recommendedcalendar && (int)$recommendedcalendar->id === (int)$calendar->id;
            $heading = local_courseplanner_calendar_label($calendar);

            echo html_writer::start_tag('li', ['class' => 'local-courseplanner-blueprint-item']);
            echo html_writer::start_tag('details', ['class' => 'local-courseplanner-blueprint-details']);

            $builderurl = new moodle_url(
                '/local/courseplanner/calendar.php',
                ['id' => $courseid, 'calendarid' => (int)$calendar->id]
            );
            echo html_writer::start_tag('summary', ['class' => 'local-courseplanner-blueprint-summary']);
            echo html_writer::start_div('local-courseplanner-blueprint-summary-main');
            echo html_writer::tag('span', $heading, ['class' => 'local-courseplanner-blueprint-name']);
            echo html_writer::tag(
                'span',
                get_string($badgekey, 'local_courseplanner'),
                ['class' => 'local-courseplanner-badge ' . $badgeclass]
            );
            if ($isrecommended) {
                echo html_writer::tag(
                    'span',
                    get_string('calendarrecommendedbadge', 'local_courseplanner'),
                    ['class' => 'local-courseplanner-badge local-courseplanner-badge--recommended']
                );
            }
            echo html_writer::end_div();
            echo html_writer::link($builderurl, get_string('opencalendarbuilderprominent', 'local_courseplanner'), [
                'class' => 'btn btn-primary local-courseplanner-open-builder',
                'onclick' => 'event.stopPropagation();',
            ]);
            echo html_writer::tag('span', get_string('editcalendarbutton', 'local_courseplanner'), [
                'class' => 'btn btn-outline-secondary btn-sm local-courseplanner-edit-indicator',
                'aria-hidden' => 'true',
            ]);
            echo html_writer::end_tag('summary');

            echo html_writer::start_div('local-courseplanner-disclosure-body');

            echo html_writer::start_tag('form', ['method' => 'post']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => (int)$calendar->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updatecalendar']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => $selectedblueprintid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

            echo html_writer::start_div('mb-2');
            echo html_writer::tag('label', get_string('calendartitlelabel', 'local_courseplanner'));
            echo html_writer::empty_tag('input', [
                'type' => 'text',
                'name' => 'title',
                'class' => 'form-control',
                'maxlength' => 255,
                'value' => s((string)$calendar->title),
            ]);
            echo html_writer::end_div();
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'class' => 'btn btn-secondary',
                'value' => get_string('savecalendarsubmit', 'local_courseplanner'),
            ]);
            echo html_writer::end_tag('form');

            echo html_writer::start_div('local-courseplanner-inline-controls');
            $calendaractions = [
                'togglecalendaractive' => 'togglecalendarsubmit',
                'deletecalendar' => 'deletecalendarsubmit',
            ];
            foreach ($calendaractions as $calendaraction => $labelkey) {
                $calformattrs = ['method' => 'post', 'class' => 'local-courseplanner-inline-form'];
                if ($calendaraction === 'deletecalendar') {
                    $calformattrs['data-cc-confirm'] = get_string('deletecalendarconfirm', 'local_courseplanner');
                    $calformattrs['data-cc-confirm-title'] = get_string('confirm', 'core');
                    $calformattrs['data-cc-confirm-action'] = get_string('deletecalendarsubmit', 'local_courseplanner');
                    $calformattrs['data-cc-confirm-style'] = 'delete';
                }
                echo html_writer::start_tag('form', $calformattrs);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'calendarid', 'value' => (int)$calendar->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $calendaraction]);
                echo html_writer::empty_tag(
                    'input',
                    ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => $selectedblueprintid]
                );
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                $buttonclass = ($calendaraction === 'deletecalendar') ? 'btn btn-outline-danger' : 'btn btn-outline-secondary';
                echo html_writer::empty_tag(
                    'input',
                    ['type' => 'submit', 'class' => $buttonclass, 'value' => get_string($labelkey, 'local_courseplanner')]
                );
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();

            echo html_writer::end_div();
            echo html_writer::end_tag('details');
            echo html_writer::end_tag('li');
        }
        echo html_writer::end_tag('ul');
    }
    echo $createcalendarhtml;
}

echo $OUTPUT->heading(
    get_string('section_blueprintlibrary', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_blueprintlibrary', 'local_courseplanner'),
    3,
    '',
    'local-courseplanner-section-blueprints'
);

if (empty($allblueprints)) {
    echo $OUTPUT->notification(get_string('noblueprints', 'local_courseplanner'), 'notifyinfo');
} else {
    echo html_writer::start_tag('ul', ['class' => 'local-courseplanner-blueprint-list']);
    foreach ($allblueprints as $blueprint) {
        $isarchived = (int)$blueprint->isarchived === 1;
        $statuskey = $isarchived ? 'blueprintstatusarchived' : 'blueprintstatusactive';
        $statusclass = $isarchived ? 'local-courseplanner-badge--archived' : 'local-courseplanner-badge--active';

        echo html_writer::start_tag('li', ['class' => 'local-courseplanner-blueprint-item']);
        echo html_writer::start_tag('details', ['class' => 'local-courseplanner-blueprint-details']);

        echo html_writer::start_tag('summary', ['class' => 'local-courseplanner-blueprint-summary']);
        echo html_writer::start_div('local-courseplanner-blueprint-summary-main');
        echo html_writer::tag('span', format_string($blueprint->name), ['class' => 'local-courseplanner-blueprint-name']);
        $blueprinttopiccount = $DB->count_records('local_courseplanner_topics', [
            'blueprintid' => (int)$blueprint->id,
            'isactive' => 1,
        ]);
        echo html_writer::tag(
            'span',
            get_string('blueprinttopiccount', 'local_courseplanner', $blueprinttopiccount),
            ['class' => 'local-courseplanner-blueprint-shortcode']
        );
        echo html_writer::tag(
            'span',
            get_string($statuskey, 'local_courseplanner'),
            ['class' => 'local-courseplanner-badge ' . $statusclass]
        );
        echo html_writer::end_div();
        echo html_writer::tag('span', get_string('editblueprintbutton', 'local_courseplanner'), [
            'class' => 'btn btn-outline-secondary btn-sm local-courseplanner-edit-indicator',
            'aria-hidden' => 'true',
        ]);
        echo html_writer::end_tag('summary');

        echo html_writer::start_div('local-courseplanner-disclosure-body');

        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => $blueprint->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('blueprintnamelabel', 'local_courseplanner'));
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'name',
            'class' => 'form-control',
            'required' => 'required',
            'value' => s((string)$blueprint->name),
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('blueprintdescriptionlabel', 'local_courseplanner'));
        echo html_writer::tag('textarea', s((string)$blueprint->description), [
            'name' => 'description',
            'rows' => 2,
            'class' => 'form-control',
        ]);
        echo html_writer::end_div();

        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updateblueprint']);
        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'class' => 'btn btn-secondary me-2',
            'value' => get_string('saveblueprintsubmit', 'local_courseplanner'),
        ]);
        echo html_writer::end_tag('form');

        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'local-courseplanner-inline-form']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => $blueprint->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'togglearchive']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $togglelabel = $isarchived
            ? get_string('unarchiveblueprintsubmit', 'local_courseplanner')
            : get_string('archiveblueprintsubmit', 'local_courseplanner');
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-secondary', 'value' => $togglelabel]);
        echo html_writer::end_tag('form');

        echo html_writer::end_div();
        echo html_writer::end_tag('details');
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}
$rendercreateblueprintform(false);

if (!$linkedblueprint) {
    echo $OUTPUT->footer();
    return;
}

echo $OUTPUT->heading(
    get_string('section_topics', 'local_courseplanner')
    . ' ' . $OUTPUT->help_icon('section_topics', 'local_courseplanner'),
    3,
    '',
    'local-courseplanner-section-topics'
);
if (!$selectedblueprint) {
    echo $OUTPUT->notification(get_string('notopicswithoutblueprint', 'local_courseplanner'), 'notifyinfo');
    echo $OUTPUT->footer();
    return;
}

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/courseplanner/manage.php', [], 'local-courseplanner-section-topics'))->out(false),
    'class' => 'local-courseplanner-card local-courseplanner-inline-controls',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::start_div('mb-2');
echo html_writer::tag(
    'label',
    get_string('topicblueprintcontextlabel', 'local_courseplanner'),
    ['for' => 'local-courseplanner-blueprintctx']
);
echo html_writer::start_tag(
    'select',
    ['id' => 'local-courseplanner-blueprintctx', 'name' => 'blueprintctx', 'class' => 'form-select']
);
foreach ($allblueprints as $blueprint) {
    $attrs = ['value' => $blueprint->id];
    if ((int)$blueprint->id === (int)$selectedblueprint->id) {
        $attrs['selected'] = 'selected';
    }
    $label = format_string($blueprint->name);
    if ((int)$blueprint->isarchived === 1) {
        $label .= ' [' . get_string('archivedshort', 'local_courseplanner') . ']';
    }
    echo html_writer::tag('option', $label, $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag(
    'label',
    get_string('topicfilterlabel', 'local_courseplanner'),
    ['for' => 'local-courseplanner-topicfilter']
);
echo html_writer::start_tag(
    'select',
    ['id' => 'local-courseplanner-topicfilter', 'name' => 'topicfilter', 'class' => 'form-select']
);
$filteroptions = array_merge(['ALL'], local_courseplanner_get_topic_types());
foreach ($filteroptions as $filteroption) {
    $attrs = ['value' => $filteroption];
    if ($topicfilter === $filteroption) {
        $attrs['selected'] = 'selected';
    }
    $label = ($filteroption === 'ALL') ? get_string('topicfilterall', 'local_courseplanner') : $filteroption;
    echo html_writer::tag('option', $label, $attrs);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();
echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('applyfilter', 'local_courseplanner')]
);
echo html_writer::end_tag('form');

$createtopichtml = '';
ob_start();
echo html_writer::start_tag('details', [
    'class' => 'local-courseplanner-card local-courseplanner-create-blueprint',
    'id' => 'local-courseplanner-createtopic',
]);
echo html_writer::tag(
    'summary',
    get_string('createtopicbutton', 'local_courseplanner'),
    ['class' => 'local-courseplanner-disclosure-summary local-courseplanner-disclosure-summary--primary']
);
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'local-courseplanner-disclosure-body']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'createtopic']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => (int)$selectedblueprint->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => (int)$selectedblueprint->id]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('mb-2');
echo html_writer::tag(
    'label',
    get_string('topictitlelabel', 'local_courseplanner'),
    ['for' => 'local-courseplanner-topictitle-new']
);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'local-courseplanner-topictitle-new',
    'name' => 'title',
    'class' => 'form-control',
    'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag(
    'label',
    get_string('topictypelabel', 'local_courseplanner'),
    ['for' => 'local-courseplanner-topictype-new']
);
echo html_writer::start_tag('select', ['id' => 'local-courseplanner-topictype-new', 'name' => 'type', 'class' => 'form-select']);
foreach (local_courseplanner_get_topic_types() as $topictype) {
    echo html_writer::tag('option', $topictype, ['value' => $topictype]);
}
echo html_writer::end_tag('select');
echo html_writer::end_div();

echo html_writer::start_div('mb-2');
echo html_writer::tag(
    'label',
    get_string('topiccontentlabel', 'local_courseplanner'),
    ['for' => 'local-courseplanner-topiccontent-new']
);
echo html_writer::tag('textarea', '', [
    'id' => 'local-courseplanner-topiccontent-new',
    'name' => 'contenthtml',
    'rows' => 8,
    'class' => 'form-control',
]);
$topiceditor->use_editor('local-courseplanner-topiccontent-new', $topiceditoroptions);
echo html_writer::end_div();
echo html_writer::empty_tag(
    'input',
    ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('createtopicsubmit', 'local_courseplanner')]
);
echo html_writer::end_tag('form');
echo html_writer::end_tag('details');
$createtopichtml = ob_get_clean();

if (empty($topics)) {
    echo $OUTPUT->notification(get_string('notopicsfound', 'local_courseplanner'), 'notifyinfo');
} else {
    echo html_writer::start_tag('ul', [
        'class' => 'local-courseplanner-blueprint-list local-courseplanner-topic-list',
        'id' => 'local-courseplanner-topiclist',
    ]);
    foreach ($topics as $topic) {
        $isactive = ((int)$topic->isactive === 1);
        $badgekey = $isactive ? 'topicstatusactive' : 'topicstatusinactive';
        $badgeclass = $isactive ? 'local-courseplanner-badge--active' : 'local-courseplanner-badge--archived';

        echo html_writer::start_tag('li', [
            'class' => 'local-courseplanner-blueprint-item local-courseplanner-topic-item',
            'data-topicid' => (int)$topic->id,
        ]);
        echo html_writer::start_tag('details', ['class' => 'local-courseplanner-blueprint-details']);

        echo html_writer::start_tag('summary', ['class' => 'local-courseplanner-blueprint-summary']);
        echo html_writer::tag('span', '⋮⋮', [
            'class' => 'local-courseplanner-drag-handle',
            'aria-hidden' => 'true',
            'title' => get_string('topicdraghandle', 'local_courseplanner'),
        ]);
        echo html_writer::start_div('local-courseplanner-blueprint-summary-main');
        echo html_writer::tag('span', (int)$topic->sortorder, ['class' => 'local-courseplanner-blueprint-shortcode']);
        echo html_writer::tag('span', format_string($topic->title), ['class' => 'local-courseplanner-blueprint-name']);
        echo html_writer::tag(
            'span',
            s($topic->type),
            ['class' => 'local-courseplanner-type-badge local-courseplanner-type-' . strtolower($topic->type)]
        );
        echo html_writer::tag(
            'span',
            get_string($badgekey, 'local_courseplanner'),
            ['class' => 'local-courseplanner-badge ' . $badgeclass]
        );
        echo html_writer::end_div();
        echo html_writer::tag('span', get_string('edittopicbutton', 'local_courseplanner'), [
            'class' => 'btn btn-outline-secondary btn-sm local-courseplanner-edit-indicator',
            'aria-hidden' => 'true',
        ]);
        echo html_writer::end_tag('summary');

        echo html_writer::start_div('local-courseplanner-disclosure-body');

        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicid', 'value' => (int)$topic->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updatetopic']);
        echo html_writer::empty_tag(
            'input',
            ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => (int)$selectedblueprint->id]
        );
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('topictitlelabel', 'local_courseplanner'));
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'title',
            'class' => 'form-control',
            'required' => 'required',
            'value' => s((string)$topic->title),
        ]);
        echo html_writer::end_div();

        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('topictypelabel', 'local_courseplanner'));
        echo html_writer::start_tag('select', ['name' => 'type', 'class' => 'form-select']);
        foreach (local_courseplanner_get_topic_types() as $topictype) {
            $attrs = ['value' => $topictype];
            if ($topic->type === $topictype) {
                $attrs['selected'] = 'selected';
            }
            echo html_writer::tag('option', $topictype, $attrs);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        $topiccontentid = 'local-courseplanner-topiccontent-' . (int)$topic->id;
        echo html_writer::start_div('mb-2');
        echo html_writer::tag('label', get_string('topiccontentlabel', 'local_courseplanner'), ['for' => $topiccontentid]);
        echo html_writer::tag('textarea', s((string)$topic->contenthtml), [
            'id' => $topiccontentid,
            'name' => 'contenthtml',
            'rows' => 8,
            'class' => 'form-control',
        ]);
        $topiceditor->use_editor($topiccontentid, $topiceditoroptions);
        echo html_writer::end_div();

        echo html_writer::empty_tag(
            'input',
            ['type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('savetopicsubmit', 'local_courseplanner')]
        );
        echo html_writer::end_tag('form');

        echo html_writer::start_div('local-courseplanner-inline-controls');
        foreach (['toggletopicactive' => 'toggletopicsubmit', 'deletetopic' => 'deletetopicsubmit'] as $topicaction => $labelkey) {
            $formattrs = ['method' => 'post', 'class' => 'local-courseplanner-inline-form'];
            if ($topicaction === 'deletetopic') {
                $formattrs['data-cc-confirm'] = get_string('deletetopicconfirm', 'local_courseplanner');
                $formattrs['data-cc-confirm-title'] = get_string('confirm', 'core');
                $formattrs['data-cc-confirm-action'] = get_string('deletetopicsubmit', 'local_courseplanner');
                $formattrs['data-cc-confirm-style'] = 'delete';
            }
            echo html_writer::start_tag('form', $formattrs);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicid', 'value' => (int)$topic->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $topicaction]);
            echo html_writer::empty_tag(
                'input',
                ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => (int)$selectedblueprint->id]
            );
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $buttonclass = ($topicaction === 'deletetopic') ? 'btn btn-outline-danger' : 'btn btn-outline-secondary';
            echo html_writer::empty_tag(
                'input',
                ['type' => 'submit', 'class' => $buttonclass, 'value' => get_string($labelkey, 'local_courseplanner')]
            );
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_tag('details');
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}

if (!empty($topics)) {
    $deletealltopicslabel = get_string('deletealltopicsbtn', 'local_courseplanner');
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'local-courseplanner-inline-form local-courseplanner-deletealltopics',
        'data-cc-confirm' => get_string('deletealltopicsconfirm', 'local_courseplanner'),
        'data-cc-confirm-title' => get_string('confirm', 'core'),
        'data-cc-confirm-action' => $deletealltopicslabel,
        'data-cc-confirm-style' => 'delete',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'deletealltopics']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => (int)$selectedblueprint->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => (int)$selectedblueprint->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-outline-danger',
        'value' => $deletealltopicslabel,
    ]);
    echo html_writer::end_tag('form');

    $forcedeletelabel = get_string('forcedeletealltopicsbtn', 'local_courseplanner');
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'local-courseplanner-inline-form local-courseplanner-deletealltopics',
        'data-cc-confirm' => get_string('forcedeletealltopicsconfirm', 'local_courseplanner'),
        'data-cc-confirm-title' => get_string('confirm', 'core'),
        'data-cc-confirm-action' => $forcedeletelabel,
        'data-cc-confirm-style' => 'delete',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'forcedeletealltopics']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintid', 'value' => (int)$selectedblueprint->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'blueprintctx', 'value' => (int)$selectedblueprint->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'topicfilter', 'value' => $topicfilter]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-danger',
        'value' => $forcedeletelabel,
    ]);
    echo html_writer::end_tag('form');
}

echo $createtopichtml;

$PAGE->requires->js_call_amd('local_courseplanner/confirmaction', 'init', []);

$tourid = local_courseplanner_get_tour_id_by_name('local_courseplanner_setup');
$PAGE->requires->js_call_amd('local_courseplanner/showtour', 'init', [
    $tourid,
    '#local-courseplanner-showtour',
]);

if ($selectedblueprint && !empty($topics)) {
    $PAGE->requires->js_call_amd('local_courseplanner/topicreorder', 'init', [
        (int)$courseid,
        (int)$selectedblueprint->id,
        '#local-courseplanner-topiclist',
    ]);
    $PAGE->requires->strings_for_js(['topicreordersaved'], 'local_courseplanner');
}

if ($selectedblueprint) {
    $importurl = new moodle_url('/local/courseplanner/import_topics.php', [
        'id' => $courseid,
        'blueprintid' => (int)$selectedblueprint->id,
    ]);
    echo html_writer::div(
        html_writer::link(
            $importurl,
            get_string('importtopicslink', 'local_courseplanner'),
            ['class' => 'local-courseplanner-faint-link']
        ),
        'local-courseplanner-faint-actions'
    );
}

echo $OUTPUT->footer();
