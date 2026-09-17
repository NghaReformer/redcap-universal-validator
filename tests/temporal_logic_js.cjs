'use strict';
const assert=require('assert');
global.window={};
global.document={addEventListener(){},getElementsByName(){return [];},readyState:'complete',body:{addEventListener(){}}};
require('../js/engine.js');
let n=0;
for(const row of require('./temporal_fixture.json')){
 assert.strictEqual(window.INSPIREUniversalValidator.whenLogic.evaluate(row.ast,row.values),row.expected,row.name);n++;
}
console.log(`temporal_logic_js: ${n} checks, 0 failures`);
