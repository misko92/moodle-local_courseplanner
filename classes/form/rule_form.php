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

namespace local_courseplanner\form;

use context;
use core_form\dynamic_form;
use local_courseplanner\local\calendars;
use local_courseplanner\local\timeline;
use moodle_url;

/**
 * Pop-up form for adding or editing a calendar date (first/last day of classes, no class, day swap, note).
 *
 * Arguments: calendarid, and ruleid when editing.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends dynamic_form {
    /** @var string[] Days a day swap can refer to. */
    protected const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /**
     * The rule being edited, or null when adding.
     *
     * @return \stdClass|null
     */
    protected function get_rule(): ?\stdClass {
        $ruleid = $this->optional_param('ruleid', 0, PARAM_INT);
        return $ruleid ? timeline::require_rule($ruleid, $this->optional_param('calendarid', 0, PARAM_INT)) : null;
    }

    /**
     * Convert a date picked in the user's timezone to midnight in the server timezone, as rules are stored.
     *
     * @param int $time
     * @return int
     */
    protected static function user_to_server_date(int $time): int {
        $date = usergetdate($time);
        return mktime(0, 0, 0, $date['mon'], $date['mday'], $date['year']);
    }

    /**
     * Convert a stored rule date (server-timezone midnight) to the same calendar date in the user's timezone.
     *
     * @param int $time
     * @return int
     */
    protected static function server_to_user_date(int $time): int {
        return make_timestamp((int)date('Y', $time), (int)date('n', $time), (int)date('j', $time));
    }

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['calendarid', 'ruleid'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }
        $types = [];
        foreach (timeline::get_rule_types() as $type) {
            $types[$type] = get_string('ruletype_' . $type, 'local_courseplanner');
        }
        $rule = $this->get_rule();
        if ($rule) {
            $mform->addElement('hidden', 'ruletype');
            $mform->setType('ruletype', PARAM_ALPHAEXT);
            $mform->addElement(
                'static',
                'ruletypename',
                get_string('ruletypelabel', 'local_courseplanner'),
                $types[$rule->ruletype] ?? $rule->ruletype
            );
        } else {
            $mform->addElement('select', 'ruletype', get_string('ruletypelabel', 'local_courseplanner'), $types);
        }
        $mform->addElement('date_selector', 'ruledate', get_string('ruledatelabel', 'local_courseplanner'));
        $mform->addElement('text', 'label', get_string('rulelabellabel', 'local_courseplanner'), ['maxlength' => 255]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addElement('textarea', 'description', get_string('ruledescriptionlabel', 'local_courseplanner'), ['rows' => 2]);
        $mform->setType('description', PARAM_TEXT);
        $days = array_combine(self::WEEKDAYS, self::WEEKDAYS);
        $mform->addElement('select', 'fromday', get_string('fromdaylabel', 'local_courseplanner'), $days);
        $mform->addElement('select', 'today', get_string('todaylabel', 'local_courseplanner'), $days);
        $mform->hideIf('fromday', 'ruletype', 'neq', 'DAY_SWAP');
        $mform->hideIf('today', 'ruletype', 'neq', 'DAY_SWAP');
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!in_array($data['ruletype'], timeline::get_rule_types(), true)) {
            $errors['ruletype'] = get_string('invalidruletype', 'local_courseplanner');
        } else if (
            in_array($data['ruletype'], ['START', 'END'], true)
                && timeline::has_active_rule_of_type((int)$data['calendarid'], $data['ruletype'], (int)$data['ruleid'])
        ) {
            $errors['ruletype'] = get_string('errorrulestartendexists', 'local_courseplanner');
        }
        if ($data['ruletype'] === 'DAY_SWAP' && $data['fromday'] === $data['today']) {
            $errors['today'] = get_string('errordayswapsameday', 'local_courseplanner');
        }
        return $errors;
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        [, , $context] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        return $context;
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        $this->get_rule();
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $rule = $this->get_rule();
        $this->set_data([
            'calendarid' => $this->optional_param('calendarid', 0, PARAM_INT),
            'ruleid' => $rule->id ?? 0,
            'ruletype' => $rule->ruletype ?? 'NO_CLASS',
            'ruledate' => isset($rule->ruledate) ? self::server_to_user_date((int)$rule->ruledate) : time(),
            'label' => $rule->label ?? '',
            'description' => $rule->description ?? '',
            'fromday' => $rule->fromday ?? 'Monday',
            'today' => $rule->today ?? 'Monday',
        ]);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        [$calendar] = calendars::require_editable((int)$data->calendarid);
        $ruledate = self::user_to_server_date((int)$data->ruledate);
        $isswap = $data->ruletype === 'DAY_SWAP';
        $fromday = $isswap ? $data->fromday : null;
        $today = $isswap ? $data->today : null;
        if ($this->get_rule()) {
            timeline::update_rule(
                (int)$data->ruleid,
                $ruledate,
                $data->label,
                $data->description,
                $fromday,
                $today,
                (int)$USER->id
            );
            return ['message' => get_string('ruleupdated', 'local_courseplanner')];
        }
        timeline::create_rule(
            (int)$calendar->id,
            $data->ruletype,
            $ruledate,
            $data->label,
            $data->description,
            $fromday,
            $today,
            (int)$USER->id
        );
        return ['message' => get_string('rulecreated', 'local_courseplanner')];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        [$calendar] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        return new moodle_url('/local/courseplanner/rules.php', ['id' => $calendar->courseid, 'calendarid' => $calendar->id]);
    }
}
