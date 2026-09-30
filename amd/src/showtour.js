/**
 * "Show walkthrough" button shim for local_courseplanner.
 *
 * Wires a user-visible button to Moodle's tool_usertours reset-tour API so
 * teachers can re-trigger the guided walkthrough on any page that ships one.
 * If tool_usertours is unavailable or the tour cannot be resolved, the
 * button is hidden so we fail gracefully.
 *
 * @module local_courseplanner/showtour
 */
define(['core/log'], function(log) {
    'use strict';

    var SCROLL_CLASS = 'local-courseplanner-tour-active';

    /**
     * Moodle's user tour clamps scrollTop to [0, maxScroll], so when a step
     * targets an element near the top of the page, the step's tooltip and the
     * target end up hugging the Moodle primary/secondary nav. This observer
     * watches for the tour popover to appear, and while it's pointing at the
     * target button (or any element near the top), it toggles a body class
     * that reserves visual space above the content.
     *
     * @param {string} buttonSelector
     */
    function watchTourForTarget(buttonSelector) {
        var body = document.body;
        if (!body) {
            return;
        }

        var scrollObserver = null;
        var scrollRaf = 0;

        /**
         * Toggle the reserved-space body class based on the current popover position.
         */
        function evaluate() {
            scrollRaf = 0;
            var popper = document.querySelector('[data-flexitour="container"]');
            if (!popper) {
                body.classList.remove(SCROLL_CLASS);
                return;
            }
            var target = document.querySelector(buttonSelector);
            if (!target) {
                body.classList.remove(SCROLL_CLASS);
                return;
            }
            var rect = target.getBoundingClientRect();
            if (rect.top < 160) {
                body.classList.add(SCROLL_CLASS);
            } else {
                body.classList.remove(SCROLL_CLASS);
            }
        }

        /**
         * Coalesce rapid mutations into a single evaluate() call per animation frame.
         */
        function queueEvaluate() {
            if (scrollRaf) {
                return;
            }
            scrollRaf = window.requestAnimationFrame(evaluate);
        }

        /**
         * Observe changes on the tour popover so we can re-evaluate positioning.
         *
         * @param {Element} popper The flexitour popover node.
         */
        function startScrollObserver(popper) {
            if (scrollObserver) {
                return;
            }
            scrollObserver = new MutationObserver(queueEvaluate);
            scrollObserver.observe(popper, {attributes: true, attributeFilter: ['style', 'class']});
            queueEvaluate();
        }

        /**
         * Tear down the popover observer and remove the reserved-space class.
         */
        function stopScrollObserver() {
            if (scrollObserver) {
                scrollObserver.disconnect();
                scrollObserver = null;
            }
            body.classList.remove(SCROLL_CLASS);
        }

        var presenceObserver = new MutationObserver(function(records) {
            var needsCheck = false;
            for (var i = 0; i < records.length; i++) {
                var r = records[i];
                if ((r.addedNodes && r.addedNodes.length) || (r.removedNodes && r.removedNodes.length)) {
                    needsCheck = true;
                    break;
                }
            }
            if (!needsCheck) {
                return;
            }
            var popper = document.querySelector('[data-flexitour="container"]');
            if (popper) {
                startScrollObserver(popper);
            } else {
                stopScrollObserver();
            }
        });
        presenceObserver.observe(body, {childList: true, subtree: false});
    }

    /**
     * @param {number|null} tourId The numeric tool_usertours_tours.id, resolved in PHP.
     * @param {string} buttonSelector CSS selector for the trigger button.
     */
    function init(tourId, buttonSelector) {
        var button = document.querySelector(buttonSelector);
        if (!button) {
            return;
        }
        if (!tourId) {
            button.style.display = 'none';
            return;
        }

        watchTourForTarget(buttonSelector);

        button.addEventListener('click', function(e) {
            e.preventDefault();
            require(['tool_usertours/usertours'], function(userTours) {
                if (userTours && typeof userTours.resetTourState === 'function') {
                    userTours.resetTourState(tourId);
                } else {
                    log.error('local_courseplanner/showtour: tool_usertours/usertours.resetTourState not available');
                }
            }, function(err) {
                log.error('local_courseplanner/showtour: failed to load tool_usertours/usertours', err);
                button.style.display = 'none';
            });
        });
    }

    return {
        init: init,
    };
});
