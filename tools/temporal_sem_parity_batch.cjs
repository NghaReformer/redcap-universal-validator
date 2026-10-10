'use strict';
// Batch twin of temporal_sem_eval.cjs: input JSON file [{ast, values, blank, cs}], prints [verdict...].
global.window = {};
global.document = {addEventListener(){}, getElementsByName(){ return []; }, readyState: 'complete', body: {addEventListener(){}}};
require('../js/engine.js');
const W = window.INSPIREUniversalValidator.whenLogic;
const cases = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));
const out = cases.map(c => { try { const r = W.evaluate(c.ast, c.values, c.blank, c.cs); return r === undefined ? null : r; } catch (e) { return 'THROW:' + e.message; } });
process.stdout.write(JSON.stringify(out));
