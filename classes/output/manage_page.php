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

namespace local_courseplanner\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use local_courseplanner\local\blueprints;
use local_courseplanner\local\calendars;
use local_courseplanner\local\course_link;
use local_courseplanner\local\topics;
use moodle_url;
use stdClass;

/**
 * The course planner setup page: next step, course link, calendars, blueprint library and topics.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page implements renderable, templatable {
    /** @var stdClass[] The teacher's blueprints keyed by id. */
    protected array $blueprints;

    /** @var stdClass|null Course link record. */
    protected ?stdClass $link;

    /** @var stdClass|null Blueprint this course is linked to (if the teacher owns it). */
    protected ?stdClass $linked = null;

    /** @var stdClass|null Blueprint whose topics are shown. */
    protected ?stdClass $selected = null;

    /**
     * Constructor.
     *
     * @param stdClass $course
     * @param int $userid Teacher viewing the page.
     * @param int $selectedblueprintid Blueprint picked in the topics section (0 for default).
     * @param string $topicfilter Topic type to show, or ALL.
     */
    public function __construct(
        /** @var stdClass Course. */
        protected stdClass $course,
        /** @var int Teacher viewing the page. */
        protected int $userid,
        int $selectedblueprintid,
        /** @var string Topic type to show, or ALL. */
        protected string $topicfilter,
    ) {
        $this->blueprints = blueprints::get_for_teacher($userid, true);
        $this->link = course_link::get((int)$course->id);
        if ($this->link && isset($this->blueprints[$this->link->blueprintid])) {
            $this->linked = $this->blueprints[$this->link->blueprintid];
        }
        if (!isset($this->blueprints[$selectedblueprintid])) {
            $selectedblueprintid = $this->linked->id ?? (array_key_first($this->blueprints) ?? 0);
        }
        $this->selected = $this->blueprints[$selectedblueprintid] ?? null;
    }

    /**
     * Hidden fields that keep the page's blueprint and filter selection across form posts.
     *
     * @return array
     */
    protected function state_params(): array {
        return [
            ['name' => 'blueprintctx', 'value' => (int)($this->selected->id ?? 0)],
            ['name' => 'topicfilter', 'value' => $this->topicfilter],
        ];
    }

    /**
     * Template data for a one-button action form.
     *
     * @param string $action
     * @param string $label Lang string key.
     * @param string $btnclass
     * @param array $params Extra hidden fields as name => value.
     * @param string|null $confirm Lang string key of a confirmation message, if the action needs confirming.
     * @return array
     */
    protected function action(string $action, string $label, string $btnclass, array $params = [], ?string $confirm = null): array {
        $label = get_string($label, 'local_courseplanner');
        $hidden = $this->state_params();
        foreach ($params as $name => $value) {
            $hidden[] = ['name' => $name, 'value' => $value];
        }
        return [
            'action' => $action,
            'label' => $label,
            'btnclass' => $btnclass,
            'params' => $hidden,
            'sesskey' => sesskey(),
            'confirm' => $confirm ? ['message' => get_string($confirm, 'local_courseplanner'),
                'title' => get_string('confirm', 'core'), 'action' => $label, 'style' => 'delete'] : null,
        ];
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $courseid = (int)$this->course->id;
        $calendars = calendars::get_for_course($courseid);
        [$calendars, $reasonkey] = calendars::rank($this->course, $calendars, time());
        $recommended = reset($calendars) ?: null;
        $linkedtopiccount = $this->linked ? count(topics::get_for_blueprint((int)$this->linked->id, false)) : null;
        $help = static fn(string $key): string => $output->help_icon($key, 'local_courseplanner');

        return [
            'courseid' => $courseid,
            'sesskey' => sesskey(),
            'stateparams' => $this->state_params(),
            'nextstep' => $this->export_next_step($calendars, $recommended, $reasonkey, $linkedtopiccount),
            'hasblueprints' => !empty($this->blueprints),
            'link' => $this->export_link($linkedtopiccount, $output),
            'calendars' => $this->linked ? $this->export_calendars($calendars, $recommended, $linkedtopiccount) : null,
            'blueprints' => $this->export_blueprints(),
            'topics' => $this->linked && $this->selected ? $this->export_topics() : null,
            'showtopicsection' => (bool)$this->linked,
            'helpblueprintlibrary' => $help('section_blueprintlibrary'),
            'helplinkcourse' => $help('section_linkcourse'),
            'helpcalendars' => $help('section_calendars'),
            'helptopics' => $help('section_topics'),
        ];
    }

    /**
     * The "recommended next step" card.
     *
     * @param stdClass[] $calendars Ranked calendars.
     * @param stdClass|null $recommended
     * @param string $reasonkey
     * @param int|null $linkedtopiccount
     * @return array
     */
    protected function export_next_step(
        array $calendars,
        ?stdClass $recommended,
        string $reasonkey,
        ?int $linkedtopiccount
    ): array {
        $active = array_filter($this->blueprints, static fn($b) => !$b->isarchived);
        $step = match (true) {
            empty($this->blueprints) => ['createblueprint', '#local-courseplanner-createblueprint'],
            empty($active) => ['restoreblueprint', '#local-courseplanner-section-blueprints'],
            !$this->linked => ['linkcourse', '#local-courseplanner-section-linkcourse'],
            $linkedtopiccount === 0 => ['addtopics', '#local-courseplanner-section-topics'],
            empty($calendars) => ['createcalendar', '#local-courseplanner-createcalendar'],
            default => ['opencalendar', (new moodle_url(
                '/local/courseplanner/calendar.php',
                ['id' => $this->course->id, 'calendarid' => $recommended->id]
            ))->out(false)],
        };
        [$key, $url] = $step;
        $complete = $key === 'opencalendar';
        return [
            'title' => get_string("setupnext_{$key}_title", 'local_courseplanner'),
            'body' => get_string(
                "setupnext_{$key}_body",
                'local_courseplanner',
                $key === 'addtopics' ? format_string($this->linked->name) : null
            ),
            'button' => get_string("setupnext_{$key}_action", 'local_courseplanner'),
            'url' => $url,
            'complete' => $complete,
            'reason' => $complete && $reasonkey !== '' ? get_string($reasonkey, 'local_courseplanner') : '',
            'modal' => $key === 'createcalendar'
                ? ['form' => \local_courseplanner\form\calendar_form::class, 'args' => $this->calendar_form_args()] : null,
        ];
    }

    /**
     * The course-to-blueprint link section.
     *
     * @param int|null $linkedtopiccount
     * @param renderer_base $output
     * @return array
     */
    protected function export_link(?int $linkedtopiccount, renderer_base $output): array {
        $data = ['linked' => null, 'choose' => null];
        if ($this->linked) {
            $auto = strtoupper((string)($this->link->linkmode ?? '')) === 'AUTO' && $this->link->linkconfidence !== null;
            $data['linked'] = [
                'name' => format_string($this->linked->name),
                'topiccount' => get_string('blueprinttopiccount', 'local_courseplanner', $linkedtopiccount),
                'autobadge' => $auto ? get_string(
                    'courseblueprintautobadge',
                    'local_courseplanner',
                    (int)$this->link->linkconfidence
                ) : '',
                'unlink' => $this->action(
                    'unlinkblueprint',
                    'unlinksubmit',
                    'btn-outline-secondary btn-sm',
                    [],
                    'unlinkconfirm'
                ),
            ];
            return $data;
        }
        $suggestion = course_link::get_autolink_suggestion($this->course, $this->userid);
        $suggestedid = (!empty($suggestion['best']) && empty($suggestion['ambiguous']))
            ? (int)$suggestion['best']['blueprint']->id : 0;
        $options = [];
        foreach ($this->blueprints as $blueprint) {
            if (!$blueprint->isarchived) {
                $options[] = ['id' => $blueprint->id, 'name' => format_string($blueprint->name),
                    'selected' => (int)$blueprint->id === $suggestedid];
            }
        }
        if ($options) {
            $data['choose'] = [
                'options' => $options,
                'hint' => $suggestedid > 0,
                'helpicon' => $output->help_icon('manuallinkheading', 'local_courseplanner'),
            ];
        }
        return $data;
    }

    /**
     * The course calendars section.
     *
     * @param stdClass[] $calendars Ranked calendars.
     * @param stdClass|null $recommended
     * @param int|null $linkedtopiccount
     * @return array
     */
    protected function export_calendars(array $calendars, ?stdClass $recommended, ?int $linkedtopiccount): array {
        $items = [];
        foreach ($calendars as $calendar) {
            $params = ['calendarid' => $calendar->id];
            $items[] = [
                'id' => $calendar->id,
                'label' => calendars::label($calendar),
                'title' => $calendar->title,
                'active' => (bool)$calendar->isactive,
                'recommended' => $recommended && (int)$recommended->id === (int)$calendar->id,
                'builderurl' => (new moodle_url(
                    '/local/courseplanner/calendar.php',
                    ['id' => $this->course->id, 'calendarid' => $calendar->id]
                ))->out(false),
                'toggle' => $this->action(
                    'togglecalendaractive',
                    $calendar->isactive ? 'deactivate' : 'activate',
                    'btn-outline-secondary',
                    $params
                ),
                'delete' => $this->action(
                    'deletecalendar',
                    'deletecalendarsubmit',
                    'btn-outline-danger',
                    $params,
                    'deletecalendarconfirm'
                ),
            ];
        }
        return [
            'items' => $items,
            'needstopics' => $linkedtopiccount === 0,
            'createargs' => $this->calendar_form_args(),
        ];
    }

    /**
     * The blueprint library.
     *
     * @return array
     */
    protected function export_blueprints(): array {
        global $DB;
        $items = [];
        foreach ($this->blueprints as $blueprint) {
            $count = $DB->count_records('local_courseplanner_topics', ['blueprintid' => $blueprint->id, 'isactive' => 1]);
            $items[] = [
                'id' => $blueprint->id,
                'name' => format_string($blueprint->name),
                'rawname' => $blueprint->name,
                'description' => $blueprint->description,
                'topiccount' => get_string('blueprinttopiccount', 'local_courseplanner', $count),
                'archived' => (bool)$blueprint->isarchived,
                'toggle' => $this->action('togglearchive', $blueprint->isarchived ? 'unarchiveblueprintsubmit'
                    : 'archiveblueprintsubmit', 'btn-outline-secondary', ['blueprintid' => $blueprint->id]),
            ];
        }
        return ['items' => $items, 'openform' => empty($items)];
    }

    /**
     * The topics section for the selected blueprint.
     *
     * @return array
     */
    protected function export_topics(): array {
        $all = topics::get_for_blueprint((int)$this->selected->id, true);
        $shown = $this->topicfilter === 'ALL' ? $all : array_filter($all, fn($t) => $t->type === $this->topicfilter);
        $items = [];
        foreach ($shown as $topic) {
            $params = ['topicid' => $topic->id];
            $items[] = [
                'id' => $topic->id,
                'editargs' => json_encode(['courseid' => (int)$this->course->id, 'topicid' => (int)$topic->id]),
                'sortorder' => $topic->sortorder,
                'title' => format_string($topic->title),
                'type' => $topic->type,
                'typeclass' => strtolower($topic->type),
                'active' => (bool)$topic->isactive,
                'toggle' => $this->action(
                    'toggletopicactive',
                    $topic->isactive ? 'deactivate' : 'activate',
                    'btn-sm btn-outline-secondary',
                    $params
                ),
                'delete' => $this->action(
                    'deletetopic',
                    'deletetopicsubmit',
                    'btn-sm btn-outline-danger',
                    $params,
                    'deletetopicconfirm'
                ),
            ];
        }
        $blueprintoptions = [];
        foreach ($this->blueprints as $blueprint) {
            $blueprintoptions[] = [
                'id' => $blueprint->id,
                'name' => format_string($blueprint->name)
                    . ($blueprint->isarchived ? ' [' . get_string('archivedshort', 'local_courseplanner') . ']' : ''),
                'selected' => (int)$blueprint->id === (int)$this->selected->id,
            ];
        }
        $filteroptions = [['value' => 'ALL', 'label' => get_string('topicfilterall', 'local_courseplanner'),
            'selected' => $this->topicfilter === 'ALL']];
        foreach (topics::get_types() as $type) {
            $filteroptions[] = ['value' => $type, 'label' => $type, 'selected' => $this->topicfilter === $type];
        }
        $bp = ['blueprintid' => $this->selected->id];
        return [
            'blueprintid' => $this->selected->id,
            'createargs' => json_encode(['courseid' => (int)$this->course->id, 'blueprintid' => (int)$this->selected->id]),
            'items' => array_values($items),
            'hasitems' => !empty($items),
            'sortable' => $this->topicfilter === 'ALL' && count($items) > 1,
            'blueprintoptions' => $blueprintoptions,
            'filteroptions' => $filteroptions,
            'filterurl' => (new moodle_url('/local/courseplanner/manage.php', [], 'local-courseplanner-section-topics'))
                ->out(false),
            'deleteall' => $this->action(
                'deletealltopics',
                'deletealltopicsbtn',
                'btn-outline-danger',
                $bp,
                'deletealltopicsconfirm'
            ),
            'forcedeleteall' => $this->action(
                'forcedeletealltopics',
                'forcedeletealltopicsbtn',
                'btn-danger',
                $bp,
                'forcedeletealltopicsconfirm'
            ),
            'importurl' => (new moodle_url(
                '/local/courseplanner/import_topics.php',
                ['id' => $this->course->id, 'blueprintid' => $this->selected->id]
            ))->out(false),
        ];
    }

    /**
     * Arguments for the create-calendar pop-up form.
     *
     * @return string JSON.
     */
    protected function calendar_form_args(): string {
        return json_encode(['courseid' => (int)$this->course->id, 'blueprintid' => (int)$this->linked->id]);
    }
}
