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
 * Open a collapsed section (a <details> element, or the one containing the target) when a same-page link
 * or the page URL points at it.
 *
 * @module     local_courseplanner/sections
 * @copyright  2026 misko92
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Open and scroll to the section a fragment points at.
 *
 * @param {string} hash Fragment such as "#local-courseplanner-createcalendar".
 */
const reveal = (hash) => {
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
 * Initialise.
 *
 * @param {string} selector Container whose same-page links should open sections.
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }
    root.addEventListener('click', (e) => {
        const link = e.target.closest('a[href^="#"]');
        if (link && link.getAttribute('href').length > 1) {
            e.preventDefault();
            reveal(link.getAttribute('href'));
        }
    });
    reveal(window.location.hash);
};
