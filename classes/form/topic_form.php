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
use local_courseplanner\local\topics;
use moodle_url;

/**
 * Pop-up form for creating a blueprint topic or editing an existing one.
 *
 * Arguments: courseid (the course the teacher is working in), and either topicid (edit) or blueprintid (create).
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class topic_form extends dynamic_form {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['courseid', 'topicid', 'blueprintid'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }
        if ($this->optional_param('topicid', 0, PARAM_INT) && $this->optional_param('shared', 0, PARAM_BOOL)) {
            $mform->addElement(
                'static',
                'sharedwarning',
                '',
                \html_writer::div(get_string('sharedtopiceditwarning', 'local_courseplanner'), 'alert alert-warning')
            );
        }
        $mform->addElement('text', 'title', get_string('topictitlelabel', 'local_courseplanner'), ['maxlength' => 255]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $types = topics::get_types();
        $mform->addElement('select', 'type', get_string('topictypelabel', 'local_courseplanner'), array_combine($types, $types));
        $mform->addElement(
            'editor',
            'content',
            get_string('topiccontentlabel', 'local_courseplanner'),
            ['rows' => 8],
            cell_form::editor_options($this->get_context_for_dynamic_submission())
        );
        $mform->setType('content', PARAM_RAW);
    }

    /**
     * The topic being edited, or null when creating.
     *
     * @return \stdClass|null
     */
    protected function get_topic(): ?\stdClass {
        global $USER;
        $topicid = $this->optional_param('topicid', 0, PARAM_INT);
        return $topicid ? topics::require_owned($topicid, (int)$USER->id) : null;
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim($data['title']) === '') {
            $errors['title'] = get_string('errortopictitlerequired', 'local_courseplanner');
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
        if (!$this->get_topic()) {
            blueprints::require_owned($this->optional_param('blueprintid', 0, PARAM_INT), (int)$USER->id);
        }
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $topic = $this->get_topic();
        $this->set_data([
            'courseid' => $this->optional_param('courseid', 0, PARAM_INT),
            'topicid' => $topic->id ?? 0,
            'blueprintid' => $topic->blueprintid ?? $this->optional_param('blueprintid', 0, PARAM_INT),
            'title' => $topic->title ?? '',
            'type' => $topic->type ?? 'LECTURE',
            'content' => ['text' => (string)($topic->contenthtml ?? ''), 'format' => FORMAT_HTML],
        ]);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        $topic = $this->get_topic();
        $fields = ['title' => trim($data->title), 'type' => topics::normalise_type($data->type),
            'contenthtml' => trim($data->content['text'])];
        if ($topic) {
            topics::update((int)$topic->id, $fields, (int)$USER->id);
            return ['message' => get_string('topicupdated', 'local_courseplanner')];
        }
        topics::create((int)$data->blueprintid, $fields, (int)$USER->id);
        return ['message' => get_string('topiccreated', 'local_courseplanner')];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/courseplanner/manage.php', ['id' => $this->optional_param('courseid', 0, PARAM_INT)]);
    }
}
