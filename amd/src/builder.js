/**
 * Course Calendar builder - client-side state management.
 *
 * Provides drag-and-drop, undo/redo, unsaved change tracking,
 * batch save, and beforeunload warning.
 *
 * @module local_courseplanner/builder
 */
define(['core/ajax', 'core/notification'], function(Ajax, Notification) {
    'use strict';

    var state = {
        courseid: 0,
        calendarid: 0,
        dirty: false,
        submitting: false,
        undoStack: [],
        redoStack: [],
        dragSource: null,
    };

    var SELECTORS = {
        grid: '.local-courseplanner-grid',
        cell: '[data-cc-row][data-cc-col]',
        saveAllBtn: '#local-courseplanner-saveall',
        undoBtn: '#local-courseplanner-undo',
        redoBtn: '#local-courseplanner-redo',
        badge: '#local-courseplanner-unsaved-badge',
    };

    /**
     * Capture a snapshot of the current grid DOM for undo/redo.
     */
    function captureSnapshot() {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid) {
            return null;
        }
        return grid.innerHTML;
    }

    /**
     * Push the current grid state onto the undo stack.
     */
    function pushUndo() {
        var snap = captureSnapshot();
        if (snap !== null) {
            state.undoStack.push(snap);
            if (state.undoStack.length > 50) {
                state.undoStack.shift();
            }
        }
        state.redoStack = [];
        markDirty();
    }

    /**
     * Restore the grid DOM from a captured snapshot.
     *
     * @param {string} html Previously captured innerHTML snapshot.
     */
    function restoreSnapshot(html) {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid || !html) {
            return;
        }
        grid.innerHTML = html;
        bindDragEvents();
        markDirty();
    }

    /**
     * Undo the most recent change by popping the undo stack.
     */
    function undo() {
        if (state.undoStack.length === 0) {
            return;
        }
        var currentSnap = captureSnapshot();
        state.redoStack.push(currentSnap);
        var prev = state.undoStack.pop();
        restoreSnapshot(prev);
    }

    /**
     * Redo the most recent undone change by popping the redo stack.
     */
    function redo() {
        if (state.redoStack.length === 0) {
            return;
        }
        var currentSnap = captureSnapshot();
        state.undoStack.push(currentSnap);
        var next = state.redoStack.pop();
        restoreSnapshot(next);
    }

    /**
     * Mark the grid as having unsaved changes and refresh the UI.
     */
    function markDirty() {
        state.dirty = true;
        updateUI();
    }

    /**
     * Mark the grid as clean (saved) and reset the undo/redo stacks.
     */
    function markClean() {
        state.dirty = false;
        state.undoStack = [];
        state.redoStack = [];
        updateUI();
    }

    /**
     * Refresh the dirty badge and undo/redo button enabled states.
     */
    function updateUI() {
        var badge = document.querySelector(SELECTORS.badge);
        if (badge) {
            badge.style.display = state.dirty ? 'inline-block' : 'none';
        }
        var undoBtn = document.querySelector(SELECTORS.undoBtn);
        if (undoBtn) {
            undoBtn.disabled = state.undoStack.length === 0;
        }
        var redoBtn = document.querySelector(SELECTORS.redoBtn);
        if (redoBtn) {
            redoBtn.disabled = state.redoStack.length === 0;
        }
    }

    /**
     * Collect all editable cell data from data attributes on the grid.
     */
    function collectBlocks() {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid) {
            return [];
        }
        var cells = grid.querySelectorAll('[data-cc-row][data-cc-col]');
        var blocks = [];
        cells.forEach(function(cell) {
            var row = parseInt(cell.getAttribute('data-cc-row'), 10);
            var col = parseInt(cell.getAttribute('data-cc-col'), 10);
            var bt = cell.getAttribute('data-cc-blocktype') || '';
            if (!bt) {
                return;
            }
            blocks.push({
                rownum: row,
                colnum: col,
                blocktype: bt,
                contenthtml: cell.getAttribute('data-cc-content') || '',
                topicid: parseInt(cell.getAttribute('data-cc-topicid') || '0', 10),
                cellheading: cell.getAttribute('data-cc-cellheading') || '',
                headerday: cell.getAttribute('data-cc-headerday') || '',
                headermode: cell.getAttribute('data-cc-headermode') || '',
                highlighted: parseInt(cell.getAttribute('data-cc-highlighted') || '0', 10),
                verticallycentred: parseInt(cell.getAttribute('data-cc-vcentred') || '0', 10),
            });
        });
        return blocks;
    }

    /**
     * Persist all current cell data to the backend via the builder AJAX call.
     */
    function saveAll() {
        var blocks = collectBlocks();
        if (blocks.length === 0) {
            return;
        }
        var saveBtn = document.querySelector(SELECTORS.saveAllBtn);
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving...';
        }
        Ajax.call([{
            methodname: 'local_courseplanner_save_builder_grid',
            args: {
                courseid: state.courseid,
                calendarid: state.calendarid,
                blocks: blocks,
            },
            done: function(result) {
                if (result.status === 'ok') {
                    markClean();
                    Notification.addNotification({
                        message: result.saved + ' block(s) saved.',
                        type: 'success',
                    });
                }
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save All';
                }
            },
            fail: function(err) {
                Notification.exception(err);
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save All';
                }
            },
        }]);
    }

    // Drag and drop.

    /**
     * Walk up from the given element until an editable grid cell is found.
     *
     * @param {Element} el Starting element.
     * @returns {Element|null} Editable cell element, or null if none found.
     */
    function getEditableCell(el) {
        while (el && el !== document) {
            if (el.hasAttribute && el.hasAttribute('data-cc-row') &&
                el.hasAttribute('data-cc-col') && el.getAttribute('data-cc-editable') === '1') {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    /**
     * Drag start handler: remember the source cell and set the drag payload.
     *
     * @param {DragEvent} e Native drag event.
     */
    function handleDragStart(e) {
        var cell = getEditableCell(e.target);
        if (!cell) {
            return;
        }
        state.dragSource = cell;
        cell.classList.add('local-courseplanner-dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain',
            cell.getAttribute('data-cc-row') + ',' + cell.getAttribute('data-cc-col'));
    }

    /**
     * Drag over handler: allow dropping and paint the hover state.
     *
     * @param {DragEvent} e Native drag event.
     */
    function handleDragOver(e) {
        var cell = getEditableCell(e.target);
        if (!cell) {
            return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        cell.classList.add('local-courseplanner-dragover');
    }

    /**
     * Drag leave handler: clear the hover state.
     *
     * @param {DragEvent} e Native drag event.
     */
    function handleDragLeave(e) {
        var cell = getEditableCell(e.target);
        if (cell) {
            cell.classList.remove('local-courseplanner-dragover');
        }
    }

    /**
     * Drop handler: swap the source and target cells via the backend.
     *
     * @param {DragEvent} e Native drag event.
     */
    function handleDrop(e) {
        e.preventDefault();
        var targetCell = getEditableCell(e.target);
        if (!targetCell || !state.dragSource) {
            return;
        }
        targetCell.classList.remove('local-courseplanner-dragover');
        state.dragSource.classList.remove('local-courseplanner-dragging');

        var fromRow = parseInt(state.dragSource.getAttribute('data-cc-row'), 10);
        var fromCol = parseInt(state.dragSource.getAttribute('data-cc-col'), 10);
        var toRow = parseInt(targetCell.getAttribute('data-cc-row'), 10);
        var toCol = parseInt(targetCell.getAttribute('data-cc-col'), 10);

        if (fromRow === toRow && fromCol === toCol) {
            state.dragSource = null;
            return;
        }

        pushUndo();

        Ajax.call([{
            methodname: 'local_courseplanner_swap_builder_cells',
            args: {
                courseid: state.courseid,
                calendarid: state.calendarid,
                fromrow: fromRow,
                fromcol: fromCol,
                torow: toRow,
                tocol: toCol,
            },
            done: function(result) {
                if (result.status === 'ok') {
                    window.location.reload();
                } else {
                    Notification.addNotification({message: result.message, type: 'error'});
                }
            },
            fail: Notification.exception,
        }]);

        state.dragSource = null;
    }

    /**
     * Drag end handler: remove any leftover drag styling.
     */
    function handleDragEnd() {
        if (state.dragSource) {
            state.dragSource.classList.remove('local-courseplanner-dragging');
        }
        document.querySelectorAll('.local-courseplanner-dragover').forEach(function(el) {
            el.classList.remove('local-courseplanner-dragover');
        });
        state.dragSource = null;
    }

    /**
     * Attach drag and drop listeners to every editable cell in the grid.
     */
    function bindDragEvents() {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid) {
            return;
        }
        var editableCells = grid.querySelectorAll('[data-cc-editable="1"]');
        editableCells.forEach(function(cell) {
            cell.setAttribute('draggable', 'true');
            cell.removeEventListener('dragstart', handleDragStart);
            cell.addEventListener('dragstart', handleDragStart);
            cell.removeEventListener('dragover', handleDragOver);
            cell.addEventListener('dragover', handleDragOver);
            cell.removeEventListener('dragleave', handleDragLeave);
            cell.addEventListener('dragleave', handleDragLeave);
            cell.removeEventListener('drop', handleDrop);
            cell.addEventListener('drop', handleDrop);
            cell.removeEventListener('dragend', handleDragEnd);
            cell.addEventListener('dragend', handleDragEnd);
        });
    }

    /**
     * Show or hide the topic dropdown for a cell editor based on the
     * selected block type. The topic dropdown is only relevant for
     * TOPIC cells, so it is hidden (and disabled, to keep it out of the
     * submitted form data) when TEXT is selected.
     *
     * @param {HTMLSelectElement} blocktypeSelect The block-type <select>.
     */
    function toggleTopicSelect(blocktypeSelect) {
        var form = blocktypeSelect.closest('form');
        if (!form) {
            return;
        }
        var topicSelect = form.querySelector('select[name="topicid"]');
        if (!topicSelect) {
            return;
        }
        var isTopic = blocktypeSelect.value === 'TOPIC';
        topicSelect.style.display = isTopic ? '' : 'none';
        topicSelect.disabled = !isTopic;
    }

    /**
     * Wire up each cell editor so the topic dropdown visibility tracks the
     * selected block type, both on initial render and on subsequent changes.
     */
    function bindCellEditors() {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid) {
            return;
        }
        grid.querySelectorAll('select[name="blocktype"]').forEach(function(sel) {
            toggleTopicSelect(sel);
            sel.addEventListener('change', function() {
                toggleTopicSelect(sel);
            });
        });
    }

    /**
     * Track changes on form inputs inside the grid to set dirty flag.
     */
    function bindChangeTracking() {
        var grid = document.querySelector(SELECTORS.grid);
        if (!grid) {
            return;
        }
        grid.addEventListener('change', function() {
            if (!state.dirty) {
                pushUndo();
            }
        });
        grid.addEventListener('input', function(e) {
            if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
                if (!state.dirty) {
                    pushUndo();
                }
            }
        });
    }

    return {
        init: function(courseid, calendarid) {
            state.courseid = courseid;
            state.calendarid = calendarid;

            bindDragEvents();
            bindChangeTracking();
            bindCellEditors();
            updateUI();

            // A real form submission (e.g. Save cell) is an intentional
            // navigation that persists data server-side, so it should not
            // trigger the unsaved-changes warning.
            document.addEventListener('submit', function() {
                state.submitting = true;
            });

            var saveBtn = document.querySelector(SELECTORS.saveAllBtn);
            if (saveBtn) {
                saveBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    saveAll();
                });
            }

            var undoBtn = document.querySelector(SELECTORS.undoBtn);
            if (undoBtn) {
                undoBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    undo();
                });
            }

            var redoBtn = document.querySelector(SELECTORS.redoBtn);
            if (redoBtn) {
                redoBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    redo();
                });
            }

            // Keyboard shortcuts: Ctrl+S to save, Ctrl+Z undo, Ctrl+Shift+Z redo.
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && !e.altKey) {
                    if (e.key === 's' || e.key === 'S') {
                        e.preventDefault();
                        if (state.dirty) {
                            saveAll();
                        }
                    } else if (e.key === 'z' && !e.shiftKey) {
                        e.preventDefault();
                        undo();
                    } else if ((e.key === 'z' && e.shiftKey) || e.key === 'y') {
                        e.preventDefault();
                        redo();
                    }
                }
            });

            window.addEventListener('beforeunload', function(e) {
                if (state.dirty && !state.submitting) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        },
    };
});
