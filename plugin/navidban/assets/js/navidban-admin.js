/**
 * Navidban admin helpers. Kept deliberately small.
 *  - "select all" in the waitlist table;
 *  - confirmation before a destructive bulk action.
 */
(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		// Per-row "customer was called" tick: submitting immediately is the whole
		// point - an assistant marks it while the phone is still in their hand.
		// Without JS the small "ثبت" button next to the box does the same thing.
		Array.prototype.forEach.call(document.querySelectorAll('[data-nvb="call-toggle"]'), function (box) {
			box.addEventListener('change', function () {
				var form = box.closest('form');
				if (!form) {
					return;
				}
				box.disabled = true;
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.submit();
				}
			});
		});

		var selectAll = document.getElementById('nvb-select-all');

		if (selectAll) {
			selectAll.addEventListener('change', function () {
				var boxes = document.querySelectorAll('input[name="ids[]"]');
				Array.prototype.forEach.call(boxes, function (box) {
					box.checked = selectAll.checked;
				});
			});
		}

		var bulkForms = document.querySelectorAll('.nvb-bulk');

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
})();
