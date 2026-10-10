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

// calendar arithmetic, the same cases as window_php.php
check('calendar fixture loads', Array.isArray(fx.calendar) && fx.calendar.length > 0);
for (const c of fx.calendar) {
  const got = W.calendar[c.fn].apply(null, c.args);
  check('calendar ' + c.fn + JSON.stringify(c.args) + ' -> ' + JSON.stringify(c.out) + ' (got ' + JSON.stringify(got) + ')',
    got === c.out);
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

// clockAt: a page open across a daylight-saving change keeps the zone's local
// time (Europe/London, 25 Oct 2026: 02:00 BST becomes 01:00 GMT).
const dst = { today: '2026-10-25', now: '2026-10-25 01:58:00', utc: Date.UTC(2026, 9, 25, 0, 58, 0),
  offset: 3600, next: Date.UTC(2026, 9, 25, 1, 0, 0), offsetAfter: 0 };
check('clockAt: before the change, plain elapsed time', W.clockAt(dst, 60 * 1000).now === '2026-10-25 01:59:00');
check('clockAt: at the change the clock goes back an hour', W.clockAt(dst, 120 * 1000).now === '2026-10-25 01:00:00');
check('clockAt: and runs on from there', W.clockAt(dst, 30 * 60 * 1000).now === '2026-10-25 01:28:00');
const spring = { today: '2027-03-28', now: '2027-03-28 00:59:30', utc: Date.UTC(2027, 2, 28, 0, 59, 30),
  offset: 0, next: Date.UTC(2027, 2, 28, 1, 0, 0), offsetAfter: 3600 };
check('clockAt: a spring change moves the clock forward', W.clockAt(spring, 45 * 1000).now === '2027-03-28 02:00:15');
check('clockAt: a clock without zone fields counts elapsed time only',
  W.clockAt({ today: '2026-10-25', now: '2026-10-25 01:58:00' }, 120 * 1000).now === '2026-10-25 02:00:00');
check('clockAt: no next change counts elapsed time only',
  W.clockAt(Object.assign({}, dst, { next: null, offsetAfter: null }), 120 * 1000).now === '2026-10-25 02:00:00');
check('clockAt: a zone field that is not a number is ignored',
  W.clockAt(Object.assign({}, dst, { offsetAfter: '0' }), 120 * 1000).now === '2026-10-25 02:00:00');

// clockLenient: a date and time may run 120 s past "now", plus how far this
// computer's clock runs ahead of the server's (in UTC), by up to 10 minutes.
const srv = { today: '2026-10-09', now: '2026-10-09 14:30:00' };
check('clockLenient: 120 s past the server clock', W.clockLenient(srv, null).now === '2026-10-09 14:32:00');
check('clockLenient: a computer behind the server does not narrow it', W.clockLenient(srv, -1800).now === '2026-10-09 14:32:00');
check('clockLenient: a computer 5 minutes fast widens it', W.clockLenient(srv, 300).now === '2026-10-09 14:37:00');
check('clockLenient: by up to 10 minutes', W.clockLenient(srv, 600).now === '2026-10-09 14:42:00');
check('clockLenient: a clock days ahead widens it by 10 minutes only', W.clockLenient(srv, 3 * 86400).now === '2026-10-09 14:42:00');
check('clockLenient: a lead that is not a number is ignored', W.clockLenient(srv, NaN).now === '2026-10-09 14:32:00');
check('clockLenient: keeps the server day', W.clockLenient(srv, 600).today === '2026-10-09');
check('clockLenient: no clock stays no clock', W.clockLenient(null, 600) === null);
check('clockError: no UTC stamp, no lead', W.clockError({ today: '2026-10-09', now: '2026-10-09 14:30:00' }) === null);
// The lead is fixed when the engine loads: against a stamp of 0 it is that moment.
const loadedAt = W.clockError({ utc: 0 }) * 1000;
check('clockError: a stamp 5 minutes before the page loaded reads as a 5-minute lead',
  W.clockError({ utc: loadedAt - 300000 }) === 300);
check('clockError: a stamp that is not a number gives no lead', W.clockError({ utc: '0' }) === null);
const dtSpec = { type: 'datetime', notFuture: true };
check('a time 2 minutes ahead passes with the margin',
  W.verdict(dtSpec, '2026-10-09 14:32', 'ymd', null, 'ymd', W.clockLenient(srv, null)).verdict === 'ok');
check('...3 minutes ahead does not',
  W.verdict(dtSpec, '2026-10-09 14:33', 'ymd', null, 'ymd', W.clockLenient(srv, null)).verdict === 'future');

Date.now = realNow;
console.log(`window_js: ${n} checks, ${fails} failure(s)`);
process.exit(fails ? 1 : 0);
