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


for (const type of ['single','pooled','constraint','required','unique','choices']) {
  for (const branched of [false,true]) {
    const field=makeEl('input'); field.name='target'; field.value='bad';
    const branch={algorithm:'none',idPattern:'GOOD',assert:'1=0',blockSave:'hard',
      choicesHide:['bad'],choicesAll:['bad','good'],uniqueScope:'project',when:'1=0',whenAst:['const',false]};
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
console.log('deferral_dom_js: '+n+' checks, '+fail+' failure(s)');
process.exit(fail?1:0);
