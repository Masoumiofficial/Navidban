#!/usr/bin/env node
/**
 * DOM regression tests against real PHP-rendered form fragments.
 *
 * Dev dependencies (not shipped): php-parser, @php-wasm/node,
 * @php-wasm/universal, jsdom@26.1.0 and jquery@3.7.1.
 * Run from the plugin root: node tests/frontend-dom.js
 * jsdom + stubs are not a substitute for a live WordPress/WooCommerce test.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const rootDir = path.resolve(__dirname, '..');
const fixtureRun = spawnSync(process.execPath, ['tools/smoke-php.mjs', '--engine=wasm', 'tests/frontend-fixtures.php'], {
	cwd: rootDir,
	encoding: 'utf8',
	timeout: 120000
});
if (fixtureRun.status !== 0) {
	throw new Error('PHP fixture generation failed: ' + fixtureRun.stderr + fixtureRun.stdout);
}
const fixtures = JSON.parse(fixtureRun.stdout);
const script = fs.readFileSync(path.join(rootDir, 'assets/js/mojoodyar-front.js'), 'utf8');
const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');
let assertions = 0;

function ok(condition, label) {
	assertions++;
	assert.ok(condition, label);
}

function equal(actual, expected, label) {
	assertions++;
	assert.equal(actual, expected, label);
}

function respond(status, body) {
	return Promise.resolve({ status, json: () => Promise.resolve(body) });
}

async function page(body, options = {}) {
	const dom = new JSDOM('<!doctype html><html><body>' + body + '</body></html>', {
		runScripts: 'outside-only',
		url: 'https://shop.test/product/test/',
		pretendToBeVisual: true
	});
	const win = dom.window;
	win.MojoodyarData = Object.assign({}, fixtures.data);
	if (options.jquery !== false) {
		win.eval(jquery);
	}
	if (options.fetch !== false) {
		win.fetch = options.fetch || (() => respond(201, { ok: true, code: 'subscribed', message: 'ثبت شد' }));
	}
	if (win.document.readyState === 'loading') {
		await new Promise((resolve) => win.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
	}
	win.eval(script);
	return dom;
}

function submit(win, form) {
	form.dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));
}

function change(win, field) {
	field.dispatchEvent(new win.Event('change', { bubbles: true }));
}

function tick() {
	return new Promise((resolve) => setTimeout(resolve, 5));
}

async function main() {
	// Before JavaScript binds, the native submit button is disabled and the
	// fallback method is POST rather than putting personal data in the URL.
	let unbound = new JSDOM(fixtures.simpleAuto).window;
	equal(unbound.document.querySelector('.mjy-form__body').method, 'post', 'native form fallback never uses a GET query string');
	ok(unbound.document.querySelector('.mjy-button').disabled, 'submit is disabled before the JavaScript handler binds');
	ok(unbound.document.querySelector('noscript').textContent.includes('جاوااسکریپت'), 'no-JavaScript visitor gets an explanation');
	unbound.close();

	// A simple product: stock marker wins over unrelated stock and summary.
	let dom = await page(`
		<aside class="related"><p class="stock out-of-stock" id="related-stock">ناموجود دیگر</p></aside>
		<main class="product" id="product-8000"><div class="summary">
			<p class="stock out-of-stock" id="stock">ناموجود</p>
			<span class="mjy-stock-anchor" data-mjy-product="8000" hidden></span>
			<span class="mjy-summary-anchor" data-mjy-product="8000" hidden></span>
		</div></main><footer>${fixtures.simpleAuto}</footer>`);
	let doc = dom.window.document;
	let auto = doc.querySelector('.mjy-form[data-placement="automatic"]');
	let marker = doc.querySelector('.mjy-stock-anchor');
	equal(marker.nextElementSibling, auto, 'automatic form is immediately after the main stock marker');
	ok(!auto.querySelector('.mjy-button').disabled, 'binding JavaScript enables the submit button');
	ok(!auto.closest('form'), 'automatic form is not nested in another form');
	equal(doc.querySelector('#related-stock').nextElementSibling, null, 'related product stock is not used as the target');
	dom.window.close();

	// A stock node inside the WC purchase form: insert after the entire form.
	dom = await page(`<main class="product" id="product-8000"><div class="summary">
		<form class="cart" id="cart-simple"><p class="stock out-of-stock">ناموجود</p><button>buy</button></form>
		</div></main><footer>${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	auto = doc.querySelector('.mjy-form');
	equal(doc.querySelector('#cart-simple').nextElementSibling, auto, 'stock inside a cart places the waitlist after the cart');
	ok(!auto.closest('form'), 'relocation never nests forms');
	dom.window.close();

	// Summary marker, no marker, and no known product landmark fallbacks.
	dom = await page(`<main id="product-8000" class="product"><div class="summary">
		<span class="mjy-summary-anchor" data-mjy-product="8000" hidden></span></div></main><footer>${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('.mjy-summary-anchor').nextElementSibling, doc.querySelector('.mjy-form'), 'summary marker is the next fallback');
	dom.window.close();
	dom = await page(`<main id="product-8000" class="product"><div class="summary"><p class="stock out-of-stock" id="plain-stock">ناموجود</p></div></main><footer>${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('#plain-stock').nextElementSibling, doc.querySelector('.mjy-form'), 'stock class fallback works without filter markers');
	dom.window.close();

	// Block product templates need not include #product-ID, and the summary
	// hook may fire before the add-to-cart block rather than its stock output.
	dom = await page(`<div class="woocommerce product"><main><div class="wp-block-column">
		<span class="mjy-summary-anchor" data-mjy-product="8000" hidden></span>
		<div class="wc-block-add-to-cart-form"><p class="stock out-of-stock" id="block-stock">ناموجود</p></div>
		</div><aside><p class="stock out-of-stock">ناموجود دیگر</p></aside></main></div><footer>${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('#block-stock').nextElementSibling, doc.querySelector('.mjy-form'), 'block theme places the form immediately after the main add-to-cart stock');
	ok(!doc.querySelector('.mjy-form').closest('form'), 'block-theme placement does not nest forms');
	dom.window.close();

	// Ambiguous block output must not be mistaken for the main product stock.
	dom = await page(`<main><div class="wp-block-column">
		<span class="mjy-summary-anchor" data-mjy-product="8000" hidden></span>
		<div class="wc-block-add-to-cart-form"><p class="stock out-of-stock"></p></div>
		<div class="wc-block-add-to-cart-form"><p class="stock out-of-stock"></p></div>
		</div></main><footer>${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('.mjy-summary-anchor').nextElementSibling, doc.querySelector('.mjy-form'), 'ambiguous block stocks use the summary fallback rather than guessing');
	dom.window.close();

	dom = await page(`<aside class="related"><p class="stock out-of-stock">ناموجود دیگر</p></aside><footer id="fallback">${fixtures.simpleAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('.mjy-form').parentElement.id, 'fallback', 'unknown theme leaves a visible footer fallback rather than attaching to a related product');
	dom.window.close();

	// Explicit shortcode wins even if it was output after the automatic form.
	dom = await page(`<main id="product-8000" class="product"><div class="summary"><p class="stock out-of-stock">ناموجود</p></div>
		<div id="chosen-position">${fixtures.simpleFirst}</div><div id="chosen-position-2">${fixtures.simpleSecond}</div></main>
		<footer>${fixtures.simpleAuto}${fixtures.simpleOther}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelectorAll('.mjy-form[data-placement="automatic"]').length, 0, 'automatic duplicate is removed when a shortcode of the same product exists');
	equal(doc.querySelectorAll('.mjy-form[data-product="8000"]').length, 2, 'intentional explicit shortcode placements are retained');
	equal(doc.querySelector('#chosen-position .mjy-form').parentElement.id, 'chosen-position', 'first shortcode stays where the editor placed it');
	equal(doc.querySelector('#chosen-position-2 .mjy-form').parentElement.id, 'chosen-position-2', 'second shortcode stays where the editor placed it');
	equal(doc.querySelectorAll('.mjy-form[data-product="8001"]').length, 1, 'an explicit form for another product is preserved');
	const ids = [...doc.querySelectorAll('[id]')].map((node) => node.id);
	equal(ids.length, new Set(ids).size, 'multiple actual PHP-rendered forms have no duplicate element IDs');
	for (const label of doc.querySelectorAll('.mjy-form label[for]')) {
		ok(label.closest('.mjy-form').querySelector('#' + label.htmlFor), 'every field label targets its own form');
	}
	dom.window.close();

	// A different product's cart appears first: only the matching main cart is
	// allowed to drive the automatic form; a shortcode for another product is
	// an independent selector on this page.
	const requests = [];
	dom = await page(`
		<form class="variations_form cart" data-product_id="7100" id="other-cart"><input name="variation_id" value=""></form>
		<main class="product" id="product-7000"><div class="summary">
			<form class="variations_form cart" data-product_id="7000" id="main-cart">
				<div class="single_variation"><p class="stock out-of-stock">ناموجود</p></div>
				<input type="hidden" name="variation_id" value="">
			</form><span class="mjy-summary-anchor" data-mjy-product="7000" hidden></span>
		</div></main>
		<div id="other-position">${fixtures.varOther}</div><footer>${fixtures.varAuto}</footer>`, {
		fetch: (url, options) => {
			requests.push({ url, options });
			return respond(201, { ok: true, code: 'subscribed', message: 'ثبت شد' });
		}
	});
	let win = dom.window;
	doc = win.document;
	auto = doc.querySelector('.mjy-form[data-product="7000"]');
	const other = doc.querySelector('.mjy-form[data-product="7100"]');
	const mainCart = doc.querySelector('#main-cart');
	const otherCart = doc.querySelector('#other-cart');
	const form = auto.querySelector('form');
	const select = form.querySelector('[name="variation_id"]');
	const otherSelect = other.querySelector('[name="variation_id"]');
	equal(mainCart.nextElementSibling, auto, 'variable form moves after its own cart, below its stock');
	ok(!auto.closest('form'), 'variable waitlist is not nested in the purchase form');
	win.jQuery(otherCart).trigger('found_variation', [{ variation_id: 7101, is_in_stock: false }]);
	equal(select.value, '0', 'events on an unrelated first cart do not select the main variation');
	equal(otherSelect.value, '0', 'shortcode for another product is not attached to a random cart');
	win.jQuery(mainCart).trigger('found_variation', [{ variation_id: 7002, is_in_stock: false }]);
	equal(select.value, '7002', 'matching sold-out variation is selected');
	ok(!auto.classList.contains('is-hidden'), 'sold-out selection shows the form');
	equal(otherSelect.value, '0', 'main-product events do not modify the other shortcode');
	win.jQuery(mainCart).trigger('found_variation', [{ variation_id: 7001, is_in_stock: true }]);
	ok(!auto.classList.contains('is-hidden'), 'an in-stock cart choice does not hide unavailable waitlist choices');
	equal(select.value, '0', 'in-stock selection is not submitted as unavailable');
	select.value = '7003';
	equal(select.value, '7003', 'visitor can independently choose a sold-out variation while the cart is in stock');
	win.jQuery(mainCart).trigger('reset_data');
	ok(!auto.classList.contains('is-hidden'), 'reset leaves the independent selector available');
	equal(select.value, '0', 'reset clears the old selection');

	// Channel controls and data attributes belong to the section, not the form.
	const emailField = form.querySelector('[data-mjy="email-field"]');
	const phoneField = form.querySelector('[data-mjy="phone-field"]');
	ok(!emailField.hidden && phoneField.hidden, 'email-first initial mode displays only the email field');
	const sms = form.querySelector('[name="channel"][value="sms"]');
	sms.checked = true;
	change(win, sms);
	ok(emailField.hidden && !phoneField.hidden, 'switching to SMS updates only this form');
	ok(other.querySelector('[data-mjy="phone-field"]').hidden, 'other shortcode channel is unaffected');
	ok(form.querySelector('[name="phone"]').required && !form.querySelector('[name="email"]').required, 'required follows the chosen channel');
	const email = form.querySelector('[name="channel"][value="email"]');
	email.checked = true;
	change(win, email);

	submit(win, form);
	equal(requests.length, 0, 'variable product cannot submit without a selection');
	ok(form.querySelector('[data-mjy="message"]').classList.contains('is-error'), 'missing selection yields an accessible error');
	equal(doc.activeElement, select, 'missing selection focuses its selector');
	win.jQuery(mainCart).trigger('found_variation', [{ variation_id: 7002, is_in_stock: false }]);
	form.querySelector('[name="name"]').value = 'مریم احمدی';
	form.querySelector('[name="email"]').value = 'one@example.com';
	submit(win, form);
	ok(form.querySelector('button').disabled, 'button is disabled while the request is pending');
	await tick();
	equal(JSON.parse(requests[0].options.body).variation_id, 7002, 'first REST payload targets the chosen variation');
	equal(select.value, '7002', 'success retains the variation even though contact fields reset');
	equal(form.querySelector('[name="email"]').value, '', 'contact fields reset after success');
	ok(!form.querySelector('button').disabled, 'button is re-enabled after success');
	form.querySelector('[name="name"]').value = 'مریم احمدی';
	form.querySelector('[name="email"]').value = 'two@example.com';
	submit(win, form);
	await tick();
	equal(JSON.parse(requests[1].options.body).variation_id, 7002, 'second submission still targets the retained variation');
	equal(JSON.parse(requests[1].options.body).product_id, 7000, 'second submission still targets the correct parent');
	otherSelect.value = '7101';
	other.querySelector('[name="name"]').value = 'مریم احمدی';
	other.querySelector('[name="email"]').value = 'other@example.com';
	submit(win, other.querySelector('form'));
	await tick();
	equal(JSON.parse(requests[2].options.body).product_id, 7100, 'independent shortcode submits its own product');
	equal(JSON.parse(requests[2].options.body).variation_id, 7101, 'independent shortcode submits its own variation');
	dom.window.close();

	// WooCommerce might have fired its initial event before this script binds.
	dom = await page(`<main id="product-7000" class="product"><div class="summary">
		<form class="variations_form" data-product_id="7000"><input name="variation_id" value="7003"></form></div></main>
		<footer>${fixtures.varAuto}</footer>`);
	doc = dom.window.document;
	equal(doc.querySelector('.mjy-form [name="variation_id"]').value, '7003', 'preselected WooCommerce variation is read on initialization');
	dom.window.close();
	dom = await page(`<main id="product-7000" class="product"><div class="summary">
		<form class="variations_form" data-product_id="7000"><input name="variation_id" value="7001"></form></div></main>
		<footer>${fixtures.varAuto}</footer>`);
	doc = dom.window.document;
	ok(!doc.querySelector('.mjy-form').classList.contains('is-hidden'), 'preselected in-stock variation does not hide the standalone selector');
	equal(doc.querySelector('.mjy-form [name="variation_id"]').value, '0', 'preselected in-stock variation does not select a sold-out choice');
	dom.window.close();

	// With a complete WC list the normal in-stock hide behaviour is safe;
	// AJAX/incomplete/malformed data must instead keep the selector accessible.
	const allVariations = '[{"variation_id":7001},{"variation_id":7002},{"variation_id":7003}]';
	dom = await page(`<main id="product-7000" class="product"><div class="summary">
		<form class="variations_form" data-product_id="7000" data-product_variations='${allVariations}'><input name="variation_id" value="7001"></form></div></main>
		<footer>${fixtures.varAuto}</footer>`);
	doc = dom.window.document;
	ok(doc.querySelector('.mjy-form').classList.contains('is-hidden'), 'complete cart list hides an in-stock selection');
	dom.window.jQuery(doc.querySelector('.variations_form')).trigger('found_variation', [{ variation_id: 7002 }]);
	ok(!doc.querySelector('.mjy-form').classList.contains('is-hidden'), 'a sold-out cart choice reveals the form again');
	dom.window.close();
	for (const incomplete of ['false', '[]', 'not-json']) {
		dom = await page(`<main id="product-7000" class="product"><div class="summary">
			<form class="variations_form" data-product_id="7000" data-product_variations='${incomplete}'><input name="variation_id" value="7001"></form></div></main>
			<footer>${fixtures.varAuto}</footer>`);
		ok(!dom.window.document.querySelector('.mjy-form').classList.contains('is-hidden'), 'incomplete WC data keeps the waitlist accessible: ' + incomplete);
		dom.window.close();
	}

	// No jQuery: the explicit selector remains independently usable.
	const standalone = [];
	dom = await page(`<div>${fixtures.varOther}</div>`, {
		jquery: false,
		fetch: (url, options) => { standalone.push(JSON.parse(options.body)); return respond(201, { ok: true, code: 'subscribed' }); }
	});
	win = dom.window;
	doc = win.document;
	const standaloneForm = doc.querySelector('.mjy-form form');
	standaloneForm.querySelector('[name="variation_id"]').value = '7101';
	submit(win, standaloneForm);
	await tick();
	equal(standalone[0].variation_id, 7101, 'standalone variation selector submits without jQuery');
	dom.window.close();

	// Required consent is checked on the section's data-consent attribute.
	const consentCalls = [];
	dom = await page(fixtures.consent, { fetch: (url, options) => { consentCalls.push(options); return respond(201, { ok: true, code: 'subscribed' }); } });
	win = dom.window;
	const consentForm = win.document.querySelector('form');
	submit(win, consentForm);
	equal(consentCalls.length, 0, 'missing required consent prevents a network request');
	ok(consentForm.querySelector('[data-mjy="message"]').textContent.includes('رضایت'), 'consent error is shown');
	consentForm.querySelector('[name="consent"]').checked = true;
	submit(win, consentForm);
	await tick();
	equal(JSON.parse(consentCalls[0].body).consent, 1, 'checked consent is submitted');
	dom.window.close();

	// A confirmation is queued, not yet delivered. Keep the legacy API code
	// but show the accurate localized text in the storefront.
	dom = await page(fixtures.simpleOther, { fetch: () => respond(201, { ok: true, code: 'confirmation_sent', message: 'پیام تأیید در صف قرار گرفت' }) });
	win = dom.window;
	const confirmForm = win.document.querySelector('form');
	submit(win, confirmForm);
	await tick();
	ok(confirmForm.querySelector('[data-mjy="message"]').textContent.includes('صف'), 'confirmation response says queued, not delivered');
	ok(!confirmForm.querySelector('[data-mjy="message"]').textContent.includes('ارسال شد'), 'confirmation response makes no delivery claim');
	dom.window.close();

	// REST 403 falls back to AJAX; the button stays locked until AJAX resolves.
	const fallbackCalls = [];
	let completeAjax;
	dom = await page(fixtures.simpleOther, {
		fetch: (url, options) => {
			fallbackCalls.push({ url, options });
			if (fallbackCalls.length === 1) {
				return respond(403, { code: 'rest_disabled' });
			}
			return new Promise((resolve) => { completeAjax = resolve; });
		}
	});
	win = dom.window;
	const fallbackForm = win.document.querySelector('form');
	submit(win, fallbackForm);
	await tick();
	equal(fallbackCalls.length, 2, 'blocked REST triggers exactly one AJAX fallback');
	ok(fallbackCalls[1].options.body.includes('action=mojoodyar_subscribe'), 'AJAX payload includes its action');
	ok(fallbackForm.querySelector('button').disabled, 'button remains disabled while AJAX is pending');
	submit(win, fallbackForm);
	equal(fallbackCalls.length, 2, 'a second submit while pending is ignored');
	completeAjax({ status: 200, json: () => Promise.resolve({ ok: true, code: 'already_subscribed', message: 'از قبل ثبت شده‌اید' }) });
	await tick();
	ok(!fallbackForm.querySelector('button').disabled, 'button unlocks after AJAX completion');
	equal(fallbackForm.querySelector('[data-mjy="message"]').textContent, 'از قبل ثبت شده‌اید', 'duplicate success preserves the server explanation');
	dom.window.close();

	// A failed AJAX fallback must not be retried automatically a second time.
	let failures = 0;
	dom = await page(fixtures.simpleOther, {
		fetch: () => { failures++; return failures === 1 ? respond(403, {}) : Promise.reject(new Error('offline')); }
	});
	win = dom.window;
	const failedForm = win.document.querySelector('form');
	submit(win, failedForm);
	await tick();
	equal(failures, 2, 'failed AJAX fallback is not repeated');
	ok(failedForm.querySelector('[data-mjy="message"]').classList.contains('is-error'), 'network failure is shown');
	ok(!failedForm.querySelector('button').disabled, 'network failure unlocks the button');
	dom.window.close();

	// Error messages are text, and lack of fetch is reported rather than throwing.
	dom = await page(fixtures.simpleOther, { fetch: () => respond(422, { ok: false, code: 'invalid_variation', message: '<img src=x onerror=alert(1)>' }) });
	win = dom.window;
	const errorForm = win.document.querySelector('form');
	submit(win, errorForm);
	await tick();
	ok(errorForm.querySelector('[data-mjy="message"]').textContent.includes('<img'), 'server error is shown as text');
	equal(errorForm.querySelectorAll('[data-mjy="message"] img').length, 0, 'server error is never inserted as HTML');
	dom.window.close();
	dom = await page(fixtures.simpleOther, { fetch: false });
	win = dom.window;
	const noFetchForm = win.document.querySelector('form');
	submit(win, noFetchForm);
	ok(noFetchForm.querySelector('[data-mjy="message"]').classList.contains('is-error'), 'missing fetch produces an error without throwing');
	dom.window.close();

	console.log(JSON.stringify({ assertions, failures: 0, jsdom: require('jsdom/package.json').version, jquery: '3.7.1', note: 'PHP-rendered fixtures, jsdom and WooCommerce/WordPress stubs; not a live installation' }, null, 2));
}

main().catch((error) => {
	console.error(error.stack || error);
	process.exitCode = 1;
});
