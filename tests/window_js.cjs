'use strict';
/*
 * window_js.cjs — the @UVWINDOW verdict and its date helpers (browser side).
 *
 * Runs every case of tests/window_fixture.json through QRID_windowVerdict;
 * tests/window_php.php runs the same cases through TemporalLogic::windowVerdict,
 * so the two runtimes cannot disagree about a date. Also pins the canonical /
 * format helpers and QRID_clockNow (a page left open across midnight).
 *
 * Run:  node tests/window_js.cjs
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');

// Freeze the page-load instant before the engine captures it.
const realNow = Date.now;
let fakeNow = Date.UTC(2030, 0, 1, 0, 0, 0);
Date.now = () => fakeNow;

global.window = {};
global.document = { addEventListener() {}, getElementsByName() { return []; }, readyState: 'complete', body: { addEventListener() {} } };
require('../js/engine.js');
const W = window.INSPIREUniversalValidator.windowLogic;

let n = 0, fails = 0;
function check(label, ok) { n++; if (!ok) { fails++; console.log('FAIL: ' + label); } }

const fx = JSON.parse(fs.readFileSync(path.join(__dirname, 'window_fixture.json'), 'utf8'));
check('fixture loads', Array.isArray(fx.cases) && fx.cases.length > 0);
for (const c of fx.cases) {
  const spec = Object.assign({ unit: 'days' }, c.spec);
  const clock = Object.prototype.hasOwnProperty.call(c, 'clock') ? c.clock : fx.clock;
  const got = W.verdict(spec, c.value, c.valueFormat || 'ymd', c.anchor, c.anchorFormat || 'ymd', clock);
  const want = { verdict: c.verdict, earliest: c.earliest, latest: c.latest };
  let same = true;
  try { assert.deepStrictEqual(got, want); } catch (e) { same = false; }
  check(c.name + ' -> ' + JSON.stringify(want) + ' (got ' + JSON.stringify(got) + ')', same);
}

// format: canonical -> how the field displays it (same pins as window_php.php)
check('format date dmy', W.format('2026-01-22', 'date', 'dmy') === '22-01-2026');
check('format date mdy', W.format('2026-01-22', 'date', 'mdy') === '01-22-2026');
check('format date ymd', W.format('2026-01-22', 'date', 'ymd') === '2026-01-22');
check('format datetime drops seconds', W.format('2026-01-22 08:05:09', 'datetime', 'dmy') === '22-01-2026 08:05');
check('format datetime_seconds keeps them', W.format('2026-01-22 08:05:09', 'datetime_seconds', 'mdy') === '01-22-2026 08:05:09');
check('format of a date for a datetime field adds midnight', W.format('2026-01-22', 'datetime', 'ymd') === '2026-01-22 00:00');
check('format refuses junk', W.format('22/01/2026', 'date', 'ymd') === '' && W.format(null, 'date', 'ymd') === '');

// canonical: seconds back to the parse form, no timezone shift, years 1-9999 only
check('canonical epoch', W.canonical(0, 'datetime') === '1970-01-01 00:00:00');
check('canonical date', W.canonical(Date.UTC(2024, 1, 29) / 1000, 'date') === '2024-02-29');
check('canonical past 9999 is null', W.canonical(Date.UTC(9999, 11, 31) / 1000 + 86400, 'date') === null);
check('canonical before year 1 is null', W.canonical(-62135596800 - 86400, 'date') === null);
check('canonical NaN is null', W.canonical(NaN, 'date') === null);
check('units have no inherited keys', W.units.constructor === undefined && W.units.toString === undefined);

// clockNow: the server clock advanced by the time the page has been open
const base = { today: '2026-10-09', now: '2026-10-09 23:59:30' };
check('clock at load', JSON.stringify(W.clockNow(base)) === JSON.stringify({ today: '2026-10-09', now: '2026-10-09 23:59:30' }));
fakeNow += 45 * 1000;   // 45 s later the page has crossed midnight
const after = W.clockNow(base);
check('clock crosses midnight', after && after.today === '2026-10-10' && after.now === '2026-10-10 00:00:15');
const spec = { type: 'date', notFuture: true };
check('a date of tomorrow passes once midnight has passed',
  W.verdict(spec, '2026-10-10', 'ymd', null, 'ymd', after).verdict === 'ok');
check('...but not before it',
  W.verdict(spec, '2026-10-10', 'ymd', null, 'ymd', base).verdict === 'future');
fakeNow -= 3600 * 1000;  // the computer's clock is set back an hour
check('clock never runs backwards', W.clockNow(base).now === '2026-10-10 00:00:15');
fakeNow += 3600 * 1000 + 1500;
check('clock resumes from the furthest point', W.clockNow(base).now === '2026-10-10 00:00:16');
check('no server clock -> null', W.clockNow(undefined) === null && W.clockNow({}) === null
  && W.clockNow({ now: '2026-02-30 00:00:00' }) === null && W.clockNow({ now: 5 }) === null);
check('notFuture without a clock is unknown', W.verdict(spec, '2026-10-01', 'ymd', null, 'ymd', null).verdict === 'unknown');

Date.now = realNow;
console.log(`window_js: ${n} checks, ${fails} failure(s)`);
process.exit(fails ? 1 : 0);
