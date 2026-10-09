'use strict';
/**
 * temporal_perf_engine: per-evaluation cost in js/engine.js of the rule ASTs
 * dumped by tools/temporal_perf_payload.php. The engine re-evaluates the rule on
 * every (debounced) input event of the field, so this is the per-keystroke cost.
 * Usage: node tools/temporal_perf_engine.cjs <dir-with-json> [reps]
 */
const fs = require('fs'), path = require('path');
global.window = {};
global.document = {addEventListener(){}, getElementsByName(){return [];}, readyState:'complete', body:{addEventListener(){}}};
require('../js/engine.js');
const logic = window.INSPIREUniversalValidator.whenLogic;
const dir = process.argv[2], reps = +(process.argv[3] || 3);
for (const f of fs.readdirSync(dir).filter(x => x.endsWith('.json'))) {
  const {assertAst, current} = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  if (!assertAst) { console.log(`${f.padEnd(34)} (deferred, nothing to evaluate)`); continue; }
  let r; const t = process.hrtime.bigint();
  for (let i = 0; i < reps; i++) r = logic.evaluate(assertAst, current);
  const ms = Number(process.hrtime.bigint() - t) / 1e6 / reps;
  console.log(`${f.padEnd(34)} ${ms.toFixed(1).padStart(9)} ms/evaluation  result=${r}`);
}
