#!/usr/bin/env node
/*
 * tools/package_contents.cjs - what the release archive may not hold.
 *
 * REDCap serves every file of a module folder to whoever asks for its URL, so
 * the archive holds what a server needs and nothing else. Up to 2.4.0 it held
 * tests/ (122 PHP scripts a browser could run, one of which connects to a
 * database as root), a slide deck, live test sheets and the old changelog:
 * 11.6 MB of 18.5 MB (FRM-1, FRM-15). .gitattributes keeps those out of
 * `git archive`; this tool is the check that it still does.
 *
 * The rules are data, in tools/package_rules.json:
 *   refuse       paths that must not ship;
 *   php_allowed  the only places a .php file may ship, so a script added
 *                anywhere else is refused until somebody decides it is a page;
 *   text_refuse  strings no shipped text file may hold.
 *
 * Usage: node tools/package_contents.cjs <unzipped module folder>
 * Exits 1 and names each file when a rule fails.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const rules = JSON.parse(fs.readFileSync(path.join(__dirname, 'package_rules.json'), 'utf8'));
const refuse = rules.refuse.map((r) => ({ re: new RegExp(r.pattern), why: r.why }));
const phpAllowed = rules.php_allowed.map((p) => new RegExp(p));
const textRefuse = rules.text_refuse.map((r) => ({ re: new RegExp(r.pattern), why: r.why }));
const textExt = new Set(rules.text_extensions);

/** Why a path may not ship, or '' when it may. */
function refusal(rel) {
  for (const r of refuse) if (r.re.test(rel)) return r.why;
  if (/\.php$/.test(rel) && !phpAllowed.some((re) => re.test(rel))) {
    return 'a PHP file outside the module class, php/, pages/ and the one data tool';
  }
  return '';
}

/** Why a text may not ship, or ''. */
function textRefusal(rel, text) {
  const ext = rel.slice(rel.lastIndexOf('.') + 1).toLowerCase();
  if (!textExt.has(ext)) return '';
  for (const r of textRefuse) if (r.re.test(text)) return r.why;
  return '';
}

/** Every problem of a list of paths; read(rel) returns a file's text, or null to skip the text rules. */
function problems(paths, read) {
  const out = [];
  for (const rel of paths) {
    const why = refusal(rel);
    if (why) { out.push(rel + ': ' + why); continue; }
    const text = read ? read(rel) : null;
    if (text === null || text === undefined) continue;
    const t = textRefusal(rel, text);
    if (t) out.push(rel + ': ' + t);
  }
  return out;
}

function walk(dir, base, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const rel = base ? base + '/' + e.name : e.name;
    if (e.isDirectory()) walk(path.join(dir, e.name), rel, out);
    else out.push(rel);
  }
  return out;
}

module.exports = { refusal, textRefusal, problems, rules };

if (require.main === module) {
  const dir = process.argv[2];
  if (!dir || !fs.existsSync(dir)) {
    console.error('usage: node tools/package_contents.cjs <unzipped module folder>');
    process.exit(2);
  }
  const found = problems(walk(dir, '', []), (rel) => fs.readFileSync(path.join(dir, rel), 'utf8'));
  if (found.length) {
    console.error('development-only content shipped in the package:');
    for (const p of found) console.error('  ' + p);
    process.exit(1);
  }
  console.log('package contents OK');
}
