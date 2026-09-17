'use strict';
const assert = require('assert');
global.window={};
global.document={addEventListener(){},getElementsByName(){return [];},readyState:'complete',body:{addEventListener(){}}};
require('../js/engine.js');
const w=window.INSPIREUniversalValidator.whenLogic;
const f=require('./qualified_fixture.json');
let n=0;
for(const row of f.valid){
 const p=w.parse(row.expr,{qualified:true}); assert(p.ok,row.expr); n++;
 assert.strictEqual(w.evaluate(p.ast,row.values),row.expect); n++;
 if(w.qualifiedRefs(p.ast).length){
  assert.strictEqual(w.parse(row.expr).ok,false); n++;
  assert.throws(()=>w.evaluate(p.ast,{}),/Unresolved/); n++;
 }
}
for(const expr of f.invalid){ assert.strictEqual(w.parse(expr,{qualified:true}).ok,false,expr); n++; }
const legacy=require('./when_fixture.json');
for(const row of legacy.eval){
 if(row.expr===undefined) continue;
 assert.deepStrictEqual(w.parse(row.expr,{qualified:true}),w.parse(row.expr)); n++;
}
console.log(`qualified_js: ${n} checks, 0 failures`);
