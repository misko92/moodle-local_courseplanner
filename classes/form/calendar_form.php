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
use context_course;
use core_form\dynamic_form;
use local_courseplanner\local\blueprints;
use local_courseplanner\local\calendars;
use local_courseplanner\local\timeline;
use moodle_url;

/**
 * Pop-up form for creating a course calendar with its first/last day of classes and terms in one go.
 *
 * Arguments: courseid, blueprintid.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_form extends dynamic_form {
    /** @var int Number of term slots offered. */
    protected const TERMS = 3;

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['courseid', 'blueprintid'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }
        $mform->addElement('text', 'title', get_string('calendartitlelabel', 'local_courseplanner'), ['maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');

        $mform->addElement('header', 'dates', get_string('calendardatesheading', 'local_courseplanner'));
        $mform->setExpanded('dates');
        $mform->addElement(
            'date_selector',
            'startdate',
            get_string('firstdayofclasses', 'local_courseplanner'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_selector',
            'enddate',
            get_string('lastdayofclasses', 'local_courseplanner'),
            ['optional' => true]
        );

        $mform->addElement('header', 'terms', get_string('termsheading', 'local_courseplanner'));
        $mform->setExpanded('terms');
        $mform->addElement('static', 'termshelp', '', get_string('termshelp', 'local_courseplanner'));
        for ($i = 1; $i <= self::TERMS; $i++) {
            $mform->addElement('text', "termname{$i}", get_string('termname', 'local_courseplanner', $i), ['maxlength' => 255]);
            $mform->setType("termname{$i}", PARAM_TEXT);
            $mform->addElement(
                'date_selector',
                "termdate{$i}",
                get_string('termstart', 'local_courseplanner', $i),
                ['optional' => true]
            );
        }
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim($data['title']) === '') {
            $errors['title'] = get_string('calendartitlerequired', 'local_courseplanner');
        }
        if (!empty($data['startdate']) && !empty($data['enddate']) && $data['enddate'] <= $data['startdate']) {
            $errors['enddate'] = get_string('errorrulesendbeforestart', 'local_courseplanner');
        }
        for ($i = 1; $i <= self::TERMS; $i++) {
            if (!empty($data["termdate{$i}"]) && trim($data["termname{$i}"]) === '') {
                $errors["termname{$i}"] = get_string('errortermnamerequired', 'local_courseplanner');
            }
        }
        return $errors;
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        return context_course::instance($this->optional_param('courseid', 0, PARAM_INT));
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        global $USER;
        require_capability('local/courseplanner:manage', $this->get_context_for_dynamic_submission());
        blueprints::require_owned($this->optional_param('blueprintid', 0, PARAM_INT), (int)$USER->id);
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $data = [
            'courseid' => $this->optional_param('courseid', 0, PARAM_INT),
            'blueprintid' => $this->optional_param('blueprintid', 0, PARAM_INT),
            'title' => calendars::suggest_title(time()),
        ];
        for ($i = 1; $i <= self::TERMS; $i++) {
            $data["termname{$i}"] = get_string('defaulttermname', 'local_courseplanner', $i);
        }
        $this->set_data($data);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        $userid = (int)$USER->id;
        $calendarid = calendars::create((int)$data->courseid, (int)$data->blueprintid, trim($data->title), $userid);
        $dates = ['START' => $data->startdate ?? 0, 'END' => $data->enddate ?? 0];
        foreach ($dates as $type => $date) {
            if ($date) {
                timeline::create_rule($calendarid, $type, timeline::date_from_user((int)$date), '', '', null, null, $userid);
            }
        }
        for ($i = 1; $i <= self::TERMS; $i++) {
            if (!empty($data->{"termdate{$i}"})) {
                timeline::create_rule(
                    $calendarid,
                    'TERM',
                    timeline::date_from_user((int)$data->{"termdate{$i}"}),
                    trim($data->{"termname{$i}"}),
                    '',
                    null,
                    null,
                    $userid
                );
            }
        }
        if ($dates['START'] && $dates['END']) {
            timeline::apply($calendarid, $userid);
        }
        return ['redirecturl' => (new moodle_url(
            '/local/courseplanner/calendar.php',
            ['id' => $data->courseid, 'calendarid' => $calendarid]
        ))->out(false)];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/courseplanner/manage.php', ['id' => $this->optional_param('courseid', 0, PARAM_INT)]);
    }
}
