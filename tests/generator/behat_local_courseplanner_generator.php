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
 * Behat data generator for local_courseplanner.
 *
 * @package    local_courseplanner
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_courseplanner_generator extends behat_generator_base {
    #[\Override]
    protected function get_creatable_entities(): array {
        return [
            'blueprints' => [
                'singular' => 'blueprint',
                'datagenerator' => 'blueprint',
                'required' => ['owner', 'name'],
                'switchids' => ['owner' => 'owneruserid'],
            ],
            'topics' => [
                'singular' => 'topic',
                'datagenerator' => 'topic',
                'required' => ['blueprint', 'title'],
                'switchids' => ['blueprint' => 'blueprintid'],
            ],
            'calendars' => [
                'singular' => 'calendar',
                'datagenerator' => 'calendar',
                'required' => ['course', 'blueprint', 'title'],
                'switchids' => ['course' => 'courseid', 'blueprint' => 'blueprintid'],
            ],
        ];
    }

    /**
     * Look up a user id from a username (for the 'owner' column).
     *
     * @param string $username
     * @return int
     */
    protected function get_owner_id(string $username): int {
        return $this->get_user_id($username);
    }

    /**
     * Look up a blueprint id from its name.
     *
     * @param string $name
     * @return int
     */
    protected function get_blueprint_id(string $name): int {
        global $DB;
        return (int)$DB->get_field('local_courseplanner_blueprints', 'id', ['name' => $name], MUST_EXIST);
    }

    /**
     * Pre-process calendar data: dates like "2026-09-08" become timestamps.
     *
     * @param array $data
     * @return array
     */
    protected function preprocess_calendar(array $data): array {
        foreach (['startdate', 'enddate'] as $field) {
            if (!empty($data[$field]) && !is_numeric($data[$field])) {
                $data[$field] = strtotime($data[$field]);
            }
        }
        return $data;
    }
}
