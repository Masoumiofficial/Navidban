#!/usr/bin/env node
/**
 * Disposable WordPress + WooCommerce regression test, not a production server.
 *
 * Prerequisites: @wp-playground/wordpress, @wp-playground/wordpress-builds,
 * @php-wasm/node, jsdom@26.1.0 and jquery@3.7.1, plus unpacked WooCommerce
 * 9.0.0 and the Twenty Twenty-One theme from WordPress 6.4.5. Run:
 *
 *   MOJOODYAR_WC_DIR=/path/to/woocommerce \
 *   MOJOODYAR_CLASSIC_THEME_DIR=/path/to/twentytwentyone \
 *     node tests/playground-wordpress.cjs
 *
 * This runs real WP/WC PHP and WC's variation script with jsdom, using the
 * Playground SQLite adapter. It is NOT a real browser, MySQL, SMTP or SMS test.
 * Test-only SQL filters neutralize ENGINE=InnoDB ALTERs and the SQLite
 * adapter's unsupported ALTER COLUMN channel SET DEFAULT during migration.
 * The migration test also temporarily denies the two new column ADDs. None
 * of these filters changes the production plugin.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const { bootWordPressAndRequestHandler } = require('@wp-playground/wordpress');
const { loadNodeRuntime, createNodeFsMountHandler } = require('@php-wasm/node');

const root = path.resolve(__dirname, '..');
const wcSource = process.env.MOJOODYAR_WC_DIR;
const classicSource = process.env.MOJOODYAR_CLASSIC_THEME_DIR;
if (!wcSource || !classicSource) {
	throw new Error('Set MOJOODYAR_WC_DIR and MOJOODYAR_CLASSIC_THEME_DIR to unpacked test fixtures.');
}
for (const file of [path.join(wcSource, 'woocommerce.php'), path.join(wcSource, 'assets/js/frontend/add-to-cart-variation.js'), path.join(classicSource, 'style.css')]) {
	if (!fs.existsSync(file)) throw new Error('Missing fixture: ' + file);
}

let assertions = 0;
function ok(value, label) { assertions++; assert.ok(value, label); }
function equal(actual, expected, label) { assertions++; assert.equal(actual, expected, label); }
function contains(value, piece, label) { assertions++; assert.ok(value.includes(piece), label); }
function getDoc(html) { return new JSDOM(html).window.document; }
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function main() {
	const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'mojoodyar-playground-'));
	let handler;
	try {
		const pluginCopy = path.join(temporary, 'mojoodyar');
		const wcCopy = path.join(temporary, 'woocommerce');
		const themeCopy = path.join(temporary, 'twentytwentyone');
		fs.cpSync(root, pluginCopy, { recursive: true });
		fs.cpSync(wcSource, wcCopy, { recursive: true });
		fs.cpSync(classicSource, themeCopy, { recursive: true });

		const builds = path.dirname(require.resolve('@wp-playground/wordpress-builds/package.json'));
		const wpZip = new File([fs.readFileSync(path.join(builds, 'src/wordpress/wp-6.4.zip'))], 'wp.zip');
		const sqliteZip = new File([fs.readFileSync(path.join(builds, 'src/sqlite-database-integration/sqlite-database-integration.zip'))], 'sqlite.zip');
		handler = await bootWordPressAndRequestHandler({
			siteUrl: 'http://example.test',
			phpVersion: '8.3',
			createPhpRuntime: () => loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }),
			wordPressZip: wpZip,
			sqliteIntegrationPluginZip: sqliteZip,
			maxPhpInstances: 1,
			wordpressInstallMode: 'install-from-existing-files-if-needed'
		});
		const php = await handler.getPrimaryPhp();
		for (const [target, source] of [
			['/wordpress/wp-content/plugins/woocommerce', wcCopy],
			['/wordpress/wp-content/plugins/mojoodyar', pluginCopy],
			['/wordpress/wp-content/themes/twentytwentyone', themeCopy]
		]) {
			await php.mkdirTree(target);
			await php.mount(target, createNodeFsMountHandler(source));
		}

		async function run(code, label) {
			const result = await php.run({ code: '<?php require "/wordpress/wp-load.php"; ' + code });
			if (result.errors) throw new Error(label + ': ' + result.errors);
			return result.text;
		}
		async function request(url, options = {}) {
			const response = await handler.request(Object.assign({ url }, options));
			if (response.errors) throw new Error('Request ' + url + ': ' + response.errors);
			return response;
		}
		async function html(url, label) {
			const response = await request(url);
			equal(response.httpStatusCode, 200, label + ' responds 200');
			return response.text;
		}

		let result = await run('require_once ABSPATH."wp-admin/includes/plugin.php"; $r=activate_plugin("woocommerce/woocommerce.php"); echo is_wp_error($r) ? $r->get_error_message() : "OK";', 'activate WooCommerce');
		equal(result, 'OK', 'WooCommerce activates');
		result = await run('echo wp_json_encode(["wp"=>get_bloginfo("version"),"wc"=>defined("WC_VERSION") ? WC_VERSION : "", "php"=>PHP_VERSION]);', 'versions');
		const versions = JSON.parse(result);
		equal(versions.wp, '6.4.5', 'expected WordPress fixture');
		equal(versions.wc, '9.0.0', 'expected WooCommerce fixture');
		result = await run(`require_once ABSPATH.'wp-admin/includes/plugin.php';
			$GLOBALS['mjy_skipped_engine']=0;
			add_filter('query',function($sql){
				if (preg_match('/^ALTER TABLE \`wp_mjy_[a-z_]+\` ENGINE=InnoDB$/',$sql)) {
					$GLOBALS['mjy_skipped_engine']++; return 'SELECT 1';
				}
				return $sql;
			});
			$r=activate_plugin('mojoodyar/mojoodyar.php');
			echo wp_json_encode(['result'=>is_wp_error($r)?$r->get_error_message():'OK','skipped'=>$GLOBALS['mjy_skipped_engine']]);`, 'activate plugin');
		const activation = JSON.parse(result);
		equal(activation.result, 'OK', 'Mojoodyar activates in Playground');
		equal(activation.skipped, 5, 'only the five unsupported InnoDB engine ALTERs are skipped');

		const fixture = await php.run({ code: fs.readFileSync(path.join(root, 'tests/playground-fixtures.php'), 'utf8') });
		if (fixture.errors) throw new Error('Build products: ' + fixture.errors);
		const ids = JSON.parse(fixture.text);
		ok(ids.simple > 0 && ids.variable > 0 && ids.variation_unavailable > 0, 'real WC products and variations were created');

		const jquery = fs.readFileSync(require.resolve('jquery'), 'utf8');
		const jsbase = path.join(builds, 'public/wp-6.4/wp-includes/js');
		const frontendScript = fs.readFileSync(path.join(root, 'assets/js/mojoodyar-front.js'), 'utf8');
		const wcVariationScript = fs.readFileSync(path.join(wcCopy, 'assets/js/frontend/add-to-cart-variation.js'), 'utf8');
		async function runDom(markup, withWoo = false) {
			const dom = new JSDOM(markup, { url: 'http://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
			const win = dom.window;
			if (win.document.readyState === 'loading') await new Promise((resolve) => win.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
			win.eval(jquery);
			win.jQuery.fx.off = true;
			if (withWoo) {
				win.eval(fs.readFileSync(path.join(jsbase, 'underscore.min.js'), 'utf8'));
				for (const id of ['wp-util-js-extra', 'wc-add-to-cart-variation-js-extra']) {
					const extra = win.document.getElementById(id);
					if (extra) win.eval(extra.textContent);
				}
				win.eval(fs.readFileSync(path.join(jsbase, 'wp-util.min.js'), 'utf8'));
				win.eval(wcVariationScript);
			}
			const localized = win.document.getElementById('mojoodyar-front-js-extra');
			if (localized) win.eval(localized.textContent);
			win.eval(frontendScript);
			await sleep(withWoo ? 200 : 0);
			return dom;
		}
		function attachFetch(win, forceAjax) {
			const calls = [];
			win.fetch = async (url, options) => {
				if (forceAjax && calls.length === 0) {
					calls.push({ url, status: 403, body: { code: 'rest_blocked' } });
					return { status: 403, json: async () => ({ code: 'rest_blocked' }) };
				}
				const response = await request(url, {
					method: options.method,
					headers: options.headers,
					body: Buffer.from(options.body)
				});
				const body = JSON.parse(response.text);
				calls.push({ url, status: response.httpStatusCode, body });
				return { status: response.httpStatusCode, json: async () => body };
			};
			return calls;
		}
		async function submit(win, form, email) {
			form.querySelector('[name="name"]').value = 'Maryam Ahmadi';
			form.querySelector('[name="email"]').value = email;
			form.dispatchEvent(new win.Event('submit', { bubbles: true, cancelable: true }));
			for (let i = 0; i < 100 && form.querySelector('.mjy-button').disabled; i++) await sleep(50);
			ok(!form.querySelector('.mjy-button').disabled, 'submit completes and re-enables the button');
			contains(form.querySelector('[data-mjy="message"]').textContent, 'ثبت شد', 'success message is displayed');
		}

		// Classic theme: actual PHP/HTML, stock marker, and safe no-JS state.
		const simpleHtml = await html('/?product=simple-sold-out', 'classic sold-out product');
		let doc = getDoc(simpleHtml);
		equal(doc.querySelectorAll('.mjy-form').length, 1, 'one automatic form on sold-out product');
		equal(doc.querySelector('.mjy-form__body').method, 'post', 'native fallback is POST, not GET');
		ok(doc.querySelector('.mjy-button').disabled, 'unbound submit is disabled');
		ok(!!doc.getElementById('mojoodyar-front-css') && !!doc.getElementById('mojoodyar-front-js'), 'classic form assets are present');
		let dom = await runDom(simpleHtml);
		doc = dom.window.document;
		equal(doc.querySelector('.mjy-stock-anchor').nextElementSibling, doc.querySelector('.mjy-form'), 'classic form moves immediately below stock output');
		ok(!doc.querySelector('.mjy-form').closest('form'), 'classic form is not inside a purchase form');
		ok(!doc.querySelector('.mjy-button').disabled, 'binding frontend JS enables submit');
		dom.window.close();

		const availableHtml = await html('/?product=simple-in-stock', 'classic available product');
		doc = getDoc(availableHtml);
		equal(doc.querySelectorAll('.mjy-form').length, 0, 'available product does not get a form');
		ok(!doc.getElementById('mojoodyar-front-css') && !doc.getElementById('mojoodyar-front-js'), 'available product does not enqueue form assets');
		const noPriceHtml = await html('/?product=sold-out-without-price', 'classic unpriced product');
		dom = await runDom(noPriceHtml);
		doc = dom.window.document;
		ok(!doc.querySelector('.mjy-stock-anchor'), 'unpriced product has no WC stock marker');
		equal(doc.querySelector('.mjy-summary-anchor').nextElementSibling, doc.querySelector('.mjy-form'), 'unpriced product uses the summary fallback');
		dom.window.close();

		const pageHtml = await html('/?page_id=' + ids.page, 'shortcode page');
		doc = getDoc(pageHtml);
		equal(doc.querySelectorAll('.mjy-form[data-placement="shortcode"]').length, 1, 'explicit product ID works on another page');
		ok(!!doc.getElementById('mojoodyar-front-css') && !!doc.getElementById('mojoodyar-front-js'), 'late shortcode enqueues CSS and JS');
		const draftHtml = await html('/?page_id=' + ids.draft_page, 'draft shortcode page');
		equal(getDoc(draftHtml).querySelectorAll('.mjy-form').length, 0, 'draft product is not exposed by shortcode');

		await run(`wp_update_post(['ID'=>${ids.simple},'post_content'=>'[mojoodyar_form]']);`, 'add product shortcode');
		const explicitHtml = await html('/?product=simple-sold-out', 'product with shortcode');
		doc = getDoc(explicitHtml);
		equal(doc.querySelectorAll('.mjy-form').length, 1, 'same-product shortcode suppresses the automatic duplicate');
		equal(doc.querySelector('.mjy-form').dataset.placement, 'shortcode', 'explicit placement is retained');
		await run(`wp_update_post(['ID'=>${ids.simple},'post_content'=>'']);`, 'remove product shortcode');

		// Actual WC variation JS emits events; submit through real WP REST.
		const variableHtml = await html('/?product=shoe', 'classic variable product');
		dom = await runDom(variableHtml, true);
		let win = dom.window;
		doc = win.document;
		let rootNode = doc.querySelector('.mjy-form');
		let form = rootNode.querySelector('form');
		let cart = doc.querySelector('form.variations_form');
		let choice = form.querySelector('[name="variation_id"]');
		equal(cart.nextElementSibling, rootNode, 'variable form is placed after its own cart');
		ok(!rootNode.closest('form'), 'variable form is not nested');
		equal(choice.value, '0', 'no variation is initially selected');
		win.jQuery(cart).find('.variations select').val('43').trigger('change');
		await sleep(400);
		equal(cart.querySelector('[name="variation_id"]').value, String(ids.variation_unavailable), 'actual WooCommerce chooses sold-out variation');
		equal(choice.value, String(ids.variation_unavailable), 'waitlist follows WC selection');
		ok(!rootNode.classList.contains('is-hidden'), 'form is visible for sold-out variation');
		win.jQuery(cart).find('.variations select').val('42').trigger('change');
		await sleep(400);
		ok(rootNode.classList.contains('is-hidden'), 'form hides for an in-stock choice when all unavailable choices remain selectable in the cart');
		equal(choice.value, '0', 'in-stock cart choice is not submitted as unavailable');
		win.jQuery(cart).find('.variations select').val('').trigger('change');
		await sleep(400);
		ok(!rootNode.classList.contains('is-hidden'), 'reset exposes the standalone selector');
		win.jQuery(cart).find('.variations select').val('43').trigger('change');
		await sleep(400);
		let calls = attachFetch(win, false);
		await submit(win, form, 'variant@example.com');
		equal(calls.length, 1, 'REST path made one request');
		equal(calls[0].status, 201, 'real WordPress REST accepts subscription');
		equal(calls[0].body.code, 'subscribed', 'one-step subscription result');
		equal(choice.value, String(ids.variation_unavailable), 'successful submission keeps selected variation');
		calls = attachFetch(win, true);
		await submit(win, form, 'fallback@example.com');
		equal(calls.length, 2, 'blocked REST falls back once');
		contains(calls[1].url, 'admin-ajax.php', 'fallback reaches WP admin-ajax');
		equal(calls[1].status, 201, 'real WP AJAX accepts fallback');
		dom.window.close();

		// WooCommerce hides sold-out options from purchase, but its store setting
		// does not disable the independent notification selector.
		await run(`update_option('woocommerce_hide_out_of_stock_items','yes'); delete_transient('wc_product_children_${ids.variable}');`, 'hide stock');
		const hiddenHtml = await html('/?product=shoe', 'variable product with catalog hiding');
		doc = getDoc(hiddenHtml);
		const wcChoices = JSON.parse(doc.querySelector('form.variations_form').dataset.product_variations);
		ok(!wcChoices.some((v) => v.variation_id === ids.variation_unavailable), 'WooCommerce purchase options still hide sold-out variation');
		ok(!!doc.querySelector('.mjy-form option[value="' + ids.variation_unavailable + '"]'), 'independent waitlist offers the sold-out variation');
		dom = await runDom(hiddenHtml, true);
		win = dom.window;
		cart = win.document.querySelector('form.variations_form');
		rootNode = win.document.querySelector('.mjy-form');
		form = rootNode.querySelector('form');
		win.jQuery(cart).find('.variations select').val('42').trigger('change');
		await sleep(400);
		equal(cart.querySelector('[name="variation_id"]').value, String(ids.variation_available), 'real WooCommerce selects the available purchase variation');
		ok(!rootNode.classList.contains('is-hidden'), 'hidden purchase options remain reachable even with an in-stock cart selection');
		form.querySelector('[name="variation_id"]').value = String(ids.variation_unavailable);
		calls = attachFetch(win, false);
		await submit(win, form, 'hidden@example.com');
		equal(calls[0].status, 201, 'hidden purchase option is accepted by real REST waitlist');
		dom.window.close();
		result = await run(`global $wpdb; echo wp_json_encode(['setting'=>get_option('woocommerce_hide_out_of_stock_items'),'rows'=>$wpdb->get_results('SELECT product_id,variation_id,email,status FROM '.$wpdb->prefix.'mjy_waitlists ORDER BY id',ARRAY_A)]);`, 'read waitlist');
		const stored = JSON.parse(result);
		equal(stored.setting, 'yes', 'catalog hiding setting was not changed');
		equal(stored.rows.length, 3, 'three real registrations were stored');
		ok(stored.rows.every((row) => Number(row.product_id) === ids.variable && Number(row.variation_id) === ids.variation_unavailable && row.status === 'confirmed'), 'all stored rows target the chosen variation');

		// When every variation is unavailable, WooCommerce can replace its
		// purchase options with a stock notice. The waitlist must still render.
		await run(`$v=wc_get_product(${ids.variation_available}); $v->set_stock_status('outofstock'); $v->save(); WC_Product_Variable::sync(${ids.variable}); delete_transient('wc_product_children_${ids.variable}');`, 'make every variation unavailable');
		const allOutHtml = await html('/?product=shoe', 'all variations unavailable with catalog hiding');
		doc = getDoc(allOutHtml);
		ok(!!doc.querySelector('.mjy-form option[value="' + ids.variation_unavailable + '"]'), 'all-unavailable parent still offers waitlist choices');
		const emptyCart = doc.querySelector('form.variations_form');
		equal(JSON.parse(emptyCart.dataset.product_variations).length, 0, 'WooCommerce offers no purchase variations when all are hidden');
		ok(!!emptyCart.querySelector('.stock.out-of-stock'), 'WooCommerce shows its all-unavailable notice');
		dom = await runDom(allOutHtml, true);
		doc = dom.window.document;
		equal(doc.querySelector('form.variations_form').nextElementSibling, doc.querySelector('.mjy-form'), 'all-unavailable form stays outside and below the purchase form');
		ok(!doc.querySelector('.mjy-form').closest('form'), 'all-unavailable waitlist form is not nested');
		dom.window.close();

		// A forged variation of a different parent must not create a row, even
		// when the queried parent itself is also out of stock.
		const nonce = JSON.parse(await run(`echo wp_json_encode(['nonce'=>wp_create_nonce('wp_rest'),'route'=>rest_url('mojoodyar/v1/subscribe')]);`, 'REST nonce'));
		const forged = await request(nonce.route, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce.nonce },
			body: Buffer.from(JSON.stringify({ product_id: ids.variable, variation_id: ids.other_variation, channel: 'email', name: 'Maryam Ahmadi', email: 'forged@example.com' }))
		});
		equal(forged.httpStatusCode, 422, 'real REST rejects another parent’s variation');
		equal(JSON.parse(forged.text).code, 'invalid_variation', 'forged variation has the correct error');
		result = await run(`global $wpdb; echo (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'mjy_waitlists');`, 'verify forged row');
		equal(Number(result), 3, 'forged request did not create a waitlist row');

		// Exercise the real block theme and WC variation script while the
		// selected variation is still sold out.
		await run(`$v=wc_get_product(${ids.variation_available}); $v->set_stock_status('instock'); $v->save(); WC_Product_Variable::sync(${ids.variable}); switch_theme('twentytwentyfour'); update_option('woocommerce_hide_out_of_stock_items','no'); delete_transient('wc_product_children_${ids.variable}');`, 'switch block theme');
		const blockVariable = await html('/?product=shoe', 'block-theme variable product');
		dom = await runDom(blockVariable, true);
		win = dom.window;
		doc = win.document;
		rootNode = doc.querySelector('.mjy-form');
		cart = doc.querySelector('form.variations_form');
		equal(cart.nextElementSibling, rootNode, 'block variable form is safely placed after the matching cart');
		ok(!rootNode.closest('form'), 'block variable form is not nested');
		win.jQuery(cart).find('.variations select').val('43').trigger('change');
		await sleep(400);
		equal(rootNode.querySelector('[name="variation_id"]').value, String(ids.variation_unavailable), 'block form follows actual WC variation event');
		dom.window.close();

		// Run real WooCommerce's stock hook and queue; wp_mail is intercepted so
		// no external message is sent. SQLite is not a MySQL/InnoDB stress test.
		result = await run(`$v=wc_get_product(${ids.variation_unavailable}); $v->set_stock_status('instock'); $v->save(); $v->save();
			global $wpdb; echo wp_json_encode($wpdb->get_results('SELECT status FROM '.$wpdb->prefix.'mjy_queue',ARRAY_A));`, 'restock');
		const queued = JSON.parse(result);
		equal(queued.length, 3, 'restock queues one message per subscriber, not twice');
		ok(queued.every((row) => row.status === 'queued'), 'all restock messages are queued');
		result = await run(`add_filter('pre_wp_mail',function($result,$atts){$GLOBALS['mjy_mail'][]=['to'=>$atts['to'],'headers'=>$atts['headers']];return true;},10,2);
			$stats=\\Mojoodyar\\Queue::process(); global $wpdb;
			echo wp_json_encode(['stats'=>$stats,'mail'=>$GLOBALS['mjy_mail']??[],'queue'=>$wpdb->get_results('SELECT status FROM '.$wpdb->prefix.'mjy_queue',ARRAY_A),'wait'=>$wpdb->get_results('SELECT status FROM '.$wpdb->prefix.'mjy_waitlists',ARRAY_A)]);`, 'deliver queue');
		const delivered = JSON.parse(result);
		equal(delivered.stats.sent, 3, 'real queue processes all three messages');
		equal(delivered.mail.length, 3, 'WordPress wp_mail is called three times (intercepted)');
		ok(delivered.queue.every((row) => row.status === 'sent') && delivered.wait.every((row) => row.status === 'notified'), 'queue and waitlist states are updated');
		ok(delivered.mail.every((mail) => mail.headers.some((header) => header.includes('owner@example.com'))), 'site admin email is used as the default sender');

		// Exercise the actual admin-post tool handler, including its nonce and
		// redirect/exit. A shutdown callback captures only the disposable test.
		result = await run(`wp_set_current_user(1); $_POST=['tool'=>'test_email','_wpnonce'=>wp_create_nonce('mojoodyar_tool')];
			add_filter('pre_wp_mail',function($r,$a){$GLOBALS['tool_mail'][]=$a;return true;},10,2);
			register_shutdown_function(function(){echo wp_json_encode(['mail'=>$GLOBALS['tool_mail']??[],'notice'=>get_transient('mojoodyar_tool_notice')]);});
			(new \\Mojoodyar\\Admin\\Admin())->handle_tool();`, 'run actual test-email tool');
		const emailTool = JSON.parse(result);
		equal(emailTool.mail.length, 1, 'email tool invokes wp_mail, not the SMS pattern probe');
		equal(emailTool.mail[0].to, 'owner@example.com', 'test email targets this site admin');
		contains(emailTool.notice, 'wp_mail', 'test-email notice describes the local result');
		contains(emailTool.notice, 'تضمین نمی‌شود', 'test-email notice does not promise inbox delivery');
		result = await run(`wp_set_current_user(1); $_POST=['tool'=>'pattern_probe','_wpnonce'=>wp_create_nonce('mojoodyar_tool')];
			add_filter('pre_wp_mail',function($r,$a){$GLOBALS['tool_mail'][]=$a;return true;},10,2);
			register_shutdown_function(function(){echo wp_json_encode(['mail'=>$GLOBALS['tool_mail']??[],'notice'=>get_transient('mojoodyar_tool_notice')]);});
			(new \\Mojoodyar\\Admin\\Admin())->handle_tool();`, 'run actual pattern probe tool');
		const patternTool = JSON.parse(result);
		equal(patternTool.mail.length, 0, 'pattern probe does not send an email');
		contains(patternTool.notice, 'شماره', 'pattern probe asks for a test phone');

		// A paused schema must not let the "send now" tool pull a future job
		// forward before it discovers that queue processing cannot run.
		result = await run(`global $wpdb; wp_set_current_user(1);
			$queue=$wpdb->prefix.'mjy_queue'; $wait=$wpdb->prefix.'mjy_waitlists';
			$id=(int)$wpdb->get_var("SELECT id FROM $wait WHERE email='variant@example.com'");
			$inserted=\\Mojoodyar\\Queue::enqueue($id,'email','diagnostic:paused',['test'=>1],time()+3600,0);
			$qid=(int)$wpdb->insert_id; $before=$wpdb->get_var($wpdb->prepare("SELECT next_run_at FROM $queue WHERE id=%d",$qid));
			$original=get_option('mojoodyar_db_version'); update_option('mojoodyar_db_version','4');
			$_POST=['tool'=>'send_due_now','_wpnonce'=>wp_create_nonce('mojoodyar_tool')];
			register_shutdown_function(function() use ($wpdb,$queue,$qid,$before,$original,$inserted){
				$after=$wpdb->get_var($wpdb->prepare("SELECT next_run_at FROM $queue WHERE id=%d",$qid));
				update_option('mojoodyar_db_version',$original); $wpdb->delete($queue,['id'=>$qid]);
				echo wp_json_encode(['inserted'=>$inserted,'before'=>$before,'after'=>$after,'notice'=>get_transient('mojoodyar_tool_notice')]);
			});
			(new \\Mojoodyar\\Admin\\Admin())->handle_tool();`, 'run send-now tool during a paused schema');
		const pausedTool = JSON.parse(result);
		ok(pausedTool.inserted && !!pausedTool.before, 'test creates a scheduled job');
		equal(pausedTool.after, pausedTool.before, 'send-now does not change the schedule while schema is paused');
		contains(pausedTool.notice, 'جدول', 'send-now explains the incomplete upgrade');
		contains(pausedTool.notice, 'متوقف', 'send-now reports processing is paused');

		// Re-register a subscriber after a genuinely sent restock. Historical
		// sent rows must remain intact without blocking the next notification.
		await run(`$v=wc_get_product(${ids.variation_unavailable}); $v->set_stock_status('outofstock'); $v->save(); WC_Product_Variable::sync(${ids.variable});`, 'make variation unavailable again');
		const renewalRequest = () => request(nonce.route, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce.nonce },
			body: Buffer.from(JSON.stringify({ product_id: ids.variable, variation_id: ids.variation_unavailable, channel: 'email', name: 'Maryam Ahmadi', email: 'variant@example.com' }))
		});
		const renewedResponse = await renewalRequest();
		const renewed = JSON.parse(renewedResponse.text);
		equal(renewedResponse.httpStatusCode, 200, 'real REST accepts repeat registration');
		equal(renewed.data.revived, true, 'previously notified row is revived');
		equal(renewed.data.needs_confirm, false, 'one-step renewal does not ask for confirmation');
		result = await run(`global $wpdb; $w=$wpdb->get_row("SELECT id,token,registration_cycle,status FROM {$wpdb->prefix}mjy_waitlists WHERE email='variant@example.com'",ARRAY_A);
			echo wp_json_encode(['row'=>$w,'waiting'=>\\Mojoodyar\\WaitlistRepository::count_confirmed(${ids.variable},${ids.variation_unavailable}),'old_sent'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mjy_queue WHERE status='sent'")]);`, 'inspect renewal');
		const renewedRow = JSON.parse(result);
		equal(Number(renewedRow.row.registration_cycle), 1, 'renewal advances the stored registration cycle');
		equal(Number(renewedRow.waiting), 1, 'old sent history does not block the renewed subscriber');
		equal(Number(renewedRow.old_sent), 3, 'previous sent history is retained');
		result = await run(`$v=wc_get_product(${ids.variation_unavailable}); $v->set_stock_status('instock'); $v->save(); $v->save();
			add_filter('pre_wp_mail',function($r,$a){$GLOBALS['mjy_mail'][]=$a['to'];return true;},10,2);
			$stats=\\Mojoodyar\\Queue::process(); global $wpdb;
			echo wp_json_encode(['stats'=>$stats,'mail'=>$GLOBALS['mjy_mail']??[],'row'=>$wpdb->get_row("SELECT status FROM {$wpdb->prefix}mjy_waitlists WHERE email='variant@example.com'",ARRAY_A)]);`, 'send renewed restock');
		const secondRestock = JSON.parse(result);
		equal(secondRestock.stats.sent, 1, 'renewed subscriber receives a second queued restock in Playground');
		equal(secondRestock.mail.length, 1, 'second notification reaches intercepted wp_mail');
		equal(secondRestock.row.status, 'notified', 'renewed subscription becomes notified again');

		// Switch on optional double opt-in and renew the same row again.
		// This disposable suite sends many requests from one simulated IP.
		await run(`global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}mjy_logs WHERE ip_hash <> ''");
			\\Mojoodyar\\Settings::update(['double_opt_in'=>true]); $v=wc_get_product(${ids.variation_unavailable}); $v->set_stock_status('outofstock'); $v->save(); WC_Product_Variable::sync(${ids.variable});`, 'enable confirmation and sell out again');
		const doubleResponse = await renewalRequest();
		const doubleRenewed = JSON.parse(doubleResponse.text);
		equal(doubleResponse.httpStatusCode, 200, 'two-step renewal succeeds via REST');
		equal(doubleRenewed.data.needs_confirm, true, 'renewal reports that confirmation is required');
		contains(doubleRenewed.message, 'صف', 'renewal says confirmation is queued, not delivered');
		result = await run(`global $wpdb; $w=$wpdb->get_row("SELECT id,token,registration_cycle,status,confirm_expires_at FROM {$wpdb->prefix}mjy_waitlists WHERE email='variant@example.com'",ARRAY_A);
			$q=$wpdb->get_row($wpdb->prepare("SELECT status,registration_cycle FROM {$wpdb->prefix}mjy_queue WHERE waitlist_id=%d AND event_key='confirm' ORDER BY id DESC LIMIT 1",$w['id']),ARRAY_A);
			echo wp_json_encode(['row'=>$w,'queue'=>$q,'old_link'=>\\Mojoodyar\\WaitlistRepository::confirm_by_token('${renewedRow.row.token}')]);`, 'inspect confirmation');
		const confirmation = JSON.parse(result);
		equal(confirmation.row.status, 'pending', 'renewed subscriber remains pending before confirmation');
		equal(Number(confirmation.row.registration_cycle), 2, 'double opt-in renewal has its own cycle');
		equal(confirmation.queue.status, 'queued', 'fresh confirmation is queued');
		equal(Number(confirmation.queue.registration_cycle), 2, 'confirmation job is bound to the new cycle');
		equal(confirmation.old_link, 'invalid', 'older confirmation token cannot confirm the new registration');
		result = await run(`add_filter('pre_wp_mail',function($r,$a){$GLOBALS['mjy_mail'][]=$a['message'];return true;},10,2);
			$stats=\\Mojoodyar\\Queue::process();
			echo wp_json_encode(['stats'=>$stats,'mail'=>$GLOBALS['mjy_mail']??[],'confirmed'=>\\Mojoodyar\\WaitlistRepository::confirm_by_token('${confirmation.row.token}')]);`, 'send renewed confirmation');
		const confirmDelivery = JSON.parse(result);
		equal(confirmDelivery.stats.sent, 1, 'new confirmation reaches intercepted wp_mail');
		contains(confirmDelivery.mail[0], confirmation.row.token, 'confirmation email contains the new token');
		equal(confirmDelivery.confirmed, 'confirmed', 'new token confirms the renewed registration');
		result = await run(`$v=wc_get_product(${ids.variation_unavailable}); $v->set_stock_status('instock'); $v->save();
			add_filter('pre_wp_mail',function($r,$a){$GLOBALS['mjy_mail'][]=$a['to'];return true;},10,2);
			$stats=\\Mojoodyar\\Queue::process(); echo wp_json_encode(['stats'=>$stats,'mail'=>$GLOBALS['mjy_mail']??[]]);`, 'send confirmed restock');
		const confirmedRestock = JSON.parse(result);
		equal(confirmedRestock.stats.sent, 1, 'confirmed renewal receives the next restock notification');
		equal(confirmedRestock.mail.length, 1, 'confirmed restock reaches intercepted wp_mail');

		// Block theme: product markup does not necessarily have #product-ID.
		const blockHtml = await html('/?product=simple-sold-out', 'block-theme sold-out product');
		dom = await runDom(blockHtml);
		doc = dom.window.document;
		ok(!doc.getElementById('product-' + ids.simple), 'block fixture lacks legacy product ID wrapper');
		const blockStock = doc.querySelector('.wc-block-add-to-cart-form .stock.out-of-stock');
		ok(!!blockStock, 'block theme renders a stock notice');
		equal(blockStock.nextElementSibling, doc.querySelector('.mjy-form'), 'block-theme form moves immediately below the stock notice');
		ok(!doc.querySelector('.mjy-form').closest('form'), 'block form is not nested');
		dom.window.close();
		const blockPage = await html('/?page_id=' + ids.page, 'block-theme shortcode page');
		doc = getDoc(blockPage);
		equal(doc.querySelectorAll('.mjy-form[data-placement="shortcode"]').length, 1, 'shortcode renders in block theme');
		ok(!!doc.getElementById('mojoodyar-front-css') && !!doc.getElementById('mojoodyar-front-js'), 'shortcode assets also print in block theme');

		// Simulate a schema-v4 installation with data and the same public 1.0.0
		// version. First deny the two ADDs, then allow dbDelta to retry. This is
		// a SQLite-adapter migration test, not a MySQL privilege/locking test.
		result = await run(`global $wpdb; $wpdb->suppress_errors(true);
			$wait=$wpdb->prefix.'mjy_waitlists'; $queue=$wpdb->prefix.'mjy_queue';
			$before=['wait'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $wait"),'queue'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue"),
				'token'=>(string)$wpdb->get_var("SELECT token FROM $wait WHERE email='variant@example.com'")];
			$dropWait=$wpdb->query("ALTER TABLE $wait DROP COLUMN registration_cycle");
			$dropQueue=$wpdb->query("ALTER TABLE $queue DROP COLUMN registration_cycle");
			update_option('mojoodyar_db_version','4'); update_option('mojoodyar_version','1.0.0');
			$blocked=0; $defaultSkips=0;
			$deny=function($sql) use (&$blocked,&$defaultSkips,$wait) {
				if (stripos($sql,'ALTER TABLE')===0 && stripos($sql,'registration_cycle')!==false) {$blocked++;return 'SELECT 1';}
				if (stripos($sql,'ALTER TABLE')===0 && strpos($sql,'ENGINE=InnoDB')!==false) return 'SELECT 1';
				if (stripos($sql,'ALTER TABLE '.$wait)===0 && stripos($sql,'ALTER COLUMN')!==false && stripos($sql,'channel')!==false && stripos($sql,'SET DEFAULT')!==false) {$defaultSkips++;return 'SELECT 1';}
				return $sql;
			};
			add_filter('query',$deny); \\Mojoodyar\\Activator::maybe_upgrade(); remove_filter('query',$deny);
			ob_start(); \\Mojoodyar\\Plugin::instance()->upgrade_incomplete_notice(); $notice=ob_get_clean();
			$failed=['db'=>(string)get_option('mojoodyar_db_version'),'version'=>(string)get_option('mojoodyar_version'),
				'subscribe'=>\\Mojoodyar\\Subscribe::handle(['product_id'=>${ids.simple},'email'=>'blocked@example.com','channel'=>'email','name'=>'Test User']),
				'queue'=>\\Mojoodyar\\Queue::process(), 'notice'=>$notice,
				'reconcile'=>(new \\Mojoodyar\\StockWatcher())->reconcile(true)];
			add_filter('query',function($sql) use (&$defaultSkips,$wait) {
				if (stripos($sql,'ALTER TABLE')===0 && strpos($sql,'ENGINE=InnoDB')!==false) return 'SELECT 1';
				if (stripos($sql,'ALTER TABLE '.$wait)===0 && stripos($sql,'ALTER COLUMN')!==false && stripos($sql,'channel')!==false && stripos($sql,'SET DEFAULT')!==false) {$defaultSkips++;return 'SELECT 1';}
				return $sql;
			});
			\\Mojoodyar\\Activator::maybe_upgrade();
			$after=['wait'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $wait"),'queue'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue"),
				'token'=>(string)$wpdb->get_var("SELECT token FROM $wait WHERE email='variant@example.com'"),
				'wait_cycle'=>(int)$wpdb->get_var("SELECT registration_cycle FROM $wait WHERE email='variant@example.com'"),
				'queue_cycles'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $queue WHERE registration_cycle <> 0"),
				'db'=>(string)get_option('mojoodyar_db_version'),'version'=>(string)get_option('mojoodyar_version')];
			echo wp_json_encode(['dropped'=>[$dropWait!==false,$dropQueue!==false],'blocked'=>$blocked,'default_skips'=>$defaultSkips,'failed'=>$failed,'before'=>$before,'after'=>$after]);`, 'upgrade schema with preserved data');
		const migration = JSON.parse(result);
		ok(migration.dropped.every(Boolean), 'test fixture recreates pre-cycle columns');
		ok(migration.blocked >= 1, 'test-only filter simulates a denied column upgrade');
		ok(migration.default_skips >= 1, 'unsupported SQLite ALTER COLUMN default is explicitly skipped');
		equal(migration.failed.db, '4', 'failed schema upgrade is not stamped complete');
		equal(migration.failed.version, '1.0.0', 'public version does not change on failure');
		equal(migration.failed.subscribe.code, 'schema_upgrade', 'subscription fails safely until upgrade succeeds');
		equal(migration.failed.subscribe.status, 503, 'failed upgrade returns temporary unavailability');
		equal(migration.failed.queue.skipped, 'schema', 'queue pauses during incomplete upgrade');
		equal(migration.failed.reconcile.skipped, 'schema', 'stock reconciliation pauses during incomplete upgrade');
		contains(migration.failed.notice, 'ثبت و پردازش صف موقتاً متوقف‌اند', 'admin notice renders and describes the pause in WordPress');
		contains(migration.failed.notice, 'mojoodyar-tools', 'admin notice links to the existing tools page');
		equal(migration.after.db, '5', 'next attempt completes the internal schema upgrade');
		equal(migration.after.version, '1.0.0', 'public version remains 1.0.0 after upgrade');
		equal(migration.after.wait, migration.before.wait, 'upgrade preserves waitlist rows');
		equal(migration.after.queue, migration.before.queue, 'upgrade preserves queue history');
		equal(migration.after.token, migration.before.token, 'upgrade preserves existing token');
		equal(migration.after.wait_cycle, 0, 'pre-upgrade subscriber defaults to cycle zero');
		equal(migration.after.queue_cycles, 0, 'pre-upgrade queue history defaults to cycle zero');

		console.log(JSON.stringify({
			assertions,
			failures: 0,
			versions,
			themes: ['Twenty Twenty-One 2.1', 'Twenty Twenty-Four 1.0'],
			storage: 'Playground SQLite adapter; test-only ENGINE and channel ALTER DEFAULT skips; ADD denial simulated',
			browser: 'jsdom ' + require('jsdom/package.json').version + ', jQuery 3.7.1, real WC variation JS',
			mail: 'pre_wp_mail intercepted; no external delivery',
			woocommerce_source: 'provided externally via MOJOODYAR_WC_DIR; verify provenance separately'
		}, null, 2));
	} finally {
		if (handler && Symbol.asyncDispose && handler[Symbol.asyncDispose]) await handler[Symbol.asyncDispose]();
		fs.rmSync(temporary, { recursive: true, force: true });
	}
}

main().then(() => process.exit(0), (error) => { console.error(error.stack || error); process.exit(1); });
