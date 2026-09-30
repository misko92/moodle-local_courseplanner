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
 * Course planner setup page: pop-up topic forms, and opening a collapsed section when a link points at it.
 *
 * @module     local_courseplanner/manage
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';
import {getString} from 'core/str';

/**
 * Open the topic form (create when topicid is 0) and reload the page once it is saved.
 *
 * @param {number} courseid
 * @param {Object} args topicid or blueprintid.
 * @param {HTMLElement} returnFocus
 */
const openTopicForm = (courseid, args, returnFocus) => {
    const form = new ModalForm({
        formClass: 'local_courseplanner\\form\\topic_form',
        args: {courseid, ...args},
        modalConfig: {
            title: getString(args.topicid ? 'edittopic' : 'createtopicbutton', 'local_courseplanner'),
            large: true,
        },
        returnFocus,
    });
    form.addEventListener(form.events.FORM_SUBMITTED, () => window.location.reload());
    form.show();
};

/**
 * Open a collapsed <details> section (or the one containing the target) and scroll to it.
 *
 * @param {string} hash Fragment such as "#local-courseplanner-createcalendar".
 */
const revealSection = (hash) => {
    const target = hash.length > 1 ? document.getElementById(hash.substring(1)) : null;
    if (!target) {
        return;
    }
    const details = target.tagName === 'DETAILS' ? target : target.closest('details');
    if (details) {
        details.open = true;
    }
    target.scrollIntoView({behavior: 'smooth', block: 'start'});
};

/**
 * Initialise the setup page.
 *
 * @param {string} selector Page container selector.
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    const courseid = parseInt(root.dataset.courseid, 10);
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="create-topic"], [data-action="edit-topic"]');
        if (button) {
            e.preventDefault();
            const args = button.dataset.action === 'edit-topic'
                ? {topicid: parseInt(button.dataset.topicid, 10)}
                : {blueprintid: parseInt(button.dataset.blueprintid, 10)};
            openTopicForm(courseid, args, button);
            return;
        }
        const link = e.target.closest('a[href^="#"]');
        if (link) {
            e.preventDefault();
            revealSection(link.getAttribute('href'));
        }
    });
    revealSection(window.location.hash);
};
