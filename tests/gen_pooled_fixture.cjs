/*
 * gen_pooled_fixture.cjs — freeze the browser pooled-parser behavior.
 *
 * Loads the verified js/engine.js, drives its pooled factory (QRIDPooledInit)
 * over a set of representative configs/inputs, and records parse() output as
 * tests/pooled_fixture.json. Both runtimes then recompute these segments and
 * fail on any mismatch (tests/pooled_js.cjs and tests/pooled_php.php), which is
 * what lets the PHP port claim parity with the browser parser.
 *
 * Deterministic: valid members are minted with the engine's own appendCheck, so
 * the fixture never hard-codes a check character. Run:
 *   node tests/gen_pooled_fixture.cjs        # rewrites tests/pooled_fixture.json
 */
'use strict';
const fs = require('fs');
const path = require('path');

global.window = {};
global.document = {
  addEventListener() {},
  getElementsByName() { return []; },
  createElement() { return { style: {}, setAttribute() {}, appendChild() {}, insertBefore() {} }; },
  readyState: 'complete',
  body: { addEventListener() {} },
};
require(path.join(__dirname, '..', 'js', 'engine.js'));
const NS = global.window.INSPIREUniversalValidator;
const Q = NS.engine;
const mod37 = 'iso7064_mod37_36';
// makeScheme() computes the .active flag appendCheck needs — a raw literal would
// be treated as inactive and mint no check character.
const MINT = Q.makeScheme({ algorithm: mod37, source: 'normalized_id', placement: 'append', enabled: true,
  normalize_rules: { strip_delimiters: '', uppercase: true, unify_unicode_dashes: true, keep_only: null } });
const app = (id) => Q.appendCheck(id, MINT);

function parseWith(config, input) {
  NS.pooledInit(Object.assign({ fields: [] }, config));
  return NS.lastPooled.parse(input);
}

// ---- representative valid members (minted, so they carry real check chars) ----
const A = app('0ABC0000'), B = app('0ABC0001'), C = app('0DEF0009');   // 9-char mod37,36 IDs
// (each is 9 chars: 8-char base + 1 check char)
const cases = [];
function add(label, config, input) { cases.push({ label, config, input, segs: parseWith(config, input) }); }

const checkCfg = { algorithm: mod37, source: 'normalized_id', strip: '-/ _|\\', idLengths: [9] };

add('clean pool of three', checkCfg, A + B + C);
add('pool with trailing junk', checkCfg, A + B + 'ZZ');
add('pool with a duplicate', checkCfg, A + B + A);
add('space/comma separated', checkCfg, A + ', ' + B + ' ' + C);
add('single member only', checkCfg, B);
add('all junk (no member)', checkCfg, 'ZZZZZZZZ');

// check + format pattern: a well-formed-but-wrong-check member surfaces as its own bad chip
const patCfg = { algorithm: mod37, source: 'normalized_id', strip: '-/ _|\\',
                 idPattern: '[0-9A-Z]{9}', idLengths: [9] };
const badB = B.slice(0, -1) + (B.slice(-1) === 'X' ? 'Y' : 'X');   // valid shape, broken check
add('check+pattern with a bad member', patCfg, A + badB + C);

// regex-only legacy project (no check character): shape is the whole test
const regexCfg = { algorithm: 'none', source: 'normalized_id', strip: '-/ _|\\',
                   idPattern: 'FC[0-9]{4}', idMinLen: 6, idMaxLen: 6 };
add('regex-only pool', regexCfg, 'FC0001FC0002XY');

