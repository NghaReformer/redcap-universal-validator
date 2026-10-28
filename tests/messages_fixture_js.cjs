/*
 * messages_fixture_js.cjs - the browser half of the message parity fixture.
 *
 * tests/messages_fixture.json holds, for each catalogue key, one rule in the
 * shape the page hands js/engine.js, one finding of that rule, and for each
 * audience two sentences: the one php/messages/catalog.json renders (for
 * staff, the report's "What is wrong" cell) and the one the form shows.
 * tests/messages_fixture_php.php renders the first; this file renders the
 * second, by booting the real engine on the rule and the case's values.
 *
 * It asserts, per case and audience:
 *   - the form shows exactly the fixture's "engine" sentence (the status
 *     mark and markup taken off, entities decoded: the text a person reads),
 *   - a case marked "same" shows the catalogue's sentence, byte for byte,
 *   - a case marked "differs" really differs, so the mark stays true,
 *   - a case the form never shows says why ("formAbsent") and has no
 *     engine sentence.
 *
 * Neither runtime reads the other's wording, so this is what stops one side
 * changing a sentence without the other side, and the fixture, saying so.
 *
 * Run:  node tests/messages_fixture_js.cjs
 */
'use strict';
const fs = require('fs');
const path = require('path');

let n = 0, fail = 0;
function check(label, cond) { n++; if (!cond) { fail++; console.error('FAIL: ' + label); } }

const FIXTURE = JSON.parse(fs.readFileSync(path.join(__dirname, 'messages_fixture.json'), 'utf8'));
/* The page-wide defaults the page config carries (tests/page_defaults.json). */
const PAGE_DEFAULTS = JSON.parse(fs.readFileSync(path.join(__dirname, 'page_defaults.json'), 'utf8')).defaults;

/* An inline style whose cssText sets display as a browser's does: the engine
   shows a region by writing the whole cssText, and hides it with display. */
function makeStyle() {
  const s = {};
  Object.defineProperty(s, 'cssText', {
    get() { return this._css || ''; },
    set(v) {
      this._css = String(v);
      const m = /(?:^|;)\s*display\s*:\s*([^;]+)/.exec(this._css);
      this.display = m ? m[1].trim() : '';
    },
  });
  return s;
}

