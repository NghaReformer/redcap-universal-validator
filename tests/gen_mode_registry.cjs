/*
 * gen_mode_registry.cjs — writes the browser's copies of php/modes.json and
 * php/growth_outputs.json into js/engine.js, between the "@generated
 * mode-registry" and "@generated growth-outputs" markers.
 *
 * php/modes.json is the one list of validation modes. The server reads it
 * through php/ModeRegistry.php; the engine cannot read a file, so the parts it
 * needs (the keys a rule carries, which type belongs to which mode, which
 * factory a type starts) are written into it here. php/growth_outputs.json is
 * the one list of values an @UVRANGE growth rule can return; the server reads
 * it through php/GrowthOutputs.php, and the engine gets what it needs to read
 * a "return" key (GrowthOutputs::clientCopy). CI runs this with --check and
 * fails when either block in js/engine.js no longer matches its file.
 *
 * Run:  node tests/gen_mode_registry.cjs          rewrite the block
 *       node tests/gen_mode_registry.cjs --check  exit 1 if the block is stale
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
const MODES = path.join(ROOT, 'php', 'modes.json');
const OUTPUTS = path.join(ROOT, 'php', 'growth_outputs.json');
const ENGINE = path.join(ROOT, 'js', 'engine.js');
const BEGIN = '/* @generated mode-registry BEGIN';
const END = '/* @generated mode-registry END */';
const OUT_BEGIN = '/* @generated growth-outputs BEGIN';
const OUT_END = '/* @generated growth-outputs END */';

function fail(msg) { console.error('gen_mode_registry: ' + msg); process.exit(1); }

const reg = JSON.parse(fs.readFileSync(MODES, 'utf8'));
if (!reg || !Array.isArray(reg.modes) || !reg.modes.length) fail('php/modes.json has no "modes" list');

const defaultKeys = [];
const modeOfType = {};
const factoryOfType = {};
const branchGroupOfType = {};
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
    if (m.branchGroup && m.branchGroup.key) branchGroupOfType[t] = m.branchGroup.key;
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
  obj('BRANCH_GROUP_OF_TYPE', branchGroupOfType),
  '  var KNOWN_TYPES_TEXT = ' + q(list(types)) + ';',
  '  ' + END,
].join('\n');

const src = fs.readFileSync(ENGINE, 'utf8');
const b = src.indexOf('  ' + BEGIN);
const e = src.indexOf(END);
if (b < 0 || e < 0 || e < b) fail('js/engine.js has no "@generated mode-registry" markers');
let generated = src.slice(0, b) + '  ' + block + src.slice(e + END.length);

// The growth output catalog, as GrowthOutputs::clientCopy gives it: id =>
// written, needs, and for a number places and integer, and the parameter's
// range. tests/growth_outputs_php.php compares the block with clientCopy.
let cat = null;
try { cat = JSON.parse(fs.readFileSync(OUTPUTS, 'utf8')); } catch (e) { fail('php/growth_outputs.json cannot be read: ' + e.message); }
if (!cat || cat.format !== 'uv-growth-outputs-1' || !cat.outputs || typeof cat.outputs !== 'object' || Array.isArray(cat.outputs)) {
  fail('php/growth_outputs.json is not a "uv-growth-outputs-1" file with an "outputs" map');
}
// Each output must be one the engine computes (QRID_GROWTH_COMPUTED, the twin
// of GrowthReference::COMPUTED, which GrowthOutputs checks on the server).
const computedAt = /var QRID_GROWTH_COMPUTED = (\[[^\]]*\]);/.exec(src);
if (!computedAt) fail('js/engine.js has no QRID_GROWTH_COMPUTED list');
const computed = JSON.parse(computedAt[1]);
const copy = {};
for (const id of Object.keys(cat.outputs)) {
  const o = cat.outputs[id];
  if (!computed.includes(id)) fail('growth output "' + id + '" is in php/growth_outputs.json but QRID_GROWTH_COMPUTED does not list it');
  if (!o || typeof o !== 'object' || (o.written !== 'number' && o.written !== 'text') || typeof o.needs !== 'string') {
    fail('growth output "' + id + '" needs "written" (number or text) and "needs"; php tests/growth_outputs_php.php says more');
  }
  const c = { written: o.written, needs: o.needs };
  if (o.places !== undefined) c.places = o.places;
  if (o.written === 'number') c.integer = o.integer;
  if (o.param) c.param = { min: o.param.min, max: o.param.max, decimals: o.param.decimals };
  copy[id] = c;
}
const outBlock = [
  OUT_BEGIN + ' — written from php/growth_outputs.json by tests/gen_mode_registry.cjs.',
  '   Edit php/growth_outputs.json and re-run that script; never edit this block. */',
  'var QRID_GROWTH_OUTPUTS = {',
  Object.keys(copy).map((id, i, a) => '  ' + q(id) + ': ' + JSON.stringify(copy[id]) + (i < a.length - 1 ? ',' : '')).join('\n'),
  '};',
  OUT_END,
].join('\n');
const ob = generated.indexOf(OUT_BEGIN);
const oe = generated.indexOf(OUT_END);
if (ob < 0 || oe < 0 || oe < ob) fail('js/engine.js has no "@generated growth-outputs" markers');
generated = generated.slice(0, ob) + outBlock + generated.slice(oe + OUT_END.length);

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
  if (generated !== src) fail('a generated block in js/engine.js is stale — run node tests/gen_mode_registry.cjs and commit');
  console.log('gen_mode_registry: blocks match php/modes.json (' + types.length + ' types, '
    + defaultKeys.length + ' keys) and php/growth_outputs.json (' + Object.keys(copy).length + ' outputs)');
} else {
  if (generated !== src) fs.writeFileSync(ENGINE, generated);
  console.log('gen_mode_registry: ' + (generated !== src ? 'rewrote' : 'unchanged') + ' the blocks in js/engine.js');
}
