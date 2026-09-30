/**
 * Drag-and-drop reordering for blueprint topics on manage.php.
 *
 * Wraps Moodle's core/sortable_list AMD module around the topic list and
 * persists the new order via the local_courseplanner_reorder_blueprint_topics
 * external function whenever the user drops a row into a new position.
 *
 * @module local_courseplanner/topicreorder
 */
define([
    'jquery',
    'core/sortable_list',
    'core/ajax',
    'core/notification',
    'core/log',
], function($, SortableList, Ajax, Notification, log) {
    'use strict';

    /**
     * Return the current order of topic ids based on DOM position.
     *
     * @param {HTMLElement} list
     * @returns {Array<number>}
     */
    function collectOrder(list) {
        return $(list).children('[data-topicid]').toArray().map(function(li) {
            return parseInt(li.getAttribute('data-topicid'), 10);
        }).filter(function(id) {
            return !isNaN(id) && id > 0;
        });
    }

    /**
     * Refresh the visible 1-based sortorder badge after a successful reorder.
     *
     * @param {HTMLElement} list
     */
    function refreshSortOrderBadges(list) {
        $(list).children('[data-topicid]').each(function(index, li) {
            var badge = li.querySelector('.local-courseplanner-blueprint-shortcode');
            if (badge) {
                badge.textContent = String(index + 1);
            }
        });
    }

    /**
     * Persist the new order via the external function.
     *
     * @param {number} courseid
     * @param {number} blueprintid
     * @param {Array<number>} topicids
     * @returns {Promise}
     */
    function saveOrder(courseid, blueprintid, topicids) {
        var request = Ajax.call([{
            methodname: 'local_courseplanner_reorder_blueprint_topics',
            args: {
                courseid: courseid,
                blueprintid: blueprintid,
                topicids: topicids,
            },
        }])[0];
        request.fail(Notification.exception);
        return request;
    }

    /**
     * Toggle the "saving" CSS state on the list element.
     *
     * @param {Element} list The sortable list element.
     * @param {boolean} saving True while an AJAX save is in flight.
     */
    function setSaving(list, saving) {
        if (saving) {
            list.classList.add('local-courseplanner-list-saving');
        } else {
            list.classList.remove('local-courseplanner-list-saving');
        }
    }

    /**
     * Entry point called from PHP.
     *
     * @param {number} courseid
     * @param {number} blueprintid
     * @param {string} selector CSS selector for the <ul> containing topic <li>s.
     */
    function init(courseid, blueprintid, selector) {
        var list = document.querySelector(selector);
        if (!list) {
            return;
        }
        if (!$(list).children('[data-topicid]').length) {
            return;
        }

        try {
            new SortableList(list);
        } catch (err) {
            log.error('local_courseplanner/topicreorder: failed to init SortableList', err);
            return;
        }

        // Give each row a human-readable name for sortable_list's live region.
        $(list).children('[data-topicid]').each(function() {
            var $item = $(this);
            var name = $item.find('.local-courseplanner-blueprint-name').text().trim();
            if (name) {
                $item.attr('data-sortable-list-name', name);
            }
        });

        $(list).on('sortablelist-drop', '> [data-topicid]', function(evt, info) {
            evt.stopPropagation();
            if (info && info.positionChanged === false) {
                return;
            }
            setSaving(list, true);
            var topicids = collectOrder(list);
            saveOrder(courseid, blueprintid, topicids)
                .then(function() {
                    refreshSortOrderBadges(list);
                    setSaving(list, false);
                    return null;
                })
                .fail(function(ex) {
                    setSaving(list, false);
                    Notification.exception(ex);
                });
        });
    }

    return {
        init: init,
    };
});
