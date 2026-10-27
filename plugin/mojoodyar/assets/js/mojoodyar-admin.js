/**
 * Mojoodyar admin helpers. Kept deliberately small.
 *  - "select all" in the waitlist table;
 *  - confirmation before a destructive bulk action.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		// Phone follow-up dialog.
		//
		// The link is a real link to a standalone page, so it works with
		// JavaScript off, in a new tab, and with middle click. When <dialog> is
		// available we intercept the click and open the same panel as a modal.
		// Nothing about the form depends on this script: the fields, the nonce
		// and the two submit buttons are plain HTML.
		var supportsDialog = typeof window.HTMLDialogElement !== 'undefined';

		Array.prototype.forEach.call(document.querySelectorAll('[data-mjy="call-open"]'), function (link) {
			link.addEventListener('click', function (event) {
				var id = link.getAttribute('data-target');
				var modal = id ? document.getElementById(id) : null;

				if (!modal || !supportsDialog || typeof modal.showModal !== 'function') {
					return;
				}

				event.preventDefault();
				modal.showModal();
				focusFirst(modal);
			});
		});

		Array.prototype.forEach.call(document.querySelectorAll('[data-mjy="call-modal"]'), function (modal) {
			// Click on the backdrop closes. The panel swallows its own clicks.
			modal.addEventListener('click', function (event) {
				if (event.target === modal) {
					modal.close();
				}
			});

			Array.prototype.forEach.call(modal.querySelectorAll('[data-mjy="call-close"]'), function (button) {
				button.addEventListener('click', function () {
					modal.close();
				});
			});

			// A note counter, so nobody types past the column length and loses text.
			var note = modal.querySelector('[data-mjy="note"]');
			var counter = modal.querySelector('[data-mjy="counter"]');

			if (note && counter) {
				var update = function () {
					counter.textContent = note.value.length + ' / 500';
				};
				note.addEventListener('input', update);
				update();
			}

			// "End follow-up" needs a reason before it does anything.
			var closeButton = modal.querySelector('[data-mjy="follow-close"]');

			if (closeButton) {
				closeButton.addEventListener('click', function (event) {
					var form = closeButton.closest('form');
					var reason = form ? form.querySelector('select[name="close_reason"]') : null;

					if (reason && reason.value === '') {
						event.preventDefault();
						reason.focus();
						window.alert('برای خاتمهٔ پیگیری، دلیل را انتخاب کنید.');
					}
				});
			}
		});

		var selectAll = document.getElementById('mjy-select-all');

		if (selectAll) {
			selectAll.addEventListener('change', function () {
				var boxes = document.querySelectorAll('input[name="ids[]"]');
				Array.prototype.forEach.call(boxes, function (box) {
					box.checked = selectAll.checked;
				});
			});
		}

		var bulkForms = document.querySelectorAll('.mjy-bulk');

		Array.prototype.forEach.call(bulkForms, function (container) {
			var form = container.closest('form');
			if (!form) {
				return;
			}

			form.addEventListener('submit', function (event) {
				var select = form.querySelector('select[name="bulk_action"]');
				var checked = form.querySelectorAll('input[name="ids[]"]:checked');

				if (!select || !select.value) {
					event.preventDefault();
					window.alert('یک عملیات گروهی انتخاب کنید.');
					return;
				}

				if (!checked.length) {
					event.preventDefault();
					window.alert('هیچ ردیفی انتخاب نشده است.');
					return;
				}

				if (select.value === 'delete') {
					if (!window.confirm('این ردیف‌ها برای همیشه حذف می‌شوند. ادامه می‌دهید؟')) {
						event.preventDefault();
					}
				}
			});
		});
	});

	/**
	 * Put the cursor on the first useful control inside a dialog.
	 *
	 * @param {Element} modal Dialog element.
	 * @return {void}
	 */
	function focusFirst(modal) {
		var target = modal.querySelector('[data-mjy="note"]') || modal.querySelector('input[name="outcome"]');

		if (target && typeof target.focus === 'function') {
			target.focus();
		}

		modal.scrollTop = 0;
	}
})();