function makeEl(tag) {
  return {
    tagName: (tag || 'div').toUpperCase(), id: '', name: '', value: '', innerHTML: '',
    type: '', checked: false, disabled: false,
    style: makeStyle(), _attrs: {}, children: [], parentNode: null, readOnly: false,
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
    removeChild(node) {
      const i = this.children.indexOf(node);
      if (i >= 0) this.children.splice(i, 1);
      node.parentNode = null;
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

/* A transport that answers every request at once with the case's reply. */
function transport(reply) {
  return {
    ajax() {
      return { then(res) { res(JSON.parse(JSON.stringify(reply))); } };
    },
  };
}

/* The engine booted on one rule, with one element per value (a <select>
   where the case lists options for that field). */
function boot(rule, form, audience) {
  const enginePath = path.join(__dirname, '..', 'js', 'engine.js');
  delete require.cache[require.resolve(enginePath)];
  const allEls = [];
  const body = makeEl('body');
  const holders = {};
  const els = {};
  const options = form.options || {};
  for (const name of Object.keys(form.values)) {
    let el;
    if (options[name]) {
      el = makeEl('select');
      for (const v of options[name]) { const o = makeEl('option'); o.value = v; el.appendChild(o); allEls.push(o); }
    } else {
      el = makeEl('input');
    }
    el.name = name;
    el.value = form.values[name];
    const holder = makeEl('div');
    holder.appendChild(el);
    body.appendChild(holder);
    allEls.push(el, holder);
    holders[name] = holder;
    els[name] = el;
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
  const config = Object.assign({ singleFields: [], pooledFields: [] }, PAGE_DEFAULTS, form.config || {}, { rules: [rule] });
  if (audience === 'survey') config.context = 'survey';
  // The server's answer, where the check asks it; a survey's may hold less.
  const reply = form.replies ? form.replies[audience] : form.reply;
  if (reply) config.jsmoName = 'EMStub.UV';
  const win = {
    _alerts: [], alert(m) { this._alerts.push(m); }, confirm() { return true; },
    INSPIRE_VALIDATOR_CONFIG: config,
  };
  if (reply) win.EMStub = { UV: transport(reply) };
  global.document = doc; global.window = win;
  // Everything is in the DOM at boot: the late-render retry loops would only
  // wait for elements that never come.
  global.setInterval = () => 0;
  global.clearInterval = () => {};
  const was = console.error;
  console.error = () => {};
  try { require(enginePath); } finally { console.error = was; }
  return { allEls, els };
}

/* The text a person reads in a message region: markup off, entities decoded,
   white space collapsed, and the leading status mark (a cross, a warning
   sign) taken off, since it is not part of the sentence. Emphasis inside a
   sentence goes without a trace; any other element (a pooled field's ID
   chips, a line break) is a gap between words, as it is on screen. */
const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', mdash: '—', ndash: '–', hellip: '…' };
function readable(html) {
  const text = String(html)
    .replace(/<\/?(?:b|i|em|strong|code)(?:\s[^>]*)?>/gi, '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&#(\d+);/g, (m, d) => String.fromCharCode(Number(d)))
    .replace(/&#x([0-9a-f]+);/gi, (m, h) => String.fromCharCode(parseInt(h, 16)))
    .replace(/&([a-z]+);/g, (m, e) => (e in ENTITIES ? ENTITIES[e] : m))
    .replace(/[ \t\r\n ]+/g, ' ')
    .trim();
  return text.replace(/^[✗⚠✓✕✖]️? ?/, '');
}

/* What the form shows for the case's field: the one message region of that
   field that is on screen. Two shown regions is a case that does not say
   which one it means, and fails rather than picking one. */
function shownSentence(env, field, region) {
  const re = new RegExp('^uvalidate-msg-');
  const shown = env.allEls.filter((e) => e.id && re.test(e.id) && e.id.indexOf(field) >= 0
    && (!region || e.id.slice(-region.length) === region)
    && e.style.display !== 'none' && e.innerHTML !== '');
  if (shown.length !== 1) return { error: shown.length + ' message regions shown (' + shown.map((e) => e.id).join(', ') + ')' };
  return { text: readable(shown[0].innerHTML) };
}

function render(c, audience) {
  const env = boot(c.rule, c.form, audience);
  const field = c.form.field;
  if (env.els[field]) env.els[field].fire('change');
  return shownSentence(env, field, c.form.region || '');
}

// ---- the reader itself, on markup of the shapes the engine writes ---------------
check('reader: the mark, markup and entities come off',
  readable('&#10007; This <b>is</b> it &mdash; a &amp; b &lt;c&gt;.') === 'This is it — a & b <c>.');
check('reader (control): a sentence with no mark is left as it is',
  readable('Plain sentence.') === 'Plain sentence.');
check('reader: elements other than emphasis keep the words apart',
  readable('2 IDs read<br><span>&#10003;&nbsp;A</span><span>&#10007;&nbsp;B</span>') === '2 IDs read ✓ A ✗ B');

// ---- every case of the fixture -----------------------------------------------------
const AUDIENCES = ['staff', 'survey'];
let rendered = 0, same = 0;
check('fixture: it has cases', Array.isArray(FIXTURE.cases) && FIXTURE.cases.length > 0);
for (const c of FIXTURE.cases) {
  const id = c.key + (c.variant ? ' (' + c.variant + ')' : '');
  for (const a of AUDIENCES) {
    const s = c[a];
    if (!s) { check(id + ' ' + a + ': the case has this audience', false); continue; }
    if (c.form === null) {
      check(id + ' ' + a + ': a case the form never shows has no engine sentence', s.engine === null);
      continue;
    }
    const got = render(c, a);
    rendered++;
    check(id + ' ' + a + ': the form shows ' + JSON.stringify(s.engine)
      + (got.error ? ' (' + got.error + ')' : ' (got ' + JSON.stringify(got.text) + ')'),
      !got.error && got.text === s.engine);
    if (s.parity === 'same') {
      same++;
      check(id + ' ' + a + ': marked "same", the form shows the catalogue sentence', got.text === s.catalogue);
    } else {
      check(id + ' ' + a + ': marked "differs", and the form does say something else',
        s.parity === 'differs' && got.text !== s.catalogue);
    }
  }
  check(id + ': a case the form never shows says why',
    c.form !== null || (typeof c.formAbsent === 'string' && c.formAbsent !== ''));
}
check('fixture: the form rendered cases in both audiences', rendered >= 2);
check('fixture: some sentences are the same on both sides', same > 0);

console.log(n + ' checks, ' + fail + ' failed (' + rendered + ' form renderings, ' + same + ' the same as the catalogue)');
process.exit(fail === 0 ? 0 : 1);
