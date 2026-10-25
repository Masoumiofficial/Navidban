#!/usr/bin/env node
/**
 * Run one PHP file of this plugin, with arguments, in a sandbox.
 *
 * Two engines, chosen automatically:
 *
 *   1. `native` — a real `php` binary from PATH. Used when present; fastest and
 *      the only way to validate against a genuine PHP build.
 *   2. `wasm`   — @php-wasm/node (PHP compiled to WebAssembly). Optional,
 *      dev-only npm packages, never shipped in the release zip.
 *
 * If neither is available the script exits with code 3 and says so. It never
 * pretends a suite ran when it did not.
 *
 * Usage:
 *   node tools/smoke-php.mjs tests/run.php
 *   node tools/smoke-php.mjs tests/run.php --json --json-out tests/results.json
 *   node tools/smoke-php.mjs --engine=wasm --php=7.4 tests/run.php --json
 *
 * Options:
 *   --engine=native|wasm|auto   default auto
 *   --php=8.3|7.4               wasm runtime version (defines the testing floor)
 *   --json-out=path             write captured stdout to a file on the host
 *   --timeout=ms                default 120000
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { createRequire } from 'node:module';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const WASM_PLUGIN_PATH = '/plugin-tree/navidban';

const rawArgs = process.argv.slice(2);
const options = { engine: 'auto', php: '8.3', jsonOut: null, timeout: 120000 };
const scriptArgs = [];

for (const arg of rawArgs) {
	if (arg.startsWith('--engine=')) {
		options.engine = arg.slice('--engine='.length);
	} else if (arg.startsWith('--php=')) {
		options.php = arg.slice('--php='.length);
	} else if (arg.startsWith('--json-out=')) {
		options.jsonOut = arg.slice('--json-out='.length);
	} else if (arg.startsWith('--timeout=')) {
		options.timeout = Number(arg.slice('--timeout='.length)) || options.timeout;
	} else {
		scriptArgs.push(arg);
	}
}

if (scriptArgs.length === 0) {
	console.error('usage: node tools/smoke-php.mjs [options] <script.php> [script args]');
	process.exit(2);
}

const script = path.resolve(ROOT, scriptArgs[0]);
if (!fs.existsSync(script)) {
	console.error(`no such script: ${script}`);
	process.exit(2);
}

const scriptRelative = path.relative(ROOT, script);
const phpArgs = scriptArgs.slice(1);

let exitCode = 0;
let output = '';

function hasNativePhp() {
	const probe = spawnSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' });
	return probe.status === 0 && /\d+\.\d+/.test(probe.stdout || '');
}

function runNative() {
	const result = spawnSync('php', [script, ...phpArgs], {
		encoding: 'utf8',
		cwd: ROOT,
		timeout: options.timeout,
		env: { ...process.env, NAVIDBAN_SMOKE: '1' },
	});
	output = (result.stdout || '') + (result.stderr || '');
	exitCode = result.status === null ? 1 : result.status;
}

function copyTree(php, source, dest) {
	php.mkdir(dest);
	for (const entry of fs.readdirSync(source, { withFileTypes: true })) {
		if (entry.name === '.git' || entry.name === 'node_modules') {
			continue;
		}
		const from = path.join(source, entry.name);
		const to = path.join(dest, entry.name);
		if (entry.isDirectory()) {
			copyTree(php, from, to);
		} else {
			php.writeFile(to, fs.readFileSync(from));
		}
	}
}

async function runWasm() {
	const require = createRequire(import.meta.url);
	let PHP;
	let loadNodeRuntime;

	try {
		({ PHP } = require('@php-wasm/universal'));
		({ loadNodeRuntime } = require('@php-wasm/node'));
	} catch (error) {
		console.error(
			'wasm engine unavailable: npm install --no-save @php-wasm/node @php-wasm/universal'
		);
		process.exit(3);
	}

	const runtime = await loadNodeRuntime(options.php, { emscriptenOptions: { processId: 1 } });
	const php = new PHP(runtime);

	php.mkdir('/plugin-tree');
	copyTree(php, ROOT, WASM_PLUGIN_PATH);

	// Scripts read $_SERVER['argv'] (php-cli style). php-wasm runs in request
	// mode, so the bootstrap seeds both $argv and $_SERVER['argv'].
	const bootstrap = `${WASM_PLUGIN_PATH}/__smoke_bootstrap.php`;
	php.writeFile(
		bootstrap,
		`<?php
$GLOBALS['argv'] = ${JSON.stringify([scriptRelative, ...phpArgs])};
$GLOBALS['argc'] = count( $GLOBALS['argv'] );
$_SERVER['argv'] = $GLOBALS['argv'];
$_SERVER['argc'] = $GLOBALS['argc'];
require ${JSON.stringify(WASM_PLUGIN_PATH + '/' + scriptRelative)};
`
	);

	// A non-zero exit throws PHPExecutionFailureError, whose payload still
	// carries the captured stdout. Losing it would hide every failing test.
	let result;
	try {
		result = await php.run({
			scriptPath: bootstrap,
			relativeUri: WASM_PLUGIN_PATH,
		});
	} catch (error) {
		result = error && error.response ? error.response : null;
		if (!result) {
			console.error(`[smoke-php] wasm run failed: ${error && error.message ? error.message : error}`);
			process.exit(1);
		}
	}

	output = (result.text || '') + (result.errors ? String(result.errors) : '');
	exitCode = result.exitCode === undefined ? (result.httpStatusCode >= 400 ? 1 : 0) : result.exitCode;

	const version = await php.run({ code: '<?php echo PHP_VERSION;' });
	console.error(`[smoke-php] wasm engine, PHP ${version.text.trim()}`);
}

const engine = options.engine === 'auto' ? (hasNativePhp() ? 'native' : 'wasm') : options.engine;

if (engine === 'native') {
	runNative();
} else if (engine === 'wasm') {
	await runWasm();
} else {
	console.error(`unknown engine: ${engine}`);
	process.exit(2);
}

process.stdout.write(output);

if (options.jsonOut) {
	const target = path.resolve(ROOT, options.jsonOut);
	fs.mkdirSync(path.dirname(target), { recursive: true });
	// Keep only the JSON document, so the artefact is machine-readable even if
	// the script also printed a human summary.
	const start = output.indexOf('{');
	const end = output.lastIndexOf('}');
	if (start !== -1 && end > start) {
		fs.writeFileSync(target, output.slice(start, end + 1).trim() + '\n');
		console.error(`[smoke-php] wrote ${path.relative(process.cwd(), target)}`);
	} else {
		console.error('[smoke-php] --json-out requested but stdout contained no JSON object');
		process.exit(1);
	}
}

process.exit(exitCode);
