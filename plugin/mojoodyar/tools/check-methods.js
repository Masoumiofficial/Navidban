#!/usr/bin/env node
/**
 * Referential integrity check: every `$this->method()`, `self::method()` and
 * `KnownClass::method()` must point at a method that actually exists in this
 * codebase.
 *
 * This is not a type checker. It catches the specific class of bug that bites
 * first after a refactor: a renamed or never-written method that only fails at
 * runtime, on the one code path nobody exercised.
 *
 * Usage: node tools/check-methods.js [--json]
 * Dependency: npm install --no-save php-parser
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

const engine = new Engine({
	parser: { extractDoc: false, suppressErrors: false, version: 803 },
	ast: { withPositions: true },
});

const files = collectPhpFiles(ROOT).sort();

/** class name => { file, methods:Set, traits:Set, parent:string|null, kind } */
const classes = new Map();
const asts = [];

for (const file of files) {
	const relative = path.relative(ROOT, file);
	let ast;
	try {
		ast = engine.parseCode(fs.readFileSync(file, 'utf8'), relative);
	} catch (error) {
		console.error(`parse error in ${relative}: ${error.message}`);
		process.exit(2);
	}
	asts.push({ file: relative, ast });
}

function className(node) {
	if (!node.name) {
		return null;
	}
	if (typeof node.name === 'string') {
		return node.name;
	}
	// php-parser uses { kind: 'identifier', name: 'Foo' } or a Name node.
	if (node.name.name) {
		return node.name.name;
	}
	return String(node.name);
}

function nameOf(node) {
	if (!node) {
		return null;
	}
	if (typeof node === 'string') {
		return node;
	}
	if (node.name) {
		return typeof node.name === 'string' ? node.name : nameOf(node.name);
	}
	return null;
}

function walk(node, visit) {
	if (!node || typeof node !== 'object') {
		return;
	}
	if (Array.isArray(node)) {
		node.forEach((child) => walk(child, visit));
		return;
	}
	if (node.kind) {
		visit(node);
	}
	for (const key of Object.keys(node)) {
		if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') {
			continue;
		}
		walk(node[key], visit);
	}
}

// Pass 1: collect declarations.
for (const { ast } of asts) {
	walk(ast, (node) => {
		if (!['class', 'interface', 'trait', 'enum'].includes(node.kind)) {
			return;
		}
		const name = className(node);
		if (!name) {
			return;
		}
		const methods = new Set();
		const traits = new Set();
		for (const member of node.body || []) {
			if (member.kind === 'method') {
				const methodName = nameOf(member.name);
				if (methodName) {
					methods.add(methodName);
				}
			}
			if (member.kind === 'traituse') {
				for (const trait of member.traits || []) {
					const traitName = nameOf(trait);
					if (traitName) {
						traits.add(traitName.split('\\').pop());
					}
				}
			}
		}
		classes.set(name, {
			methods,
			traits,
			parent: node.extends ? nameOf(node.extends) : null,
			kind: node.kind,
		});
	});
}

function methodsOf(name, seen = new Set()) {
	const entry = classes.get(name);
	if (!entry || seen.has(name)) {
		return new Set();
	}
	seen.add(name);

	const all = new Set(entry.methods);
	for (const trait of entry.traits) {
		for (const method of methodsOf(trait, seen)) {
			all.add(method);
		}
	}
	if (entry.parent) {
		for (const method of methodsOf(entry.parent, seen)) {
			all.add(method);
		}
	}

	return all;
}

// Pass 2: check call sites.
const problems = [];
let checks = 0;

for (const { file, ast } of asts) {
	walk(ast, (node) => {
		if (!['class', 'interface', 'trait'].includes(node.kind)) {
			return;
		}
		const owner = className(node);
		if (!owner) {
			return;
		}
		const available = methodsOf(owner);

		walk(node.body || [], (child) => {
			if (child.kind !== 'call' || !child.what) {
				return;
			}

			const target = child.what;

			// php-parser keeps the member name on the lookup node, not on the
			// call node: `$this->g()` => call{ what: propertylookup{ what: $this, offset: 'g' } }.
			const method = nameOf(target.offset) || nameOf(child.offset);

			if (!method) {
				return;
			}

			const isThisLookup =
				(target.kind === 'propertylookup' || target.kind === 'nullsafepropertylookup') &&
				target.what &&
				target.what.kind === 'variable' &&
				target.what.name === 'this';

			if (isThisLookup) {
				checks++;
				if (!available.has(method)) {
					problems.push({
						file,
						line: (child.loc && child.loc.start && child.loc.start.line) || 0,
						call: `$this->${method}()`,
						owner,
					});
				}
				return;
			}

			if (target.kind === 'staticlookup' && target.what) {
				const container = nameOf(target.what);
				if (container === 'self' || container === 'static') {
					checks++;
					if (!available.has(method)) {
						problems.push({
							file,
							line: (child.loc && child.loc.start && child.loc.start.line) || 0,
							call: `${container}::${method}()`,
							owner,
						});
					}
					return;
				}
				if (container && classes.has(container)) {
					checks++;
					if (!methodsOf(container).has(method)) {
						problems.push({
							file,
							line: (child.loc && child.loc.start && child.loc.start.line) || 0,
							call: `${container}::${method}()`,
							owner,
						});
					}
				}
			}
		});
	});
}

const asJson = process.argv.includes('--json');
const summary = {
	generated_at: new Date().toISOString(),
	files: files.length,
	classes: classes.size,
	checked_calls: checks,
	problems: problems.length,
	details: problems,
};

if (asJson) {
	console.log(JSON.stringify(summary, null, 2));
} else {
	console.log('\n=== Mojoodyar method reference check ===');
	console.log(`files: ${files.length} · classes/interfaces/traits: ${classes.size} · checked calls: ${checks}`);
	for (const problem of problems) {
		console.log(`[BROKEN] ${problem.file}:${problem.line} ${problem.call} (inside ${problem.owner})`);
	}
	if (problems.length === 0) {
		console.log('no broken method references');
	}
	console.log(`\nproblems: ${problems.length}`);
}

process.exit(problems.length === 0 ? 0 : 1);
