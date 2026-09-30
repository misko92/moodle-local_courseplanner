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
 * Drag-and-drop reordering of a blueprint's topics on the setup page.
 *
 * @module     local_courseplanner/topicreorder
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import SortableList from 'core/sortable_list';

/**
 * Topic items in the list, in their current order.
 *
 * @param {HTMLElement} list
 * @returns {HTMLElement[]}
 */
const items = (list) => [...list.children].filter((li) => li.dataset.topicid);

/**
 * Initialise.
 *
 * @param {number} courseid
 * @param {number} blueprintid
 * @param {string} selector Topic list selector.
 */
export const init = (courseid, blueprintid, selector) => {
    const list = document.querySelector(selector);
    if (!list || items(list).length < 2) {
        return;
    }
    new SortableList(list);
    items(list).forEach((li) => {
        li.dataset.sortableListName = li.querySelector('.local-courseplanner-blueprint-name')?.textContent.trim() ?? '';
    });

    list.addEventListener(SortableList.EVENTS.elementDrop, (e) => {
        if (e.detail?.positionChanged === false) {
            return;
        }
        list.classList.add('local-courseplanner-list-saving');
        Ajax.call([{
            methodname: 'local_courseplanner_reorder_blueprint_topics',
            args: {courseid, blueprintid, topicids: items(list).map((li) => parseInt(li.dataset.topicid, 10))},
        }])[0].then(() => {
            items(list).forEach((li, index) => {
                const badge = li.querySelector('.local-courseplanner-blueprint-shortcode');
                if (badge) {
                    badge.textContent = String(index + 1);
                }
            });
            return null;
        }).catch(Notification.exception).finally(() => list.classList.remove('local-courseplanner-list-saving'));
    });
};
