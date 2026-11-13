'use strict';
/*
 * tests/package_contents_js.cjs - the release archive holds no test, tool or
 * development file (FRM-1, FRM-15).
 *
 * The 2.4.0 archive held tests/: 122 PHP scripts REDCap would serve from
 * /modules/universal_validator_vX/tests/, one of which connects to a database
 * as root. It also held a slide deck, the live test sheets and the old
 * changelog. .gitattributes now keeps them out of `git archive`,
 * tools/package_contents.cjs refuses them, and the CI package job runs that
 * tool on the archive it built.
 *
 * This suite checks the three agree: the rules refuse what they should, the
 * archive `git archive` builds from this tree passes them, and the workflow
 * calls the tool.
 *
 * Run:  node tests/package_contents_js.cjs
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const root = path.join(__dirname, '..');
const tool = require(path.join(root, 'tools', 'package_contents.cjs'));

// ---- the rules ---------------------------------------------------------------
const mustRefuse = [
  'tests/when_php.php', 'tests/mysql/run.php', 'tests/when_fixture.json', 'tests/delta_mutations.py',
  'tools/release_version.cjs', 'reports/x.md', 'site/index.html', '.github/workflows/parity.yml',
  '.gitattributes', '.gitignore', '.graphifyignore', 'CLAUDE.md', 'PLAN-event-instance-references.md',
  'CHANGELOG-ARCHIVE.md', 'docs/testbed/LIVE_TEST_PLAN.md', 'docs/testbed/gen_tb_event_instance.py',
  'docs/user-guide-deck.html', 'docs/architecture-diagram.html', 'docs/event-instance-reference-plan-original.md',
  'Universal Field Validator Flier.pdf', 'temporal_probe.php', 'php/temporal_x.php', 'graphify-out/graph.json',
  // a PHP file anywhere but the module class, php/, pages/ and the data tool
  'probe.php', 'docs/info.php', 'js/x.php', 'data/x.php', 'php/sub/X.php', 'pages/sub/x.php',
];
for (const p of mustRefuse) check('refused: ' + p, tool.refusal(p) !== '');
const mustShip = [
  'UniversalValidator.php', 'config.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'js/engine.js', 'js/engine.min.js',
  'js/scan.js', 'js/growth/264a8d921eb83803.js', 'php/Logic.php', 'php/Scan/Schema.php', 'php/modes.json',
  'pages/scan.php', 'pages/export.php', 'data/references/convert.php', 'data/references/index.json',
  'data/references/who/LICENSE', 'docs/INSTALL.md', 'docs/USER_GUIDE.md', 'docs/TESTING.md',
];
for (const p of mustShip) check('ships: ' + p, tool.refusal(p) === '');
check('a page that loads a font CDN is refused',
  tool.problems(['docs/x.html'], () => '<link href="https://fonts.googleapis.com/css2?family=A" rel="stylesheet">').length === 1);
check('...and one that does not is not', tool.problems(['docs/x.html'], () => '<p>fonts</p>').length === 0);
check('a binary file is not read as text', tool.textRefusal('x.png', 'https://fonts.googleapis.com/') === '');

// ---- .gitattributes says the same --------------------------------------------
const attrs = fs.readFileSync(path.join(root, '.gitattributes'), 'utf8');
const ignored = attrs.split('\n').map((l) => l.trim()).filter((l) => /\sexport-ignore$/.test(l)).map((l) => l.replace(/\s+export-ignore$/, ''));
for (const p of ['tests', 'tools', 'reports', 'site', '.github', 'CHANGELOG-ARCHIVE.md', 'docs/testbed',
  'docs/user-guide-deck.html', 'docs/architecture-diagram.html', 'docs/event-instance-reference-plan-original.md']) {
  check('.gitattributes export-ignores ' + p, ignored.includes(p));
}
check('.gitattributes no longer says tests ship on purpose', !/Tests and docs ship on purpose/.test(attrs));

// ---- the archive this tree builds --------------------------------------------
// --worktree-attributes reads the .gitattributes on disk, so an edit to it is
// tested before it is committed. A tree without git (an unzipped archive) has
// no tests/ either, so this suite never runs there.
const ar = spawnSync('git', ['-C', root, 'archive', '--worktree-attributes', '--format=tar', 'HEAD'], { maxBuffer: 256 * 1024 * 1024 });
check('git archive runs (status ' + ar.status + ')', ar.status === 0 && ar.stdout.length > 0);
if (ar.status === 0) {
  // A tar is 512-byte blocks: a header with the name at 0, the size in octal at 124, then the data.
  const files = [];
  const buf = ar.stdout;
  let longName = null;
  for (let off = 0; off + 512 <= buf.length;) {
    const head = buf.subarray(off, off + 512);
    if (head[0] === 0) break;
    const cut = (b) => { const z = b.indexOf(0); return b.subarray(0, z < 0 ? b.length : z).toString('utf8'); };
    let name = cut(head.subarray(0, 100));
    const prefix = cut(head.subarray(345, 500));
    if (prefix) name = prefix + '/' + name;
    const size = parseInt(cut(head.subarray(124, 136)).trim() || '0', 8);
    const type = String.fromCharCode(head[156] || 48);
    const data = buf.subarray(off + 512, off + 512 + size);
    if (type === 'L') longName = cut(data);
    else if (type === 'x' || type === 'g') {
      const m = /\d+ path=([^\n]*)\n/.exec(data.toString('utf8'));
      if (m) longName = m[1];
    } else if (type === '0') { files.push({ name: longName || name, data }); longName = null; }
    else longName = null;
    off += 512 + Math.ceil(size / 512) * 512;
  }
  check('the archive lists its files (got ' + files.length + ')', files.length > 100);
  const byName = new Map(files.map((f) => [f.name, f.data]));
  const found = tool.problems(files.map((f) => f.name), (rel) => byName.get(rel).toString('utf8'));
  check('the archive holds nothing the rules refuse (got ' + JSON.stringify(found.slice(0, 12)) + ')', found.length === 0);
  check('the archive holds no file under tests/', !files.some((f) => /^tests\//.test(f.name)));
  for (const p of ['UniversalValidator.php', 'config.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'js/engine.min.js', 'docs/INSTALL.md']) {
    check('the archive still holds ' + p, byName.has(p));
  }
  const bytes = files.reduce((s, f) => s + f.data.length, 0);
  check('the archive is under 9 MB unpacked (got ' + bytes + ')', bytes < 9 * 1024 * 1024);
}

// ---- the tool as CI runs it ----------------------------------------------------
const os = require('os');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'uv-package-'));
fs.mkdirSync(path.join(tmp, 'php'));
fs.writeFileSync(path.join(tmp, 'UniversalValidator.php'), '<?php\n');
fs.writeFileSync(path.join(tmp, 'php', 'Logic.php'), '<?php\n');
const cli = path.join(root, 'tools', 'package_contents.cjs');
let r = spawnSync(process.execPath, [cli, tmp], { encoding: 'utf8' });
check('a clean folder passes (got ' + r.status + ' ' + JSON.stringify(r.stderr) + ')', r.status === 0);
fs.mkdirSync(path.join(tmp, 'tests'));
fs.writeFileSync(path.join(tmp, 'tests', 'when_php.php'), '<?php\n');
r = spawnSync(process.execPath, [cli, tmp], { encoding: 'utf8' });
check('a folder with tests/ fails and names the file', r.status === 1 && /tests\/when_php\.php/.test(r.stderr));
fs.rmSync(tmp, { recursive: true, force: true });

const yml = fs.readFileSync(path.join(root, '.github', 'workflows', 'parity.yml'), 'utf8');
check('the CI package job runs the tool on the unzipped archive',
  /node "\$GITHUB_WORKSPACE\/tools\/package_contents\.cjs" \./.test(yml));

console.log('package_contents_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
