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
use local_courseplanner\local\course_info;
use stdClass;

/**
 * The student view of a course calendar (also used for the teacher preview and the embed page).
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $calendar Calendar record.
     * @param bool $showintro Include the course intro texts.
     */
    public function __construct(
        /** @var stdClass Calendar record. */
        protected stdClass $calendar,
        /** @var bool Include the course intro texts. */
        protected bool $showintro = true,
    ) {
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $context = \context_course::instance((int)$this->calendar->courseid);
        $format = static function (string $html) use ($context): string {
            if (trim($html) === '') {
                return '';
            }
            $html = format_text($html, FORMAT_HTML, ['context' => $context]);
            return preg_replace('/<a\b(?![^>]*\btarget=)/', '<a target="_blank"', $html);
        };
        $info = $this->showintro ? course_info::get((int)$this->calendar->courseid) : null;
        $intro = $format((string)($info->introhtml ?? ''));
        $links = $format((string)($info->linkshtml ?? ''));
        $grid = (new calendar_grid($this->calendar))->export_for_template($output);
        return [
            'label' => calendars::label($this->calendar),
            'intro' => $intro,
            'links' => $links,
            'hasintro' => $intro !== '' || $links !== '',
            'grid' => $grid,
            'empty' => !$grid['hasrows'],
        ];
    }
}
