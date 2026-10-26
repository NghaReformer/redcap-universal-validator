'use strict';
/*
 * tests/release_version_js.cjs - the rules tools/release_version.cjs holds a
 * release tag to, and the folder table in docs/INSTALL.md.
 *
 * External Modules reads the version from the module folder's name. The
 * 2.3.0 candidates were tagged v2.3.0-rc.N, renamed to plain numbers on the
 * test server, and ran there as versions no tag carries. A tag is now a plain
 * vX.Y.Z equal to the first CHANGELOG.md version, above every earlier version,
 * and not a folder number a server already uses.
 *
 * Run:  node tests/release_version_js.cjs
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawnSync } = require('child_process');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const root = path.join(__dirname, '..');
const tool = path.join(root, 'tools', 'release_version.cjs');
const reservedFile = path.join(root, 'tools', 'release_reserved_versions.json');
const reserved = JSON.parse(fs.readFileSync(reservedFile, 'utf8')).reserved;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'uv-release-'));

// A changelog with these headings, newest first, run through the tool as CI runs it.
function run(headings, tag, reservedList) {
  const log = path.join(tmp, 'CHANGELOG.md');
  fs.writeFileSync(log, '# Changelog\n\nIntro.\n\n' + headings.map((h) => '## ' + h + '\n\nText.\n').join('\n'));
  const args = [tool, '--changelog', log];
  if (reservedList) {
    const res = path.join(tmp, 'reserved.json');
    fs.writeFileSync(res, JSON.stringify({ reserved: reservedList }));
    args.push('--reserved', res);
  }
  if (tag !== undefined) args.push('--tag', tag);
  const r = spawnSync(process.execPath, args, { encoding: 'utf8' });
  return { code: r.status, out: r.stdout.trim(), err: r.stderr };
}

const HISTORY = ['2.3.0-rc.3 — 2026-10-25', '2.3.0-rc.2 — 2026-10-18', '2.3.0-rc.1 — 2026-10-17', '2.1.0-rc.2 — 2026-10-07', '1.10.0 — Multiple ID formats per field', 'Earlier versions'];

// A plain release above the candidates, tagged as CI sees it.
let r = run(['2.3.3 — 2026-11-01'].concat(HISTORY), 'v2.3.3', reserved);
check('v2.3.3 on "## 2.3.3" above the candidates is accepted (got ' + r.code + ' ' + JSON.stringify(r.err) + ')', r.code === 0);
check('... and the tool prints the version the zip and folder are named after', r.out === '2.3.3');

// "## Unreleased" and other headings that are not versions are skipped.
r = run(['Unreleased', '2.3.3 — 2026-11-01'].concat(HISTORY), 'v2.3.3', reserved);
check('"## Unreleased" on top is skipped (got ' + r.code + ' ' + r.out + ')', r.code === 0 && r.out === '2.3.3');

// A suffix in the tag.
r = run(['2.3.0-rc.4 — 2026-11-01'].concat(HISTORY), 'v2.3.0-rc.4', reserved);
check('a -rc.4 tag is refused', r.code === 1);
check('... saying tags are plain vX.Y.Z (got ' + JSON.stringify(r.err) + ')', /plain vX\.Y\.Z/.test(r.err));
r = run(['2.3.3-beta — 2026-11-01'].concat(HISTORY), 'v2.3.3-beta', reserved);
check('any other suffix is refused too', r.code === 1 && /plain vX\.Y\.Z/.test(r.err));
r = run(['2.3.3 — 2026-11-01'].concat(HISTORY), '2.3.3', reserved);
check('a tag without the "v" is refused', r.code === 1 && /plain vX\.Y\.Z/.test(r.err));

// The tag and the changelog disagree.
r = run(['2.3.3 — 2026-11-01'].concat(HISTORY), 'v2.3.4', reserved);
check('v2.3.4 on a changelog whose first version is 2.3.3 is refused (got ' + JSON.stringify(r.err) + ')', r.code === 1 && /differ/.test(r.err));
r = run(['2.3.0-rc.3 — 2026-10-25'].concat(HISTORY.slice(1)), 'v2.3.3', reserved);
check('a plain tag on a changelog that still starts at a candidate is refused', r.code === 1 && /differ/.test(r.err));

// Not above an earlier version: REDCap would install it as an older build.
r = run(['2.3.0 — 2026-11-01'].concat(HISTORY), 'v2.3.0', []);
check('final 2.3.0 above 2.3.0-rc.3 is refused, as REDCap orders folders by number (got ' + JSON.stringify(r.err) + ')',
  r.code === 1 && /not above 2\.3\.0-rc\.3/.test(r.err));
r = run(['2.2.9 — 2026-11-01'].concat(HISTORY), 'v2.2.9', []);
check('a number below an earlier version is refused', r.code === 1 && /not above/.test(r.err));
r = run(['2.3.3 — 2026-11-02', '2.3.3 — 2026-11-01'].concat(HISTORY), 'v2.3.3', []);
check('the same number twice is refused', r.code === 1 && /not above 2\.3\.3/.test(r.err));
r = run(['2.10.0 — 2026-11-01', '2.9.0 — 2026-10-01'], 'v2.10.0', []);
check('numbers compare as numbers: 2.10.0 is above 2.9.0 (got ' + r.code + ' ' + JSON.stringify(r.err) + ')', r.code === 0 && r.out === '2.10.0');

// A folder number a server already uses for other code.
for (const e of reserved) {
  r = run([e.version + ' — 2026-11-01', '2.2.0 — 2026-01-01'], 'v' + e.version, reserved);
  check('v' + e.version + ', the test server\'s folder for ' + e.holds + ', is refused (got ' + JSON.stringify(r.err) + ')',
    r.code === 1 && r.err.includes('holds ' + e.holds));
}

// A branch build: a plain first version gets the same checks before it is tagged.
r = run(['2.3.2 — 2026-11-01', '2.2.0 — 2026-01-01'], undefined, reserved);
check('a branch whose first version is a reserved number fails before anyone tags it', r.code === 1 && /taken/.test(r.err));
r = run(['2.3.3 — 2026-11-01'].concat(HISTORY), undefined, reserved);
check('a branch whose first version is 2.3.3 builds as 2.3.3 with no warning', r.code === 0 && r.out === '2.3.3' && r.err === '');
// ...and a candidate heading still builds, with a warning, so history stays buildable.
r = run(HISTORY, undefined, reserved);
check('a branch whose first version is 2.3.0-rc.3 builds (got ' + r.code + ')', r.code === 0 && r.out === '2.3.0-rc.3');
check('... with a warning that it cannot be tagged', /warning: .*cannot be\s+tagged/.test(r.err));

r = run(['Unreleased', 'Earlier versions'], undefined, reserved);
check('a changelog with no version heading fails', r.code === 1 && /no "## X\.Y\.Z" heading/.test(r.err));

// The repository's own changelog builds as CI builds a branch.
const real = spawnSync(process.execPath, [tool], { encoding: 'utf8' });
check('tools/release_version.cjs reads this repository\'s CHANGELOG.md (got ' + real.status + ' ' + JSON.stringify(real.stderr) + ')',
  real.status === 0 && /^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/.test(real.stdout.trim()));

// docs/INSTALL.md lists the reserved folders for administrators, row for row.
const install = fs.readFileSync(path.join(root, 'docs', 'INSTALL.md'), 'utf8');
const rows = install.split(/\r?\n/).filter((l) => l.startsWith('| `universal_validator_v'));
check('docs/INSTALL.md has one folder row per reserved number (' + rows.length + ' rows, ' + reserved.length + ' numbers)', rows.length === reserved.length);
for (const e of reserved) {
  const row = rows.find((l) => l.startsWith('| `universal_validator_v' + e.version + '`'));
  check('docs/INSTALL.md row for v' + e.version + ' names ' + e.holds + ' (' + e.commit + ')',
    !!row && row.includes('| v' + e.version + ' |') && row.includes('`' + e.holds + '`') && row.includes(e.commit));
}
for (const e of reserved) {
  check('reserved v' + e.version + ' names a tag, a commit and a server', /^v\d+\.\d+\.\d+-/.test(e.holds) && /^[0-9a-f]{7,40}$/.test(e.commit) && !!e.server);
}

// The README does not name a "current" version that is not the changelog's.
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const stated = readme.match(/Current (?:release|release candidate|version)[^`\n]*`v?([^`]+)`/i);
check('README.md names no current version other than the changelog\'s (it says ' + (stated ? stated[1] : 'none') + ')',
  !stated || stated[1] === real.stdout.trim());

fs.rmSync(tmp, { recursive: true, force: true });
console.log('release_version_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
