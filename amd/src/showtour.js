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
 * "Show walkthrough" button: restarts the page's user tour, and keeps the tour's first step clear of the
 * navigation bars when it points at an element near the top of the page.
 *
 * @module     local_courseplanner/showtour
 * @copyright  2026 Greg Mulcair
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {resetTourState} from 'tool_usertours/usertours';

const SCROLL_CLASS = 'local-courseplanner-tour-active';

/**
 * While a tour popover is open and the button is near the top of the page, add a body class that reserves
 * space above the content (Moodle's tour can't scroll above the top of the page).
 *
 * @param {HTMLElement} button
 */
const watchTour = (button) => {
    let frame = 0;
    let popoverObserver = null;
    const evaluate = () => {
        frame = 0;
        const open = document.querySelector('[data-flexitour="container"]');
        document.body.classList.toggle(SCROLL_CLASS, !!open && button.getBoundingClientRect().top < 160);
    };
    const queue = () => {
        frame = frame || window.requestAnimationFrame(evaluate);
    };
    new MutationObserver(() => {
        const popover = document.querySelector('[data-flexitour="container"]');
        if (popover && !popoverObserver) {
            popoverObserver = new MutationObserver(queue);
            popoverObserver.observe(popover, {attributes: true, attributeFilter: ['style', 'class']});
            queue();
        } else if (!popover && popoverObserver) {
            popoverObserver.disconnect();
            popoverObserver = null;
            document.body.classList.remove(SCROLL_CLASS);
        }
    }).observe(document.body, {childList: true});
};

/**
 * Initialise.
 *
 * @param {number|null} tourId tool_usertours tour id, or null if the tour isn't installed.
 * @param {string} buttonSelector
 */
export const init = (tourId, buttonSelector) => {
    const button = document.querySelector(buttonSelector);
    if (!button) {
        return;
    }
    if (!tourId) {
        button.hidden = true;
        return;
    }
    watchTour(button);
    button.addEventListener('click', (e) => {
        e.preventDefault();
        resetTourState(tourId);
    });
};
