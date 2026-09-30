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
use local_courseplanner\local\course_info;
use moodle_url;

/**
 * Pop-up form for the intro texts shown to students above a course's calendar.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_info_form extends dynamic_form {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $options = cell_form::editor_options($this->get_context_for_dynamic_submission());
        $mform->addElement(
            'editor',
            'intro',
            get_string('courseinfointroleftlabel', 'local_courseplanner'),
            ['rows' => 10],
            $options
        );
        $mform->setType('intro', PARAM_RAW);
        $mform->addElement(
            'editor',
            'links',
            get_string('courseinfointrorightlabel', 'local_courseplanner'),
            ['rows' => 10],
            $options
        );
        $mform->setType('links', PARAM_RAW);
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        return context_course::instance($this->optional_param('courseid', 0, PARAM_INT));
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/courseplanner:manage', $this->get_context_for_dynamic_submission());
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $courseid = $this->optional_param('courseid', 0, PARAM_INT);
        $info = course_info::get($courseid);
        $this->set_data([
            'courseid' => $courseid,
            'intro' => ['text' => (string)($info->introhtml ?? ''), 'format' => FORMAT_HTML],
            'links' => ['text' => (string)($info->linkshtml ?? ''), 'format' => FORMAT_HTML],
        ]);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        course_info::save((int)$data->courseid, trim($data->intro['text']), trim($data->links['text']), (int)$USER->id);
        return ['message' => get_string('courseinfosaved', 'local_courseplanner')];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/courseplanner/manage.php', ['id' => $this->optional_param('courseid', 0, PARAM_INT)]);
    }
}
