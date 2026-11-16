/**
 * Mojoodyar storefront behaviour.
 *
 * One controller per form. Automatic forms move next to the main product's
 * stock output without nesting inside WooCommerce's purchase form; explicit
 * shortcode forms stay where the editor put them.
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
		node.textContent = text || '';
		node.classList.remove('is-error', 'is-success');
		if (type) {
			node.classList.add(type === 'error' ? 'is-error' : 'is-success');
		}
	}

	function currentChannel(form) {
		var checked = qs(form, 'input[name="channel"]:checked');
		if (checked) {
			return checked.value;
		}
		var hidden = qs(form, 'input[name="channel"][type="hidden"]');
		return hidden ? hidden.value : 'email';
	}

	function syncChannelFields(root, form) {
		var mode = root.getAttribute('data-mode') || 'email';
		var channel = currentChannel(form);
		var emailField = qs(form, '[data-mjy="email-field"]');
		var phoneField = qs(form, '[data-mjy="phone-field"]');
		var emailInput = qs(form, 'input[name="email"]');
		var phoneInput = qs(form, 'input[name="phone"]');

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
		}
	}

	function readValue(form, name) {
		var field = qs(form, '[name="' + name + '"]');
		return field ? field.value : '';
	}

	function requestJSON(url, options) {
		return window.fetch(url, options).then(function (response) {
			return response.json().then(function (body) {
				return { status: response.status, body: body };
			});
		});
	}

	function ajaxFallback(payload) {
		if (!data.ajaxUrl) {
			return Promise.reject(new Error('No AJAX endpoint'));
		}

		var parts = [
			'action=' + encodeURIComponent('mojoodyar_subscribe'),
			'nonce=' + encodeURIComponent(data.ajaxNonce || '')
		];
		Object.keys(payload).forEach(function (key) {
			parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]));
		});

		return requestJSON(data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: parts.join('&')
		});
	}

	function submit(root, form, messageNode) {
		var button = qs(form, '.mjy-button');
		if (button && button.disabled) {
			return;
		}
		var variationField = qs(form, '[data-mjy="variation-id"]');
		var payload = {
			product_id: parseInt(readValue(form, 'product_id'), 10) || 0,
			variation_id: parseInt(readValue(form, 'variation_id'), 10) || 0,
			channel: currentChannel(form),
			name: readValue(form, 'name'),
			email: readValue(form, 'email'),
			phone: readValue(form, 'phone'),
			consent: qs(form, 'input[name="consent"]') && qs(form, 'input[name="consent"]').checked ? 1 : 0
		};

		if (readValue(form, 'website') !== '') {
			// Bots fill hidden fields; pretend everything went fine.
			setMessage(messageNode, data.strings ? data.strings.success : 'ثبت شد.', 'success');
			return;
		}

		if (root.getAttribute('data-variable') === '1' && !payload.variation_id) {
			setMessage(messageNode, (data.strings && data.strings.variation) || 'لطفاً تنوع ناموجود مورد نظر را انتخاب کنید.', 'error');
			if (variationField) {
				variationField.focus();
			}
			return;
		}

		if (payload.consent === 0 && root.getAttribute('data-consent') === '1') {
			setMessage(messageNode, (data.strings && data.strings.consent) || 'برای ادامه باید رضایت خود را تأیید کنید.', 'error');
			return;
		}

		if (!window.fetch) {
			setMessage(messageNode, (data.strings && data.strings.network) || 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.', 'error');
			return;
		}

		if (button) {
			button.disabled = true;
		}

		var request;
		if (data.restUrl) {
			request = requestJSON(data.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': data.nonce || '' },
				body: JSON.stringify(payload)
			}).then(function (result) {
				// Common REST blocks can still be handled by the separate AJAX route.
				if (data.ajaxUrl && (result.status === 403 || result.status === 404 || result.status === 405)) {
					return ajaxFallback(payload);
				}
				return result;
			}, function () {
				return ajaxFallback(payload);
			});
		} else {
			request = ajaxFallback(payload);
		}

		request.then(function (result) {
			var body = result.body || {};
			if (result.status < 200 || result.status >= 300 || !body.ok) {
				var map = {
					duplicate: data.strings ? data.strings.duplicate : '',
					invalid_name: data.strings ? data.strings.invalidName : '',
					invalid_email: data.strings ? data.strings.invalid : '',
					invalid_phone: data.strings ? data.strings.invalid : '',
					variation_required: data.strings ? data.strings.variation : '',
					consent_required: data.strings ? data.strings.consent : ''
				};
				setMessage(messageNode, map[body.code] || body.message || 'خطا رخ داد.', 'error');
				return;
			}

			var text = body.message;
			if (body.code === 'confirmation_sent') {
				text = (data.strings && data.strings.confirmSent) || text;
			} else if (body.code !== 'already_subscribed') {
				text = (data.strings && data.strings.success) || text;
			}
			setMessage(messageNode, text || 'درخواست ثبت شد.', 'success');

			// Reset contact fields, but retain the selected variation: WooCommerce
			// does not fire found_variation again until its selection changes.
			var selectedVariation = variationField ? variationField.value : '0';
			form.reset();
			if (variationField && root.getAttribute('data-variable') === '1') {
				variationField.value = selectedVariation;
			}
			syncChannelFields(root, form);
		}).catch(function () {
			setMessage(messageNode, (data.strings && data.strings.network) || 'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.', 'error');
		}).finally(function () {
			if (button) {
				button.disabled = false;
			}
		});
	}

	function matchingCart(root) {
		if (root.getAttribute('data-sync-product') !== '1') {
			return null;
		}
		var productId = root.getAttribute('data-product');
		var product = document.getElementById('product-' + productId);
		var candidates = (product || document).querySelectorAll('form.variations_form[data-product_id]');
		var matches = Array.prototype.filter.call(candidates, function (candidate) {
			return candidate.getAttribute('data-product_id') === productId;
		});
		// Do not guess between two purchase forms for the same product.
		return matches.length === 1 ? matches[0] : null;
	}

	function hasHiddenWaitlistChoices(cart, field) {
		// WC omits out-of-stock choices when catalog hiding is enabled. Its
		// AJAX mode also provides no complete list. In either case, keep our
		// independent selector visible even if the cart selects an in-stock item.
		var variations;
		try {
			variations = JSON.parse(cart.getAttribute('data-product_variations') || 'null');
		} catch (error) {
			return true;
		}
		if (!Array.isArray(variations)) {
			return true;
		}
		var listed = {};
		variations.forEach(function (variation) {
			if (variation && variation.variation_id && variation.variation_is_active !== false) {
				listed[String(variation.variation_id)] = true;
			}
		});
		return Array.prototype.some.call(field.options, function (item) {
			return item.value !== '0' && !listed[item.value];
		});
	}

	function bindVariationSync(root, form) {
		if (root.getAttribute('data-variable') !== '1') {
			return;
		}
		var field = qs(form, '[data-mjy="variation-id"]');
		var cart = matchingCart(root);
		if (!field || !cart || !window.jQuery) {
			return;
		}

		function apply(variationId) {
			var id = variationId ? String(variationId) : '0';
			var option = Array.prototype.some.call(field.options, function (item) {
				return item.value === id && id !== '0';
			});
			field.value = option ? id : '0';
			// Hide for an in-stock selection only if every waitlist choice can
			// still be selected in the cart; otherwise keep our selector accessible.
			root.classList.toggle('is-hidden', id !== '0' && !option && !hasHiddenWaitlistChoices(cart, field));
		}

		window.jQuery(cart).on('found_variation', function (_event, variation) {
			apply(variation && variation.variation_id ? variation.variation_id : 0);
		});
		window.jQuery(cart).on('reset_data', function () {
			apply(0);
		});

		var existing = qs(cart, 'input[name="variation_id"]');
		if (existing && existing.value) {
			apply(existing.value);
		}
	}

	function moveAfterSafely(root, target) {
		if (!target) {
			return false;
		}
		// WooCommerce often displays a variation's stock inside its cart form.
		// Move after that whole form, never create nested HTML forms.
		var enclosingForm = target.closest('form');
		var position = enclosingForm || target;
		if (!position.parentNode || position === root || root.contains(position)) {
			return false;
		}
		position.parentNode.insertBefore(root, position.nextSibling);
		return true;
	}

	function positionAutomatic(root) {
		var productId = root.getAttribute('data-product');
		var product = document.getElementById('product-' + productId);
		var markerSelector = '.mjy-stock-anchor[data-mjy-product="' + productId + '"]';
		var marker = (product && qs(product, markerSelector)) || qs(document, markerSelector);
		if (moveAfterSafely(root, marker)) {
			return;
		}

		// Some theme overrides omit the stock filter but retain WooCommerce's
		// standard stock classes. Only search the main product, not related
		// products or the archive loop.
		var stock = product && qs(product, '.summary .stock.out-of-stock, form.variations_form .stock.out-of-stock');
		if (moveAfterSafely(root, stock)) {
			return;
		}

		var fallback = qs(document, '.mjy-summary-anchor[data-mjy-product="' + productId + '"]');
		// Block themes may omit #product-ID and render stock outside the legacy
		// summary hook. Search only the marker's block column (or main), and
		// only when it contains one unambiguous add-to-cart stock output.
		if (!product && fallback) {
			var scope = fallback.closest('.wp-block-column') || fallback.closest('main');
			var blockStocks = scope ? scope.querySelectorAll('.wc-block-add-to-cart-form .stock.out-of-stock') : [];
			if (blockStocks.length === 1 && moveAfterSafely(root, blockStocks[0])) {
				return;
			}
		}

		var cart = matchingCart(root);
		if (moveAfterSafely(root, cart)) {
			return;
		}

		if (moveAfterSafely(root, fallback)) {
			return;
		}

		var summary = product && qs(product, '.summary');
		var container = summary || product;
		if (container && container.closest('form')) {
			moveAfterSafely(root, container);
		} else if (container) {
			container.appendChild(root);
		}
		// If the theme supplies none of these landmarks, the footer remains a
		// visible fallback; a shortcode gives the editor an exact position.
	}

	function init() {
		var roots = document.querySelectorAll('.mjy-form');
		var explicit = {};
		var automatic = {};
		Array.prototype.forEach.call(roots, function (root) {
			if (root.getAttribute('data-placement') === 'shortcode') {
				explicit[root.getAttribute('data-product')] = true;
			}
		});

		Array.prototype.forEach.call(roots, function (root) {
			var productId = root.getAttribute('data-product');
			if (root.getAttribute('data-placement') === 'automatic') {
				if (explicit[productId] || automatic[productId]) {
					root.remove();
					return;
				}
				automatic[productId] = true;
				positionAutomatic(root);
			}

			var form = qs(root, '.mjy-form__body');
			if (!form || root.getAttribute('data-mjy-bound') === '1') {
				return;
			}
			root.setAttribute('data-mjy-bound', '1');
			var messageNode = qs(form, '[data-mjy="message"]');

			form.addEventListener('change', function (event) {
				if (event.target && event.target.name === 'channel') {
					syncChannelFields(root, form);
				}
			});
			form.addEventListener('submit', function (event) {
				event.preventDefault();
				setMessage(messageNode, '', null);
				submit(root, form, messageNode);
			});

			syncChannelFields(root, form);
			bindVariationSync(root, form);
			// Until the handler is bound, prevent an accidental native form
			// submission if JavaScript is disabled or fails to load.
			var button = qs(form, '.mjy-button');
			if (button) {
				button.disabled = false;
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
