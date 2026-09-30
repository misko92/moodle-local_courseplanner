/**
 * Modal confirmation shim for local_courseplanner action forms.
 *
 * Replaces the browser-native `confirm()` dialog used by the builder's
 * automation buttons (Auto-populate, Fill Problem Sessions, the two
 * delete actions) with Moodle's standard saveCancel / deleteCancel
 * modal so the prompt matches the rest of the platform UI.
 *
 * A form opts in by setting `data-cc-confirm` to the question text and
 * (optionally) `data-cc-confirm-title`, `data-cc-confirm-action`, and
 * `data-cc-confirm-style` (`save` for default, `delete` for destructive
 * actions). When the user confirms, the form is submitted directly so
 * the submit listener is not re-entered.
 *
 * @module local_courseplanner/confirmaction
 */
define(['core/notification'], function(Notification) {
    'use strict';

    var SELECTOR = 'form[data-cc-confirm]';
    var BOUND_ATTR = 'ccConfirmBound';

    /**
     * Submit a form without re-firing this module's submit listener.
     *
     * @param {HTMLFormElement} form
     */
    function submitForm(form) {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            HTMLFormElement.prototype.submit.call(form);
        }
    }

    /**
     * @param {HTMLFormElement} form
     */
    function bindForm(form) {
        if (form.dataset[BOUND_ATTR] === '1') {
            return;
        }
        form.dataset[BOUND_ATTR] = '1';

        form.addEventListener('submit', function(e) {
            // The user already confirmed; let the submit go through.
            if (form.dataset.ccConfirmGo === '1') {
                form.dataset.ccConfirmGo = '';
                return;
            }
            e.preventDefault();

            var message = form.dataset.ccConfirm || '';
            var title = form.dataset.ccConfirmTitle || 'Confirm';
            var actionLabel = form.dataset.ccConfirmAction || 'OK';
            var dialog = (form.dataset.ccConfirmStyle === 'delete')
                ? Notification.deleteCancel
                : Notification.saveCancel;

            dialog(title, message, actionLabel, function() {
                form.dataset.ccConfirmGo = '1';
                submitForm(form);
            });
        });
    }

    /**
     * @param {ParentNode} root
     */
    function bindAll(root) {
        var forms = (root || document).querySelectorAll(SELECTOR);
        Array.prototype.forEach.call(forms, bindForm);
    }

    return {
        init: function() {
            bindAll(document);
        },
    };
});
