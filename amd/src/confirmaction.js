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
 * Ask for confirmation before submitting a form, using Moodle's standard dialogs.
 *
 * A form opts in with data-cc-confirm (the question), and optionally data-cc-confirm-title,
 * data-cc-confirm-action (button label) and data-cc-confirm-style ("delete" for destructive actions).
 *
 * @module     local_courseplanner/confirmaction
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {deleteCancelPromise, saveCancelPromise} from 'core/notification';

/**
 * Initialise: intercept submits of any form with data-cc-confirm.
 */
export const init = () => {
    if (document.body.dataset.ccConfirmBound) {
        return;
    }
    document.body.dataset.ccConfirmBound = '1';
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-cc-confirm]');
        if (!form) {
            return;
        }
        if (form.dataset.ccConfirmGo === '1') {
            form.dataset.ccConfirmGo = '';
            return;
        }
        e.preventDefault();
        const dialog = form.dataset.ccConfirmStyle === 'delete' ? deleteCancelPromise : saveCancelPromise;
        dialog(form.dataset.ccConfirmTitle, form.dataset.ccConfirm, form.dataset.ccConfirmAction)
            .then(() => {
                form.dataset.ccConfirmGo = '1';
                form.requestSubmit();
                return null;
            })
            .catch(() => null);
    });
};
