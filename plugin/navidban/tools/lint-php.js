#!/usr/bin/env node
/**
 * Parse every PHP file with two grammars: PHP 7.4 (the supported floor) and
 * PHP 8.3. A file that only parses on 8.x is a compatibility regression, even
 * if the current host runs 8.3.
 *
 * Dependencies: one dev-only npm package, never shipped in the plugin zip.
 *   npm install --no-save php-parser
 *
 * Usage:
 *   node tools/lint-php.js            # both grammars
 *   node tools/lint-php.js --json
 */

'use strict';

const fs = require('fs');
const path = require('path');

let Engine;
try {
	Engine = require('php-parser');
} catch (error) {
	console.error('php-parser is not installed. Run: npm install --no-save php-parser');
	process.exit(2);
}

const ROOT = path.resolve(__dirname, '..');
const VERSIONS = [
	{ label: 'PHP 7.4', value: 704 },
	{ label: 'PHP 8.3', value: 803 },
];

const EXCLUDED_DIRS = new Set(['node_modules', '.git', 'dist', 'vendor']);

function collectPhpFiles(dir, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.isDirectory()) {
			if (EXCLUDED_DIRS.has(entry.name)) {
				continue;
			}
			collectPhpFiles(path.join(dir, entry.name), out);
		} else if (entry.isFile() && entry.name.endsWith('.php')) {
			out.push(path.join(dir, entry.name));
		}
	}

	return out;
}

function parseWith(version, code, filename) {
	const engine = new Engine({
		parser: { extractDoc: false, suppressErrors: false, version },
		ast: { withPositions: true },
		lexer: { all_tokens: false, comment_tokens: false, mode_eval: false, asp_tags: false, short_tags: true },
	});

	try {
		engine.parseCode(code, filename);
		return null;
	} catch (error) {
		return {
			message: String(error.message || error),
			line: error.lineNumber || (error.loc && error.loc.start && error.loc.start.line) || 0,
		};
	}
}

const files = collectPhpFiles(ROOT).sort();
const results = [];

for (const file of files) {
	const relative = path.relative(ROOT, file);
	const code = fs.readFileSync(file, 'utf8');

	for (const version of VERSIONS) {
		const error = parseWith(version.value, code, relative);
		results.push({
			file: relative,
			version: version.label,
			ok: error === null,
			error,
		});
	}
}

const failures = results.filter((row) => !row.ok);
const asJson = process.argv.includes('--json');

const summary = {
	generated_at: new Date().toISOString(),
	files: files.length,
	parses: results.length,
	failures: failures.length,
	versions: VERSIONS.map((v) => v.label),
	details: failures,
};

if (asJson) {
	console.log(JSON.stringify(summary, null, 2));
} else {
	console.log(`\n=== Navidban PHP grammar check ===`);
	console.log(`files: ${files.length} · parses: ${results.length} (${VERSIONS.map((v) => v.label).join(' + ')})`);

	for (const row of failures) {
		console.log(`[FAIL] ${row.file} (${row.version}) line ${row.error.line}: ${row.error.message}`);
	}

	if (failures.length === 0) {
		console.log('every file parses with both grammars');
	}

	console.log(`\nfailures: ${failures.length}`);
}

process.exit(failures.length === 0 ? 0 : 1);
