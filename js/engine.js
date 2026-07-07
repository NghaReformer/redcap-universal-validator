/* engine.js — vendored from the qrcode_generation repo:
   integrations/redcap/redcap_combined_id_check.js
   THE ONLY CHANGE from upstream: the config object is read from
   window.INSPIRE_VALIDATOR_CONFIG (injected by the REDCap module) instead of a
   hardcoded literal. Every algorithm / factory / dispatch line below is identical
   to the verified upstream and is checked against tests/check_fixture.json by
   tests/parity_js.cjs. Re-vendor with tools described in js/README.md. */

/* ============================================================
   QR STUDY-ID REAL-TIME CHECK for REDCap  (COMBINED: single + pooled fields)
   One script for both kinds of ID fields on an instrument:
     - singleFields: fields that hold ONE participant ID
       -> green "verified" / red "typo?" message under the field
     - pooledFields: fields that hold SEVERAL IDs in one box (pooled testing),
       space/comma-separated or jammed together with no separator
       -> the value is split into individual IDs (boundaries chosen where the
          check character verifies) and shown as one green/red chip per member,
          with warnings for junk, duplicates, and wrong pool size.
   Paste this whole file into the "JavaScript Injector" external module.
   Edit only QRID_COMBINED_CONFIG below. Warn-only: it never blocks saving.
   ============================================================ */
/* ---- CONFIG: edit this part only ---- */
var QRID_COMBINED_CONFIG = (typeof window !== "undefined" && window.INSPIRE_VALIDATOR_CONFIG)
  ? window.INSPIRE_VALIDATOR_CONFIG
  : { singleFields: [], pooledFields: [], rules: [], algorithm: "iso7064_mod37_36",
      idPattern: null, source: "normalized_id", strip: "-/ _|", suggestFix: true,
      keepChars: "", idLengths: null, idMinLen: 8, idMaxLen: 14, expectedIds: null, blockSave: "off" };
