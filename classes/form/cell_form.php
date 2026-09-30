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
use local_courseplanner\local\grid;
use local_courseplanner\local\topics;
use moodle_url;

/**
 * Pop-up form for editing one content cell of the calendar grid.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cell_form extends dynamic_form {
    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        foreach (['calendarid', 'rownum', 'colnum'] as $field) {
            $mform->addElement('hidden', $field);
            $mform->setType($field, PARAM_INT);
        }

        $mform->addElement('select', 'blocktype', get_string('blocktype', 'local_courseplanner'), [
            'TEXT' => get_string('blocktypetext', 'local_courseplanner'),
            'TOPIC' => get_string('blocktypetopic', 'local_courseplanner'),
        ]);

        $mform->addElement('select', 'topicid', get_string('topic', 'local_courseplanner'), $this->get_topic_options());
        $mform->setType('topicid', PARAM_INT);
        $mform->hideIf('topicid', 'blocktype', 'neq', 'TOPIC');

        $mform->addElement(
            'editor',
            'content',
            get_string('cellcontent', 'local_courseplanner'),
            ['rows' => 6],
            self::editor_options($this->get_context_for_dynamic_submission())
        );
        $mform->setType('content', PARAM_RAW);
        $mform->hideIf('content', 'blocktype', 'neq', 'TEXT');

        $mform->addElement('textarea', 'cellheading', get_string('cellheading', 'local_courseplanner'), ['rows' => 2]);
        $mform->setType('cellheading', PARAM_RAW);

        $mform->addElement('advcheckbox', 'highlighted', get_string('cellhighlightedlabel', 'local_courseplanner'));
        $mform->addElement('advcheckbox', 'verticallycentred', get_string('cellverticalcentredlabel', 'local_courseplanner'));
        $mform->addElement('advcheckbox', 'clear', get_string('clearcell', 'local_courseplanner'));
    }

    /**
     * Editor options shared by the plugin's HTML fields (no file uploads).
     *
     * @param context $context
     * @return array
     */
    public static function editor_options(context $context): array {
        return ['context' => $context, 'maxfiles' => 0, 'enable_filemanagement' => false, 'autosave' => false];
    }

    /**
     * Topic choices: the blueprint's active topics, plus the cell's current topic if it has been deactivated.
     *
     * @return array
     */
    protected function get_topic_options(): array {
        [$calendar] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        $options = [0 => get_string('selecttopicplaceholder', 'local_courseplanner')];
        foreach (topics::get_for_blueprint((int)$calendar->blueprintid, true) as $topic) {
            $label = format_string($topic->title) . ' (' . $topic->type . ')';
            if (!$topic->isactive) {
                $current = $this->get_block();
                if (!$current || (int)$current->topicid !== (int)$topic->id) {
                    continue;
                }
                $label .= ' - ' . get_string('topicinactive', 'local_courseplanner');
            }
            $options[$topic->id] = $label;
        }
        return $options;
    }

    /**
     * The block currently in this cell, if any.
     *
     * @return \stdClass|null
     */
    protected function get_block(): ?\stdClass {
        global $DB;
        return $DB->get_record('local_courseplanner_blocks', [
            'calendarid' => $this->optional_param('calendarid', 0, PARAM_INT),
            'rownum' => $this->optional_param('rownum', 0, PARAM_INT),
            'colnum' => $this->optional_param('colnum', 0, PARAM_INT),
        ]) ?: null;
    }

    #[\Override]
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);
        if ((int)$data['rownum'] < 1 || (int)$data['colnum'] < 1) {
            $errors['blocktype'] = get_string('errorinvalidcell', 'local_courseplanner');
        }
        if (empty($data['clear']) && $data['blocktype'] === 'TOPIC') {
            [$calendar] = calendars::require_editable((int)$data['calendarid']);
            $topic = empty($data['topicid']) ? null : topics::require_owned((int)$data['topicid'], (int)$USER->id);
            if (!$topic || (int)$topic->blueprintid !== (int)$calendar->blueprintid) {
                $errors['topicid'] = get_string('errorinvalidtopicselection', 'local_courseplanner');
            }
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
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $block = $this->get_block();
        $istopic = $block && $block->blocktype === 'TOPIC';
        $this->set_data([
            'calendarid' => $this->optional_param('calendarid', 0, PARAM_INT),
            'rownum' => $this->optional_param('rownum', 0, PARAM_INT),
            'colnum' => $this->optional_param('colnum', 0, PARAM_INT),
            'blocktype' => $istopic ? 'TOPIC' : 'TEXT',
            'topicid' => $istopic ? (int)$block->topicid : 0,
            'content' => ['text' => $istopic ? '' : (string)($block->contenthtml ?? ''), 'format' => FORMAT_HTML],
            'cellheading' => (string)($block->cellheading ?? ''),
            'highlighted' => (int)($block->highlighted ?? 0),
            'verticallycentred' => (int)($block->verticallycentred ?? 0),
        ]);
    }

    #[\Override]
    public function process_dynamic_submission() {
        global $DB, $USER;
        $data = $this->get_data();
        [$calendar] = calendars::require_editable((int)$data->calendarid);
        $content = $data->blocktype === 'TEXT' ? trim($data->content['text']) : '';
        $cellheading = trim($data->cellheading);
        $isempty = $data->blocktype === 'TEXT' && $content === '' && $cellheading === ''
            && !$data->highlighted && !$data->verticallycentred;
        if ($data->clear || $isempty) {
            $DB->delete_records('local_courseplanner_blocks', [
                'calendarid' => $calendar->id, 'rownum' => $data->rownum, 'colnum' => $data->colnum,
            ]);
            return ['message' => get_string('cellcleared', 'local_courseplanner')];
        }
        grid::upsert_block(
            (int)$calendar->id,
            (int)$data->rownum,
            (int)$data->colnum,
            $data->blocktype,
            $content,
            (int)$USER->id,
            null,
            null,
            $data->blocktype === 'TOPIC' ? (int)$data->topicid : null,
            $cellheading,
            (int)$data->highlighted,
            (int)$data->verticallycentred
        );
        return ['message' => get_string('cellsaved', 'local_courseplanner')];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        [$calendar] = calendars::require_editable($this->optional_param('calendarid', 0, PARAM_INT));
        return new moodle_url('/local/courseplanner/calendar.php', ['id' => $calendar->courseid, 'calendarid' => $calendar->id]);
    }
}
