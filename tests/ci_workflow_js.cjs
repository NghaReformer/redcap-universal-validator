'use strict';
/*
 * tests/ci_workflow_js.cjs - the CI workflows run every suite, and each command
 * of a step is its own command.
 *
 * A `run:` written as a plain scalar folds the more-indented lines under it
 * into one command line. PRF-7 put a one-line step in front of the growth
 * step's last two lines, so CI ran
 * `php tools/build_growth_rows.php --check php tests/growth_chart_gaps_module_php.php php tests/convert_php.php`,
 * and the two suites never ran: the script reads only its first argument.
 * This suite refuses a plain `run:` with lines under it, and a suite file that
 * no workflow names.
 *
 * Run:  node tests/ci_workflow_js.cjs
 */
const fs = require('fs');
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const dir = path.join(__dirname, '..', '.github', 'workflows');
const workflows = fs.readdirSync(dir).filter((f) => /\.ya?ml$/.test(f)).map((f) => ({ f, text: fs.readFileSync(path.join(dir, f), 'utf8') }));
check('the workflows are found', workflows.length >= 2);

const indent = (l) => l.match(/^ */)[0].length;
for (const { f, text } of workflows) {
  const lines = text.split('\n');
  const folded = [];
  lines.forEach((l, i) => {
    const m = l.match(/^( *)(?:- )?run:\s*(\S.*)?$/);
    if (!m || !m[2] || /^[|>]/.test(m[2])) return;
    const next = lines.slice(i + 1).find((x) => x.trim() !== '' && !/^\s*#/.test(x));
    if (next !== undefined && indent(next) > indent(l)) folded.push((i + 1) + ': ' + l.trim());
  });
  check(f + ': no plain run: line has more-indented lines under it (got ' + JSON.stringify(folded) + ')', folded.length === 0);
}

// Every suite in tests/ is named by some workflow. Included helpers, generators
// and the min-engine preload are not suites.
const named = workflows.map((w) => w.text).join('\n');
const helpers = new Set(['use_engine_min.cjs']);
const suites = fs.readdirSync(__dirname).filter((f) =>
  (/_php\.php$/.test(f) || /\.cjs$/.test(f)) && !/^gen_/.test(f) && !helpers.has(f));
const missing = suites.filter((f) => !named.includes('tests/' + f));
check('every suite runs in CI (missing ' + JSON.stringify(missing) + ')', missing.length === 0);
check('the suite list is not empty', suites.length > 100);

console.log('ci_workflow_js: ' + n + ' checks, ' + fail + ' failure(s)');
process.exit(fail ? 1 : 0);
