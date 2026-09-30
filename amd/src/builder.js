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
 * Calendar builder: drag one content cell onto another to swap them. (Edit buttons use local_courseplanner/modal_forms.)
 *
 * @module     local_courseplanner/builder
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Set up drag-and-drop: dropping one content cell on another swaps them.
 *
 * @param {HTMLElement} root Builder container.
 */
const initDragDrop = (root) => {
    const courseid = parseInt(root.dataset.courseid, 10);
    const calendarid = parseInt(root.dataset.calendarid, 10);
    let source = null;
    const cellFrom = (target) => target.closest('[data-cc-editable="1"]');

    root.querySelectorAll('[data-cc-editable="1"]').forEach((cell) => cell.setAttribute('draggable', 'true'));

    root.addEventListener('dragstart', (e) => {
        source = cellFrom(e.target);
        if (source) {
            source.classList.add('local-courseplanner-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', source.dataset.ccRow + ',' + source.dataset.ccCol);
        }
    });
    root.addEventListener('dragover', (e) => {
        const cell = cellFrom(e.target);
        if (source && cell) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            cell.classList.add('local-courseplanner-dragover');
        }
    });
    root.addEventListener('dragleave', (e) => {
        cellFrom(e.target)?.classList.remove('local-courseplanner-dragover');
    });
    root.addEventListener('dragend', () => {
        source?.classList.remove('local-courseplanner-dragging');
        root.querySelectorAll('.local-courseplanner-dragover').forEach((el) => el.classList.remove('local-courseplanner-dragover'));
        source = null;
    });
    root.addEventListener('drop', (e) => {
        const target = cellFrom(e.target);
        if (!source || !target) {
            return;
        }
        e.preventDefault();
        target.classList.remove('local-courseplanner-dragover');
        if (source === target) {
            return;
        }
        Ajax.call([{
            methodname: 'local_courseplanner_swap_builder_cells',
            args: {
                courseid,
                calendarid,
                fromrow: parseInt(source.dataset.ccRow, 10),
                fromcol: parseInt(source.dataset.ccCol, 10),
                torow: parseInt(target.dataset.ccRow, 10),
                tocol: parseInt(target.dataset.ccCol, 10),
            },
        }])[0].then((result) => {
            if (result.status === 'ok') {
                window.location.reload();
            } else {
                Notification.addNotification({message: result.message, type: 'error'});
            }
            return result;
        }).catch(Notification.exception);
    });
};

/**
 * Initialise the builder.
 *
 * @param {string} selector Builder container selector.
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    initDragDrop(root);
};