// The junk re-scan must only offer lengths the rule DECLARES. It used to walk
// the contiguous range minLen..maxLen, so a non-contiguous idLengths set let it
// stamp an "invalid ID" chip at a length the segmentation pass can never
// produce. The pattern below matches at exactly 11 characters; the first rule
// declares only 10 and 12, so 11 must never be reported, while the second rule
// declares the whole 10..12 range and therefore SHOULD report it. The pair is
// what makes this a real lock rather than a coincidence.
const gapPat = 'Z[0-9A-Z]{10}';                       // matches only at length 11
const gapJunk = 'ZQQQQQQQQQQ';                        // 11 chars, matches gapPat
add('idLengths gap: re-scan must skip the undeclared length',
    { algorithm: 'iso7064_mod37_36', source: 'normalized_id', strip: '-/ _|\\',
      idPattern: gapPat, idLengths: [10, 12] }, gapJunk);
add('idMinLen/idMaxLen range: the same length IS declared, so it is reported',
    { algorithm: 'iso7064_mod37_36', source: 'normalized_id', strip: '-/ _|\\',
      idPattern: gapPat, idMinLen: 10, idMaxLen: 12 }, gapJunk);

// ---- multi-format rules: one field, four ID families -----------------------
// The sample-transportation case. GHIT carries NO check character, the other
// three carry ISO 7064 Mod 37,36, and all four arrive in one box. These freeze
// the (alternate, length) candidate walk, the union KEEP set, and the per-
// alternate junk re-scan across BOTH runtimes.
const MINT_DASH = Q.makeScheme({ algorithm: mod37, source: 'normalized_id', placement: 'append', enabled: true,
  normalize_rules: { strip_delimiters: '-', uppercase: true, unify_unicode_dashes: true, keep_only: null } });
const appD = (id) => Q.appendCheck(id, MINT_DASH);
const FC = 'FC1-0589', FC2 = 'FC3-0179';
const SK = appD('SK1-0123'), DT = appD('DT1-12345'), ST = appD('ST3-77012');
const altCfg = { strip: '-', alternates: [
  { label: 'GHIT',       pattern: 'FC[1-9]-[0-9]{4}',         algorithm: 'none', lengths: [8] },
  { label: 'START4KIDS', pattern: 'SK[1-5]-[0-9]{4}[0-9A-Z]', algorithm: mod37,  lengths: [9] },
  { label: 'DARETB',     pattern: 'DT[1-2]-[0-9]{5}[0-9A-Z]', algorithm: mod37,  lengths: [10] },
  { label: 'SCREENTB',   pattern: 'ST[1-5]-[0-9]{5}[0-9A-Z]', algorithm: mod37,  lengths: [10] },
] };
add('mixed manifest, jammed with no separators', altCfg, FC + SK + DT + ST);
add('mixed manifest, comma/space separated', altCfg, FC + ', ' + SK + ' ' + DT + '  ' + ST);
// a mis-scanned START4KIDS member must be CAUGHT, never swallowed into a
// neighbour: this is the protection regex-only mode cannot give
add('mixed manifest, one broken check character', altCfg,
    FC + ' ' + SK.slice(0, 5) + (SK[5] === '9' ? '8' : '9') + SK.slice(6) + ' ' + DT);
add('mixed manifest with junk between members', altCfg, FC + 'ZZ' + DT);
// two 8-char format-only members back to back: 16 is not a declared length, so
// the union sum-swallow reasoning must keep them apart
add('two adjacent format-only members stay two', altCfg, FC + FC2);

const out = {
  generated_by: 'tests/gen_pooled_fixture.cjs',
  note: 'Frozen browser pooled-parser output; recomputed by pooled_js.cjs and pooled_php.php.',
  cases,
};
fs.writeFileSync(path.join(__dirname, 'pooled_fixture.json'), JSON.stringify(out, null, 2) + '\n');
console.log('wrote tests/pooled_fixture.json with ' + cases.length + ' cases');
for (const c of cases) {
  const ids = c.segs.filter((s) => s.type === 'id');
  console.log('  ' + c.label.padEnd(34) + ' -> ' +
    ids.length + ' id(s), ' + c.segs.filter((s) => s.type === 'junk').length + ' junk run(s)');
}
