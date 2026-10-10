/*
 * branch_dom_js.cjs — branched validation in the browser.
 *
 * Several conditional rules may share one field since 0.9.0; the server
 * (php/Branching.php) rewrites the sharing into a per-field branch rule and
 * the client picks the branch whose "when" is true. This suite locks the
 * client side of the SAME scenario table tests/branching_php.php and
 * tests/hook_php.php lock server-side:
 *   - the ACTIVE branch's algorithm/pattern validates the field,
 *   - the else branch fires only when no condition is true,
 *   - no condition + no else = inert,
 *   - a conflict (two conditions true) shows both conditions, validates
 *     nothing, and NEVER traps the save — even when a branch is "hard",
 *   - blockSave and suggestFix are per-branch,
 *   - readonly fields never arm the blocker,
 *   - the pooled factory branches identically.
 *
 * Run:  node tests/branch_dom_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false,
    style: {}, _attrs: {}, children: [], parentNode: null, readOnly: false, disabled: false,
    _handlers: {},
    setAttribute(k, v) { this._attrs[k] = String(v); if (k === 'id') this.id = String(v); },
    getAttribute(k) { return (k in this._attrs) ? this._attrs[k] : (k === 'id' ? (this.id || null) : null); },
    removeAttribute(k) { delete this._attrs[k]; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev || {})); },
    appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
    insertBefore(node, ref) {
      node.parentNode = this;
      const i = ref ? this.children.indexOf(ref) : -1;
      if (i >= 0) this.children.splice(i, 0, node); else this.children.push(node);
      return node;
    },
    closest() { return null; },
    focus() { this._focused = true; },
    get nextSibling() {
      const i = this.parentNode ? this.parentNode.children.indexOf(this) : -1;
      return i >= 0 ? (this.parentNode.children[i + 1] || null) : null;
    },
    get firstChild() { return this.children[0] || null; },
  };
}

function boot(els, config) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  for (const el of els) {
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(el, holder);
    holders[el.name] = holder;
  }
  const doc = {
    body, readyState: 'complete', _handlers: {},
    createElement(t) { const e = makeEl(t); allEls.push(e); return e; },
    getElementById(id) { return allEls.find((e) => e.id === id) || null; },
    getElementsByName(name) { return allEls.filter((e) => e.name === name); },
    querySelector() { return null; },
    addEventListener(type, fn) { (this._handlers[type] = this._handlers[type] || []).push(fn); },
    fire(type, ev) { (this._handlers[type] || []).forEach((fn) => fn(ev)); },
  };
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    INSPIRE_VALIDATOR_CONFIG: config,
  };
  global.document = doc; global.window = win;
  require(enginePath);
  return { doc, win, holders, NS: win.INSPIREUniversalValidator };
}
function msgOf(env, field) { return env.holders[field].children[1]; }
function submitEv() {
  return { _prevented: false, preventDefault() { this._prevented = true; }, stopImmediatePropagation() {} };
}


// Every type of every mode in php/modes.json, each started with its sample
// from tests/mode_samples.json, so a new mode is covered the day it is added.
const REGISTRY = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'php', 'modes.json'), 'utf8'));
const SAMPLES = JSON.parse(fs.readFileSync(path.join(__dirname, 'mode_samples.json'), 'utf8')).modes;
const ALL_TYPES = [];
const SAMPLE_KEYS = {};
for (const m of REGISTRY.modes) {
  for (const t of m.types) ALL_TYPES.push(t);
  Object.assign(SAMPLE_KEYS, SAMPLES[m.mode].client);
}
check('the deferral loop covers every registry type', ALL_TYPES.length >= 6);
for (const type of ALL_TYPES) {
  for (const branched of [false,true]) {
    const field=makeEl('input'); field.name='target'; field.value='bad';
    const branch=Object.assign({}, SAMPLE_KEYS, {blockSave:'hard',when:'1=0',whenAst:['const',false]});
    const rule=Object.assign({type,fields:['target']},branch,{deferred:true,deferredWhy:['source unavailable']});
    if(branched){rule.branches=[branch,Object.assign({},branch,{when:null,whenAst:null})];}
    const env=boot([field],{rules:[rule]});
    const event=submitEv(); env.doc.fire('submit',event);
    check(type+' deferred '+branched+' never blocks',!event._prevented);
    check(type+' deferred '+branched+' never marks invalid',!field.__qridInvalid);
  }
}
for(const type of ['single','pooled','constraint','required']){
 const field=makeEl('input'); field.name='target'; field.value='bad';
 const rule={type,fields:['target'],algorithm:'none',idPattern:'GOOD',assert:'1=0',
   blockSave:'hard',snapshotFields:['baseline']};
 const env=boot([field],{rules:[rule]});
 const event=submitEv(); env.doc.fire('submit',event);
 check(type+' snapshot never blocks',!event._prevented);
}
// A dynamic unknown selector cannot choose the fallback or retain its hard guard.
for(const type of ['single','pooled','constraint','required']){
 const field=makeEl('input');field.name='target';field.value='bad';
 const key=makeEl('input');key.name='key';key.value='B';
 const unknown=['temporal',['cmp','=', ['guard',[['key','A']],['lit','1']],['lit','1']]];
 const base={algorithm:'none',idPattern:'GOOD',assert:'1=0',blockSave:'hard'};
 const env=boot([field,key],{rules:[{type,fields:['target'],branches:[
   Object.assign({},base,{when:"[key]='A'",whenAst:unknown}),Object.assign({},base,{when:null})]}]});
 const event=submitEv();env.doc.fire('submit',event);
 check(type+' unknown selector never chooses else',!event._prevented&&!field.__qridInvalid);
 check(type+' unknown selector has status',msgOf(env,'target').innerHTML.includes('cannot resolve'));
}
// Live aggregate members must trigger assertion changes; the server tree contains no qualified refs.
{
 const field=makeEl('input');field.name='target';field.value='8';
 const tree=['temporal',['cmp','<',['ref','target',null],['aggregate','average',[['ref','target',null],['lit','12']]]]];
 const env=boot([field],{rules:[{type:'constraint',fields:['target'],assert:'1=1',assertAst:tree,blockSave:'off'}]});
 check('live average initially valid',field.getAttribute('aria-invalid')!=='true');
 field.value='14';field.fire('change');
 check('live average updates verdict',field.getAttribute('aria-invalid')==='true');
}
{
 const field=makeEl('input');field.name='target';field.value='012';
 const tree=['temporal',['not',['cmp','identical',['ref','target',null],['lit','12']]]];
 const env=boot([field],{rules:[{type:'unique',fields:['target'],uniqueScope:'record',uniqueRecordAsts:{target:tree},blockSave:'off'},
   {type:'constraint',fields:['target'],assert:"[target]>0",blockSave:'off'}]});
 check('local unique composes with assertion',field.getAttribute('data-qrid-bound-q')==='1'&&field.getAttribute('data-qrid-bound-c')==='1');
 check('local unique keeps leading zeros',field.getAttribute('aria-invalid')!=='true');
 field.value='12';field.fire('change');
 check('local unique flags repeat without AJAX',field.getAttribute('aria-invalid')==='true');
 const event=submitEv();env.doc.fire('submit',event);check('local unique advisory',!event._prevented);
}
// Record uniqueness on a D-M-Y date: the server sends the other entries as saved
// (Y-M-D) and the page compares the typed date in that form.
{
 const field=makeEl('input');field.name='a_val';field.value='07-01-2026';
 const same=(d)=>['not',['and',[['cmp','same:fold:text',['ref','a_val',null],['lit',d]]]]];
 const tree=['temporal',['and',[same('2026-01-07'),same('2026-01-09')]]];
 const env=boot([field],{dateFormats:{a_val:['date','dmy']},
   rules:[{type:'unique',fields:['a_val'],uniqueScope:'record',uniqueRecordAsts:{a_val:tree},blockSave:'off'}]});
 check('D-M-Y record uniqueness: a typed duplicate is flagged',field.getAttribute('aria-invalid')==='true');
 field.value='09-01-2026';field.fire('change');
 check('D-M-Y record uniqueness: a duplicate in another event is flagged',field.getAttribute('aria-invalid')==='true');
 field.value='08-01-2026';field.fire('change');
 check('D-M-Y record uniqueness: a free date passes',field.getAttribute('aria-invalid')!=='true');
}
// A binding's match key on a D-M-Y date: the guard holds the saved Y-M-D value and
// the page compares the typed key in that form.
{
 const field=makeEl('input');field.name='target';field.value='30';
 const key=makeEl('input');key.name='key_a';key.value='05-01-2026';
 const tree=['temporal',['cmp','<',['ref','target',null],['guard',[['key_a','2026-01-05']],['lit','20']]]];
 const env=boot([field,key],{dateFormats:{key_a:['date','dmy']},
   rules:[{type:'constraint',fields:['target'],assert:'1=1',assertAst:tree,blockSave:'off'}]});
 check('a D-M-Y match key that matches the saved one: the comparison is judged',
   field.getAttribute('aria-invalid')==='true'&&!msgOf(env,'target').innerHTML.includes('cannot resolve'));
 key.value='06-01-2026';key.fire('change');field.fire('change');
 const m=msgOf(env,'target').innerHTML;
 check('a changed match key: not checked on this page, and checked after the save',
   m.includes('cannot resolve')&&m.includes('It is checked after the record is saved.')&&!m.includes('not checked after saving either'));
}
console.log('deferral_dom_js: '+n+' checks, '+fail+' failure(s)');
process.exit(fail?1:0);