/* ---- HOW TO CONFIGURE: the three supported set-ups -------------------------
   1) CHECK-CHARACTER PROJECT (the QR generator's default):
        algorithm: "iso7064_mod37_36"        idPattern: null
      Validation recomputes the check character — catches virtually every typo.
   2) CHECK + FORMAT:
        algorithm: "iso7064_mod37_36"        idPattern: "[0-9][A-Z]{3}[0-9]{5}[0-9A-Z]"
      Flags wrong-shape and wrong-check separately (strictest).
   3) LEGACY / REGEX-ONLY PROJECT (IDs minted WITHOUT a check character):
        algorithm: "none"                    idPattern: "FC[1-9]-[0-9]{4}"
      Validation is format-only. Honest limit: a regex cannot catch a typo that
      stays inside the format (3 -> 8 in a digit still matches) — that is
      exactly what check characters exist for.
   Anything else (algorithm "none" with no idPattern, a misspelled algorithm,
   or an invalid regex) shows a configuration error on the field instead of
   fake results. Patterns may include dashes and the ^ $ anchors are optional.

   ---- ALGORITHM REFERENCE (for the "algorithm" setting above) ---------------
   The value MUST match the method your QR codes were minted with
   (STUDY_ID Studio -> Settings -> check-character format). The generator's
   default is iso7064_mod37_36. All are implemented by the verified engine
   below and mirror qrcode_generation/check_characters.py exactly.

   iso7064_mod37_36   ISO 7064 Mod 37,36 (hybrid) - THE DEFAULT. Runs over the
                      whole ID (letters + digits); one check char 0-9A-Z.
                      Catches every single-character typo and ~99.9% of
                      adjacent swaps (hybrid systems miss a small fraction;
                      measured ~0.13% by brute force). Recommended.
   iso7064_letters1   Letters-only variant 1 - one check char that is ALWAYS a
                      letter A-Z. Folds 36 symbols onto 26 letters, so a few
                      digit<->letter confusions (e.g. 0 vs O) can slip through;
                      slightly weaker than mod37_36.
   iso7064_letters2   Letters-only variant 2 - TWO check letters (A-F each).
                      Full Mod 37,36 strength with a letters-only check, at the
                      cost of one extra character.
   iso7064_mod11_10   ISO 7064 Mod 11,10 (hybrid) - digits only, one check
                      DIGIT. Catches all single-digit typos and ~97.8% of
                      adjacent swaps (measured). Site letters are not
                      protected (use source digits_only).
   iso7064_mod97_10   ISO 7064 Mod 97-10 (pure) - digits only, TWO check digits
                      (IBAN-grade). Strongest numeric option; catches all
                      single errors and ALL adjacent transpositions.
   iso7064_mod11_2    ISO 7064 Mod 11-2 (pure) - digits only, one check char
                      that can be "X" (like ISBN-10). Catches all single typos
                      and adjacent swaps.
   iso7064_mod37_2    ISO 7064 Mod 37-2 (pure) - letters + digits, one check
                      char that can be "*". Catches all single typos and
                      adjacent swaps.
   damm               Damm algorithm - digits only, one check digit computed
                      from an anti-symmetric quasigroup table. Catches all
                      single typos and adjacent swaps; no special characters.
   verhoeff           Verhoeff algorithm - digits only, one check digit based
                      on the dihedral group D5. Catches all single typos and
                      adjacent swaps; no special characters.
   luhn               Luhn / Mod 10 (credit-card style) - digits only, one
                      check digit. WEAKEST of the set: cannot catch the 09<->90
                      adjacent swap. Provided for compatibility only.
   none               No check character - every value passes (legacy projects
                      minted without a check char). Provides no protection.
--------------------------------------------------------------------------- */
/* ---- verified engine (extracted verbatim from playground/qr_check_playground.html; do not edit) ---- */
(function(){
  "use strict";
  const G = (typeof globalThis !== "undefined") ? globalThis : this;

  const DIGITS = "0123456789";
  const LETTERS26 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  const ALNUM36 = DIGITS + LETTERS26;

  class CheckError extends Error {}

  function mod(a, m){ return ((a % m) + m) % m; }           // Python-style non-negative modulo

  function valueOf(ch, alphabet, name){
    const idx = alphabet.indexOf(ch);
    if(idx < 0){
      throw new CheckError("character " + JSON.stringify(ch) +
        " is not valid for algorithm " + JSON.stringify(name) +
        " (allowed: " + JSON.stringify(alphabet) + ")");
    }
    return idx;
  }

  // Build [identity, base, base^2, ...] — mirrors _power_perm_table (check_characters.py:69).
  function powerPermTable(base, count){
    const table = [[0,1,2,3,4,5,6,7,8,9]];
    for(let k=1;k<count;k++){
      const prev = table[table.length-1];
      const next = [];
      for(let x=0;x<10;x++) next.push(base[prev[x]]);
      table.push(next);
    }
    return table;
  }

  function makeAlgo(spec){
    const algo = {
      name: spec.name, inputAlphabet: spec.inputAlphabet,
      checkAlphabet: spec.checkAlphabet, nCheckChars: spec.nCheckChars,
      compute: spec.compute,
    };
    algo.generate = function(payload){ return payload + algo.compute(payload); };
    algo.validate = spec.validate || function(full){
      const n = algo.nCheckChars;
      if(n === 0) return true;
      if(full.length <= n) return false;
      const body = full.slice(0, -n), check = full.slice(-n);
      try { return algo.compute(body) === check; }
      catch(e){ return false; }
    };
    return algo;
  }

  // ---- ISO/IEC 7064 generic engine (pure + hybrid) ----
  function isoPure(name, payloadAlphabet, modulus, radix, checkAlphabet, nCheckChars){
    checkAlphabet = checkAlphabet || payloadAlphabet;
    function compute(payload){
      let p = 0;
      for(const ch of payload){
        const d = valueOf(ch, payloadAlphabet, name);
        p = ((p + d) * radix) % modulus;
      }
      for(let i=0;i<nCheckChars-1;i++) p = (p * radix) % modulus;
      if(nCheckChars === 1){
        const checkValue = mod(1 - p, modulus);      // index into an alphabet of size M
        return checkAlphabet[checkValue];
      }
      // Multi-char pure system (Mod 97-10): check_value = (M+1) - p, NOT reduced mod M.
      let x = (modulus + 1) - p;
      const chars = [];
      for(let i=0;i<nCheckChars;i++){ chars.push(payloadAlphabet[x % radix]); x = Math.floor(x / radix); }
      return chars.reverse().join("");
    }
    return makeAlgo({name, inputAlphabet:payloadAlphabet, checkAlphabet, nCheckChars, compute});
  }

  function hybridValue(values, modulus){
    let check = Math.floor(modulus / 2);
    for(const d of values){
      check = (((check || modulus) * 2) % (modulus + 1) + d) % modulus;
    }
    return mod(1 - ((check || modulus) * 2) % (modulus + 1), modulus);
  }

  function isoHybrid(name, alphabet){
    const modulus = alphabet.length;
    function compute(payload){
      const values = [];
      for(const ch of payload) values.push(valueOf(ch, alphabet, name));
      return alphabet[hybridValue(values, modulus)];
    }
    return makeAlgo({name, inputAlphabet:alphabet, checkAlphabet:alphabet, nCheckChars:1, compute});
  }

  // ---- Letters-only hybrids ----
  function lettersHybrid1(){
    const name = "iso7064_letters1";
    function compute(payload){
      const values = [];
      for(const ch of payload){
        const idx = ALNUM36.indexOf(ch);
        if(idx < 0) throw new CheckError("character " + JSON.stringify(ch) + " not valid for " + name);
        values.push(idx % 26);                       // fold 0-9A-Z onto 26 letters
      }
      return LETTERS26[hybridValue(values, 26)];
    }
    return makeAlgo({name, inputAlphabet:ALNUM36, checkAlphabet:LETTERS26, nCheckChars:1, compute});
  }

  function lettersHybrid2(){
    const name = "iso7064_letters2";
    const CA = "ABCDEF";
    function compute(payload){
      const values = [];
      for(const ch of payload){
        const idx = ALNUM36.indexOf(ch);
        if(idx < 0) throw new CheckError("character " + JSON.stringify(ch) + " not valid for " + name);
        values.push(idx);
      }
      const v = hybridValue(values, 36);             // 0..35
      return CA[Math.floor(v / 6)] + CA[v % 6];      // two base-6 letters A-F
    }
    return makeAlgo({name, inputAlphabet:ALNUM36, checkAlphabet:CA, nCheckChars:2, compute});
  }

  // ---- Damm ----
  const DAMM_TABLE = [
    [0,3,1,7,5,9,8,6,4,2],[7,0,9,2,1,5,4,8,6,3],[4,2,0,6,8,7,1,3,5,9],
    [1,7,5,0,9,8,3,4,2,6],[6,1,2,3,0,4,5,9,7,8],[3,6,7,4,2,0,9,5,8,1],
    [5,8,6,9,7,2,0,1,3,4],[8,9,4,5,3,6,2,0,1,7],[9,4,3,8,6,1,7,2,0,5],
    [2,5,8,1,4,3,6,7,9,0],
  ];
  function damm(){
    function compute(payload){
      let interim = 0;
      for(const ch of payload){ interim = DAMM_TABLE[interim][valueOf(ch, DIGITS, "damm")]; }
      return String(interim);
    }
    return makeAlgo({name:"damm", inputAlphabet:DIGITS, checkAlphabet:DIGITS, nCheckChars:1, compute});
  }

  // ---- Verhoeff ----
  const V_D = [
    [0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],
    [3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],
    [6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],
    [9,8,7,6,5,4,3,2,1,0],
  ];
  const V_P = powerPermTable([1,5,7,6,2,8,3,0,9,4], 8);
  const V_INV = [0,4,3,2,1,5,6,7,8,9];
  function verhoeff(){
    function compute(payload){
      let c = 0;
      const rev = Array.from(payload).reverse();
      for(let i=0;i<rev.length;i++){
        const d = valueOf(rev[i], DIGITS, "verhoeff");
        c = V_D[c][V_P[(i + 1) % 8][d]];
      }
      return String(V_INV[c]);
    }
    function validate(full){
      if(!full) return false;
      try {
        let c = 0;
        const rev = Array.from(full).reverse();
        for(let i=0;i<rev.length;i++){
          const d = valueOf(rev[i], DIGITS, "verhoeff");
          c = V_D[c][V_P[i % 8][d]];
        }
        return c === 0;
      } catch(e){ return false; }
    }
    return makeAlgo({name:"verhoeff", inputAlphabet:DIGITS, checkAlphabet:DIGITS, nCheckChars:1, compute, validate});
  }

  // ---- Luhn ----
  function luhn(){
    function compute(payload){
      let total = 0;
      const rev = Array.from(payload).reverse();
      for(let idx=0; idx<rev.length; idx++){
        let d = valueOf(rev[idx], DIGITS, "luhn");
        if(idx % 2 === 0){ d *= 2; if(d > 9) d -= 9; }
        total += d;
      }
      return String((10 - (total % 10)) % 10);
    }
    return makeAlgo({name:"luhn", inputAlphabet:DIGITS, checkAlphabet:DIGITS, nCheckChars:1, compute});
  }

  // ---- NoCheck ----
  function noCheck(){
    return makeAlgo({name:"none", inputAlphabet:ALNUM36, checkAlphabet:"", nCheckChars:0,
      compute:function(){ return ""; }, validate:function(){ return true; }});
  }

  // ---- Registry (mirrors _register_builtins, check_characters.py:469) ----
  const ALGORITHMS = {};
  function register(a){ ALGORITHMS[a.name] = a; }
  register(isoPure("iso7064_mod11_2", DIGITS, 11, 2, DIGITS + "X", 1));
  register(isoPure("iso7064_mod37_2", ALNUM36, 37, 2, ALNUM36 + "*", 1));
  register(isoPure("iso7064_mod97_10", DIGITS, 97, 10, DIGITS, 2));
  register(isoHybrid("iso7064_mod11_10", DIGITS));
  register(isoHybrid("iso7064_mod37_36", ALNUM36));
  register(lettersHybrid1());
  register(lettersHybrid2());
  register(damm());
  register(verhoeff());
  register(luhn());
  register(noCheck());

  // ---- By-hand derivation tracers -------------------------------------------
  // Each returns {title, intro, columns, rows, final, check}. The `check` is
  // recomputed independently here; a self-test group asserts explain(p).check ===
  // compute(p) for every Python-anchored fixture row, so the shown derivation can
  // never disagree with the verified check character.
  function explainPure(name, payload, alphabet, M, r, nCheck, checkAlphabet){
    const rows = [], positions = [], states = []; let p = 0;
    for(let i=0;i<payload.length;i++){
      const ch = payload[i], d = valueOf(ch, alphabet, name);
      const pnew = ((p + d) * r) % M;
      rows.push([String(i+1), ch, String(d), "((" + p + " + " + d + ") × " + r + ") mod " + M + " = " + pnew]);
      positions.push(i); states.push(pnew);
      p = pnew;
    }
    const extra = [];
    for(let k=0;k<nCheck-1;k++){ const pn = (p * r) % M; extra.push("(" + p + " × " + r + ") mod " + M + " = " + pn); p = pn; }
    let check, final;
    if(nCheck === 1){
      const cv = mod(1 - p, M); check = checkAlphabet[cv];
      final = "check = (1 − " + p + ") mod " + M + " = " + cv + "  →  “" + check + "”";
    } else {
      let x = (M + 1) - p; const digs = [];
      for(let k=0;k<nCheck;k++){ digs.unshift(alphabet[x % r]); x = Math.floor(x / r); }
      check = digs.join("");
      final = "check = (" + M + " + 1) − " + p + " = " + ((M + 1) - p) + "  →  “" + check + "”";
    }
    return { title: "ISO/IEC 7064 pure system — modulus " + M + ", radix " + r,
      intro: "Start p = 0. Left to right, for each character: p = ((p + value) × " + r + ") mod " + M + "."
        + (extra.length ? "  Then " + extra.length + " more radix step(s): " + extra.join("; ") + "." : "")
        + (checkAlphabet !== alphabet ? "  Check values 0–" + (M-1) + " map through “" + checkAlphabet + "”." : ""),
      columns: ["#", "Char", "Value", "p after step"], rows: rows, final: final, check: check,
      stateLabel: "p", stateStart: 0, positions: positions, states: states };
  }
  function explainHybrid(name, payload, indexAlphabet, modulus, fold, outAlphabet, twoLetter){
    const rows = [], positions = [], states = []; let check = Math.floor(modulus / 2);
    for(let i=0;i<payload.length;i++){
      const ch = payload[i], idx = indexAlphabet.indexOf(ch);
      if(idx < 0) throw new CheckError("character " + JSON.stringify(ch) + " is not valid for " + name);
      const d = fold ? (idx % modulus) : idx;
      const base = check || modulus, m = (base * 2) % (modulus + 1), nc = (m + d) % modulus;
      rows.push([String(i+1), ch, fold ? (idx + " → " + d) : String(d),
        "(" + base + "×2) mod " + (modulus + 1) + " = " + m + ";  (" + m + " + " + d + ") mod " + modulus + " = " + nc]);
      positions.push(i); states.push(nc);
      check = nc;
    }
    const base = check || modulus, m = (base * 2) % (modulus + 1), v = mod(1 - m, modulus);
    let cstr, final;
    if(twoLetter){
      cstr = "ABCDEF"[Math.floor(v / 6)] + "ABCDEF"[v % 6];
      final = "v = (1 − (" + base + "×2 mod " + (modulus + 1) + ")) mod " + modulus + " = " + v
        + "  →  base-6 (" + Math.floor(v/6) + "," + (v%6) + ") → “" + cstr + "”";
    } else {
      cstr = outAlphabet[v];
      final = "v = (1 − (" + base + "×2 mod " + (modulus + 1) + ")) mod " + modulus + " = " + v + "  →  “" + cstr + "”";
    }
    return { title: "ISO/IEC 7064 hybrid system — moduli (" + (modulus + 1) + ", " + modulus + ")",
      intro: "Start check = ⌊" + modulus + "/2⌋ = " + Math.floor(modulus / 2)
        + ". For each character: check = ((check×2) mod " + (modulus + 1) + " + value) mod " + modulus
        + ".  When check is 0, use " + modulus + " in its place."
        + (fold ? "  Each 0–9A–Z value is folded onto " + modulus + " letters (value mod " + modulus + ")." : ""),
      columns: ["#", "Char", fold ? "idx → value" : "Value", "check after step"], rows: rows, final: final, check: cstr,
      stateLabel: "check", stateStart: Math.floor(modulus / 2), positions: positions, states: states };
  }
  function explainDamm(payload){
    const rows = [], positions = [], states = []; let interim = 0;
    for(let i=0;i<payload.length;i++){
      const ch = payload[i], d = valueOf(ch, DIGITS, "damm"), nx = DAMM_TABLE[interim][d];
      rows.push([String(i+1), ch, "row " + interim + ", col " + d, "D[" + interim + "][" + d + "] = " + nx]);
      positions.push(i); states.push(nx);
      interim = nx;
    }
    return { title: "Damm — anti-symmetric quasigroup (order 10)",
      intro: "Start interim = 0. For each digit: interim = D[interim][digit] via the Damm table. The final interim is the check digit.",
      columns: ["#", "Digit", "Lookup", "interim = D[interim][digit]"], rows: rows,
      final: "check = final interim = " + interim, check: String(interim),
      stateLabel: "interim", stateStart: 0, positions: positions, states: states };
  }
  function explainVerhoeff(payload){
    const rows = [], positions = [], states = []; let c = 0; const rev = Array.from(payload).reverse();
    for(let i=0;i<rev.length;i++){
      const ch = rev[i], d = valueOf(ch, DIGITS, "verhoeff");
      const perm = V_P[(i + 1) % 8][d], nc = V_D[c][perm];
      rows.push([String(i+1), ch, "P[" + ((i+1)%8) + "][" + d + "] = " + perm, "D[" + c + "][" + perm + "] = " + nc]);
      positions.push(payload.length - 1 - i); states.push(nc);
      c = nc;
    }
    return { title: "Verhoeff — dihedral group D₅",
      intro: "Process digits right-to-left with c = 0. Permute each digit then combine: c = D[c][ P[(position) mod 8][digit] ]. The check is the inverse of the final c.",
      columns: ["Pos (from right)", "Digit", "Permute", "c = D[c][permuted]"], rows: rows,
      final: "check = inverse[" + c + "] = " + V_INV[c], check: String(V_INV[c]),
      stateLabel: "c", stateStart: 0, positions: positions, states: states };
  }
  function explainLuhn(payload){
    const rows = [], positions = [], states = []; let total = 0; const rev = Array.from(payload).reverse();
    for(let i=0;i<rev.length;i++){
      const ch = rev[i], v = valueOf(ch, DIGITS, "luhn");
      let contrib = v, note = String(v);
      if(i % 2 === 0){ const dbl = v * 2;
        if(dbl > 9){ contrib = dbl - 9; note = v + "×2 = " + dbl + " − 9 = " + contrib; }
        else { contrib = dbl; note = v + "×2 = " + contrib; } }
      total += contrib;
      rows.push([String(i+1), ch, note, String(total)]);
      positions.push(payload.length - 1 - i); states.push(total);
    }
    const check = (10 - (total % 10)) % 10;
    return { title: "Luhn — Mod 10",
      intro: "From the right, double every second digit (subtract 9 if over 9), sum the contributions, then check = (10 − sum mod 10) mod 10.",
      columns: ["Pos (from right)", "Digit", "Contribution", "Running sum"], rows: rows,
      final: "check = (10 − " + total + " mod 10) mod 10 = " + check, check: String(check),
      stateLabel: "sum", stateStart: 0, positions: positions, states: states };
  }
  ALGORITHMS["iso7064_mod11_2"].explain  = function(p){ return explainPure("iso7064_mod11_2", p, DIGITS, 11, 2, 1, DIGITS + "X"); };
  ALGORITHMS["iso7064_mod37_2"].explain  = function(p){ return explainPure("iso7064_mod37_2", p, ALNUM36, 37, 2, 1, ALNUM36 + "*"); };
  ALGORITHMS["iso7064_mod97_10"].explain = function(p){ return explainPure("iso7064_mod97_10", p, DIGITS, 97, 10, 2, DIGITS); };
  ALGORITHMS["iso7064_mod11_10"].explain = function(p){ return explainHybrid("iso7064_mod11_10", p, DIGITS, 10, false, DIGITS, false); };
  ALGORITHMS["iso7064_mod37_36"].explain = function(p){ return explainHybrid("iso7064_mod37_36", p, ALNUM36, 36, false, ALNUM36, false); };
  ALGORITHMS["iso7064_letters1"].explain = function(p){ return explainHybrid("iso7064_letters1", p, ALNUM36, 26, true, LETTERS26, false); };
  ALGORITHMS["iso7064_letters2"].explain = function(p){ return explainHybrid("iso7064_letters2", p, ALNUM36, 36, false, null, true); };
  ALGORITHMS["damm"].explain     = explainDamm;
  ALGORITHMS["verhoeff"].explain = explainVerhoeff;
  ALGORITHMS["luhn"].explain     = explainLuhn;
  ALGORITHMS["none"].explain     = function(){ return { title: "No check character",
    intro: "This method appends no check character — there is nothing to derive.",
    columns: [], rows: [], final: "", check: "",
    stateLabel: "", stateStart: 0, positions: [], states: [] }; };

  // ---- Normalization ----
  const DASHES = "‐‑‒–—―−";  // matches _DASHES (check_characters.py:497)
  function unifyDashes(s){
    let out = "";
    for(const ch of s) out += (DASHES.indexOf(ch) >= 0) ? "-" : ch;
    return out;
  }
  const DEFAULT_RULES = {strip_delimiters:"-/ ", uppercase:true, unify_unicode_dashes:true, keep_only:null};

  function normalize(value, rules){
    rules = rules || DEFAULT_RULES;
    if(value === null || value === undefined) return "";
    if(typeof value === "number" && Number.isNaN(value)) return "";
    let s = String(value);
    if(rules.unify_unicode_dashes) s = unifyDashes(s);
    if(rules.uppercase) s = s.toUpperCase();
    if(rules.strip_delimiters){
      const strip = new Set(Array.from(rules.strip_delimiters));
      s = Array.from(s).filter(function(ch){ return !strip.has(ch); }).join("");
    }
    if(rules.keep_only !== null && rules.keep_only !== undefined){
      const keep = new Set(Array.from(rules.keep_only));
      s = Array.from(s).filter(function(ch){ return keep.has(ch); }).join("");
    }
    return s;
  }

  function applySource(normalized, source){
    if(typeof source === "function") return source(normalized);
    if(source === "normalized_id") return normalized;
    // Unicode-aware to mirror Python re \d/\D (which match every Unicode decimal
    // digit, category Nd), so a non-ASCII digit is KEPT — the downstream ASCII
    // alphabet then rejects it, exactly as Python does. ASCII /\d/ would silently
    // strip it and flip the verdict.
    if(source === "sequence_only"){ const m = normalized.match(/(\p{Nd}+)\P{Nd}*$/u); return m ? m[1] : ""; }
    if(source === "digits_only") return normalized.replace(/[^\p{Nd}]/gu, "");
    throw new CheckError("unknown source spec " + JSON.stringify(source));
  }

  // ---- Schemes ----
  function makeScheme(o){
    const s = Object.assign({
      algorithm:"none", source:"normalized_id", placement:"append", delimiter:"-",
      normalize_rules: DEFAULT_RULES, enabled:true,
    }, o || {});
    s.active = !!s.enabled && s.algorithm !== "none";
    return s;
  }
  const CHECK_SCHEMES = {
    inspire_default: makeScheme({algorithm:"iso7064_mod37_36", source:"normalized_id", placement:"append"}),
    none: makeScheme({algorithm:"none", enabled:false}),
  };
  function getScheme(name){
    if(name === null || name === undefined) return CHECK_SCHEMES.none;
    if(typeof name === "object") return name;
    const s = CHECK_SCHEMES[name];
    if(!s) throw new CheckError("unknown check scheme " + JSON.stringify(name));
    return s;
  }

  function computeCheck(raw, scheme){
    const sch = getScheme(scheme);
    if(!sch.active) return "";
    if(raw === null || raw === undefined) throw new CheckError("cannot compute a check character for a missing ID");
    const algo = ALGORITHMS[sch.algorithm];
    const payload = applySource(normalize(raw, sch.normalize_rules), sch.source);
    if(payload === "") throw new CheckError("ID " + JSON.stringify(raw) + " normalizes to empty; nothing to compute a check over");
    return algo.compute(payload);
  }

  function appendCheck(raw, scheme){
    const sch = getScheme(scheme);
    const chk = computeCheck(raw, sch);
    if(!chk) return String(raw);
    if(sch.placement === "append_after_delimiter") return String(raw) + sch.delimiter + chk;
    return String(raw) + chk;
  }

  function preparePayload(full, scheme){
    const sch = getScheme(scheme);
    const norm = normalize(full, sch.normalize_rules);
    if(!sch.active) return [applySource(norm, sch.source), null];
    const algo = ALGORITHMS[sch.algorithm];
    const n = algo.nCheckChars;
    if(n === 0 || norm.length <= n) return [applySource(norm, sch.source), null];
    const check = norm.slice(-n);
    const remainder = norm.slice(0, -n);
    return [applySource(remainder, sch.source), check];
  }

  function validateIdCheck(full, scheme){
    const sch = getScheme(scheme);
    if(!sch.active) return true;
    if(full === null || full === undefined) return false;
    const algo = ALGORITHMS[sch.algorithm];
    const pair = preparePayload(full, sch);
    const payload = pair[0], check = pair[1];
    if(check === null || payload === "") return false;
    try { return algo.compute(payload) === check; }
    catch(e){ return false; }
  }

  G.QRCheck = {
    DIGITS, LETTERS26, ALNUM36, CheckError, DEFAULT_RULES,
    ALGORITHMS, CHECK_SCHEMES, makeScheme, getScheme,
    normalize, applySource, computeCheck, appendCheck, preparePayload, validateIdCheck,
  };
})();
/* ---- single-field validator (factory: one instance per config/rule) ---- */
window.QRIDSingleInit = function(QRID_CONFIG){
  "use strict";
  var Q = window.QRCheck;

  /* ---- resolve the validation mode from the config ---- */
  var configError = QRID_CONFIG.configErrorOverride || "";
  var CHECK_MODE = false, REGEX_ONLY = false, fullRe = null;
  var algoName = QRID_CONFIG.algorithm;
  if(!configError && algoName !== "none" && !Q.ALGORITHMS[algoName]){
    configError = "Unknown algorithm \"" + algoName + "\". Valid: " +
      Object.keys(Q.ALGORITHMS).join(", ") + ".";
  }
  if(!configError && QRID_CONFIG.idPattern){
    var rawPatS = String(QRID_CONFIG.idPattern);
    if(/\\[AZ]/.test(rawPatS)){
      /* JS would silently treat \A / \Z as literal letters — a Python-only trap */
      configError = "idPattern uses Python-only \\A or \\Z anchors; patterns are JavaScript " +
        "regex — use ^ and $ instead (anchors are optional anyway).";
    } else try {
      var p = rawPatS.replace(/^\^/, "").replace(/\$$/, "");
      fullRe = new RegExp("^(?:" + p + ")$");
    } catch(e){
      configError = "idPattern is not a valid JavaScript regex: " + e.message +
        " (Python-only syntax like (?P<name>...) or inline flags is not supported).";
    }
  }
  if(!configError){
    CHECK_MODE = (algoName !== "none");
    REGEX_ONLY = (!CHECK_MODE && !!fullRe);
    if(!CHECK_MODE && !REGEX_ONLY){
      configError = "algorithm is \"none\" and no idPattern is set — nothing to validate. " +
        "Set the check algorithm your IDs were minted with, or (for a legacy project " +
        "without check characters) set idPattern to your ID regex.";
    }
  }
  if(!configError && CHECK_MODE){
    var srcS = QRID_CONFIG.source || "normalized_id";
    if(srcS !== "normalized_id" && srcS !== "digits_only" && srcS !== "sequence_only"){
      configError = 'Unknown source "' + srcS + '" — use normalized_id, digits_only or sequence_only.';
    }
  }
  var scheme = CHECK_MODE ? Q.makeScheme({
    algorithm: algoName, source: QRID_CONFIG.source,
    placement: "append", delimiter: "-",
    normalize_rules: { strip_delimiters: QRID_CONFIG.strip, uppercase: true,
                       unify_unicode_dashes: true, keep_only: null },
    enabled: true
  }) : null;
  var nCheck = CHECK_MODE ? Q.ALGORITHMS[algoName].nCheckChars : 0;
  /* regex target: trimmed + uppercased + unicode dashes unified, but separators
     KEPT — user patterns are written against the printed form (FC1-1001). */
  var REGEX_RULES = { strip_delimiters: "", uppercase: true,
                      unify_unicode_dashes: true, keep_only: null };

  function styleMsg(el, kind){   /* kind: true=ok, false=bad, "info"=typing, "err"=config error */
    var c = (kind === true) ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "info") ? "#c9dbf5;background:#eef3fb;color:#3a567f"
          : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }

  /* ---- progressive format guidance ------------------------------------------
     Tokenize a SIMPLE pattern (literals, [classes], \d, {n} {n,} {n,m} ? + )
     into atoms so that, while the fielder types, we can say exactly what is
     still remaining ("2 more digits, then 1 letter"). Patterns using groups,
     alternation or other advanced syntax return null -> guidance is skipped and
     validation stays binary (full regex remains the authority either way). */
  function tokenizePattern(pat){
    pat = String(pat).replace(/^\^/, "").replace(/\$$/, "");
    var atoms = [], i = 0, n = pat.length;
    function quant(){
      var c = pat.charAt(i);
      if(c === "{"){
        var m = /^\{(\d+)(,(\d*)?)?\}/.exec(pat.slice(i));
        if(!m) return null;
        i += m[0].length;
        var lo = parseInt(m[1], 10);
        var hi = (m[2] === undefined) ? lo : (m[3] ? parseInt(m[3], 10) : 64);
        return [lo, hi];
      }
      if(c === "?"){ i++; return [0, 1]; }
      if(c === "+"){ i++; return [1, 64]; }
      if(c === "*"){ i++; return [0, 64]; }
      return [1, 1];
    }
    while(i < n){
      var ch = pat.charAt(i), src, desc;
      if(ch === "(" || ch === ")" || ch === "|") return null;      /* unsupported */
      if(ch === "["){
        var j = i + 1, body = "";
        if(pat.charAt(j) === "^"){ body += "^"; j++; }
        for(; j < n; j++){
          if(pat.charAt(j) === "\\"){ body += pat.substr(j, 2); j++; continue; }
          if(pat.charAt(j) === "]") break;
          body += pat.charAt(j);
        }
        if(j >= n) return null;
        i = j + 1;
        src = "[" + body + "]";
        desc = (body === "0-9") ? "digit" : (body === "A-Z") ? "letter"
             : (body === "0-9A-Z" || body === "A-Z0-9") ? "letter/digit"
             : "one of [" + body + "]";
      } else if(ch === "\\"){
        var e = pat.charAt(i + 1); i += 2;
        if(e === "d"){ src = "[0-9]"; desc = "digit"; }
        else if(e === "w" || e === "s" || e === "D" || e === "W" || e === "S") return null;
        else { src = "\\" + e; desc = '"' + e + '"'; }
      } else if(ch === "."){
        i++; src = "[\\s\\S]"; desc = "any character";
      } else {
        i++; src = ch.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); desc = '"' + ch + '"';
      }
      var q = quant();
      if(!q) return null;
      var re; try { re = new RegExp("^(?:" + src + ")$"); } catch(_e){ return null; }
      atoms.push({ re: re, min: q[0], max: q[1], desc: desc });
    }
    return atoms.length ? atoms : null;
  }
  var atoms = fullRe ? tokenizePattern(QRID_CONFIG.idPattern) : null;
  function atomPhrase(count, a){
    return (count > 1 ? count + " x " : "") + a.desc;
  }
  function remainingText(atomIdx, stillNeeded){
    var parts = [], k;
    if(stillNeeded > 0) parts.push(atomPhrase(stillNeeded, atoms[atomIdx]));
    for(k = atomIdx + 1; k < atoms.length && parts.length < 5; k++){
      if(atoms[k].min > 0) parts.push(atomPhrase(atoms[k].min, atoms[k]));
      else parts.push("optionally " + atomPhrase(atoms[k].max, atoms[k]));
    }
    if(k < atoms.length) parts.push("...");
    return parts.join(", then ");
  }
  /* Walk the typed value along the atoms: 'match' | {partial} | {mismatch}.
     Greedy per atom; the full regex stays the final authority on completeness. */
  function walkPattern(s){
    var i = 0, a;
    for(var ai = 0; ai < atoms.length; ai++){
      a = atoms[ai];
      var count = 0;
      while(count < a.max && i < s.length && a.re.test(s.charAt(i))){ i++; count++; }
      if(count < a.min){
        if(i >= s.length) return { state: "partial", atomIdx: ai, needed: a.min - count };
        return { state: "mismatch", pos: i, expected: a.desc, got: s.charAt(i) };
      }
    }
    if(i < s.length) return { state: "mismatch", pos: i, expected: "end of ID", got: s.charAt(i) };
    return { state: "match" };
  }

  /* Deliberately NOT a copy-paste "did you mean" ID: if the typo is in the BODY,
     a re-stamped suggestion would be a perfectly-valid-looking WRONG participant.
     Instead, state conditionally what the final character(s) would be. */
  function suggestion(raw){
    if(!QRID_CONFIG.suggestFix || QRID_CONFIG.source !== "normalized_id") return "";
    try {
      var norm = Q.normalize(raw, scheme.normalize_rules);
      if(norm.length <= nCheck) return "";
      var expected = Q.ALGORITHMS[algoName].compute(norm.slice(0, -nCheck));
      return ' If everything before the last ' + (nCheck > 1 ? nCheck + ' characters' : 'character') +
        ' is correct, the ID should end in <b style="font-family:monospace">' + expected + "</b>." +
        " Otherwise the typo is earlier in the ID.";
    } catch(e){ return ""; }
  }
  function verdict(v, isFinal){
    /* returns {ok: true|false|"info", html} for a non-empty value.
       Order: 1) FORMAT (regex, with live remaining-guidance) 2) CHECK character —
       so the fielder always knows WHICH kind of error they are making. */
    if(fullRe){
      var normR = Q.normalize(v, REGEX_RULES).trim();
      if(!fullRe.test(normR)){
        if(atoms){
          var w = walkPattern(normR);
          if(w.state === "partial"){
            var rem = remainingText(w.atomIdx, w.needed);
            if(!isFinal) return { ok: "info",
              html: "&#8230; format OK so far &mdash; remaining: <b>" + rem + "</b>." };
            return { ok: false,
              html: "&#10007; FORMAT error &mdash; the ID is incomplete. Still remaining: <b>" + rem + "</b>." };
          }
          if(w.state === "mismatch"){
            return { ok: false, html: "&#10007; FORMAT error at character " + (w.pos + 1) +
              ": expected " + w.expected + ", got <b style=\"font-family:monospace\">" +
              String(w.got).replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</b>." +
              (CHECK_MODE ? " (The check character is only tested once the format is right.)" : "") };
          }
        }
        return { ok: false, html: "&#10007; FORMAT error &mdash; this does <b>not</b> match this " +
          "project's ID format. Please re-scan or re-type it." };
      }
      if(REGEX_ONLY){
        return { ok: true, html: "&#10003; ID format OK. (This project's IDs carry no check " +
          "character, so typos that keep the format cannot be detected.)" };
      }
      if(Q.validateIdCheck(v, scheme)){
        return { ok: true, html: "&#10003; Format OK <b>and</b> check character verified." };
      }
      return { ok: false, html: "&#10007; The format is correct, but the CHECK character does " +
        "<b>not</b> match &mdash; one of the characters is mistyped. Please re-scan or re-type it." +
        suggestion(v) };
    }
    if(Q.validateIdCheck(v, scheme)){
      return { ok: true, html: "&#10003; ID verified &mdash; the check character matches." };
    }
    return { ok: false, html: "&#10007; This ID's check character does <b>not</b> match &mdash; " +
      "probably a typo or mis-scan. Please re-scan or re-type it." + suggestion(v) };
  }

  /* ---- optional save blocking ------------------------------------------------
     blockSave: "off" (warn-only) | "confirm" (Save anyway? dialog) | "hard"
     (refuse to save). Guards both real form submits and REDCap's save buttons
     (which submit programmatically and never fire a submit event). */
  var BLOCK = QRID_CONFIG.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  function registerBlocker(input, fieldName){
    if(BLOCK === "off" || configError) return;
    input.__qridBlockMode = BLOCK;
    input.__qridFieldName = fieldName;
    var G2 = window.__QRIDGuard = window.__QRIDGuard || { items: [], armed: false };
    G2.items.push(input);
    if(G2.armed) return;
    G2.armed = true;
    function guard(e){
      var bad = G2.items.filter(function(el){ return el.__qridInvalid; });
      if(!bad.length) return;
      var names = bad.map(function(el){ return el.__qridFieldName; }).join(", ");
      var hard = bad.some(function(el){ return el.__qridBlockMode === "hard"; });
      if(hard){
        e.preventDefault();
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();
        window.alert("Cannot save yet — please fix the flagged ID field(s): " + names);
        try { bad[0].focus(); } catch(_f){}
        return;
      }
      if(!window.confirm("ID validation FAILED for: " + names + "\n\nSave anyway?")){
        e.preventDefault();
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      }
    }
    document.addEventListener("submit", guard, true);
    document.addEventListener("click", function(e){
      var t = e.target;
      while(t && t.getAttribute){
        var nm = (t.getAttribute("name") || t.id || "");
        if(nm.indexOf("submit-btn-save") === 0){ guard(e); return; }
        t = t.parentNode;
      }
    }, true);
  }
  /* Safe field lookup (no CSS-selector string building — field names with
     special characters cannot break it). */
  function findField(name){
    var els = document.getElementsByName ? document.getElementsByName(name) : [];
    for(var i = 0; i < els.length; i++){
      var tag = (els[i].tagName || "").toLowerCase();
      if(tag === "input" || tag === "textarea") return els[i];
    }
    return null;
  }
  function attach(fieldName){
    var input = findField(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound")) return true;  /* idempotent */
    if(input.setAttribute) input.setAttribute("data-qrid-bound", "1");
    var msg = document.createElement("div");
    msg.style.display = "none";
    input.parentNode.insertBefore(msg, input.nextSibling);
    if(configError){
      styleMsg(msg, "err");
      msg.innerHTML = "&#9888; ID-check configuration error: " + configError;
      return true;
    }
    function check(isFinal){
      var v = (input.value || "").trim();
      if(!v){ msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false; return; }
      var r = verdict(v, !!isFinal);
      styleMsg(msg, r.ok);
      input.style.outline = (r.ok === true) ? "2px solid #2e9e44"
                          : (r.ok === "info") ? "2px solid #0067c0" : "2px solid #c62828";
      input.__qridInvalid = (r.ok !== true);          /* "info" (still typing) also blocks a save */
      msg.innerHTML = r.html;
    }
    input.addEventListener("input", function(){ check(false); });
    input.addEventListener("change", function(){ check(true); });
    input.addEventListener("blur", function(){ check(true); });
    check(true);
    registerBlocker(input, fieldName);
    return true;
  }
  /* per-field registry (testing / power users) */
  window.QRIDValidators = window.QRIDValidators || {};
  (QRID_CONFIG.fields || []).forEach(function(f){
    window.QRIDValidators[f] = { type: "single",
      mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY, configError: configError,
              guidance: !!atoms, blockSave: BLOCK },
      test: function(v, isFinal){ return configError ? null : verdict(v, isFinal !== false); } };
  });
  function boot(){
    var pending = (QRID_CONFIG.fields || []).slice();
    function sweep(){
      pending = pending.filter(function(f){ return !attach(f); });
      return pending.length === 0;
    }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){                 /* REDCap builds the form progressively */
      tries++;
      if(sweep() || tries >= 20) clearInterval(timer);
    }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      var mo = new MutationObserver(function(){ if(sweep()) mo.disconnect(); });
      mo.observe(document.body, { childList: true, subtree: true });   /* late-rendered fields */
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
};
/* ---- pooled-field validator (factory: one instance per config/rule) ---- */
window.QRIDPooledInit = function(QRID_MULTI_CONFIG){
  "use strict";
  var Q = window.QRCheck;
  var ALPHA = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

  /* ---- resolve the validation mode from the config ---- */
  var configError = QRID_MULTI_CONFIG.configErrorOverride || "";
  var CHECK_MODE = false, REGEX_ONLY = false, fullRe = null;
  var algoName = QRID_MULTI_CONFIG.algorithm;
  if(!configError && algoName !== "none" && !Q.ALGORITHMS[algoName]){
    configError = "Unknown algorithm \"" + algoName + "\". Valid: " +
      Object.keys(Q.ALGORITHMS).join(", ") + ".";
  }
  if(!configError && QRID_MULTI_CONFIG.idPattern){
    var rawPatP = String(QRID_MULTI_CONFIG.idPattern);
    if(/\\[AZ]/.test(rawPatP)){
      /* JS would silently treat \A / \Z as literal letters — a Python-only trap */
      configError = "idPattern uses Python-only \\A or \\Z anchors; patterns are JavaScript " +
        "regex — use ^ and $ instead (anchors are optional anyway).";
    } else try {
      var _p = rawPatP.replace(/^\^/, "").replace(/\$$/, "");
      fullRe = new RegExp("^(?:" + _p + ")$");
    } catch(e){
      configError = "idPattern is not a valid JavaScript regex: " + e.message +
        " (Python-only syntax like (?P<name>...) or inline flags is not supported).";
    }
  }
  if(!configError){
    CHECK_MODE = (algoName !== "none");
    REGEX_ONLY = (!CHECK_MODE && !!fullRe);
    if(!CHECK_MODE && !REGEX_ONLY){
      configError = "algorithm is \"none\" and no idPattern is set — nothing to validate. " +
        "Set the check algorithm your IDs were minted with, or (for a legacy project " +
        "without check characters) set idPattern to your ID regex.";
    }
  }
  var srcP = QRID_MULTI_CONFIG.source || "normalized_id";
  if(!configError && CHECK_MODE &&
     srcP !== "normalized_id" && srcP !== "digits_only" && srcP !== "sequence_only"){
    configError = 'Unknown source "' + srcP + '" — use normalized_id, digits_only or sequence_only.';
  }
  var scheme = CHECK_MODE ? Q.makeScheme({
    algorithm: algoName, source: srcP,
    placement: "append", delimiter: "-",
    normalize_rules: { strip_delimiters: QRID_MULTI_CONFIG.strip || "", uppercase: true,
                       unify_unicode_dashes: true, keep_only: null },
    enabled: true
  }) : null;
  var nCheck = CHECK_MODE ? Q.ALGORITHMS[algoName].nCheckChars : 0;
  /* ---- validate the length configuration ---- */
  function isPosInt(x){ return typeof x === "number" && isFinite(x) && x > 0 && x % 1 === 0; }
  var minLen = QRID_MULTI_CONFIG.idMinLen === undefined ? 8 : QRID_MULTI_CONFIG.idMinLen;
  var maxLen = QRID_MULTI_CONFIG.idMaxLen === undefined ? 14 : QRID_MULTI_CONFIG.idMaxLen;
  var LENS = [];
  if(!configError && QRID_MULTI_CONFIG.idLengths != null){
    var lensCfg = QRID_MULTI_CONFIG.idLengths;
    if(!(lensCfg instanceof Array) || !lensCfg.length || !lensCfg.every(isPosInt)){
      configError = "idLengths must be a list of positive whole numbers, e.g. [10] — got " +
        JSON.stringify(lensCfg) + ".";
    } else {
      LENS = lensCfg.slice().sort(function(a, b){ return a - b; });
      minLen = LENS[0]; maxLen = LENS[LENS.length - 1];
      /* a member length equal to the sum of two others would let one token
         swallow two real members */
      for(var _a = 0; _a < LENS.length && !configError; _a++)
        for(var _b = _a; _b < LENS.length && !configError; _b++)
          if(LENS.indexOf(LENS[_a] + LENS[_b]) >= 0)
            configError = "idLengths " + JSON.stringify(LENS) + " is unsafe: " +
              (LENS[_a] + LENS[_b]) + " = " + LENS[_a] + " + " + LENS[_b] +
              ", so one \"member\" could swallow two real ones. Split such projects into separate fields/rules.";
    }
  } else if(!configError){
    if(!isPosInt(minLen) || !isPosInt(maxLen)){
      configError = "idMinLen/idMaxLen must be positive whole numbers.";
    } else if(maxLen < minLen){
      configError = "idMaxLen (" + maxLen + ") is smaller than idMinLen (" + minLen + ").";
    } else if(maxLen >= 2 * minLen){
      configError = "idMaxLen (" + maxLen + ") must be LESS than 2 x idMinLen (" + (2 * minLen) +
        ") so one \"member\" can never swallow two real ones. Narrow the range, or set idLengths " +
        "to your exact ID length(s).";
    }
    minLen = Math.max(minLen, nCheck + 1);
    for(var _L = minLen; _L <= maxLen; _L++) LENS.push(_L);
  }

  /* Mirror study_id_patterns.validate_concatenated_ids cleaning: unify unicode
     dashes, uppercase, then keep ONLY A-Z 0-9 (+ configured keepChars) — so
     spaces, commas, newlines and stray separators all disappear before splitting.
     Also automatically kept: the check algorithm's special characters (e.g. the
     "*" that iso7064_mod37_2 can emit) and any literal separator the ID regex
     itself uses (-, /, _, ...), so the printed form still matches. */
  var KEEP = ALPHA + (QRID_MULTI_CONFIG.keepChars || "");
  if(CHECK_MODE){
    var CA = Q.ALGORITHMS[algoName].checkAlphabet || "";
    for(var _c = 0; _c < CA.length; _c++)
      if(KEEP.indexOf(CA.charAt(_c)) < 0) KEEP += CA.charAt(_c);
  }
  if(fullRe){
    var _pat = String(QRID_MULTI_CONFIG.idPattern), _meta = "\\^$.|?*+()[]{}";
    for(var _pi = 0; _pi < _pat.length; _pi++){
      var _pc = _pat.charAt(_pi);
      if(_pc === "\\"){                       /* escaped char: literal if it is a metachar */
        _pi++; _pc = _pat.charAt(_pi);
        if(_pc && _meta.indexOf(_pc) >= 0 && KEEP.indexOf(_pc) < 0) KEEP += _pc;
        continue;
      }
      if(_meta.indexOf(_pc) < 0 && !/[A-Za-z0-9]/.test(_pc) && KEEP.indexOf(_pc) < 0) KEEP += _pc;
    }
  }
  function clean(v){
    return Q.normalize(v, { strip_delimiters: "", uppercase: true, unify_unicode_dashes: true,
      keep_only: KEEP });
  }
  /* A verified member. Check mode: the check character verifies (and the shape
     matches, when a pattern is also given) — the check char alone marks where
     one ID ends and the next begins. Regex-only mode (legacy projects, no check
     character): the shape IS the whole test. */
  function verifies(t){
    if(fullRe && !fullRe.test(t)) return false;
    if(REGEX_ONLY) return true;
    return Q.validateIdCheck(t, scheme);
  }
  function parse(raw){
    var s = clean(raw);
    var N = s.length;
    /* Optimal segmentation into verified members + junk, scored lexicographically:
         1. MAXIMIZE the number of verified members,
         2. then MINIMIZE the number of junk runs,
         3. then MINIMIZE the longest member length,
         4. then PREFER SHORTER members overall (= more junk characters).
       (1) defeats chance check-collisions that would swallow several members
       into one verifying blob. (2) keeps clean pools clean (zero junk runs) and
       stops 1-in-36 prefix collisions from shaving a member and leaving crumbs.
       (3) breaks equal-junk ties toward ordinary-length members (phantoms are
       freak-length). (4) final determinism.
       STRUCTURAL GUARANTEE: with idMaxLen < 2 x idMinLen (the defaults: 14 < 16)
       no single token can span two real members, so merged-member phantoms are
       impossible by construction — a false "all verified" then requires the
       corrupted member ITSELF to carry a valid check (the irreducible ~1-in-36
       residual that any single-check-character system has, same as the
       single-ID field).
       sc[i] = best score for suffix s[i:]; move = member length, 0 = junk char. */
    var sc = new Array(N + 1);
    sc[N] = { tok: 0, maxL: 0, runs: 0, chars: 0, startsJunk: false, move: -1 };
    function betterThan(a, b){
      if(a.tok !== b.tok) return a.tok > b.tok;     /* most members            */
      if(a.runs !== b.runs) return a.runs < b.runs; /* fewest junk gaps        */
      if(a.maxL !== b.maxL) return a.maxL < b.maxL; /* no freak-length members */
      return a.chars > b.chars;                     /* shorter members overall */
    }
    for(var i = N - 1; i >= 0; i--){
      var c = sc[i + 1];
      var best = { tok: c.tok, maxL: c.maxL, runs: c.runs + (c.startsJunk ? 0 : 1),
                   chars: c.chars + 1, startsJunk: true, move: 0 };
      for(var li = 0; li < LENS.length; li++){     /* ascending: shortest member wins ties */
        var L = LENS[li];
        if(L > N - i) break;
        if(!verifies(s.substr(i, L))) continue;
        var ch = sc[i + L];
        var cand = { tok: ch.tok + 1, maxL: (L > ch.maxL ? L : ch.maxL),
                     runs: ch.runs, chars: ch.chars, startsJunk: false, move: L };
        if(betterThan(cand, best)) best = cand;
      }
      sc[i] = best;
    }
    var segs = [], pos = 0, junk = "";
    function flushJunk(){ if(junk){ segs.push({type:"junk", text:junk}); junk = ""; } }
    while(pos < N){
      var m = sc[pos].move;
      if(m > 0){
        flushJunk();
        segs.push({type:"id", id: s.substr(pos, m), valid: true});
        pos += m;
      } else {
        junk += s.charAt(pos);
        pos++;
      }
    }
    flushJunk();
    /* Check+pattern mode: re-scan junk runs for well-formed-but-wrong-check members
       so they surface as their own X chips instead of anonymous leftover text.
       (Not in regex-only mode — there the shape IS the test, so a leftover that
       matched the shape would already be a member.) */
    if(fullRe && CHECK_MODE){
      var out = [];
      for(var g = 0; g < segs.length; g++){
        if(segs[g].type !== "junk"){ out.push(segs[g]); continue; }
        var rest = segs[g].text, buf = "";
        while(rest.length){
          var hit = "";
          var lim2 = Math.min(maxLen, rest.length);
          for(var L2 = minLen; L2 <= lim2 && !hit; L2++){
            if(fullRe.test(rest.substr(0, L2))) hit = rest.substr(0, L2);
          }
          if(hit){
            if(buf){ out.push({type:"junk", text: buf}); buf = ""; }
            out.push({type:"id", id: hit, valid: false});
            rest = rest.slice(hit.length);
          } else {
            buf += rest.charAt(0);
            rest = rest.slice(1);
          }
        }
        if(buf) out.push({type:"junk", text: buf});
      }
      segs = out;
    }
    return segs;
  }
  var api = { clean: clean, parse: parse,              /* exposed for testing / power users */
              mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY, configError: configError } };
  window.QRIDMulti = api;                              /* back-compat: last pooled instance */
  window.QRIDValidators = window.QRIDValidators || {};
  (QRID_MULTI_CONFIG.fields || []).forEach(function(f){
    window.QRIDValidators[f] = { type: "pooled", mode: api.mode, parse: parse, clean: clean };
  });

  function esc(t){ return String(t).replace(/&/g, "&amp;").replace(/</g, "&lt;"); }
  function chip(text, kind){
    var c = (kind === "ok") ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "junk") ? "#e6d4a8;background:#fbf6e8;color:#b26a00"
          : "#e0b4b0;background:#fbeceb;color:#c62828";   /* bad + dup share red */
    var mark = (kind === "ok") ? "&#10003;&nbsp;"
             : (kind === "dup") ? "&#8855;&nbsp;"          /* circled x = scanned again */
             : (kind === "bad") ? "&#10007;&nbsp;" : "?&nbsp;";
    var suffix = (kind === "dup") ? "&nbsp;(again!)" : "";
    return '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:10px;' +
      'font-family:monospace;font-size:12px;border:1px solid ' + c + '">' + mark + text + suffix + '</span>';
  }
  function render(msg, input){
    var v = (input.value || "").trim();
    if(!v){ msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false; return; }
    var segs = parse(v);
    var ids = segs.filter(function(x){ return x.type === "id"; });
    var junkSegs = segs.filter(function(x){ return x.type === "junk"; });
    var bad = ids.filter(function(x){ return !x.valid; });
    var seen = {}, dups = 0;
    ids.forEach(function(x){ if(seen[x.id]) dups++; seen[x.id] = true; });
    var expected = QRID_MULTI_CONFIG.expectedIds;
    var problems = [];
    if(!ids.length) problems.push("no ID could be read");
    if(bad.length) problems.push(bad.length + " check-character mismatch" + (bad.length > 1 ? "es" : ""));
    if(junkSegs.length) problems.push("leftover text that is not an ID");
    if(dups) problems.push(dups + " duplicate" + (dups > 1 ? "s" : ""));
    if(expected && ids.length !== expected) problems.push(ids.length + " IDs read but " + expected + " expected");
    var ok = (problems.length === 0);
    msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " +
      (ok ? "#bcd9bd;background:#eef7ef;color:#2e7d32" : "#e0b4b0;background:#fbeceb;color:#c62828");
    var okWord = REGEX_ONLY ? "all match the ID format &#10003; (no check character in this project)"
                            : "all verified &#10003;";
    var html = "<b>" + ids.length + " ID" + (ids.length === 1 ? "" : "s") + " read" +
      (ok ? " &mdash; " + okWord : " &mdash; " + esc(problems.join("; "))) + "</b><br>";
    var occ = {};
    segs.forEach(function(x){
      if(x.type === "id"){
        occ[x.id] = (occ[x.id] || 0) + 1;
        if(x.valid && occ[x.id] > 1) html += chip(esc(x.id), "dup");   /* repeat scans stand out */
        else html += chip(esc(x.id), x.valid ? "ok" : "bad");
      } else {
        html += chip(esc(x.text), "junk");
      }
    });
    msg.innerHTML = html;
    input.style.outline = ok ? "2px solid #2e9e44" : "2px solid #c62828";
    input.__qridInvalid = !ok;
  }
  /* ---- optional save blocking (same semantics as the single-ID script) ---- */
  var BLOCK = QRID_MULTI_CONFIG.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  function registerBlocker(input, fieldName){
    if(BLOCK === "off" || configError) return;
    input.__qridBlockMode = BLOCK;
    input.__qridFieldName = fieldName;
    var G2 = window.__QRIDGuard = window.__QRIDGuard || { items: [], armed: false };
    G2.items.push(input);
    if(G2.armed) return;
    G2.armed = true;
    function guard(e){
      var bad = G2.items.filter(function(el){ return el.__qridInvalid; });
      if(!bad.length) return;
      var names = bad.map(function(el){ return el.__qridFieldName; }).join(", ");
      var hard = bad.some(function(el){ return el.__qridBlockMode === "hard"; });
      if(hard){
        e.preventDefault();
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();
        window.alert("Cannot save yet — please fix the flagged ID field(s): " + names);
        try { bad[0].focus(); } catch(_f){}
        return;
      }
      if(!window.confirm("ID validation FAILED for: " + names + "\n\nSave anyway?")){
        e.preventDefault();
        if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      }
    }
    document.addEventListener("submit", guard, true);
    document.addEventListener("click", function(e){
      var t = e.target;
      while(t && t.getAttribute){
        var nm = (t.getAttribute("name") || t.id || "");
        if(nm.indexOf("submit-btn-save") === 0){ guard(e); return; }
        t = t.parentNode;
      }
    }, true);
  }
  /* Safe field lookup (no CSS-selector string building). */
  function findField(name){
    var els = document.getElementsByName ? document.getElementsByName(name) : [];
    for(var i = 0; i < els.length; i++){
      var tag = (els[i].tagName || "").toLowerCase();
      if(tag === "input" || tag === "textarea") return els[i];
    }
    return null;
  }
  function attach(fieldName){
    var input = findField(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound")) return true;  /* idempotent */
    if(input.setAttribute) input.setAttribute("data-qrid-bound", "1");
    var msg = document.createElement("div");
    msg.style.display = "none";
    input.parentNode.insertBefore(msg, input.nextSibling);
    if(configError){
      msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
        "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
      msg.innerHTML = "&#9888; ID-check configuration error: " + configError;
      return true;
    }
    function check(){ render(msg, input); }
    input.addEventListener("input", check);
    input.addEventListener("change", check);
    input.addEventListener("blur", check);
    check();
    registerBlocker(input, fieldName);
    return true;
  }
  function boot(){
    var pending = (QRID_MULTI_CONFIG.fields || []).slice();
    function sweep(){
      pending = pending.filter(function(f){ return !attach(f); });
      return pending.length === 0;
    }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){                 /* REDCap builds the form progressively */
      tries++;
      if(sweep() || tries >= 20) clearInterval(timer);
    }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      var mo = new MutationObserver(function(){ if(sweep()) mo.disconnect(); });
      mo.observe(document.body, { childList: true, subtree: true });   /* late-rendered fields */
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
};
/* ---- dispatcher (do not edit): one validator instance per rule ---- */
(function(){
  var C = QRID_COMBINED_CONFIG;
  var DEFAULT_KEYS = ["algorithm", "idPattern", "source", "strip", "suggestFix",
                      "keepChars", "idLengths", "idMinLen", "idMaxLen", "expectedIds",
                      "blockSave"];
  function cfgFor(rule){
    var cfg = {}, i, k;
    for(i = 0; i < DEFAULT_KEYS.length; i++){
      k = DEFAULT_KEYS[i];
      cfg[k] = (rule[k] !== undefined) ? rule[k] : C[k];
    }
    cfg.fields = rule.fields || [];
    return cfg;
  }
  var rules = (C.rules || []).slice();
  if(C.singleFields && C.singleFields.length) rules.push({ type: "single", fields: C.singleFields });
  if(C.pooledFields && C.pooledFields.length) rules.push({ type: "pooled", fields: C.pooledFields });
  /* a field may be owned by exactly ONE rule/list — duplicates would attach
     two contradictory validators to the same input. Detect them FIRST and bind
     the error box before any real validator can claim the field. */
  var counts = {};
  rules.forEach(function(rule){
    (rule.fields || []).forEach(function(f){ counts[f] = (counts[f] || 0) + 1; });
  });
  var dupFields = [];
  for(var df in counts) if(counts[df] > 1) dupFields.push(df);
  if(dupFields.length){
    window.QRIDSingleInit({ fields: dupFields, algorithm: "none",
      configErrorOverride: 'field(s) "' + dupFields.join('", "') +
        '" are listed in more than one rule/list — remove the duplicates so each field has exactly one validator.' });
  }
  rules.forEach(function(rule){
    var cfg = cfgFor(rule);
    cfg.fields = (rule.fields || []).filter(function(f){ return counts[f] === 1; });
    if(!cfg.fields.length) return;
    if(rule.type === "pooled"){
      window.QRIDPooledInit(cfg);
    } else if(rule.type === "single"){
      window.QRIDSingleInit(cfg);
    } else {
      cfg.configErrorOverride = 'rule for fields [' + cfg.fields.join(", ") +
        '] has unknown type "' + rule.type + '" — use "single" or "pooled".';
      window.QRIDSingleInit(cfg);
    }
  });
})();
