'use strict';
// Evaluates one compiled temporal AST with engine.js. Input: a JSON file {ast, values, blank, cs}.
// Prints the verdict (true / false / null for unknown).
global.window = {};
global.document = {addEventListener(){}, getElementsByName(){ return []; }, readyState: 'complete', body: {addEventListener(){}}};
require('../js/engine.js');
const c = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));
const r = window.INSPIREUniversalValidator.whenLogic.evaluate(c.ast, c.values, c.blank, c.cs);
process.stdout.write(JSON.stringify(r === undefined ? null : r));
