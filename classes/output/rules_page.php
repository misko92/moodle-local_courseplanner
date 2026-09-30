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
use local_courseplanner\local\timeline;
use moodle_url;
use stdClass;

/**
 * The dates page: a calendar's first/last day of classes, closures, day swaps and notes.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rules_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $calendar
     */
    public function __construct(
        /** @var stdClass Calendar record. */
        protected stdClass $calendar,
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $calendarid = (int)$this->calendar->id;
        $confirmtitle = get_string('confirm', 'core');
        $rules = [];
        foreach (timeline::get_rules($calendarid) as $rule) {
            $typename = get_string('ruletype_' . $rule->ruletype, 'local_courseplanner');
            $label = trim((string)$rule->label) !== '' ? format_string($rule->label) : $typename;
            if ($rule->ruletype === 'DAY_SWAP' && ($rule->fromday || $rule->today)) {
                $label .= ' (' . $rule->fromday . ' → ' . $rule->today . ')';
            }
            $params = [['name' => 'ruleid', 'value' => $rule->id]];
            $deletelabel = get_string('deleterulesubmit', 'local_courseplanner');
            $rules[] = [
                'date' => userdate($rule->ruledate, get_string('strftimedatefullshort', 'core_langconfig'), 99),
                'isodate' => date('Y-m-d', (int)$rule->ruledate),
                'label' => $label,
                'description' => $rule->description,
                'typename' => $typename,
                'typeclass' => strtolower($rule->ruletype),
                'active' => (bool)$rule->isactive,
                'editargs' => json_encode(['calendarid' => $calendarid, 'ruleid' => (int)$rule->id]),
                'toggle' => ['action' => 'togglerule', 'params' => $params, 'sesskey' => sesskey(),
                    'btnclass' => 'btn-sm btn-outline-secondary',
                    'label' => get_string($rule->isactive ? 'deactivate' : 'activate', 'local_courseplanner')],
                'delete' => ['action' => 'deleterule', 'params' => $params, 'sesskey' => sesskey(),
                    'btnclass' => 'btn-sm btn-outline-danger', 'label' => $deletelabel,
                    'confirm' => ['message' => get_string('deleteruleconfirm', 'local_courseplanner'),
                        'title' => $confirmtitle, 'action' => $deletelabel, 'style' => 'delete']],
            ];
        }
        $params = ['id' => $this->calendar->courseid, 'calendarid' => $calendarid];
        return [
            'contextlabel' => get_string('buildercontextlabel', 'local_courseplanner', calendars::label($this->calendar)),
            'builderurl' => (new moodle_url('/local/courseplanner/calendar.php', $params))->out(false),
            'apply' => ['action' => 'applyrules', 'sesskey' => sesskey(), 'btnclass' => 'btn-primary',
                'label' => get_string('applyrulesbtn', 'local_courseplanner')],
            'rules' => $rules,
            'hasrules' => !empty($rules),
            'addargs' => json_encode(['calendarid' => $calendarid]),
            'helpapply' => $output->help_icon('section_rulesapply', 'local_courseplanner'),
            'helpexisting' => $output->help_icon('section_rulesexisting', 'local_courseplanner'),
        ];
    }
}
