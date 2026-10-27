/**
 * Mojoodyar storefront behaviour.
 *
 * Vanilla JS, no dependencies, no globals except the localised MojoodyarData.
 *  - submits the form through the REST route, falling back to admin-ajax;
 *  - follows WooCommerce variation changes so the form always targets the
 *    variation the visitor actually selected.
 */
(function () {
	'use strict';

	var data = window.MojoodyarData || {};

	function qs(root, selector) {
		return root.querySelector(selector);
	}

	function setMessage(node, text, type) {
		if (!node) {
			return;
		}
		node.textContent = text;
		node.classList.remove('is-error', 'is-success');
		if (type) {
			node.classList.add(type === 'error' ? 'is-error' : 'is-success');
		}
	}

	function currentChannel(form) {
		var checked = form.querySelector('input[name="channel"]:checked');
		if (checked) {
			return checked.value;
		}
		var hidden = form.querySelector('input[name="channel"][type="hidden"]');
		return hidden ? hidden.value : 'email';
	}

	function syncChannelFields(form) {
		var mode = form.getAttribute('data-mode') || 'email';
		var channel = currentChannel(form);
		var emailField = qs(form, '[data-mjy="email-field"]');
		var phoneField = qs(form, '[data-mjy="phone-field"]');
		var emailInput = qs(form, '#mjy-email');
		var phoneInput = qs(form, '#mjy-phone');

		if (mode === 'both') {
			if (emailField) {
				emailField.hidden = channel !== 'email';
			}
			if (phoneField) {
				phoneField.hidden = channel !== 'sms';
			}
			if (emailInput) {
				emailInput.required = channel === 'email';
			}
			if (phoneInput) {
				phoneInput.required = channel === 'sms';
			}
		} else if (mode === 'sms') {
			if (emailField) {
				emailField.hidden = true;
			}
			if (phoneInput) {
				phoneInput.required = true;
			}
		} else {
			if (phoneField) {
				phoneField.hidden = false;
			}
			if (emailInput) {
				emailInput.required = true;
			}
		}
	}

	function submit(form, messageNode) {
		var button = form.querySelector('.mjy-button');
		var payload = {
			product_id: parseInt(qs(form, 'input[name="product_id"]').value, 10) || 0,
			variation_id: parseInt(qs(form, 'input[name="variation_id"]').value, 10) || 0,
			channel: currentChannel(form),
			name: (qs(form, '#mjy-name') || {}).value || '',
			email: (qs(form, '#mjy-email') || {}).value || '',
			phone: (qs(form, '#mjy-phone') || {}).value || '',
			consent: form.querySelector('input[name="consent"]') && form.querySelector('input[name="consent"]').checked ? 1 : 0
		};

		var honey = qs(form, 'input[name="website"]');
		if (honey && honey.value !== '') {
			// Bots fill hidden fields; pretend everything went fine.
			setMessage(messageNode, data.strings ? data.strings.success : 'ثبت شد.', 'success');
			return;
		}

		if (data.strings && data.strings.consent && payload.consent === 0 && form.getAttribute('data-consent') === '1') {
			setMessage(messageNode, data.strings.consent, 'error');
			return;
		}

		if (button) {
			button.disabled = true;
		}

		function fail() {
			if (button) {
				button.disabled = false;
			}
		}

		function handle(payloadResponse, status) {
			var body = payloadResponse || {};
			var ok = status >= 200 && status < 300 && body.ok;

			if (!ok && status === 403) {
				setMessage(messageNode, (data.strings && data.strings.network) || 'دوباره تلاش کنید.', 'error');
				return;
			}

			if (!ok) {
				var map = {
					duplicate: data.strings ? data.strings.duplicate : '',
					invalid_name: data.strings ? data.strings.invalidName : '',
					invalid_email: data.strings ? data.strings.invalid : '',
					invalid_phone: data.strings ? data.strings.invalid : '',
					consent_required: data.strings ? data.strings.consent : ''
				};
				setMessage(messageNode, map[body.code] || body.message || 'خطا رخ داد.', 'error');
				return;
			}

			setMessage(
				messageNode,
				body.code === 'confirmation_sent'
					? (data.strings ? data.strings.confirmSent : body.message)
					: (data.strings ? data.strings.success : body.message),
				'success'
			);

			form.reset();
			var variationField = qs(form, '[data-mjy="variation-id"]');
			if (variationField) {
				variationField.value = '0';
			}
		}

		if (window.fetch && data.restUrl) {
			window
				.fetch(data.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': data.nonce || ''
					},
					body: JSON.stringify(payload)
				})
				.then(function (response) {
					return response.json().then(function (body) {
						return { status: response.status, body: body };
					});
				})
				.then(function (result) {
					handle(result.body, result.status);
				})
				.catch(function () {
					ajaxFallback(payload, handle);
				})
				.finally(fail);
			return;
		}

		ajaxFallback(payload, handle);
		fail();
	}

	function ajaxFallback(payload, handle) {
		if (!data.ajaxUrl) {
			handle({ ok: false, message: data.strings ? data.strings.network : '' }, 500);
			return;
		}

		var body = new URLSearchParams();
		body.set('action', 'mojoodyar_subscribe');
		body.set('nonce', data.ajaxNonce || '');
		Object.keys(payload).forEach(function (key) {
			body.set(key, payload[key]);
		});

		window
			.fetch(data.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			})
			.then(function (response) {
				return response.json().then(function (json) {
					return { status: response.status, body: json };
				});
			})
			.then(function (result) {
				handle(result.body, result.status);
			})
			.catch(function () {
				handle({ ok: false, message: data.strings ? data.strings.network : '' }, 500);
			});
	}

	function parseVariations(root) {
		try {
			var raw = root.getAttribute('data-variations');
			return raw ? JSON.parse(raw) : [];
		} catch (error) {
			return [];
		}
	}

	function bindVariationSync(root) {
		var variations = parseVariations(root);
		if (!variations.length) {
			return;
		}

		var byId = {};
		variations.forEach(function (item) {
			byId[String(item.id)] = item;
		});

		var variationField = qs(root, '[data-mjy="variation-id"]');

		function apply(variationId) {
			if (!variationField) {
				return;
			}

			variationField.value = variationId ? String(variationId) : '0';

			var variation = byId[String(variationId)];
			var unavailable = variation ? !variation.in_stock : true;
			root.classList.toggle('is-hidden', variation ? !unavailable : true);
		}

		// WooCommerce fires these events on the variations form.
		var form = document.querySelector('form.variations_form');
		if (!form || !window.jQuery) {
			return;
		}

		window.jQuery(form).on('found_variation', function (_event, variation) {
			apply(variation && variation.variation_id ? variation.variation_id : 0);
		});

		window.jQuery(form).on('reset_data', function () {
			apply(0);
		});
	}

	function init() {
		var roots = document.querySelectorAll('.mjy-form');

		Array.prototype.forEach.call(roots, function (root) {
			var form = qs(root, '.mjy-form__body');
			if (!form) {
				return;
			}

			var messageNode = qs(form, '[data-mjy="message"]');

			form.addEventListener('change', function (event) {
				if (event.target && event.target.name === 'channel') {
					syncChannelFields(form);
				}
			});

			form.addEventListener('submit', function (event) {
				event.preventDefault();
				setMessage(messageNode, '', null);
				submit(form, messageNode);
			});

			syncChannelFields(form);
			bindVariationSync(root);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
