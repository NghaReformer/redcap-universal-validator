/*
 * gen_mode_registry.cjs — writes the browser's copy of php/modes.json into
 * js/engine.js, between the "@generated mode-registry" markers.
 *
 * php/modes.json is the one list of validation modes. The server reads it
 * through php/ModeRegistry.php; the engine cannot read a file, so the parts it
 * needs (the keys a rule carries, which type belongs to which mode, which
 * factory a type starts) are written into it here. CI runs this with --check
 * and fails when the block in js/engine.js no longer matches the file.
 *
 * Run:  node tests/gen_mode_registry.cjs          rewrite the block
 *       node tests/gen_mode_registry.cjs --check  exit 1 if the block is stale
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const MODES = path.join(ROOT, 'php', 'modes.json');
const ENGINE = path.join(ROOT, 'js', 'engine.js');
const BEGIN = '/* @generated mode-registry BEGIN';
const END = '/* @generated mode-registry END */';

function fail(msg) { console.error('gen_mode_registry: ' + msg); process.exit(1); }

const reg = JSON.parse(fs.readFileSync(MODES, 'utf8'));
if (!reg || !Array.isArray(reg.modes) || !reg.modes.length) fail('php/modes.json has no "modes" list');

const defaultKeys = [];
const modeOfType = {};
const factoryOfType = {};
const types = [];
for (const m of reg.modes) {
  for (const k of m.clientKeys || []) if (!defaultKeys.includes(k)) defaultKeys.push(k);
  for (const t of m.types || []) {
    if (Object.prototype.hasOwnProperty.call(modeOfType, t)) fail('type "' + t + '" belongs to two modes');
    modeOfType[t] = m.mode;
    types.push(t);
    const f = m.js && m.js.factories && m.js.factories[t];
    if (!f) fail('mode "' + m.mode + '" names no js factory for type "' + t + '"');
    factoryOfType[t] = f;
  }
}

function q(s) { return JSON.stringify(s); }
function list(a) { return a.length < 2 ? a.map(q).join('') : a.slice(0, -1).map(q).join(', ') + ' or ' + q(a[a.length - 1]); }
function wrapArray(name, arr) {
  const lines = [];
  let line = '  var ' + name + ' = [';
  const pad = ' '.repeat(line.length);
  arr.forEach((v, i) => {
    const item = q(v) + (i < arr.length - 1 ? ',' : '];');
    if (line.length + item.length + 1 > 96 && !/\[$/.test(line)) { lines.push(line); line = pad + item; }
    else line += (/\[$/.test(line) ? '' : ' ') + item;
  });
  lines.push(line);
  return lines.join('\n');
}
function obj(name, o) {
  const keys = Object.keys(o);
  return '  var ' + name + ' = {\n' + keys.map((k, i) => '    ' + q(k) + ': ' + q(o[k]) + (i < keys.length - 1 ? ',' : '')).join('\n') + '\n  };';
}

const block = [
  BEGIN + ' — written from php/modes.json by tests/gen_mode_registry.cjs.',
  '     Edit php/modes.json and re-run that script; never edit this block. */',
  wrapArray('DEFAULT_KEYS', defaultKeys),
  obj('MODE_OF_TYPE', modeOfType),
  obj('FACTORY_OF_TYPE', factoryOfType),
  '  var KNOWN_TYPES_TEXT = ' + q(list(types)) + ';',
  '  ' + END,
].join('\n');

const src = fs.readFileSync(ENGINE, 'utf8');
const b = src.indexOf('  ' + BEGIN);
const e = src.indexOf(END);
if (b < 0 || e < 0 || e < b) fail('js/engine.js has no "@generated mode-registry" markers');
const generated = src.slice(0, b) + '  ' + block + src.slice(e + END.length);

// Every factory the file names must be wired in the engine's hand-written
// name table, or the dispatcher would look it up and find nothing.
const tableAt = generated.indexOf('var QRID_FACTORY_BY_NAME = {');
if (tableAt < 0) fail('js/engine.js has no QRID_FACTORY_BY_NAME table');
const table = generated.slice(tableAt, generated.indexOf('};', tableAt));
for (const t of types) {
  const f = factoryOfType[t];
  if (!new RegExp('\\b' + f + '\\s*:\\s*' + f + '\\b').test(table)) {
    fail('factory ' + f + ' (type "' + t + '") is not in QRID_FACTORY_BY_NAME');
  }
}

if (process.argv.includes('--check')) {
  if (generated !== src) fail('the mode-registry block in js/engine.js is stale — run node tests/gen_mode_registry.cjs and commit');
  console.log('gen_mode_registry: block matches php/modes.json (' + types.length + ' types, '
    + defaultKeys.length + ' keys)');
} else {
  if (generated !== src) fs.writeFileSync(ENGINE, generated);
  console.log('gen_mode_registry: ' + (generated !== src ? 'rewrote' : 'unchanged') + ' the block in js/engine.js');
}
