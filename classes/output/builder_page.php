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
use local_courseplanner\local\calendars;
use moodle_url;
use stdClass;

/**
 * The calendar builder page: actions, automation buttons and the editable grid.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class builder_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $calendar Calendar record.
     */
    public function __construct(
        /** @var stdClass Calendar record. */
        protected stdClass $calendar,
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $courseid = (int)$this->calendar->courseid;
        $params = ['id' => $courseid, 'calendarid' => (int)$this->calendar->id];
        $confirmtitle = get_string('confirm', 'core');
        $automation = [];
        foreach (
            [
            'autopopulate' => ['autopopulatebtn', 'btn-success', 'autopopulateconfirm', 'save'],
            'fillproblemsessions' => ['fillproblemsessionsbtn', 'btn-outline-success', 'fillproblemsessionsconfirm', 'save'],
            'deletenonheader' => ['deletenonheaderbtn', 'btn-outline-danger', 'deletenonheaderconfirm', 'delete'],
            'deletenonheadernontext' => ['deletenonheadernontextbtn', 'btn-outline-danger', 'deletenonheadernontextconfirm',
                'delete'],
            ] as $action => [$label, $class, $confirm, $style]
        ) {
            $label = get_string($label, 'local_courseplanner');
            $automation[] = [
                'action' => $action,
                'label' => $label,
                'btnclass' => 'btn-sm ' . $class,
                'sesskey' => sesskey(),
                'confirm' => ['message' => get_string($confirm, 'local_courseplanner'), 'title' => $confirmtitle,
                    'action' => $label, 'style' => $style],
            ];
        }
        $weekrow = static fn(string $action, string $label, string $class): array => [
            'action' => $action, 'label' => get_string($label, 'local_courseplanner'), 'btnclass' => $class,
            'sesskey' => sesskey(),
        ];
        $help = static fn(string $key): string => $output->help_icon($key, 'local_courseplanner');
        $rulesurl = new moodle_url('/local/courseplanner/rules.php', $params);
        return [
            'courseid' => $courseid,
            'calendarid' => (int)$this->calendar->id,
            'courseinfoargs' => json_encode(['courseid' => $courseid]),
            'contextlabel' => get_string('buildercontextlabel', 'local_courseplanner', calendars::label($this->calendar)),
            'backurl' => (new moodle_url('/local/courseplanner/manage.php', ['id' => $courseid]))->out(false),
            'contenturl' => (new moodle_url(
                '/local/courseplanner/manage.php',
                ['id' => $courseid, 'blueprintctx' => $this->calendar->blueprintid]
            ))->out(false),
            'rulesurl' => $rulesurl->out(false),
            'previewurl' => (new moodle_url('/local/courseplanner/view.php', $params))->out(false),
            'coverageurl' => (new moodle_url('/local/courseplanner/coverage.php', $params))->out(false),
            'automation' => $automation,
            'weekrowactions' => [
                $weekrow('addweekrow', 'addweekrowsubmit', 'btn-primary'),
                $weekrow('removelastweekrow', 'removelastweekrowsubmit', 'btn-outline-secondary'),
            ],
            'autobuildreminder' => get_string(
                'weekrowautobuildreminder',
                'local_courseplanner',
                \html_writer::link($rulesurl, get_string('manageruleslink', 'local_courseplanner'), ['class' => 'alert-link'])
            ),
            'helpactions' => $help('section_builderactions'),
            'helpautomation' => $help('section_automation'),
            'helpintrotexts' => $help('section_introtexts'),
            'helpgrid' => $help('section_buildergrid'),
            'grid' => (new calendar_grid($this->calendar, true))->export_for_template($output),
        ];
    }
}
