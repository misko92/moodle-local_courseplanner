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
 * Open the plugin's pop-up forms from buttons. Once a form is saved, go to the redirecturl it returns, if any,
 * otherwise reload the page.
 *
 * A button opts in with data-modalform="<dynamic form class>", data-modalform-args='{"json": "args"}' and
 * data-modalform-title="Modal title".
 *
 * @module     local_courseplanner/modal_forms
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';

/**
 * Initialise pop-up form buttons within a container.
 *
 * @param {string} selector Container selector.
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-modalform]');
        if (!button || !root.contains(button)) {
            return;
        }
        e.preventDefault();
        const form = new ModalForm({
            formClass: button.dataset.modalform,
            args: JSON.parse(button.dataset.modalformArgs || '{}'),
            modalConfig: {title: button.dataset.modalformTitle, large: true},
            returnFocus: button,
        });
        form.addEventListener(form.events.FORM_SUBMITTED, (event) => {
            if (event.detail?.redirecturl) {
                window.location.href = event.detail.redirecturl;
            } else {
                window.location.reload();
            }
        });
        form.show();
    });
};
