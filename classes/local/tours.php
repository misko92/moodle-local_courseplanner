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

namespace local_courseplanner\local;

/**
 * The user tours shipped with the plugin.
 *
 * @package    local_courseplanner
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tours {
    /**
     * Look up a tour's numeric ID by its configured name.
     *
     * Returns null if tool_usertours is not installed or the tour does not exist.
     *
     * @param string $name The tour name (matches tool_usertours_tours.name).
     * @return int|null
     */
    public static function get_id_by_name(string $name): ?int {
        global $DB;
        if (!class_exists('\\tool_usertours\\manager')) {
            return null;
        }
        $id = $DB->get_field('tool_usertours_tours', 'id', ['name' => $name], IGNORE_MISSING);
        return $id ? (int)$id : null;
    }

    /**
     * List of user tours shipped with this plugin.
     *
     * Map of JSON filename (in local/courseplanner/tours/) to a version integer.
     * Bump the version when the JSON changes so the seeder re-imports it.
     *
     * @return array<string, int>
     */
    protected static function shipped(): array {
        return [
            'teacher_setup_tour.json'   => 4,
            'teacher_builder_tour.json' => 4,
            'teacher_rules_tour.json'   => 4,
        ];
    }

    /**
     * Install or refresh the plugin's shipped Moodle user tours.
     *
     * No-op if tool_usertours is not installed. Idempotent: it imports any
     * shipped tour whose version is newer than what's recorded in the DB,
     * removing the stale copy first. Uses the same configdata keys that
     * Moodle core uses for its own shipped tours so admins see the "shipped"
     * badge and warning on tours they might edit.
     *
     * Safe to call from both db/install.php and db/upgrade.php.
     */
    public static function install(): void {
        global $DB, $CFG;

        if (!class_exists('\\tool_usertours\\manager')) {
            return;
        }

        $shipped = self::shipped();
        $tourdir = $CFG->dirroot . '/local/courseplanner/tours/';

        $existingrecords = $DB->get_recordset('tool_usertours_tours');
        foreach ($existingrecords as $record) {
            $tour = \tool_usertours\tour::load_from_record($record);
            $filename = $tour->get_config('local_courseplanner_filename');
            if (empty($filename) || !isset($shipped[$filename])) {
                continue;
            }
            $installedversion = (int)$tour->get_config('local_courseplanner_version');
            if ($installedversion < $shipped[$filename]) {
                $tour->remove();
            } else {
                unset($shipped[$filename]);
            }
        }
        $existingrecords->close();

        if (class_exists('\\tool_usertours\\helper')) {
            \tool_usertours\helper::reset_tour_sortorder();
        }

        foreach ($shipped as $filename => $version) {
            $filepath = $tourdir . $filename;
            if (!is_readable($filepath)) {
                continue;
            }
            $tourjson = file_get_contents($filepath);
            if ($tourjson === false) {
                continue;
            }
            try {
                $tour = \tool_usertours\manager::import_tour_from_json($tourjson);
            } catch (\Throwable $e) {
                debugging('local_courseplanner: failed to import user tour ' . $filename . ': ' . $e->getMessage());
                continue;
            }

            $tour->set_config('local_courseplanner_filename', $filename);
            $tour->set_config('local_courseplanner_version', $version);
            $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_TOUR, true);
            $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_FILENAME, $filename);
            $tour->set_config(\tool_usertours\manager::CONFIG_SHIPPED_VERSION, $version);
            $tour->persist();

            if (defined('BEHAT_SITE_RUNNING') || (defined('PHPUNIT_TEST') && PHPUNIT_TEST)) {
                $tour->set_enabled(\tool_usertours\tour::DISABLED);
                $tour->persist();
            }
        }
    }
}
