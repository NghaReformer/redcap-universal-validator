/* engine.js — vendored from the qrcode_generation repo:
   integrations/redcap/redcap_combined_id_check.js

   DEVIATIONS FROM UPSTREAM (full list + rationale in js/README.md, which is the
   authoritative provenance record — keep the two in sync):
     1. Config source: parsed from the inert <script type="application/json"
        id="inspire-validator-config"> node UniversalValidator.php emits (with
        window.INSPIRE_VALIDATOR_CONFIG as a test-only fallback) instead of a
        hardcoded literal. A config of another configShape is not read.
     2. UI-layer security hardening: HTML-escaping of config-derived text,
        catastrophic-regex (ReDoS) rejection at config time, per-field input
        length caps, pooled work caps, input debouncing, and MutationObserver
        cleanup.
     3. Accessibility: status messages are polite live regions wired to their
        inputs with aria-describedby/aria-invalid; save-block dialogs name
        fields by their visible label.
     4. Single public namespace: everything below the engine core lives in one
        IIFE and is exposed ONLY as window.INSPIREUniversalValidator; the
        legacy upstream globals (QRCheck, QRIDSingleInit, QRIDPooledInit,
        QRIDValidators, QRIDMulti, __QRIDGuard) are not published.
   The ENGINE CORE — the QRCheck IIFE with every algorithm and ISO/IEC 7064
   factory — is byte-identical to the verified upstream and is proven against
   tests/check_fixture.json by tests/parity_js.cjs on every push. When
   re-vendoring, port the deviations forward (see js/README.md) or the hardening
   is silently lost. */

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
/* ---- CONFIG ----
   The module reads its config inside the UI IIFE below (QRID_readConfig):
   first the inert JSON node UniversalValidator.php emits, then the
   window.INSPIRE_VALIDATOR_CONFIG fallback used by the test harnesses. The
   key reference that follows documents that config object. */
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
   gs1_mod10          GS1 Mod 10 - digits only, one check digit; weights 3,1
                      from the right (GTIN/EAN/UPC, GLN, SSCC, carrier tracking).
                      Catches all single-digit typos; misses adjacent swaps of
                      digits that differ by 5. For IDs that also contain
                      letters, set source to digits_only.
   aba_mod10          US ABA routing Mod 10 - digits only, one check digit;
                      weights 3,7,1 from the left. Catches all single-digit
                      typos; misses differ-by-5 adjacent swaps. Digit-only:
                      use source digits_only for letter-bearing IDs.
   mrz_mod10          ICAO 9303 MRZ Mod 10 - digits only, one check digit;
                      weights 7,3,1 from the left, no complement. Catches all
                      single-digit typos; misses differ-by-5 adjacent swaps.
                      Digit-only: use source digits_only for letter-bearing IDs.
   weighted_mod11     Weighted Mod 11 (ISBN-10 style) - digits only, one check
                      char that can be "X". For IDs up to 9 digits catches all
                      single typos and adjacent swaps; longer IDs develop a blind
                      spot, so prefer iso7064_mod11_2 (length-safe) for new work.
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

  // ---- Weighted-modulus family (mirrors WeightedModulus, check_characters.py) ----
  // One engine for the positional weighted-sum schemes; each named scheme is a
  // row in WEIGHTED_SCHEMES below, so a new scheme is one table entry, not code.
  function weightedModulus(spec){
    const M = spec.modulus, alpha = spec.inputAlphabet || DIGITS,
          checkAlphabet = spec.checkAlphabet, fromRight = spec.weightFrom === "right",
          mode = spec.weightMode || "cycle", weights = spec.weights || [],
          lStart = spec.linearStart || 0, lStep = spec.linearStep || 0,
          complement = spec.complement !== false;
    function compute(payload){
      if(!payload) throw new Error(spec.name + ": payload must be non-empty");
      const chars = Array.from(payload), n = chars.length; let total = 0;
      for(let i=0;i<n;i++){
        const d = valueOf(chars[i], alpha, spec.name);
        const pos = fromRight ? (n - 1 - i) : i;
        const w = mode === "cycle" ? weights[pos % weights.length] : (lStart + lStep * pos);
        total += d * w;
      }
      const v = complement ? ((M - total % M) % M) : (total % M);
      return checkAlphabet[v];
    }
    return makeAlgo({name:spec.name, inputAlphabet:alpha, checkAlphabet:checkAlphabet, nCheckChars:1, compute});
  }
  const WEIGHTED_SCHEMES = [
    {name:"gs1_mod10",      modulus:10, weightMode:"cycle",  weights:[3,1],   weightFrom:"right", complement:true,  checkAlphabet:DIGITS},
    {name:"aba_mod10",      modulus:10, weightMode:"cycle",  weights:[3,7,1], weightFrom:"left",  complement:true,  checkAlphabet:DIGITS},
    {name:"mrz_mod10",      modulus:10, weightMode:"cycle",  weights:[7,3,1], weightFrom:"left",  complement:false, checkAlphabet:DIGITS},
    {name:"weighted_mod11", modulus:11, weightMode:"linear", linearStart:2, linearStep:1, weightFrom:"right", complement:true, checkAlphabet:DIGITS + "X"},
  ];

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
  WEIGHTED_SCHEMES.forEach(function(s){ register(weightedModulus(s)); });

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
  function explainWeighted(spec, payload){
    const M = spec.modulus, alpha = spec.inputAlphabet || DIGITS,
          checkAlphabet = spec.checkAlphabet, fromRight = spec.weightFrom === "right",
          mode = spec.weightMode || "cycle", weights = spec.weights || [],
          lStart = spec.linearStart || 0, lStep = spec.linearStep || 0,
          complement = spec.complement !== false;
    const chars = Array.from(payload), n = chars.length;
    const rows = [], positions = [], states = []; let total = 0;
    for(let i=0;i<n;i++){
      const ch = chars[i], v = valueOf(ch, alpha, spec.name);
      const pos = fromRight ? (n - 1 - i) : i;
      const w = mode === "cycle" ? weights[pos % weights.length] : (lStart + lStep * pos);
      total += v * w;
      rows.push([String(i+1), ch, v + "×" + w + " = " + (v*w), String(total)]);
      positions.push(i); states.push(total);
    }
    const rem = total % M, cv = complement ? ((M - rem) % M) : rem, cstr = checkAlphabet[cv];
    const dir = fromRight ? "right" : "left";
    const wdesc = mode === "cycle" ? "cycle [" + weights.join(",") + "] from the " + dir
                                   : "start " + lStart + ", +" + lStep + " per step from the " + dir;
    const formula = complement ? "(" + M + " − " + total + " mod " + M + ") mod " + M : total + " mod " + M;
    return { title: "Weighted-sum modulus " + M + " — " + wdesc,
      intro: "Weight each digit by position (" + wdesc + "), sum, then check = "
        + (complement ? "(" + M + " − sum mod " + M + ") mod " + M : "sum mod " + M)
        + (M === 11 ? "  (a check value of 10 is written “X”)." : "."),
      columns: ["#", "Digit", "digit × weight", "Running sum"], rows: rows,
      final: "check = " + formula + " = " + cv + "  →  “" + cstr + "”", check: cstr,
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
  WEIGHTED_SCHEMES.forEach(function(s){ ALGORITHMS[s.name].explain = function(p){ return explainWeighted(s, p); }; });
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
/* ============================================================================
   UI MODULE (everything below is a module addition — NOT part of the vendored
   core; see js/README.md "intentional deviations"). One IIFE, one public name:
   window.INSPIREUniversalValidator. No other global survives loading.
   ============================================================================ */
(function(){
  "use strict";
  var G = (typeof globalThis !== "undefined") ? globalThis
        : (typeof window !== "undefined") ? window : this;
  /* Capture the verified core before its legacy global alias is retired at the
     bottom of this IIFE. */
  var Q = G.QRCheck;

  /* Config source: the inert JSON node emitted by UniversalValidator.php is
     authoritative; the window fallback exists for the Node test harnesses and
     legacy JavaScript-Injector use. */
  /* The config shape this script reads; twin of UniversalValidator::
     CONFIG_SHAPE, and tests/hook_php.php fails while the two differ. A page
     whose config states another shape was built for a different engine.js
     (a cached copy after an upgrade): the script checks nothing there and a
     notice says to reload, rather than read keys it does not know (QUA-7). A
     config that states no shape (the Node test suites) is read as it is. */
  var QRID_CONFIG_SHAPE = 1;
  var QRID_SHAPE_MISMATCH = null;
  function QRID_emptyConfig(context){
    return { singleFields: [], pooledFields: [], rules: [], algorithm: "iso7064_mod37_36",
      idPattern: null, alternates: null, source: "normalized_id", strip: "-/ _|\\", suggestFix: false,
      keepChars: "", idLengths: null, idMinLen: 8, idMaxLen: 14, expectedIds: null, blockSave: "off",
      context: context === "survey" ? "survey" : "form" };
  }
  function QRID_shapeChecked(cfg){
    if (cfg && typeof cfg === "object" && cfg.configShape !== undefined && cfg.configShape !== QRID_CONFIG_SHAPE){
      QRID_SHAPE_MISMATCH = String(cfg.configShape);
      return QRID_emptyConfig(cfg.context);
    }
    return cfg;
  }
  function QRID_readConfig(){
    try {
      if (typeof document !== "undefined" && document.getElementById) {
        var el = document.getElementById("inspire-validator-config");
        if (el && el.textContent) return QRID_shapeChecked(JSON.parse(el.textContent));
      }
    } catch(e){
      if (typeof console !== "undefined" && console.error)
        console.error("Universal Field Validator: could not parse config", e);
    }
    if (typeof window !== "undefined" && window.INSPIRE_VALIDATOR_CONFIG)
      return QRID_shapeChecked(window.INSPIRE_VALIDATOR_CONFIG);
    var none = QRID_emptyConfig("form");
    delete none.context;
    return none;
  }
  var QRID_COMBINED_CONFIG = QRID_readConfig();
  /* Survey pages face respondents who cannot act on configuration problems:
     technical config detail is muted there (UX-001) — admins still see every
     detail on data-entry forms, in the Configure dialog, and in the module log. */
  var QRID_IS_SURVEY = (QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.context === "survey");
  /* A config of another shape: the console always, and the page-level notice
     on a staff form (QRID_configErrorNotice is muted on surveys). */
  if (QRID_SHAPE_MISMATCH !== null){
    var QRID_shapeText = "This page's validator script (config shape " + QRID_CONFIG_SHAPE +
      ") does not match the server's settings (config shape " + QRID_SHAPE_MISMATCH +
      "), so nothing on this page is checked as you type. Reload the page without the browser cache " +
      "(Ctrl+F5). The server still audits each save.";
    if (typeof console !== "undefined" && console.warn) console.warn("Universal Field Validator: " + QRID_shapeText);
    var QRID_shapeNotice = function(){ try { QRID_configErrorNotice(QRID_shapeText); } catch(e){} };
    if (typeof document !== "undefined"){
      if (document.readyState === "loading" && document.addEventListener) document.addEventListener("DOMContentLoaded", QRID_shapeNotice);
      else QRID_shapeNotice();
    }
  }
  /* The project's Missing Data Codes. A field marked with REDCap's "M" button
     holds the code itself; REDCap does not validate it, and neither does a
     check that judges the value (php/modes.json "missingCodes":"skip"). Every
     factory except @UVREQUIRED treats it like a blank field. */
  var QRID_MISSING_CODES = (function(){
    var set = Object.create(null);
    var list = (QRID_COMBINED_CONFIG && Array.isArray(QRID_COMBINED_CONFIG.missingCodes)) ? QRID_COMBINED_CONFIG.missingCodes : [];
    for(var i = 0; i < list.length; i++){ if(typeof list[i] === "string" && list[i] !== "") set[list[i]] = true; }
    return set;
  })();
  function QRID_isMissingCode(value){ return QRID_codeIn(value, QRID_MISSING_CODES); }
  /* Whether value, trimmed, is a key of set (code => true). Twin of
     Logic::isMissingCode. */
  function QRID_codeIn(value, set){
    if(value === null || value === undefined) return false;
    /* PHP trim()'s set (space, tab, CR, LF, NUL, vertical tab), by index (AST-8) */
    var t = String(value), i = 0, j = t.length, c;
    while(i < j && ((c = t.charCodeAt(i)) === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11)) i++;
    while(j > i && ((c = t.charCodeAt(j - 1)) === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11)) j--;
    t = t.slice(i, j);
    return t !== "" && set[t] === true;
  }
  /* The date and datetime fields of this form shown day- or month-first
     (config.dateFormats: field => [type, format]). REDCap stores every date as
     Y-M-D, and the server compares that, so a condition on this page compares
     the same: QRID_logicValue turns what the form shows into the stored form.
     A value that is not a whole date yet is compared as typed. */
  var QRID_DATE_FORMATS = (function(){
    var out = Object.create(null);
    var m = QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.dateFormats;
    if(m && typeof m === "object" && !Array.isArray(m)){
      for(var k in m){
        if(!Object.prototype.hasOwnProperty.call(m, k)) continue;
        var fm = m[k];
        if(Array.isArray(fm) && (fm[0] === "date" || fm[0] === "datetime" || fm[0] === "datetime_seconds")
           && (fm[1] === "dmy" || fm[1] === "mdy")) out[k] = [fm[0], fm[1]];
      }
    }
    return out;
  })();
  /* The fields of this form that hold numbers (config.numberMarks: field =>
     "point" | "comma"). A condition reads their value as the number, so 12,5
     on a comma-decimal field compares as 12.5 and 2.5E1 as 25, as the server
     reads it (Logic::numberValue, AST-1). */
  var QRID_NUMBER_MARKS = (function(){
    var out = Object.create(null);
    var m = QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.numberMarks;
    if(m && typeof m === "object" && !Array.isArray(m)){
      for(var k in m){
        if(Object.prototype.hasOwnProperty.call(m, k) && (m[k] === "point" || m[k] === "comma")) out[k] = m[k];
      }
    }
    return out;
  })();
  function QRID_logicValue(f, v){
    var fm = QRID_DATE_FORMATS[f];
    if(!fm || typeof v !== "string") return QRID_numberValue(f, v, QRID_NUMBER_MARKS);
    var p = QRID_temporalDate(QRID_whenTrim(v), fm[0], fm[1]);
    if(!p) return v;
    return fm[0] === "datetime" ? p.value.slice(0, 16) : p.value;
  }
  function QRID_pageNumberValue(f, v){ return QRID_numberValue(f, v, QRID_NUMBER_MARKS); }

  /* Shared per-load registries (were window globals; now namespace-only).
     Object.create(null): REDCap field names are attacker-ish input for a plain
     object — a field named "constructor" or "toString" must not inherit
     prototype members and corrupt lookups (COR-005). */
  var UV_validators = Object.create(null);
  var UV_guard = { items: [], armed: false };
  var UV_lastPooled = null;

function QRID_escapeHtml(t){
  return String(t)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}
/* ---- rule text in the page's language (Multi-Language Management) ---------
   A text key of a rule (php/modes.json "textKeys": the "message") always
   arrives as a plain string, the default. A tag that gave it as a language
   map also sends the other languages under V.i18n[key], keyed by lowercased
   MLM language id (AnnotationRules::takeTexts). The page shows the text of
   the language MLM has on screen, else the default. A rule with no map
   renders exactly as it did before maps existed: QRID_ruleHtml returns the
   same escaped string and nothing listens for language changes. */
var UV_texts = [];                         /* [{def, map}], indexed by data-uv-text */
var UV_lang = { hooked: false, waiting: false };
function QRID_mlm(){
  try { return (window.REDCap && window.REDCap.MultiLanguage) || null; } catch(e){ return null; }
}
function QRID_currentLang(){
  try {
    var M = QRID_mlm();
    var id = M && typeof M.getCurrentLanguage === "function" ? M.getCurrentLanguage() : "";
    return typeof id === "string" ? id.toLowerCase() : "";
  } catch(e){ return ""; }
}
/* The text for the language on screen: that id, else its primary part ("fr"
   for "fr-cm"), else another variant of the same primary part, else def. */
function QRID_pickText(def, map){
  var lang = map ? QRID_currentLang() : "";
  if(!lang) return def;
  function has(k){ return Object.prototype.hasOwnProperty.call(map, k) && typeof map[k] === "string" && map[k] !== ""; }
  if(has(lang)) return map[lang];
  var base = lang.split(/[-_]/)[0];
  if(has(base)) return map[base];
  for(var k in map) if(has(k) && k.split(/[-_]/)[0] === base) return map[k];
  return def;
}
/* Escaped HTML for V[key] ("" when the rule has none). A rule with a language
   map gets a marked span that QRID_relabelTexts swaps in place when the
   language changes, so a switch never re-runs a check: a check may ask the
   server, or write fields (@UVEXISTS and @UVRANGE "return"). */
function QRID_ruleHtml(V, key){
  var def = V ? V[key] : "";
  if(typeof def !== "string" || def === "") return "";
  var map = V.i18n && typeof V.i18n === "object" ? V.i18n[key] : null;
  if(!map || typeof map !== "object") return QRID_escapeHtml(def);
  var id = QRID_textId(V, key, def, map);
  QRID_watchLang();
  return '<span data-uv-text="' + id + '">' + QRID_escapeHtml(QRID_pickText(def, map)) + "</span>";
}
/* The data-uv-text id of V's text key, registered once per variant and key,
   so drawing a text again never grows UV_texts. */
function QRID_textId(V, key, def, map){
  if(!V.__qridTextId) V.__qridTextId = {};
  var id = V.__qridTextId[key];
  if(id === undefined){
    id = V.__qridTextId[key] = UV_texts.length;
    UV_texts.push({ def: def, map: map });
  }
  return id;
}
function QRID_relabelTexts(){
  try {
    if(!document.querySelectorAll) return;
    var els = document.querySelectorAll("[data-uv-text]");
    for(var i = 0; i < els.length; i++){
      var t = UV_texts[+els[i].getAttribute("data-uv-text")];
      if(t) els[i].textContent = QRID_pickText(t.def, t.map);
    }
  } catch(e){}
  for(var h = 0; h < UV_relabelHooks.length; h++){ try { UV_relabelHooks[h](); } catch(e){} }
}
/* Hooked once, by the first rule that shows a translated text. MLM runs its
   callbacks after it rewrites the page, and once at start-up; one already past
   start-up is caught by the relabel right after hooking. Without MLM on the
   page yet, try again once the page has loaded; without it then, the defaults
   stay. */
function QRID_watchLang(){
  if(UV_lang.hooked) return;
  var M = QRID_mlm();
  if(M && typeof M.onLangChanged === "function"){
    UV_lang.hooked = true;
    try { M.onLangChanged(QRID_relabelTexts); } catch(e){}
    QRID_relabelTexts();
    return;
  }
  if(!UV_lang.waiting && window.addEventListener){
    UV_lang.waiting = true;
    window.addEventListener("load", function(){ QRID_watchLang(); });
  }
}
/* Whether a pattern uses a \p{}/\P{}/\u{}/\x{}/\k<> escape that only works with the
   RegExp "u" flag — the browser compiles ID patterns WITHOUT it, so such a pattern
   enforces a different value set than the server's PCRE /u (F2, F2-BYPASS-01).
   Escaped-backslash pairs are stripped first so a literal-backslash pattern like
   "\\u{2}" is not misread (F2-OVERREJECT-02). Twin of usesUFlagEscape (php). */
function QRID_uFlagEscape(p){
  var d = String(p).replace(/\\\\/g, "");                  /* drop escaped-backslash pairs (parity) */
  /* The BRACE-LESS property form is the same split: PCRE reads \pL as a Unicode
     letter, this engine as the literal "pL", so both runtimes admitted the
     pattern and then disagreed about every value (M-1). */
  return /\\[pP][{A-Za-z]/.test(d) || /\\[ux]\{/.test(d) || d.indexOf("\\k<") !== -1;
}
/* PCRE syntax a browser reads as something else. \K \G \h \H \v \V \R \N \X \C
   \Q \E \z \g all compile here as identity escapes (or, for \v, a vertical tab)
   and mean something else to PCRE; [[:digit:]] is a POSIX class on the server
   and the class [[:digt] plus a literal "]" here. Both engines accept them and
   then classify the same value differently — the F2 split, one family wider
   (M-1). Twin of CheckCharacter::usesPcreOnlySyntax (php). */
function QRID_pcreOnly(p){
  var d = String(p).replace(/\\\\/g, "");                  /* drop escaped-backslash pairs (parity) */
  return /\\[KGhHvVRNXCQEzg]/.test(d) || /\[:\^?[a-z]+:\]/.test(d);
}
/* PCRE syntax the other dialect gates do not look for (VAL-5): a backtracking
   verb such as (*ACCEPT), a named back-reference \k'name' or \k{name}, an octal
   \o{...}, a reference \8 or \9, or a character-class range that starts or ends
   at \d, \w or \s ([\d-z]). PCRE runs the first three and refuses the last
   two; this engine refuses the first two and reads the others as other
   characters. Twin of CheckCharacter::usesPcreVerbOrRef (php). */
function QRID_pcreVerbOrRef(p){
  var src = String(p);
  if(src.replace(/\\[\s\S]/g, "").indexOf("(*") !== -1) return true;   /* escaped characters dropped */
  var d = src.replace(/\\\\/g, "");                                       /* escaped-backslash pairs dropped */
  if(/\\k['{]|\\o\{|\\[89]/.test(d)) return true;
  return QRID_shorthandRange(d);
}
/* Whether a character class in d has a range that starts or ends at \d, \w or
   \s (or their capitals). A "-" first or last in the class, or escaped, is a
   literal. Twin of CheckCharacter::shorthandRange (php). */
function QRID_shorthandRange(d){
  var n = d.length;
  for(var i = 0; i < n; i++){
    if(d.charAt(i) === "\\"){ i++; continue; }
    if(d.charAt(i) !== "[") continue;
    var j = i + 1;
    if(j < n && d.charAt(j) === "^") j++;
    var first = j;
    if(j < n && d.charAt(j) === "]") j++;                 /* a "]" first is a literal */
    for(; j < n && d.charAt(j) !== "]"; j++){
      if(d.charAt(j) !== "\\") continue;
      if(j + 1 < n && "dDwWsS".indexOf(d.charAt(j + 1)) !== -1){
        if(j - 1 > first && d.charAt(j - 1) === "-" && d.charAt(j - 2) !== "\\") return true;
        if(j + 3 < n && d.charAt(j + 2) === "-" && d.charAt(j + 3) !== "]") return true;
      }
      j++;
    }
    i = j;
  }
  return false;
}
/* A "(?..." group form this engine cannot compile: inline flags, comments,
   atomic groups, conditionals and the PCRE named forms. PCRE compiles all of
   them, so the server accepted a rule the browser could not run — the field
   silently stopped being checked in front of the fielder while the post-save
   audit kept filing findings (M-2). Allow-list: "(?" must be followed by ":",
   "=", "!" or "<". Twin of CheckCharacter::usesNonJsGroup (php). */
function QRID_nonJsGroup(p){
  var d = String(p).replace(/\\[\s\S]/g, "");              /* drop escaped chars */
  return /\(\?([^:=!<]|$)/.test(d);
}
/* Conservative catastrophic-backtracking detector. Two stages, both at CONFIG
   time. Stage one rejects the EXPONENTIAL shapes: nested quantifiers AND any
   repetition of a group that can match the same text more than one way
   (alternation, optionals, or inner quantifiers) — so (a|aa)+, (a?)+ and
   ((a)|(aa))+ are refused, not only (a+)+ — collapsing inner groups layer by
   layer so nesting cannot hide the shape. Stage two (QRID_polyOverlap) rejects
   the POLYNOMIAL shapes stage one misses: two or more unbounded quantifiers over
   overlapping character classes with no mandatory separator between them
   (.*.*, [0-9]*[0-9]*, [A-Z]+[A-Z0-9]+), and three or more joined by separators
   the quantifier before each can also match (.*-.*-.*). PCRE2 auto-possessifies those and stays
   fast, but this browser engine backtracks and would freeze the tab, so they
   must be refused before they ever run. Genuinely linear shapes — disjoint
   adjacent classes ([A-Z]+[0-9]+) and a mandatory separator (.*x.*) — pass.
   Still a heuristic, not a proof: it over-rejects, and on the server the
   match-time PCRE-error guard remains the backstop for the bounded residue.
   Keep BYTE-IDENTICAL behavior with CheckCharacter::riskyPattern (php);
   tests/risky_*.{cjs,php} assert parity. */
function QRID_riskyPattern(src){
  if(Array.from(String(src)).length > 512) return true;   /* absurd pattern source */
  var s = QRID_riskyEscapes(String(src));                  /* each escape -> an atom of the same reach */
  s = s.replace(/\(\?(<?[=!]|:|<[A-Za-z_][A-Za-z0-9_]*>)/g, "("); /* (?: (?= (?! (?<= (?<! (?<name> -> plain ( (VAL-11) */
  /* Whitespace is an explicit ASCII class, NOT \s: JS \s matches Unicode
     whitespace but PCRE \s (no /u) does not, so \s would classify differently
     across runtimes. The group check accepts a bounded quantifier ({n,m}/{n,})
     as the "repeating" token too, so nested bounded quantifiers like
     ([0-9]{1,20}){1,20} are caught, not only +/* nesting. Keep BYTE-IDENTICAL to
     CheckCharacter::riskyPattern (php); tests/risky_*.{cjs,php} assert parity. */
  for(;;){
    if(/[+*][ \t\n\r\f]*[*+{]/.test(s)) return true;                                        /* a++  a*+  a*{2} ...      */
    if(/\([^()]*(?:[*+]|\{[0-9]+,[0-9]*\})[^()]*\)[ \t\n\r\f]*[*+{?]/.test(s)) return true; /* (…a+…)+  (…{1,20}…){1,20} */
    if(/\[[^\]]*\][ \t\n\r\f]*[*+][ \t\n\r\f]*[*+{]/.test(s)) return true;                  /* [0-9]+* style            */
    /* Collapse every innermost group: a group whose body can match more than
       one way (alternation, optional, or variable-length content) becomes a
       variable-length token X*; a fixed body becomes the single token A. A
       repetition of a variable token is then caught by the rules above on the
       next pass — (a|aa)+ -> X*+ -> rule 1. */
    var t = s.replace(/\(([^()]*)\)/g, function(_m, body){
      return (/[|?*+]/.test(body) || /\{[0-9]+,[0-9]*\}/.test(body)) ? "X*" : "A";
    });
    if(t === s) break;
    s = t;
  }
  /* Stage two: the polynomial-overlap class the exponential rules miss. */
  if(QRID_polyOverlap(s)) return true;                                                       /* .*.*  [0-9]*[0-9]*  [A-Z]+[A-Z0-9]+ */
  return false;
}
/* Rewrite each escape as an atom that matches at least what it matches, so the
   stages below see its class (VAL-4). Deleting escapes, as before, dropped \w
   from a class body ([\w.]+_ read as [.]+_ and passed) and let a quantifier
   after \d or \. attach to the atom before it ([0-9]+\.[0-9]+ read as
   [0-9]+[0-9]+ and was refused). Outside a class: \d \w \s become their ASCII
   classes, \D \W \S and an escaped metacharacter become "." (any character),
   any other escaped symbol is that symbol, and a letter or digit escape (\b \n
   \x \u \1 \k ...) is dropped. Inside a class the members are added at the end
   of the body ("-" last, so it forms no range), and \D \W \S or an escaped "]"
   make the class match everything. Twin of CheckCharacter::riskyEscapes (php). */
var QRID_RISKY_ESCAPE_CLASS = { d: "0-9", w: "0-9A-Z_a-z", s: " \t\n\r\f" };
function QRID_riskyEscapes(src){
  var out = "", n = src.length, i = 0, inCls = false, body = "", extra = "", dash = false, wide = false;
  while(i < n){
    var c = src.charAt(i);
    if(c === "\\" && i + 1 < n){
      var e = src.charAt(i + 1), cls = QRID_RISKY_ESCAPE_CLASS.hasOwnProperty(e) ? QRID_RISKY_ESCAPE_CLASS[e] : null;
      var word = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz".indexOf(e) !== -1;
      i += 2;
      if(inCls){
        if(cls !== null) extra += cls;
        else if(e === "D" || e === "W" || e === "S" || e === "]") wide = true;
        else if(e === "-") dash = true;
        else if(!word) extra += e;
      } else {
        if(cls !== null) out += "[" + cls + "]";
        else if(e === "D" || e === "W" || e === "S" || "^$.*+?()[]{}|\\".indexOf(e) !== -1) out += ".";
        else if(!word) out += e;
      }
      continue;
    }
    i++;
    if(inCls){
      if(c === "]"){ out += "[" + (wide ? "^" : "") + body + extra + (dash ? "-" : "") + "]"; inCls = false; }
      else body += c;
    }
    else if(c === "["){ inCls = true; body = ""; extra = ""; dash = false; wide = false; }
    else out += c;
  }
  if(inCls) out += "[" + body + extra + (dash ? "-" : "");   /* unterminated class: left as written */
  return out;
}
/* Reject two or more unbounded quantifiers (*, +, {n,}) whose atoms overlap with
   no mandatory atom pinning a split between them. Runs on `s` AFTER escape-strip,
   lookaround-unwrap, and the group-collapse loop (groups are already A / X*
   tokens, so two repeats of one collapsed group read as overlapping too). Assumes
   an ASCII pattern source. Twin of CheckCharacter::polynomialOverlap (php). */
function QRID_polyOverlap(s){
  var toks = QRID_tokenizePattern(s);
  for(var i=0;i<toks.length;i++){
    if(!toks[i].unbounded) continue;
    for(var j=i+1;j<toks.length;j++){
      if(toks[j].unbounded && QRID_classesOverlap(toks[i].cls, toks[j].cls)) return true;
      if(toks[j].mandatory) break;                     /* a must-match atom anchors the split point */
    }
  }
  /* Stage two-c (VAL-4): a must-match atom pins the split only when the
     unbounded quantifier before it cannot match it too. In .*-.*-.*-.*-.*X each
     "-" is also matched by ".", so on a run of dashes every placement is tried:
     one verdict on 200 dashes took 5.6 s in the browser. Follow a chain of
     unbounded quantifiers whose classes overlap, joined by atoms that may match
     nothing or that the quantifier before them also matches; three or more in
     one chain are refused. Two (.*x.*, at most n*n steps on the 512-character
     input cap) still pass. Twin of CheckCharacter::polynomialOverlap (php). */
  for(var u=0;u<toks.length;u++){
    if(!toks[u].unbounded) continue;
    var chain = 1, last = toks[u].cls;
    for(var v=u+1;v<toks.length;v++){
      if(toks[v].unbounded && QRID_classesOverlap(last, toks[v].cls)){
        if(++chain >= 3) return true;
        last = toks[v].cls;
        continue;
      }
      if(!toks[v].mandatory) continue;                                 /* may match nothing: does not pin */
      if(QRID_classesOverlap(last, toks[v].cls)) continue;             /* the quantifier before also matches it */
      break;                                                           /* a disjoint must-match atom pins the split */
    }
  }
  /* Stage two-b: the BOUNDED-quantifier chain (F1). A contiguous run of
     overlapping bounded repeats (A{1,20}A{1,20}…) backtracks with the factor
     count as the exponent — a browser tab freeze past ~5 factors, and this engine
     has no match-time guard the server's PCRE path has. Bound the product of
     per-factor match-length choices; a fixed atom (choices 1), an unbounded
     quantifier, or a disjoint class ends the run and anchors a split. A single
     quantifier (or the deliberate 3-factor residue) stays under the budget.
     Twin of CheckCharacter::polynomialOverlap stage two-b (php). */
  /* F1-1 fix: what ENDS a run is a genuine split point — an UNBOUNDED quantifier
     (stage two-a's territory) or a class DISJOINT from the run so far. A fixed/bare
     atom whose class OVERLAPS does NOT end the run (its char is fungible with the
     surrounding class, so it cannot pin a split — e.g. '…{1,20}[0-9]{1,20}…' with a
     lone [0-9] between must NOT reset the product); it bridges on with choices 1.
     Track the class FORWARD so a bridging chain (A, [AB], B) is measured whole. */
  for(var a=0;a<toks.length;a++){
    if(toks[a].choices < 2) continue;                                  /* a run starts at a bounded repeat */
    var prod = toks[a].choices, prevCls = toks[a].cls;
    for(var b=a+1;b<toks.length;b++){
      if(toks[b].unbounded) break;                                     /* unbounded -> stage two-a; ends the bounded run */
      if(!QRID_classesOverlap(prevCls, toks[b].cls)) break;            /* a DISJOINT atom pins a split, ending the run */
      if(toks[b].choices >= 2){ prod *= toks[b].choices; if(prod > QRID_MAX_BACKTRACK_PRODUCT) return true; }
      prevCls = toks[b].cls;                                           /* overlapping fixed atom bridges on (does not pin) */
    }
  }
  return false;
}
var QRID_MAX_BACKTRACK_PRODUCT = 1000000;   /* bounded-chain budget; twin of CheckCharacter::MAX_BACKTRACK_PRODUCT (php) */
/* Split a normalized pattern into atoms with their character set + quantifier
   facts. cls === null is "universal" (a '.', or a collapsed group token, treated
   as overlapping everything). unbounded = no upper limit (*, +, {n,}); mandatory
   = must consume >= 1 char (bare atom, +, {n>=1}). Twin of tokenizePattern (php). */
function QRID_tokenizePattern(s){
  var toks = [], n = s.length, i = 0;
  while(i < n){
    var c = s.charAt(i), cls = null;
    if(c === "."){ cls = null; i++; }
    else if(c === "["){
      var close = s.indexOf("]", i + 1);
      if(close === -1){ i++; continue; }               /* unterminated class: skip the '[' */
      cls = QRID_expandClass(s.slice(i + 1, close));
      i = close + 1;
    }
    else if("^$*+?{}|()".indexOf(c) !== -1){ i++; continue; }   /* anchors / stray metachars: not atoms */
    else { cls = c; i++; }                                       /* a literal char (incl. A / X collapse tokens) */
    /* choices = the number of distinct match-lengths this atom can take (its
       backtracking slack): 1 for a bare atom or a fixed {n}, 2 for ?, (hi-lo+1)
       for {lo,hi}; an unbounded quantifier is handled by stage two-a. */
    var unbounded = false, mandatory = true, choices = 1;   /* a bare atom must match once */
    if(i < n){
      var q = s.charAt(i);
      if(q === "*"){ unbounded = true; mandatory = false; i++; }
      else if(q === "+"){ unbounded = true; mandatory = true; i++; }
      else if(q === "?"){ unbounded = false; mandatory = false; choices = 2; i++; }
      else if(q === "{"){
        var qc = s.indexOf("}", i + 1), mm;
        if(qc !== -1 && (mm = /^([0-9]*)(,?)([0-9]*)$/.exec(s.slice(i + 1, qc)))){
          var lo = mm[1] === "" ? 0 : parseInt(mm[1], 10);
          unbounded = (mm[2] === ",") && (mm[3] === "");   /* {n,} unbounded; {n} / {n,m} bounded */
          mandatory = lo >= 1;
          if(!unbounded){
            var hi = mm[3] === "" ? lo : parseInt(mm[3], 10);   /* {n} -> hi=lo (choices 1) */
            choices = hi - lo + 1;
            if(choices < 1) choices = 1;                        /* malformed {5,2}: JS rejects it anyway */
          }
          i = qc + 1;
        }
      }
    }
    toks.push({ cls: cls, unbounded: unbounded, mandatory: mandatory, choices: choices });
  }
  return toks;
}
/* Expand a bracket-class body to its literal ASCII members; null = universal. */
function QRID_expandClass(inner){
  if(inner === "") return "";                          /* matches nothing */
  if(inner.charAt(0) === "^") return null;             /* negated class: large -> universal */
  var out = "", len = inner.length, k = 0;
  while(k < len){
    if(k + 2 < len && inner.charAt(k + 1) === "-"){
      var lo = inner.charCodeAt(k), hi = inner.charCodeAt(k + 2);
      if(lo <= hi && hi - lo <= 255){
        for(var x = lo; x <= hi; x++) out += String.fromCharCode(x);
        k += 3; continue;
      }
    }
    out += inner.charAt(k); k++;
  }
  return out;
}
/* Do two character sets share a member? null = universal (overlaps all). */
function QRID_classesOverlap(a, b){
  if(a === null || b === null) return true;
  if(a === "" || b === "") return false;
  for(var k = 0; k < a.length; k++){
    if(b.indexOf(a.charAt(k)) !== -1) return true;
  }
  return false;
}
/* THE one place an ID pattern is admitted and compiled. Four gates, then
   compile: Python-only anchors, printable ASCII (the only subset the browser
   and PCRE provably agree on), u-flag-only escapes (F2), and catastrophic
   backtracking (F1). Returns {re, error}; exactly one of them is meaningful.

   Extracted because the identical chain lived in the single-field factory, the
   pooled factory and AnnotationRules::checkFragment — three copies that had to
   be edited together, and a rule with SEVERAL patterns would have made it five.
   Twin of CheckCharacter::gatePattern (php); the wording is asserted on by
   tests/config_notice_js.cjs and tests/dispatch_notice_js.cjs, so it must stay
   byte-identical across both runtimes. `label` names which alternate failed
   (empty for a single-pattern rule, so legacy messages are unchanged). */
function QRID_gatePattern(raw, label){
  /* Label placement matches the php twin, which reports "<name>: <message>"
     from checkFragment. The base sentences differ by audience (this one
     names the config KEY for an injector/browser reader, the server names
     "the format pattern" for a dialog reader) — that split predates
     alternates; what must not drift is which alternate is blamed. */
  var src = String(raw), pre = label ? label + ": " : "";
  if(/\\[AZ]/.test(src)){
    /* JS would silently treat \A / \Z as literal letters — a Python-only trap */
    return { re: null, error: pre + "idPattern" + " uses Python-only \\A or \\Z anchors; patterns are JavaScript " +
      "regex — use ^ and $ instead (anchors are optional anyway)." };
  }
  if(/[^\x20-\x7E]/.test(src)){
    /* the browser (UTF-16) and server (PCRE /u) only provably agree on ASCII */
    return { re: null, error: pre + "idPattern" + " must contain printable ASCII only — the browser and server " +
      "regex engines are only guaranteed to agree on that subset." };
  }
  if(QRID_nonJsGroup(src)){
    /* left to the compile step below this refused with a JavaScript syntax
       message the SERVER never produced — PCRE compiles (?i) and (?>...) — so a
       rule could pass the server gate and die here, stopping live checking
       while the post-save audit kept enforcing it (M-2) */
    return { re: null, error: pre + "idPattern" + " uses a group form JavaScript cannot compile — inline " +
      "flags ((?i) (?x) (?m) (?s)), comments ((?#...)), atomic groups ((?>...)), conditionals ((?(1)...)) " +
      "or the PCRE named forms ((?P...) (?'n'...)). Patterns are JavaScript regex: use (?:...) for a " +
      "plain group, (?=...) or (?!...) for lookahead, (?<=...) or (?<!...) for lookbehind, and " +
      "(?<name>...) for a named group." };
  }
  if(QRID_uFlagEscape(src)){
    /* \p{}, \P{}, \u{}, \x{}, \k<> only work with JS's "u" flag; the browser
       compiles ID patterns WITHOUT it (so \p reads as literal "p" and \x{41} as
       x{41}) while the server matches with /u — they disagree (F2, F2-BYPASS-01). */
    return { re: null, error: pre + "idPattern" + " uses a Unicode-property, code-point or named escape (\\p{...}, " +
      "\\P{...}, \\u{...}, \\x{...} or \\k<...>) that only works with JavaScript's \"u\" flag — the " +
      "browser compiles ID patterns without it, so the value would validate differently in the " +
      "browser and on the server. Use explicit character classes such as [A-Z] or [0-9] instead." };
  }
  if(QRID_pcreOnly(src)){
    /* same split as \p{...}, one family wider: both engines compile these and
       then read them differently, so the value would validate in one runtime
       and not the other (M-1) */
    return { re: null, error: pre + "idPattern" + " uses PCRE-only syntax (\\K, \\G, \\h, \\v, \\R, \\N, " +
      "\\X, \\C, \\Q...\\E, \\g, \\z or a POSIX class such as [[:digit:]]) that a browser reads as an " +
      "ordinary character, so the value would validate differently in the browser and on the server. " +
      "Use explicit character classes such as [A-Z] or [0-9] instead." };
  }
  if(QRID_pcreVerbOrRef(src)){
    return { re: null, error: pre + "idPattern" + " uses PCRE syntax that a browser cannot run or reads as " +
      "other characters: a verb such as (*ACCEPT), a named back-reference \\k'name' or \\k{name}, an octal " +
      "\\o{...}, a reference \\8 or \\9, or a class range that starts or ends at \\d, \\w or \\s, such as " +
      "[\\d-z]. Use explicit character classes such as [A-Z] or [0-9] instead." };
  }
  if(/\[\^?\]/.test(src.replace(/\\[\s\S]/g, ""))){
    /* "[]" is a compile error to PCRE and an empty class — one that can never
       match — here, so the server refused the rule for a reason this side would
       never have reached (M-2) */
    return { re: null, error: pre + "idPattern" + " contains an empty character class (\"[]\" or \"[^]\"). " +
      "PCRE refuses it and JavaScript compiles it to a class that can never match, so the pattern would " +
      "behave differently in the browser and on the server — write out the characters the class should " +
      "contain." };
  }
  if(QRID_newPcreQuantifier(src)){
    /* "{,n}" and quantifiers with spaces ("{2, 3}") are literal text here and
       to PCRE2 before 10.43, and quantifiers from 10.43 on (bundled with PHP
       8.4): a server upgrade would silently split the two runtimes. Twin of
       gatePattern (php). */
    return { re: null, error: pre + "idPattern" + " uses \"{,n}\" or a quantifier with spaces such as " +
      "\"{2, 3}\". JavaScript reads these as literal text, PCRE before 10.43 does too, and PCRE 10.43 and " +
      "later (PHP 8.4) read them as quantifiers, so the pattern would behave differently in the browser and " +
      "on some servers. Write {0,n} or {2,3} without spaces, or escape the brace (\\{) for the literal text." };
  }
  if(QRID_riskyPattern(src)){
    return { re: null, error: pre + "idPattern" + " looks catastrophically backtracking (nested quantifiers, a " +
      "repeated ambiguous group, overlapping unbounded quantifiers, or a long run of overlapping " +
      "bounded quantifiers — e.g. (a+)+, (a|aa)+, .*.* / [0-9]*[0-9]*, .*-.*-.*, or " +
      "A{1,20}A{1,20}A{1,20}A{1,20}A{1,20}). Rewrite it so no ambiguous group repeats, no two " +
      "unbounded quantifiers overlap, and no long run of bounded quantifiers shares a character class." };
  }
  try {
    var body = src.replace(/^\^/, "").replace(/\$$/, "");
    return { re: new RegExp("^(?:" + body + ")$"), error: "" };
  } catch(e){
    return { re: null, error: pre + "idPattern" + " is not a valid JavaScript regex: " + e.message +
      " (Python-only syntax like (?P<name>...) or inline flags is not supported)." };
  }
}
/* Every rule is a LIST of accepted ID formats, each with its own pattern and its
   own check algorithm (or "none"). A rule written the old way — one scalar
   algorithm, one scalar idPattern — normalizes to a ONE-ELEMENT list, so every
   consumer below runs exactly one loop and there is no second code path to keep
   in step. Pure normalization: no gates, no compilation, total. The gates live
   in the factories (QRID_gatePattern per alternate) and, authoritatively, in
   AnnotationRules::checkFragment.

   `lengths` is null when the alternate does not declare its own; the pooled
   factory then hands it the rule-level idLengths/idMinLen..idMaxLen set, which
   is what makes a legacy rule byte-identical to today.

   Twin of CheckCharacter::alternatesOf (php). */
function QRID_altName(alt, i){ return alt.label ? alt.label : "alternate " + (i + 1); }
/* The non-alphanumeric characters a pattern matches LITERALLY - its
   separators, such as the "-" in FC[1-9]-[0-9]{4} - which pooled cleaning must
   therefore keep. Regex syntax is not a literal: the comma of a {n,m}
   quantifier and the ":", "=", "!", "<", ">" and group name of a "(?..."
   prefix used to be read as separators. An escaped separator is literal too:
   "\:" and "\x3A" both match ":". Twin of CheckCharacter::patternLiterals
   (php). */
/* A brace group of digits, commas and spaces is a quantifier only to PCRE2
   10.43+ when it has an empty minimum ("{,n}") or white space. Twin of
   CheckCharacter::usesNewPcreQuantifier (php). */
function QRID_newPcreQuantifier(src){
  var groups = String(src).replace(/\\[\s\S]/g, "").match(/\{[0-9 ,]*\}/g) || [];
  for(var i = 0; i < groups.length; i++){
    if(/^\{ *,/.test(groups[i])) return true;
    if(groups[i].indexOf(" ") >= 0 && /[0-9]/.test(groups[i])) return true;
  }
  return false;
}

function QRID_patternLiterals(pattern){
  var pat = String(pattern), meta = "\\^$.|?*+()[]{}", out = "", m;
  for(var i = 0; i < pat.length; i++){
    var pc = pat.charAt(i);
    if(pc === "\\"){
      i++;
      if(i >= pat.length) continue;
      var ec = pat.charAt(i), hx = null;
      if(ec === "x") hx = /^[0-9A-Fa-f]{2}/.exec(pat.slice(i + 1));
      else if(ec === "u") hx = /^[0-9A-Fa-f]{4}/.exec(pat.slice(i + 1));
      if(hx){
        i += hx[0].length;
        ec = String.fromCharCode(Math.min(parseInt(hx[0], 16), 0x7F));  /* only printable ASCII is kept below */
      } else if(/[A-Za-z0-9]/.test(ec)){
        continue;                               /* \d, \w, \b ... are not text */
      }
      if(ec >= " " && ec <= "~" && !/[A-Za-z0-9]/.test(ec) && out.indexOf(ec) < 0) out += ec;
      continue;
    }
    if(pc === "{" && (m = /^\{\d+(,\d*)?\}/.exec(pat.slice(i)))){
      i += m[0].length - 1;                     /* a quantifier, not text */
      continue;
    }
    if(pc === "(" && pat.charAt(i + 1) === "?" &&
       (m = /^\(\?(?:<[A-Za-z_][A-Za-z0-9_]*>|<=|<!|[:=!])/.exec(pat.slice(i)))){
      i += m[0].length - 1;                     /* a group prefix, not text */
      continue;
    }
    if(meta.indexOf(pc) < 0 && !/[A-Za-z0-9]/.test(pc) && out.indexOf(pc) < 0) out += pc;
  }
  return out;
}

function QRID_alternatesOf(cfg){
  var raw = cfg.alternates;
  function bad(msg){ return { list: [], error: msg }; }
  /* The rule-level scalars are read as regex sources and algorithm names by
     every consumer below. The php twin returns null (an unconfigurable rule)
     when one of them is not text rather than throwing on it (L-1); refuse the
     same shapes here so the two channels still agree about what is a rule. */
  var ALT_SCALARS = ["algorithm", "source", "strip"];        /* also valid per entry */
  var SCALARS = ALT_SCALARS.concat(["idPattern"]);
  for(var ri = 0; ri < SCALARS.length; ri++){
    var rk = SCALARS[ri];
    if(cfg[rk] !== undefined && cfg[rk] !== null && typeof cfg[rk] !== "string"){
      return bad("\"" + rk + "\" must be text.");
    }
  }
  if(raw === undefined || raw === null || raw === ""){
    return { list: [{ label: "", pattern: (cfg.idPattern || null), algorithm: cfg.algorithm,
                      source: cfg.source, strip: cfg.strip, lengths: null }], error: "" };
  }
  if(!(raw instanceof Array) || !raw.length){
    return bad("alternates must be a non-empty LIST of formats, e.g. " +
      "[{\"pattern\":\"FC[1-9]-[0-9]{4}\",\"algorithm\":\"none\",\"lengths\":[8]}]. " +
      "A JSON object is not accepted: the browser and the server order its keys differently.");
  }
  if(raw.length > QRID_MAX_ALTERNATES){
    return bad("alternates lists " + raw.length + " formats — at most " + QRID_MAX_ALTERNATES +
      " are supported. More ID families than that in one field is usually a sign they belong in separate fields.");
  }
  var list = [];
  for(var i = 0; i < raw.length; i++){
    var a = raw[i], nm = "alternates entry " + (i + 1);
    if(!a || typeof a !== "object" || a instanceof Array) return bad(nm + " must be an object with a \"pattern\".");
    if(typeof a.pattern !== "string" || a.pattern === ""){
      /* a pattern-less alternate matches everything, so in declaration order it
         would swallow every token and silently kill the alternates after it */
      return bad(nm + " needs a non-empty \"pattern\" — an alternate without one would accept " +
        "every value and the alternates after it would never be reached.");
    }
    if(a.label !== undefined && a.label !== null && a.label !== ""){
      if(typeof a.label !== "string" || a.label.length > QRID_MAX_ALT_LABEL ||
         /[^\x20-\x7E]/.test(a.label)){
        return bad(nm + " has a \"label\" that is not printable ASCII of at most " +
          QRID_MAX_ALT_LABEL + " characters.");
      }
    }
    for(var si = 0; si < ALT_SCALARS.length; si++){
      var sk = ALT_SCALARS[si];
      if(a[sk] !== undefined && a[sk] !== null && typeof a[sk] !== "string"){
        return bad(nm + " has a \"" + sk + "\" that is not text.");
      }
    }
    var lens = null;
    if(a.lengths !== undefined && a.lengths !== null){
      if(!(a.lengths instanceof Array) || !a.lengths.length ||
         !a.lengths.every(function(x){ return typeof x === "number" && isFinite(x) && x > 0 && x % 1 === 0; })){
        return bad(nm + " has \"lengths\" that is not a list of positive whole numbers, e.g. [8].");
      }
      var seen = {};
      lens = [];
      a.lengths.forEach(function(x){ var n = x | 0; if(!seen[n]){ seen[n] = 1; lens.push(n); } });
      lens.sort(function(x, y){ return x - y; });
    }
    list.push({ label: (a.label || ""), pattern: a.pattern,
                algorithm: (a.algorithm === undefined || a.algorithm === null || a.algorithm === "")
                  ? cfg.algorithm : a.algorithm,
                source: (a.source === undefined || a.source === null || a.source === "")
                  ? cfg.source : a.source,
                strip: (a.strip === undefined || a.strip === null) ? cfg.strip : a.strip,
                lengths: lens });
  }
  return { list: list, error: "" };
}
/* Can any declared member length be built by adding TWO OR MORE of the declared
   lengths together? If so one token can span several real members and still
   verify, so a mis-scan is reported as a clean ID — the failure the pooled
   parser's structural guarantee exists to make impossible.

   Returns null when the set is safe, else {target, parts} naming one witness
   decomposition so the designer is told which lengths collide.

   Supersedes the pairwise test this replaces, which only ever compared TWO
   lengths and therefore accepted [4,12] even though 12 = 4+4+4 — a valid 4-char
   member plus eight characters of scan debris read back as one verified
   12-character ID with zero junk. Unbounded-coin reachability, O(maxLen x |U|).
   Twin of CheckCharacter::swallowSum (php); keep the witness identical, because
   both runtimes print it. */
function QRID_swallowSum(LENS){
  var i, L, s, maxL = 0;
  for(i = 0; i < LENS.length; i++) if(LENS[i] > maxL) maxL = LENS[i];
  /* rep1[s] = s is the sum of ONE OR MORE declared lengths; via[s] = a length
     used in that sum, so a witness can be reconstructed. */
  var rep1 = new Array(maxL + 1), via = new Array(maxL + 1);
  for(s = 0; s <= maxL; s++){ rep1[s] = false; via[s] = 0; }
  for(s = 1; s <= maxL; s++){
    for(i = 0; i < LENS.length; i++){
      L = LENS[i];
      if(L > s) break;                          /* ascending — twin of the php break */
      if(L === s || rep1[s - L]){ rep1[s] = true; via[s] = L; break; }
    }
  }
  /* L is a sum of >= 2 lengths iff some SHORTER declared length leaves a
     remainder that is itself a sum of one or more. */
  for(i = 0; i < LENS.length; i++){
    L = LENS[i];
    for(var j = 0; j < LENS.length; j++){
      var first = LENS[j];
      if(first >= L) break;
      if(!rep1[L - first]) continue;
      var parts = [first], rest = L - first;
      while(rest > 0){ parts.push(via[rest]); rest -= via[rest]; }
      return { target: L, parts: parts };
    }
  }
  return null;
}
var QRID_MAX_SINGLE_LEN = 512;    /* one ID field: refuse to validate absurd input */
var QRID_MAX_POOLED_LEN = 4096;   /* pooled field: cap total scanned length        */
/* Length in CODE POINTS — the unit parse() and CheckCharacter::pooledParse
   (mb_strlen) both compare against the scan cap. The field handler compared
   String.length, which counts UTF-16 units, so an astral value near the cap made
   the browser announce "too long to scan" for a field the parser and the server
   both still read — findings on a field the fielder was told was not checked
   (L-5). The UTF-16 count is an upper bound on this one, so the exact walk is
   only paid when it could change the answer. */
function QRID_codePointLen(v){
  var s = String(v);
  if(!/[\uD800-\uDBFF]/.test(s)) return s.length;         /* no surrogate pair possible */
  return s.length - (s.match(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g) || []).length;
}
/* Rule-config work caps — mirror php/CheckCharacter.php MAX_* constants (the
   server rejects the same limits at settings-save time and treats them as
   unconfigurable at audit time); keep the values in sync. */
var QRID_MAX_ID_LEN      = 64;    /* longest single ID/member the parser considers */
var QRID_MAX_LEN_CHOICES = 32;    /* most candidate lengths a pooled rule may declare */
var QRID_MAX_ALTERNATES  = 8;     /* most ID formats one rule may accept          */
var QRID_MAX_ALT_LABEL   = 40;    /* an alternate's display name (reaches innerHTML) */
var QRID_MAX_EXPECTED    = 9999;
var QRID_MAX_KEEP        = 64;
/* One pooled parse costs about (scanned length) x |PAIRS| x (member length)
   "character operations"; this budget bounds that product for EVERY legal
   config, so an expensive rule shrinks its scan cap instead of freezing the
   tab. Default rules keep the full QRID_MAX_POOLED_LEN.

   The unit is nominal, not literal: one step is a substr, a regex test and -
   whenever the pattern does not reject first - a full normalize + source +
   check-character computation, which measures around an order of magnitude
   above a character comparison. What that buys, measured at each config's own
   cap (php 8.3, one pooled field, one save):

     legacy 8..15, no pattern      cap 4096   ~195 ms   <- worst admitted
     legacy 8..14 (the default)    cap 4096   ~160 ms
     legacy 8..14 + a pattern      cap 4096   ~130 ms
     legacy exact [9]              cap 4096    ~30 ms
     alternates x8 over 15..22     cap 2840    ~31 ms
     the four-family example       cap 4096    ~25 ms

   Read the first two rows before trusting the constant: QRID_MAX_POOLED_LEN,
   not this budget, is what binds a pattern-less legacy rule, and it bound it at
   2,000,000 as well - so lowering the budget did NOT move the worst case, and
   the earlier "~300 ms before, ~75 ms after" reading of these numbers was wrong
   (M-3). What the lower budget does bound is the wide tail, where the cap
   really does fall: alternates x8 above, and 32 candidate pairs over 64-char
   members dropping from 976 to the 256 floor. The multi-format rule this
   feature exists for is an order of magnitude cheaper than the legacy default
   it sits beside, because each alternate's pattern rejects before any check
   character is computed - which is also the fix for an expensive legacy rule:
   give it a format pattern. Keep in sync with php/CheckCharacter.php
   POOLED_WORK_BUDGET. */
var QRID_POOLED_WORK_BUDGET = 500000;
/* How long after the last keystroke before validating (change/blur validate
   immediately). Bounds per-keystroke work on slow machines (PER-002). */
var QRID_DEBOUNCE_MS = 150;
function QRID_debounced(fn){
  if (typeof setTimeout !== "function") return fn;
  var timer = null;
  var wrapped = function(){
    if (timer !== null) clearTimeout(timer);
    timer = setTimeout(function(){ timer = null; fn(); }, QRID_DEBOUNCE_MS);
  };
  wrapped.cancel = function(){ if (timer !== null){ clearTimeout(timer); timer = null; } };
  return wrapped;
}
/* Best-effort visible label for a field; falls back to the internal field
   name. Used so save-block dialogs and announcements speak the language of the
   FORM, not the data dictionary (A11Y-001).
   REDCap renders the label as <label id="label-<field>"> on data entry forms
   and surveys alike, and points the input's aria-labelledby at it; both are
   asked before the row's cells. The row is the last resort, and its FIRST
   .labelrc cell is not the label on a survey with question numbers: that cell
   is the empty number column, so the dialog named "block_hard" to the
   respondent. Empty cells are skipped. */
function QRID_fieldLabel(input, fieldName){
  function text(el){
    var t = el && el.textContent ? el.textContent.replace(/\s+/g, " ").trim() : "";
    return t ? t.slice(0, 80) : "";
  }
  try {
    var t = input.getAttribute && input.getAttribute("aria-label");
    if (t && t.replace(/\s+/g, "").length) return t.replace(/\s+/g, " ").trim().slice(0, 80);
    if (document.getElementById) {
      t = text(document.getElementById("label-" + fieldName));
      if (t) return t;
      var ids = (input.getAttribute && input.getAttribute("aria-labelledby")) || "";
      ids = ids.split(/\s+/);
      for (var i = 0; i < ids.length; i++) {
        t = ids[i] ? text(document.getElementById(ids[i])) : "";
        if (t) return t;
      }
    }
    if (input.labels && input.labels.length) {
      t = text(input.labels[0]);
      if (t) return t;
    }
    var row = input.closest ? input.closest("tr") : null;
    if (row && row.querySelectorAll) {
      var cells = row.querySelectorAll("td.labelrc, .labelrc");
      for (var c = 0; c < cells.length; c++) {
        t = text(cells[c]);
        if (t) return t;
      }
    } else if (row && row.querySelector) {
      t = text(row.querySelector("td.labelrc, .labelrc"));
      if (t) return t;
    }
  } catch(e){}
  return fieldName;
}
/* The label a save dialog names a field by, read when the dialog opens. Read
   once when the field was bound, it stayed in the page's first language after
   Multi-Language Management switched it, and in the base language whenever
   the engine bound before MLM's first pass (it always does: the engine runs at
   the top of the page). A plain guard object carries the element to read it
   from; the label read at bind time is kept for when no element answers. */
function QRID_guardLabel(el){
  var name = el.__qridFieldName;
  var src = el.__qridLabelEl || (el.getAttribute ? el : null);
  if(src){
    var t = QRID_fieldLabel(src, name);
    if(t && t !== name) return t;
  }
  return el.__qridFieldLabel || name;
}
/* Stable, selector-safe id for a field's status region (aria-describedby). */
function QRID_msgId(fieldName){
  return "uvalidate-msg-" + String(fieldName).replace(/[^A-Za-z0-9_-]/g, "_");
}
/* Safe field lookup (no CSS-selector string building — field names with
   special characters cannot break it). Shared by both factories. */
function QRID_findField(name){
  var els = document.getElementsByName ? document.getElementsByName(name) : [];
  for(var i = 0; i < els.length; i++){
    var tag = (els[i].tagName || "").toLowerCase();
    if(tag === "input" || tag === "textarea") return els[i];
  }
  return null;
}
/* Anchor element for a field of ANY input type — where the constraint/required
   modes hang their status region and save block. Unlike QRID_findField (ID
   fields are always <input>/<textarea>), this also returns a <select> (dropdown)
   or the hidden mirror <input> REDCap keeps for radio/yes-no/true-false, and
   falls back to a radio/checkbox input as a last resort. The field's own VALUE
   is read separately via QRID_WHEN.readRef, which knows the same conventions. */
function QRID_findAnchor(name){
  var els = document.getElementsByName ? document.getElementsByName(name) : [];
  var fallback = null;
  /* A REDCap field may have a hidden mirror before its visible select. */
  for(var si = 0; si < els.length; si++){
    if((els[si].tagName || "").toLowerCase() === "select") return els[si];
  }
  for(var i = 0; i < els.length; i++){
    var tag = (els[i].tagName || "").toLowerCase();
    if(tag === "select" || tag === "textarea") return els[i];
    if(tag === "input"){
      var ty = (els[i].type || "").toLowerCase();
      if(ty !== "radio" && ty !== "checkbox") return els[i]; /* text / hidden radio mirror */
      if(!fallback) fallback = els[i];
    }
  }
  return fallback;
}
/* Where "Cannot save yet" puts the focus for a field's guard item. The anchor
   of a radio or checkbox field is a hidden mirror that cannot take it, so the
   focus stayed on the page body (CHO-9): the field's checked option, else its
   first option on show. A dropdown with REDCap's autocomplete takes it in the
   text box. Any other field takes it on the anchor itself. */
function QRID_focusTarget(input, fieldName){
  if(typeof document === "undefined") return input;
  if((input && input.tagName || "").toLowerCase() === "select"){
    var ac = document.getElementById ? document.getElementById("rc-ac-input_" + fieldName) : null;
    return ac || input;
  }
  if(!document.getElementsByName) return input;
  var opts = [], els, i;
  els = document.getElementsByName(fieldName + "___radio");
  for(i = 0; i < els.length; i++) opts.push(els[i]);
  els = document.getElementsByName("__chkn__" + fieldName);
  for(i = 0; i < els.length; i++) opts.push(els[i]);
  for(i = 0; i < opts.length; i++) if(opts[i].checked) return opts[i];
  for(i = 0; i < opts.length; i++){
    var row = opts[i].parentNode;
    if(!(row && (row.__qridHidden || (row.style && row.style.display === "none")))) return opts[i];
  }
  return input;
}
/* Whether the person cannot change the field on this page (REQ-2). @READONLY
   leaves the anchor of a radio, yes/no, true/false or checkbox field enabled,
   because that anchor is the hidden mirror REDCap keeps, and disables the
   options the person clicks instead. On an autocomplete dropdown it locks the
   text box that stands in for the select. Every factory asks this one test
   when it binds, and the save guard asks it again at save, because REDCap may
   apply @READONLY after the engine bound the field. codes: the checkbox codes
   whose visible boxes (id-__chk__<field>_RC_<code>) count, when known. */
function QRID_fieldLocked(anchor, fieldName, codes){
  if(!anchor) return false;
  if(anchor.readOnly || anchor.disabled) return true;
  if(!document.getElementsByName) return false;
  var seen = 0, locked = 0, els, i;
  function count(el){ seen++; if(el.disabled || el.readOnly) locked++; }
  els = document.getElementsByName(fieldName + "___radio");
  for(i = 0; i < els.length; i++) count(els[i]);
  els = document.getElementsByName("__chkn__" + fieldName);
  for(i = 0; i < els.length; i++) count(els[i]);
  for(i = 0; codes && document.getElementById && i < codes.length; i++){
    var box = document.getElementById("id-__chk__" + fieldName + "_RC_" + codes[i]);
    if(box) count(box);
  }
  if(seen > 0 && locked === seen) return true;
  var ac = document.getElementById ? document.getElementById("rc-ac-input_" + fieldName) : null;
  return !!(ac && (ac.disabled || ac.readOnly));
}
/* Whether REDCap hides the field from the person (REQ-1): branching logic
   that is false, or @HIDDEN, sets display:none on the field's row
   (#<field>-tr). The walk starts above the anchor, which is itself hidden for
   a radio's mirror input, and stops at the field's row, so an embedded field
   ({field} in another field's label) is judged by the row it sits in now,
   and a page container REDCap hides while it loads is never read. With no
   row found the field counts as shown. */
function QRID_fieldHidden(anchor){
  var el = anchor ? anchor.parentNode : null;
  var gcs = (typeof window !== "undefined" && typeof window.getComputedStyle === "function") ? window.getComputedStyle : null;
  var path = [];
  while(el && el.nodeType === 1){
    path.push(el);
    if((el.tagName || "").toLowerCase() === "tr" && /-tr$/.test(el.id || "")) break;
    el = el.parentNode;
  }
  if(!el || el.nodeType !== 1) return false;
  for(var i = 0; i < path.length; i++){
    var p = path[i];
    if(p.style && p.style.display === "none") return true;
    if(gcs){ try { if(gcs(p).display === "none") return true; } catch(e){} }
  }
  return false;
}
/* REDCap shows and hides rows in doBranching(), which fires no DOM event. A
   factory whose notice depends on whether its row is shown subscribes here and
   runs again after each call, and once more when a fade has had time to end. */
var QRID_BRANCHING = { fns: [], armed: false, timer: null };
var QRID_BRANCHING_SETTLE_MS = 450;
function QRID_onBranching(fn){
  QRID_BRANCHING.fns.push(fn);
  if(QRID_BRANCHING.armed) return;
  QRID_BRANCHING.armed = true;
  function run(){
    for(var i = 0; i < QRID_BRANCHING.fns.length; i++){ try { QRID_BRANCHING.fns[i](); } catch(e){} }
  }
  function wrap(){
    var orig = window.doBranching;
    if(typeof orig !== "function") return false;
    if(orig.__qridBranching) return true;
    var wrapped = function(){
      var r = orig.apply(this, arguments);
      run();
      if(QRID_BRANCHING.timer) clearTimeout(QRID_BRANCHING.timer);
      QRID_BRANCHING.timer = setTimeout(function(){ QRID_BRANCHING.timer = null; run(); }, QRID_BRANCHING_SETTLE_MS);
      return r;
    };
    wrapped.__qridBranching = true;
    window.doBranching = wrapped;
    return true;
  }
  if(wrap() || document.readyState === "complete") return;
  if(document.readyState === "loading" && typeof document.addEventListener === "function"){
    document.addEventListener("DOMContentLoaded", wrap);
  }
  if(typeof window.addEventListener === "function") window.addEventListener("load", wrap);
}
/* ---- "when" conditions: parser/evaluator twins (module feature) ------------
   A rule may carry {when: "[specimen_type]='2'"} — the rule then validates
   only while the condition is true. php/Logic.php is the NORMATIVE dialect
   spec (grammar, resolution, comparison semantics, caps, error wording); keep
   this block behavior-identical to it. tests/when_js.cjs and
   tests/when_php.php drive both runtimes against the shared
   tests/when_fixture.json, so any drift fails CI.
   AST: ["or",[...]] | ["and",[...]] | ["not",x] | ["cmp",op,lhs,rhs]
        | ["const",bool];
   operands: ["ref",field,codeOrNull] | ["lit",string].
   A "const" node is never produced by the parser here — the server folds
   comparisons it will not send values for (Logic::fold in php/Logic.php) and
   ships the result as a prebuilt AST, so no record value reaches the page. */
var QRID_WHEN_MAX_LEN = 500, QRID_WHEN_MAX_REFS = 20, QRID_WHEN_MAX_DEPTH = 10;
var QRID_WHEN_NUM_RE = /^[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)$/;
/* s without its trailing zeros. An index scan: /0+$/ is retried from every
   zero of a long run inside a typed number, which is quadratic. */
function QRID_trimZerosRight(s){
  var j = s.length;
  while(j > 0 && s.charCodeAt(j - 1) === 48) j--;
  return j === s.length ? s : s.slice(0, j);
}
/* The verdict an ORDERED comparison (< > <= >=) yields when it has NO ANSWER,
   i.e. an operand is blank so there is nothing to order. Twins of
   Logic::BLANK_PASSES / Logic::BLANK_INERT — see that docblock for why the two
   roles settle it in opposite directions. An @UVASSERT test reads no answer as
   "not a violation"; a "when" gate reads it as "cannot say the rule applies". */
var QRID_BLANK_PASSES = true;
var QRID_BLANK_INERT  = false;
/* The text mode of a test with "exact": true, passed where the rule's
   caseSensitive flag goes: = and <> compare character for character. Twin of
   Logic::EXACT. */
var QRID_WHEN_EXACT = "exact";
/* The ordered operators. A field marked with a Missing Data Code has no answer
   to them, as a blank has none (AST-10). Twin of Logic::ORDERED_OPS. */
var QRID_WHEN_ORDERED = { "<": true, ">": true, "<=": true, ">=": true };

function QRID_whenErr(msg){ return { ok: false, error: msg }; }

/* Parse one condition -> {ok:true, ast} | {ok:false, error}. Error strings are
   subject-less predicates so callers can prefix 'the "when" condition '. */
/* Extended operands are emitted only by the permission-aware server compiler. */
function QRID_temporalMultiply(a,b){
  if(!QRID_WHEN_NUM_RE.test(a)||!QRID_WHEN_NUM_RE.test(b)) return null;
  var negative=(a.charAt(0)==='-')!==(b.charAt(0)==='-'),scale=0;
  var parts=[a,b].map(function(v){v=v.replace(/^[+-]/,'');var p=v.split('.');scale+=(p[1]||'').length;return v.replace('.','').replace(/^0+/,'');});
  a=parts[0];b=parts[1];if(a.length+b.length>4096)return null;if(!a||!b)return '0';
  var out=new Array(a.length+b.length).fill(0);
  for(var i=a.length-1;i>=0;i--)for(var j=b.length-1;j>=0;j--){var n=out[i+j+1]+Number(a[i])*Number(b[j]);out[i+j+1]=n%10;out[i+j]+=Math.floor(n/10);}
  var v=out.join('').replace(/^0+/,'');if(scale){while(v.length<scale+1)v='0'+v;v=v.slice(0,-scale)+'.'+v.slice(-scale);v=QRID_trimZerosRight(v).replace(/\.$/,'');}
  return (negative?'-':'')+v;
}
/* x/1 against y/n needs one multiplication, not two. Twin of TemporalLogic::scaled. */
function QRID_temporalScaled(numerator,denominator){
  if(denominator!=='1')return QRID_temporalMultiply(numerator,denominator);
  return typeof numerator==='string'&&QRID_WHEN_NUM_RE.test(numerator)&&numerator.length<=4096?numerator:null;
}
function QRID_temporalSum(values){
  function add(a,b){
    var sa=a[0]==='-'?-1:1,sb=b[0]==='-'?-1:1;a=a.replace(/^-/,'').replace(/^0+/,'')||'0';b=b.replace(/^-/,'').replace(/^0+/,'')||'0';
    var out='',carry=0,i=a.length-1,j=b.length-1,v;
    if(sa===sb){while(i>=0||j>=0||carry){v=(i>=0?Number(a[i--]):0)+(j>=0?Number(b[j--]):0)+carry;out=String(v%10)+out;carry=Math.floor(v/10);}return (sa<0&&out!=='0'?'-':'')+out;}
    var cmp=a.length===b.length?(a===b?0:(a<b?-1:1)):(a.length<b.length?-1:1);if(!cmp)return '0';if(cmp<0){v=a;a=b;b=v;sa=sb;}
    j=b.length-1;for(i=a.length-1;i>=0;i--){v=Number(a[i])-carry-(j>=0?Number(b[j--]):0);carry=v<0?1:0;if(v<0)v+=10;out=String(v)+out;}
    out=out.replace(/^0+/,'')||'0';return (sa<0&&out!=='0'?'-':'')+out;
  }
  var sum='0',scale=0;
  for(var i=0;i<values.length;i++){
    /* QRID_whenCompare's trim set (twin of ExactDecimal::sum): the wider PHP default
       summed "9\v" as 9 and then lost every min/max comparison as text. */
    if(typeof values[i]==='object')return null;var v=QRID_whenTrim(values[i]);if(!QRID_WHEN_NUM_RE.test(v)||v.length>4096)return null;
    var neg=v[0]==='-';v=v.replace(/^[+-]/,'');var p=v.split('.'),f=p[1]||'',d=(p[0]+f).replace(/^0+/,'')||'0';if(neg&&d!=='0')d='-'+d;
    var target=Math.max(scale,f.length);sum=add(sum+'0'.repeat(target-scale),d+'0'.repeat(target-f.length));if(sum.length>4096)return null;scale=target;
  }
  var negative=sum[0]==='-';sum=sum.replace(/^-/,'');if(scale){while(sum.length<scale+1)sum='0'+sum;sum=sum.slice(0,-scale)+'.'+sum.slice(-scale);sum=QRID_trimZerosRight(sum).replace(/\.$/,'');}
  sum=sum.replace(/^0+/,'');if(!sum||sum[0]==='.')sum='0'+sum;return (negative&&sum!=='0'?'-':'')+sum;
}
function QRID_temporalDate(value,type,format){
  if(typeof value!=='string'||!['date','datetime','datetime_seconds'].includes(type)||!['ymd','dmy','mdy'].includes(format))return null;
  var pattern=format==='ymd'?'(\\d{4})[-/](\\d{2})[-/](\\d{2})':'(\\d{2})[-/](\\d{2})[-/](\\d{4})';
  if(type!=='date')pattern+=' (\\d{2}):(\\d{2})'+(type==='datetime_seconds'?':(\\d{2})':'');
  var m=new RegExp('^'+pattern+'$').exec(value);if(!m||m[0].length!==value.length)return null;
  var y=Number(format==='ymd'?m[1]:m[3]),mo=Number(format==='dmy'?m[2]:(format==='mdy'?m[1]:m[2])),d=Number(format==='ymd'?m[3]:(format==='dmy'?m[1]:m[2]));
  var h=type==='date'?0:Number(m[4]),mi=type==='date'?0:Number(m[5]),se=type==='datetime_seconds'?Number(m[6]):0;
  if(y<1||mo<1||mo>12||d<1||h>23||mi>59||se>59)return null;
  var date=new Date(0);date.setUTCFullYear(y,mo-1,d);date.setUTCHours(h,mi,se,0);if(date.getUTCMonth()!==mo-1||date.getUTCDate()!==d)return null;
  var canonical=date.toISOString().slice(0,19).replace('T',' ');
  return {date:type==='date'?'date':'datetime',value:type==='date'?canonical.slice(0,10):canonical,seconds:date.getTime()/1000};
}
/* Blank exactly as QRID_whenCompare sees it: empty after trimming space, tab, CR, LF. */
function QRID_temporalBlank(v){ return v!==null&&typeof v!=='object'&&QRID_whenTrim(v)===''; }
/* raw: read a field as the form shows it. Only a "date" node asks for that (it
   parses the value with the field's own format); anywhere else a comparison sees
   a date as REDCap stores it (read.logicValue), as QRID_whenOperandVal does. */
function QRID_temporalValue(op,read,raw){
  if(op[0]==='lit')return op[1];if(op[0]==='unknown')return null;
  if(op[0]==='ref'){var rv=read(op[1],op[2]);return (!raw&&op[2]==null&&typeof read.logicValue==='function')?read.logicValue(op[1],rv):rv;}
  if(op[0]==='guard'){
    /* the match key as REDCap stores it, as the server saved it into the guard */
    for(var g=0;g<op[1].length;g++){var gv=read(op[1][g][0],null);if(typeof read.logicValue==='function')gv=read.logicValue(op[1][g][0],gv);if(String(gv)!==op[1][g][1])return null;}
    return QRID_temporalValue(op[2],read,raw);
  }
  if(op[0]==='date'){
    var dv=QRID_temporalValue(op[3],read,true);if(dv===null||typeof dv==='object')return null;
    /* A saved blank is a RESOLVED answer ("not entered yet"), not an unknown: it
       takes the caller's blank polarity, as a legacy comparison does. Twin of
       TemporalLogic::value. */
    if(QRID_temporalBlank(dv))return '';
    return QRID_temporalDate(dv,op[1],op[2]);
  }
  if(op[0]==='elapsed'){
    var a=QRID_temporalValue(op[2],read,raw),b=QRID_temporalValue(op[3],read,raw),units=Object.create(null);
    units.days=86400;units.hours=3600;units.minutes=60;units.seconds=1;   /* no inherited keys: "constructor" is not a unit */
    if(a===null||b===null)return null;
    if(a===''||b==='')return '';   /* elapsed from/to a date not entered yet is itself blank */
    if(!a||!b||!a.date||a.date!==b.date||!units[op[1]]||(a.date==='date'&&op[1]!=='days'))return null;
    return {numerator:String(b.seconds-a.seconds),denominator:String(units[op[1]])};
  }
  if(op[0]==='set'||op[0]==='aggregate'){
    var values=op[2].map(function(x){return QRID_temporalValue(x,read,raw);});if(values.includes(null))return null;
    if(op[0]==='set')return {set:op[1],values:values};var k=op[1];if(k==='count')return String(values.length);if(k==='exists')return values.length?'1':'0';
    values=values.filter(function(v){return v!=='';});if(k==='populated-count')return String(values.length);if(k==='distinct-count')return String(new Set(values.map(String)).size);
    if(!values.length)return null;var sum=QRID_temporalSum(values);if(sum===null)return null;if(k==='sum')return sum;if(k==='average')return {numerator:sum,denominator:String(values.length)};
    var best=values[0];values.forEach(function(v){if(QRID_whenCompare(k==='minimum'?'<':'>',v,best,true,false))best=v;});return best;
  }
  return null;
}
/* A lookup equality "same:FOLD:MARK" as [fold, mark], or null. Twin of TemporalLogic::sameFlags. */
function QRID_sameFlags(op){
  if(op==='identical')return [false,null];
  var p=String(op).split(':');
  if(p.length!==3||p[0]!=='same'||(p[1]!=='fold'&&p[1]!=='exact')||['text','point','comma'].indexOf(p[2])<0)return null;
  return [p[1]==='fold',p[2]==='text'?null:p[2]];
}
function QRID_temporalCompare(op,a,b,blank,cs){
  if(a===null||b===null)return null;
  if(op==='identical'||String(op).slice(0,5)==='same:'){var f=QRID_sameFlags(op);return f!==null&&typeof a!=='object'&&typeof b!=='object'?QRID_lookupKey(a,f[0],f[1])===QRID_lookupKey(b,f[0],f[1]):null;}
  if((a&&a.set)||(b&&b.set)){
    if(a.set&&b.set)return null;var left=!!a.set,set=left?a:b;if(!set.values.length)return null;var and=set.set==='all',unknown=false;
    for(var i=0;i<set.values.length;i++){var r=QRID_temporalCompare(op,left?set.values[i]:a,left?b:set.values[i],blank,cs);if(r===null)unknown=true;else if(r!==and)return !and;}return unknown?null:and;
  }
  if((a&&a.date)||(b&&b.date)){
    if(QRID_temporalBlank(a)||QRID_temporalBlank(b))return QRID_whenCompare(op,typeof a==='object'?a.value:'',typeof b==='object'?b.value:'',blank,cs);
    if(!a.date||a.date!==b.date)return null;return QRID_whenCompare(op,a.value,b.value,blank,cs);
  }
  if(typeof a==='object'||typeof b==='object'){
    if(typeof a!=='object')a=QRID_whenTrim(a);if(typeof b!=='object')b=QRID_whenTrim(b);
    /* Blank against an average or elapsed time: absence, not an unknown. */
    if(a===''||b==='')return QRID_whenCompare(op,typeof a==='object'&&a.numerator!==undefined?a.numerator:'',typeof b==='object'&&b.numerator!==undefined?b.numerator:'',blank,cs);
    a=typeof a==='object'?a:{numerator:String(a),denominator:'1'};b=typeof b==='object'?b:{numerator:String(b),denominator:'1'};
    if(a.numerator===undefined||b.numerator===undefined)return null;
    var x=QRID_temporalScaled(a.numerator,b.denominator),y=QRID_temporalScaled(b.numerator,a.denominator);if(x===null||y===null)return null;a=x;b=y;
  }
  return QRID_whenCompare(op,String(a),String(b),blank,cs);
}
function QRID_temporalEvaluate(ast,read,blank,cs){
  if(ast[0]==='temporal')return QRID_temporalEvaluate(ast[1],read,blank,cs);
  if(ast[0]==='const')return !!ast[1];if(ast[0]==='unknown')return null;
  if(ast[0]==='not'){var n=QRID_temporalEvaluate(ast[1],read,!blank,cs);return n===null?null:!n;}
  if(ast[0]==='and'||ast[0]==='or'){
    var and=ast[0]==='and',unknown=false;for(var i=0;i<ast[1].length;i++){var r=QRID_temporalEvaluate(ast[1][i],read,blank,cs);if(r===null)unknown=true;else if(r!==and)return !and;}return unknown?null:and;
  }
  if(ast[0]==='cmp')return QRID_temporalCompare(ast[1],QRID_temporalValue(ast[2],read),QRID_temporalValue(ast[3],read),blank,cs);
  return null;
}

/* ---- @UVWINDOW date helpers. Twins of TemporalValue::family / canonical /
   format and TemporalLogic::windowVerdict; tests/window_fixture.json drives both
   runtimes (tests/window_php.php, tests/window_js.cjs). */
var QRID_WINDOW_UNITS=Object.create(null);   /* no inherited keys: "constructor" is not a unit */
QRID_WINDOW_UNITS.minutes=60;QRID_WINDOW_UNITS.hours=3600;QRID_WINDOW_UNITS.days=86400;QRID_WINDOW_UNITS.weeks=604800;
function QRID_temporalFamily(type){ return type==='date'?'date':'datetime'; }
/* Seconds of UTC wall-clock time back to the canonical form QRID_temporalDate
   produces; null outside the years 1-9999, which no date field can hold. */
function QRID_temporalCanonical(seconds,type){
  var d=new Date(seconds*1000),y=d.getUTCFullYear();
  if(!isFinite(y)||y<1||y>9999)return null;
  var iso=d.toISOString();
  return type==='date'?iso.slice(0,10):iso.slice(0,19).replace('T',' ');
}
/* Calendar arithmetic for @UVWINDOW months, years and periods: Y-M-D dates in
   and out, null for an invalid input or a result outside the years 1-9999.
   Twins of TemporalValue::shiftMonths and the rest; tests/window_fixture.json
   "calendar" drives both. A month shift clamps the day to the end of the
   target month (Jan 31 + 1 month = Feb 28); Date.setUTCMonth would overflow. */
function QRID_daysInMonth(y,m){
  if(m===2)return (y%4===0&&(y%100!==0||y%400===0))?29:28;
  return (m===4||m===6||m===9||m===11)?30:31;
}
function QRID_ymd(date){
  var p=QRID_temporalDate(typeof date==='string'?date:'','date','ymd');if(!p)return null;
  return [Number(p.value.slice(0,4)),Number(p.value.slice(5,7)),Number(p.value.slice(8,10))];
}
function QRID_shiftMonths(date,n){
  var p=QRID_ymd(date);if(!p||typeof n!=='number'||Math.floor(n)!==n)return null;
  var total=p[0]*12+(p[1]-1)+n;if(total<12||total>9999*12+11)return null;
  var y=Math.floor(total/12),mo=total-y*12+1,d=Math.min(p[2],QRID_daysInMonth(y,mo));
  return ('000'+y).slice(-4)+'-'+('0'+mo).slice(-2)+'-'+('0'+d).slice(-2);
}
function QRID_shiftDays(date,n){
  var p=QRID_temporalDate(typeof date==='string'?date:'','date','ymd');
  if(!p||typeof n!=='number'||Math.floor(n)!==n)return null;
  return QRID_temporalCanonical(p.seconds+n*86400,'date');
}
function QRID_isoWeekday(date){
  var p=QRID_temporalDate(typeof date==='string'?date:'','date','ymd');if(!p)return null;
  var days=Math.floor(p.seconds/86400);   /* 1970-01-01 was a Thursday */
  return (((days+3)%7)+7)%7+1;
}
function QRID_periodStart(date,period,weekStart){
  if(weekStart===undefined)weekStart='monday';
  var p=QRID_ymd(date);if(!p)return null;
  var pad=function(v,w){return ('000'+v).slice(-w);};
  if(period==='week'){
    if(weekStart!=='monday'&&weekStart!=='sunday')return null;
    var back=(QRID_isoWeekday(date)-(weekStart==='sunday'?7:1)+7)%7;
    return QRID_shiftDays(date,-back);
  }
  if(period==='month')return pad(p[0],4)+'-'+pad(p[1],2)+'-01';
  if(period==='quarter')return pad(p[0],4)+'-'+pad(Math.floor((p[1]-1)/3)*3+1,2)+'-01';
  if(period==='year')return pad(p[0],4)+'-01-01';
  return null;
}
function QRID_periodShift(start,period,n){
  if(typeof n!=='number'||Math.floor(n)!==n)return null;
  if(period==='week')return QRID_shiftDays(start,7*n);
  if(period==='month')return QRID_shiftMonths(start,n);
  if(period==='quarter')return QRID_shiftMonths(start,3*n);
  if(period==='year')return QRID_shiftMonths(start,12*n);
  return null;
}
function QRID_periodEnd(start,period){
  var next=QRID_periodShift(start,period,1);
  return next===null?null:QRID_shiftDays(next,-1);
}
/* A canonical value written the way a field of this type and format shows it. */
function QRID_temporalFormat(canonical,type,format){
  var m=typeof canonical==='string'?/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(canonical):null;
  if(!m)return '';
  var date=format==='dmy'?m[3]+'-'+m[2]+'-'+m[1]:(format==='mdy'?m[2]+'-'+m[3]+'-'+m[1]:m[1]+'-'+m[2]+'-'+m[3]);
  if(type==='date')return date;
  var time=(m[4]!==undefined?m[4]:'00')+':'+(m[5]!==undefined?m[5]:'00');
  if(type==='datetime_seconds')time+=':'+(m[6]!==undefined&&m[6]!==''?m[6]:'00');
  return date+' '+time;
}
/* The @UVWINDOW verdict: inert (blank, or nothing applied) | unknown | future | past | window-early | window-late | ok,
   with the window's earliest / latest bound (canonical, null when open). Pure:
   the caller passes the clock. See TemporalLogic::windowVerdict. */
var QRID_CALENDAR_UNITS=Object.create(null);QRID_CALENDAR_UNITS.months=1;QRID_CALENDAR_UNITS.years=12;
function QRID_unitFits(unit,type){
  if(typeof unit!=='string'||(QRID_WINDOW_UNITS[unit]===undefined&&QRID_CALENDAR_UNITS[unit]===undefined))return false;
  return QRID_temporalFamily(type)!=='date'||(unit!=='minutes'&&unit!=='hours');
}
function QRID_windowVerdict(spec,value,valueFormat,anchor,anchorFormat,clock){
  var out={verdict:'ok',earliest:null,latest:null};
  if(typeof value!=='string'||QRID_whenTrim(value)===''){out.verdict='inert';return out;}
  var type=spec.type==null?null:spec.type;
  var v=QRID_temporalDate(QRID_whenTrim(value),type,valueFormat);
  if(!v){out.verdict='unknown';return out;}
  var lo=spec.lo==null?null:spec.lo,hi=spec.hi==null?null:spec.hi,period=spec.period==null?null:spec.period;
  var unit=spec.unit==null?'days':spec.unit,hasWindow=lo!==null||hi!==null||period!==null,windowReason=null,checked=false;
  /* The window part cannot be judged (anchor false: unresolved or not sent;
     unreadable; of another kind). That never stops "notFuture" or "notPast". */
  var windowUnknown=false,a=null,anchored=typeof anchor==='string'&&QRID_whenTrim(anchor)!=='';
  if(hasWindow&&anchor===false)windowUnknown=true;
  else if(hasWindow&&anchored){
    var fromType=spec.fromType==null?null:spec.fromType;
    a=QRID_temporalDate(QRID_whenTrim(anchor),fromType,anchorFormat);
    /* A period reads only the anchor's day, so it may count from either kind of date. */
    if(!a||(period===null&&(QRID_temporalFamily(type)!==QRID_temporalFamily(fromType)||!QRID_unitFits(unit,type))))windowUnknown=true;
  }
  if(!windowUnknown&&hasWindow&&anchored){
    if(type==='datetime')a.seconds-=((a.seconds%60)+60)%60;   /* read to the minute, as the field is */
    if(period!==null||QRID_CALENDAR_UNITS[unit]!==undefined){
      var b=QRID_calendarBounds(spec,a,v.date);
      if(b===null)windowUnknown=true;
      else{
        checked=true;out.earliest=b[0];out.latest=b[1];
        if(b[0]!==null&&v.value<b[0])windowReason='window-early';
        else if(b[1]!==null&&v.value>b[1])windowReason='window-late';
      }
    }else{
      checked=true;
      var u=QRID_WINDOW_UNITS[unit];
      var diff=v.seconds-a.seconds;
      if(lo!==null)out.earliest=QRID_temporalCanonical(a.seconds+(lo|0)*u,v.date);
      if(hi!==null)out.latest=QRID_temporalCanonical(a.seconds+(hi|0)*u,v.date);
      if(lo!==null&&diff<(lo|0)*u)windowReason='window-early';
      else if(hi!==null&&diff>(hi|0)*u)windowReason='window-late';
    }
  }
  if(spec.notFuture||spec.notPast){
    var today=clock&&typeof clock==='object'&&clock.today!=null?clock.today:null;
    var now=clock&&typeof clock==='object'&&clock.now!=null?QRID_temporalDate(String(clock.now),'datetime_seconds','ymd'):null;
    /* No clock: a window the value broke is still a finding. */
    if(typeof today!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(today)||!now){out.verdict=windowReason!==null?windowReason:'unknown';return out;}
    checked=true;
    if(spec.notFuture&&(v.date==='date'?v.value>today:v.seconds>QRID_clockMoment(clock,'futureNow',now))){out.verdict='future';return out;}
    if(spec.notPast&&(v.date==='date'?v.value<today:v.seconds<QRID_clockMoment(clock,'pastNow',now))){out.verdict='past';return out;}
  }
  if(windowUnknown)out.verdict='unknown';
  else if(windowReason!==null)out.verdict=windowReason;
  else if(!checked)out.verdict='inert';   /* a blank anchor and no notFuture or notPast: nothing was checked */
  return out;
}
/* The clock's key moment in seconds, or now's when it has none that reads. */
function QRID_clockMoment(clock,key,now){
  if(clock[key]!=null){var m=QRID_temporalDate(String(clock[key]),'datetime_seconds','ymd');if(m)return m.seconds;}
  return now.seconds;
}
/* [earliest, latest] of a months/years window or a period, or null when a
   bound falls outside the years 1-9999. Twin of TemporalLogic::calendarBounds. */
function QRID_calendarBounds(spec,a,valueType){
  var anchor=QRID_temporalCanonical(a.seconds,'datetime');if(anchor===null)return null;
  var day=anchor.slice(0,10);
  if(spec.period!=null){
    var period=spec.period,start=QRID_periodStart(day,period,spec.weekStart==null?'monday':spec.weekStart);
    var offLo=spec.offLo==null?0:spec.offLo,offHi=spec.offHi==null?0:spec.offHi;
    var first=start===null?null:QRID_periodShift(start,period,offLo);
    var lastStart=start===null?null:QRID_periodShift(start,period,offHi);
    var last=lastStart===null?null:QRID_periodEnd(lastStart,period);
    if(first===null||last===null)return null;
    return valueType==='date'?[first,last]:[first+' 00:00:00',last+' 23:59:59'];
  }
  var k=QRID_CALENDAR_UNITS[spec.unit],time=valueType==='date'?'':anchor.slice(10),out=[null,null],keys=['lo','hi'];
  for(var i=0;i<2;i++){
    if(spec[keys[i]]==null)continue;
    var d=QRID_shiftMonths(day,(spec[keys[i]]|0)*k);if(d===null)return null;
    out[i]=d+time;
  }
  return out;
}
/* The server's wall-clock time now: config.clock, stamped when the page was
   built, advanced by how long the page has been open. A page left open across
   midnight therefore moves to the next day. The elapsed time never goes
   backwards, so setting the computer's clock back cannot undo a day. null
   without a usable server clock. */
var QRID_CLOCK_T0=Date.now(),QRID_CLOCK_ELAPSED=0;
function QRID_clockNow(base){
  var e=Date.now()-QRID_CLOCK_T0;if(e>QRID_CLOCK_ELAPSED)QRID_CLOCK_ELAPSED=e;
  return QRID_clockAt(base,QRID_CLOCK_ELAPSED);
}
/* config.clock advanced by elapsedMs. The clock also carries its moment in
   UTC milliseconds (utc), the zone's offset in seconds (offset), and the
   zone's next offset change (next, in UTC milliseconds, with offsetAfter):
   a page left open across a daylight-saving change moves its local time by
   the change, as the server's clock does. Only that one change is known: a
   page open across a second one (Casablanca's pair around Ramadan) is off by
   it until reloaded. */
function QRID_clockAt(base,elapsedMs){
  if(!base||typeof base!=='object'||typeof base.now!=='string')return null;
  var b=QRID_temporalDate(base.now,'datetime_seconds','ymd');if(!b)return null;
  var secs=b.seconds+Math.floor(elapsedMs/1000);
  if(QRID_finiteNumber(base.utc)&&QRID_finiteNumber(base.offset)&&QRID_finiteNumber(base.next)
     &&QRID_finiteNumber(base.offsetAfter)&&base.utc+elapsedMs>=base.next)secs+=base.offsetAfter-base.offset;
  var now=QRID_temporalCanonical(secs,'datetime');
  return now===null?null:{today:now.slice(0,10),now:now};
}
function QRID_finiteNumber(v){ return typeof v==='number'&&isFinite(v); }
/* "Now" for a date and time typed on this page is a span around the server's
   clock. futureNow (for notFuture, and the late end of a window counted from
   "now") is the clock plus 120 seconds (TemporalLogic::CLOCK_SLACK_S, which
   the post-save audit allows too), plus how far this computer's clock runs
   ahead of the server's, up to 10 minutes. pastNow (for notPast, and the
   early end of a window counted from "now") is as far before it, plus how far
   this computer's clock runs behind. REDCap's Now button and @NOW fill in the
   computer's time, so on a computer a little off they still pass. The error
   is measured in UTC (QRID_deviceClockError), so the computer's time zone
   plays no part: a computer set to another zone than the rule's is still
   refused, which points to the project's time zone setting. "today" and
   "now" are unchanged, and dates have no margin. */
var QRID_CLOCK_SLACK_S=120,QRID_DEVICE_ERROR_MAX_S=600;
function QRID_clockLenient(clock,errorSeconds){
  var b=clock?QRID_temporalDate(clock.now,'datetime_seconds','ymd'):null;if(!b)return clock;
  var err=QRID_finiteNumber(errorSeconds)?errorSeconds:0;
  var lead=err>0?Math.min(Math.floor(err),QRID_DEVICE_ERROR_MAX_S):0;
  var lag=err<0?Math.min(Math.floor(-err),QRID_DEVICE_ERROR_MAX_S):0;
  var out={today:clock.today,now:clock.now};
  var later=QRID_temporalCanonical(b.seconds+QRID_CLOCK_SLACK_S+lead,'datetime');
  var earlier=QRID_temporalCanonical(b.seconds-QRID_CLOCK_SLACK_S-lag,'datetime');
  if(later!==null)out.futureNow=later;
  if(earlier!==null)out.pastNow=earlier;
  return out;
}
/* How many seconds this computer's clock ran ahead of the server's when the
   page loaded (behind is negative), from the clock's UTC stamp; null without
   one. The time the page took to arrive counts as a little lead. */
function QRID_deviceClockError(base){
  return (base&&typeof base==='object'&&QRID_finiteNumber(base.utc))?(QRID_CLOCK_T0-base.utc)/1000:null;
}
/* A period by name: "last month", "the 3 months before this one", "the month
   of [visit_date]". rel: counted from today or now; otherwise name is the
   "from". Twin of TemporalLogic::periodLabel, which names it in the scan
   report; the "labels" cases of tests/window_fixture.json lock both. */
function QRID_windowPeriodLabel(p, a, b, name, rel){
  function plural(n){ return n + " " + p + (n === 1 ? "" : "s"); }
  function one(o){
    if(rel) return o === 0 ? "this " + p : o === -1 ? "last " + p : o === 1 ? "next " + p
                 : "the " + p + " " + plural(Math.abs(o)) + (o < 0 ? " ago" : " from now");
    var of = "the " + p + " of " + name;
    return o === 0 ? of : plural(Math.abs(o)) + (o < 0 ? " before " : " after ") + of;
  }
  if(a === b) return one(a);
  if(rel && b === -1) return "the " + plural(-a) + " before this one";
  if(rel && a === 1) return "the " + plural(b) + " after this one";
  if(rel && b === 0) return "this " + p + " and the " + plural(-a) + " before it";
  if(rel && a === 0) return "this " + p + " and the " + plural(b) + " after it";
  if(!rel && b < 0) return (-b) + " to " + plural(-a) + " before the " + p + " of " + name;
  if(!rel && a > 0) return a + " to " + plural(b) + " after the " + p + " of " + name;
  return one(a) + " to " + one(b);
}
/* A window's bounds by name: "21 to 35 days after [visit_date_bl]", "7 to 30
   days before today", "3 days before [x] to 5 days after [x]"; one bound
   alone is "no earlier than ..." or "no later than ...". A negative bound
   reads as "before": the note printed the count with its sign, as "-7 to -7
   minutes from now" (WIN-7). Twin of TemporalLogic::spanLabel; the "spans"
   cases of tests/window_fixture.json lock both. */
function QRID_windowSpanLabel(lo, hi, unit, name){
  var u = String(unit);
  function count(n){ var one = /s$/.test(u) ? u.slice(0, -1) : u; return n + " " + one + (n === 1 ? "" : "s"); }
  function bound(n){
    if(n > 0) return count(n) + " after " + name;
    if(n < 0) return count(-n) + " before " + name;
    if(name === "today" || name === "now") return name;
    return (QRID_WINDOW_UNITS[u] !== undefined && QRID_WINDOW_UNITS[u] < 86400 ? "at the time of " : "on the day of ") + name;
  }
  if(lo == null && hi == null) return "";
  if(hi == null) return "no earlier than " + (lo === 0 ? name : bound(lo));
  if(lo == null) return "no later than " + (hi === 0 ? name : bound(hi));
  if(lo === hi) return bound(lo);
  if(lo >= 0) return lo + " to " + count(hi) + " after " + name;
  if(hi <= 0) return (-hi) + " to " + count(-lo) + " before " + name;
  return bound(lo) + " to " + bound(hi);
}
/* The verdict for a window counted from a moment known only to within a
   margin (a "from" of "now", which a computer a little off fills in): a value
   passes when some anchor between anchorLo and anchorHi accepts it. The
   earliest bound comes from anchorLo, the latest from anchorHi. Twin of
   TemporalLogic::windowVerdictSpread. */
function QRID_windowVerdictSpread(spec,value,valueFormat,anchorLo,anchorHi,anchorFormat,clock){
  var lo=QRID_windowVerdict(spec,value,valueFormat,anchorLo,anchorFormat,clock);
  if(anchorHi===anchorLo)return lo;
  var hi=QRID_windowVerdict(spec,value,valueFormat,anchorHi,anchorFormat,clock);
  var windowish=['ok','window-early','window-late'];
  if(windowish.indexOf(lo.verdict)<0)return lo;
  if(windowish.indexOf(hi.verdict)<0)return hi;
  var verdict=lo.verdict==='window-early'?'window-early':(hi.verdict==='window-late'?'window-late':'ok');
  return {verdict:verdict,earliest:lo.earliest,latest:hi.latest};
}
/* The temporal type of a date written in a rule ("from": "2026-01-01"): date,
   datetime (to the minute) or datetime_seconds; null when it is not a date.
   Twin of TemporalLogic::anchorType. */
function QRID_anchorType(text){
  if(typeof text!=='string')return null;
  var type=text.length===10?'date':(text.length===16?'datetime':'datetime_seconds');
  return QRID_temporalDate(text,type,'ymd')?type:null;
}
/* The type of a "from" keyword's or written date's anchor: today a date, now a
   date and time to the second. Twin of TemporalLogic::keywordAnchorType. */
function QRID_keywordAnchorType(anchor){
  if(anchor==='today')return 'date';
  if(anchor==='now')return 'datetime_seconds';
  return QRID_anchorType(anchor);
}

/* Opt-in qualified grammar. The legacy lexer and AST remain unchanged. */
function QRID_whenRefKey(ref){
  if(ref[0] === "binding") return "{" + ref[1] + "}";
  if(ref[0] !== "qref") throw new Error("Expected an extended reference.");
  return (ref[3] === null ? "" : "[" + ref[3] + "]") + "[" + ref[1] +
    (ref[2] === null ? "" : "(" + ref[2] + ")") + "]" + (ref[4] === null ? "" : "[" + ref[4] + "]");
}
function QRID_whenQualifiedToken(text){
  var error = {error: "has an invalid event, instance, field, or binding reference."};
  var m, g = [], pattern, part;
  if(text.charAt(0) === "{"){
    m = /^\{([A-Za-z][A-Za-z0-9_]*)\}/.exec(text);
    return m ? {node: ["binding", m[1].toLowerCase()], length: m[0].length} : error;
  }
  m = /^(?:\[[^\[\]]*\])+/.exec(text);
  if(!m) return error;
  pattern = /\[([^\[\]]*)\]/g;
  while((part = pattern.exec(m[0])) !== null) g.push(part[1]);
  if(g.length > 3) return error;
  function isInstance(v){
    return /^[1-9][0-9]*$/.test(v) || ["current-instance","previous-instance","next-instance","first-instance","last-instance","any-instance","all-instances"].indexOf(v.toLowerCase()) !== -1;
  }
  var event = null, instance = null, field = g[0];
  if(g.length === 2){
    if(isInstance(g[1])) instance = g[1].toLowerCase();
    else {event = g[0].toLowerCase(); field = g[1];}
  } else if(g.length === 3){
    event = g[0].toLowerCase(); field = g[1]; instance = g[2].toLowerCase();
    if(!isInstance(instance)) return error;
  }
  if(event !== null && !/^[a-z0-9_]+$/.test(event) && ["event-name","previous-event-name","next-event-name","first-event-name","last-event-name"].indexOf(event) === -1) return error;
  var f = /^([A-Za-z][A-Za-z0-9_]*)(?:\(([A-Za-z0-9._-]+)\))?$/.exec(field);
  if(!f) return error;
  var node = ["ref", f[1].toLowerCase(), f[2] === undefined ? null : f[2]];
  if(g.length > 1) node = ["qref", node[1], node[2], event, instance];
  return {node: node, length: m[0].length};
}
function QRID_whenQualifiedRefs(ast){
  var out = [], seen = Object.create(null);
  function walk(n){
    if(n[0] === "qref" || n[0] === "binding"){
      var key = QRID_whenRefKey(n); if(!seen[key]){ seen[key] = true; out.push(n); } return;
    }
    if(n[0] === "cmp"){walk(n[2]); walk(n[3]);}
    else if(n[0] === "not") walk(n[1]);
    else if(n[0] === "and" || n[0] === "or") n[1].forEach(walk);
  }
  walk(ast); return out;
}

function QRID_whenParse(expr, opts){
  if(typeof expr !== "string") return QRID_whenErr("must be a non-empty condition string.");
  /* trimmed by index, not /^ +| +$/: that regex retries " +$" from every
     space of a run inside the condition, which is quadratic in its length */
  var s = expr.replace(/[\t\r\n]/g, " "), a = 0, b = s.length;
  while(a < b && s.charCodeAt(a) === 32) a++;
  while(b > a && s.charCodeAt(b - 1) === 32) b--;
  s = s.slice(a, b);
  if(s === "") return QRID_whenErr("must be a non-empty condition string.");
  if(/[^\x20-\x7E]/.test(s)) return QRID_whenErr("must contain printable ASCII only.");
  if(s.length > QRID_WHEN_MAX_LEN) return QRID_whenErr("is limited to " + QRID_WHEN_MAX_LEN + " characters.");
  var lex = QRID_whenLex(s, opts || {});
  if(lex.error) return QRID_whenErr(lex.error);
  var st = { t: lex.tokens, p: 0 };
  var r = QRID_whenOr(st, 0);
  if(r.error) return QRID_whenErr(r.error);
  if(st.p < st.t.length){
    return QRID_whenErr('has unexpected content after a complete condition — combine comparisons with "and"/"or".');
  }
  return { ok: true, ast: r.node };
}
function QRID_whenLex(s, opts){
  var tokens = [];
  var n = s.length;
  var refs = 0;
  var i = 0;
  while(i < n){
    var ch = s.charAt(i);
    if(ch === " "){ i++; continue; }
    if(opts && opts.qualified && (ch === "[" || ch === "{")){
      var q = QRID_whenQualifiedToken(s.slice(i));
      if(q.error) return q;
      tokens.push(q.node); i += q.length;
      if(++refs > QRID_WHEN_MAX_REFS) return {error: "uses more than " + QRID_WHEN_MAX_REFS + " field references."};
      continue;
    }
    if(ch === "["){
      var m = /^\[([A-Za-z][A-Za-z0-9_]*)(\(([A-Za-z0-9._-]+)\))?\]/.exec(s.slice(i));
      if(!m){
        return { error: "may only use plain [field] or [field(code)] references — no [event][field] prefixes, smart variables, or empty brackets." };
      }
      i += m[0].length;
      if(i < n && s.charAt(i) === "["){
        return { error: "may only use plain [field] or [field(code)] references — no [event][field] prefixes, smart variables, or empty brackets." };
      }
      refs++;
      if(refs > QRID_WHEN_MAX_REFS){
        return { error: "uses more than " + QRID_WHEN_MAX_REFS + " field references." };
      }
      tokens.push(["ref", m[1].toLowerCase(), (m[3] !== undefined && m[3] !== "") ? m[3] : null]);
      continue;
    }
    if(ch === "'" || ch === '"'){
      var end = s.indexOf(ch, i + 1);
      if(end === -1) return { error: "has an unterminated quoted string." };
      tokens.push(["lit", s.slice(i + 1, end)]);
      i = end + 1;
      continue;
    }
    if(ch === "-" || (ch >= "0" && ch <= "9")){
      var nm = /^-?[0-9]+(\.[0-9]+)?/.exec(s.slice(i));
      if(nm){
        tokens.push(["lit", nm[0]]); /* numbers are literals; numeric-ness is decided at compare time */
        i += nm[0].length;
        continue;
      }
      return { error: 'contains an unexpected character "' + ch + '".' };
    }
    var two = s.slice(i, i + 2);
    if(two === "<>" || two === "<=" || two === ">="){ tokens.push(["op", two]); i += 2; continue; }
    if(two === "!="){ tokens.push(["op", "<>"]); i += 2; continue; } /* canonicalized */
    if(ch === "="){ tokens.push(["op", "="]); i++; continue; }
    if(ch === "<" || ch === ">"){ tokens.push(["op", ch]); i++; continue; }
    if(ch === "("){ tokens.push(["("]); i++; continue; }
    if(ch === ")"){ tokens.push([")"]); i++; continue; }
    var wm = /^[A-Za-z_][A-Za-z0-9_]*/.exec(s.slice(i));
    if(wm){
      var w = wm[0].toLowerCase();
      i += wm[0].length;
      if(w === "and" || w === "or" || w === "not"){ tokens.push(["kw", w]); continue; }
      var j = i;
      while(j < n && s.charAt(j) === " ") j++;
      if(j < n && s.charAt(j) === "("){
        return { error: 'uses the function "' + wm[0] + '(...)", which is not supported — only [field] or [field(code)] comparisons combined with and/or/not and parentheses.' };
      }
      return { error: 'contains the bare word "' + wm[0] + '" — field references are written in [brackets], text values in quotes.' };
    }
    /* REDCap logic spells these and/or/not; name the word to write instead */
    if(ch === "&" || ch === "|"){
      var sym = s.charAt(i + 1) === ch ? ch + ch : ch;
      return { error: 'uses "' + sym + '" — write "' + (ch === "&" ? "and" : "or") + '" instead.' };
    }
    if(ch === "!") return { error: 'uses "!" — write "not" instead, or "<>" for "not equal".' };
    return { error: 'contains an unexpected character "' + ch + '".' };
  }
  return { tokens: tokens };
}
/* or := and ( OR and )* — returns {node} | {error}. */
function QRID_whenOr(st, depth){
  var r = QRID_whenAnd(st, depth);
  if(r.error) return r;
  var children = [r.node];
  while(QRID_whenPeekKw(st, "or")){
    st.p++;
    r = QRID_whenAnd(st, depth);
    if(r.error) return r;
    children.push(r.node);
  }
  return { node: children.length === 1 ? children[0] : ["or", children] };
}
/* and := not ( AND not )* */
function QRID_whenAnd(st, depth){
  var r = QRID_whenNot(st, depth);
  if(r.error) return r;
  var children = [r.node];
  while(QRID_whenPeekKw(st, "and")){
    st.p++;
    r = QRID_whenNot(st, depth);
    if(r.error) return r;
    children.push(r.node);
  }
  return { node: children.length === 1 ? children[0] : ["and", children] };
}
/* not := NOT not | primary — "not" adds a nesting level. */
function QRID_whenNot(st, depth){
  if(QRID_whenPeekKw(st, "not")){
    if(depth + 1 > QRID_WHEN_MAX_DEPTH){
      return { error: "is nested more than " + QRID_WHEN_MAX_DEPTH + " levels deep." };
    }
    st.p++;
    var r = QRID_whenNot(st, depth + 1);
    if(r.error) return r;
    return { node: ["not", r.node] };
  }
  return QRID_whenPrimary(st, depth);
}
/* primary := '(' or ')' | comparison — parentheses add a nesting level. */
function QRID_whenPrimary(st, depth){
  var t = QRID_whenPeek(st);
  if(t !== null && t[0] === "("){
    if(depth + 1 > QRID_WHEN_MAX_DEPTH){
      return { error: "is nested more than " + QRID_WHEN_MAX_DEPTH + " levels deep." };
    }
    st.p++;
    var r = QRID_whenOr(st, depth + 1);
    if(r.error) return r;
    t = QRID_whenPeek(st);
    if(t === null || t[0] !== ")") return { error: "has an unbalanced parenthesis." };
    st.p++;
    return r;
  }
  return QRID_whenCmp(st);
}
/* comparison := operand op operand */
function QRID_whenCmp(st){
  var lhs = QRID_whenOperandTok(st);
  if(lhs.error) return lhs;
  var t = QRID_whenPeek(st);
  if(t === null || t[0] !== "op"){
    return { error: "must compare each [field] or value to something, e.g. [specimen_type]='2'." };
  }
  var op = t[1];
  st.p++;
  var rhs = QRID_whenOperandTok(st);
  if(rhs.error) return rhs;
  var sets = [lhs.node, rhs.node].filter(function(n){
    return n[0] === "qref" && (n[4] === "any-instance" || n[4] === "all-instances");
  }).length;
  if(sets > 1) return {error: "allows at most one collection operand per comparison."};
  /* [h]>[h] is always false and [h]=[h] always true: a rule built on it never
     reads the value (AST-11). Twin of Logic::parseCmp. */
  if(lhs.node[0] !== "lit" && JSON.stringify(lhs.node) === JSON.stringify(rhs.node)){
    var nd = lhs.node;
    var name = nd[0] === "ref" ? '"[' + nd[1] + (nd[2] !== null ? "(" + nd[2] + ")" : "") + ']"' : "a reference";
    return { error: "compares " + name + " with itself, so the answer never depends on the value. " +
      "Compare it with another field or a value." };
  }
  return { node: ["cmp", op, lhs.node, rhs.node] };
}
/* operand := ref | string | number */
function QRID_whenOperandTok(st){
  var t = QRID_whenPeek(st);
  if(t === null){
    return { error: "ends where a [field] or quoted value was expected." };
  }
  if(t[0] === "qref" || t[0] === "binding"){ st.p++; return {node: t}; }
  if(t[0] === "ref"){ st.p++; return { node: ["ref", t[1], t[2]] }; }
  if(t[0] === "lit"){ st.p++; return { node: ["lit", t[1]] }; }
  var label = (t[0] === "op" || t[0] === "kw") ? t[1] : t[0];
  return { error: 'has "' + label + '" where a [field] or quoted value was expected.' };
}
function QRID_whenPeek(st){
  return st.p < st.t.length ? st.t[st.p] : null;
}
function QRID_whenPeekKw(st, kw){
  var t = QRID_whenPeek(st);
  return t !== null && t[0] === "kw" && t[1] === kw;
}
/* Evaluate against a resolver callback (field, codeOrNull) -> string. The
   runtime gate resolves from the live DOM/snapshot; the map form below is the
   fixture-locked entry point. */
function QRID_whenEvaluateWith(ast, resolve, blank, caseSensitive){
  if(blank === undefined) blank = QRID_BLANK_PASSES;
  if(caseSensitive === undefined) caseSensitive = false;
  switch(ast[0]){
    case "temporal": return QRID_temporalEvaluate(ast, resolve, blank, caseSensitive);
    case "const":
      return !!ast[1];
    case "or":
      for(var i = 0; i < ast[1].length; i++){ if(QRID_whenEvaluateWith(ast[1][i], resolve, blank, caseSensitive)) return true; }
      return false;
    case "and":
      for(var j = 0; j < ast[1].length; j++){ if(!QRID_whenEvaluateWith(ast[1][j], resolve, blank, caseSensitive)) return false; }
      return true;
    case "not":
      /* THE FLIP — twin of Logic::evaluate. "No answer" must mean the same
         thing about the WHOLE condition however deeply it is negated, so
         descending through a "not" inverts the constant substituted for it.
         Without it, not([dose]>[max]) and [dose]<=[max] disagree on a blank
         [max], which is the original defect in a different spelling. Case
         sensitivity does not flip: it is how strings match, not what "no
         answer" means. */
      return !QRID_whenEvaluateWith(ast[1], resolve, !blank, caseSensitive);
    case "cmp":
      if(typeof resolve.isMissing === "function" && QRID_whenCodeCompared(ast, resolve)) return blank;
      return QRID_whenCompare(ast[1],
        QRID_whenOperandVal(ast[2], resolve),
        QRID_whenOperandVal(ast[3], resolve),
        blank, caseSensitive);
  }
  return false; /* unreachable for QRID_whenParse-produced ASTs */
}
/* Whether the comparison is ordered (< > <= >=) and a plain [field] operand
   holds a Missing Data Code (resolve.isMissing), so it has no answer: [end] >=
   [start] with start UNK passes as a test and leaves a "when" inert, while =
   and <> read the code as itself (AST-10). Twin of Logic::codeCompared. */
function QRID_whenCodeCompared(ast, resolve){
  if(QRID_WHEN_ORDERED[ast[1]] !== true) return false;
  for(var s = 2; s <= 3; s++){
    var op = ast[s];
    if(op[0] === "ref" && op[2] === null && resolve.isMissing(resolve(op[1], null))) return true;
  }
  return false;
}
/* The value of field f as a condition reads it: a field that holds numbers
   (marks: field => "point" | "comma") as its number, written as NUM_RE
   (QRID_rangeNumber); anything else, or a value that is not a number,
   unchanged. Twin of Logic::numberValue. */
function QRID_numberValue(f, v, marks){
  if(!marks || typeof v !== "string" || !Object.prototype.hasOwnProperty.call(marks, f)) return v;
  var n = QRID_rangeNumber(v, marks[f] === "comma");
  return (n === null || n === "") ? v : n;
}
function QRID_whenOperandVal(op, resolve){
  if(op[0] === "qref" || op[0] === "binding"){
    if(!resolve.extendedValues) throw new Error("Resolve extended references before browser evaluation.");
    var xv = resolve(QRID_whenRefKey(op), null, true);
    return (op[0] === "qref" && op[2] === null && typeof resolve.numberValue === "function") ? resolve.numberValue(op[1], xv) : xv;
  }
  if(op[0] === "lit") return op[1];
  /* A page resolver may say how a field's value is compared (resolve.logicValue:
     a D-M-Y date as its stored Y-M-D). A temporal "date" node reads the value
     raw, with the field's own format. */
  var v = resolve(op[1], op[2]);
  return (op[2] === null && typeof resolve.logicValue === "function") ? resolve.logicValue(op[1], v) : v;
}
/* Evaluate against a value map (field => string, or field => {code:'0'|'1'}
   for checkboxes). Missing fields resolve to '' (checkbox refs to '0').
   numbers (field => "point" | "comma") names the fields that hold numbers,
   as the $numbers map of Logic::evaluate does; codes lists the project's
   Missing Data Codes, as its $codes does. */
function QRID_whenEvaluate(ast, values, blank, caseSensitive, numbers, codes){
  if(blank === undefined) blank = QRID_BLANK_PASSES;
  var resolve = function(f, code, extended){
    if(extended && (!values || !Object.prototype.hasOwnProperty.call(values, f) || values[f] === null || typeof values[f] === "object")) throw new Error("Unresolved extended reference.");
    var v = (values && Object.prototype.hasOwnProperty.call(values, f)) ? values[f] : null;
    if(code !== null){
      if(v !== null && typeof v === "object"){
        return (v[code] !== undefined && String(v[code]) === "1") ? "1" : "0";
      }
      return "0";
    }
    if(v === null || v === undefined || typeof v === "object") return "";
    return String(v);
  };
  resolve.extendedValues = true;
  if(numbers){
    resolve.logicValue = resolve.numberValue = function(f, v){ return QRID_numberValue(f, v, numbers); };
  }
  if(Array.isArray(codes) && codes.length){
    var set = Object.create(null);
    for(var i = 0; i < codes.length; i++) set[String(codes[i])] = true;
    resolve.isMissing = function(v){ return QRID_codeIn(v, set); };
  }
  return QRID_whenEvaluateWith(ast, resolve, blank, caseSensitive);
}
/* Space, tab, CR and LF off both ends, as PHP's trim($v, " \t\r\n") in Logic::compare.
   An index scan from each end (AST-8): the regex it replaces retried its end
   alternative from every character of a long inner run of spaces, so one
   check of a Notes value with 80,000 inner spaces took 3.8 s. */
function QRID_whenSpace(c){ return c === 32 || c === 9 || c === 13 || c === 10; }
/* Whether a value holds nothing a person could read: only spaces, tabs, line
   breaks, NUL, vertical tab, form feed, no-break spaces, the Unicode spaces
   U+1680, U+2000 to U+200A, U+202F, U+205F and U+3000, the line and paragraph
   separators, and the invisible U+200B to U+200F, U+2060 and U+FEFF (REQ-8).
   @UVREQUIRED asks it, so a no-break space pasted into a field is no answer.
   Twin of Logic::isBlank; tests/blank_fixture.json pins both. */
function QRID_isBlank(v){
  var s = (v === null || v === undefined) ? "" : String(v);
  for(var i = 0; i < s.length; i++){
    var c = s.charCodeAt(i);
    if(c === 0 || (c >= 9 && c <= 13) || c === 32 || c === 0xA0 || c === 0x1680 || (c >= 0x2000 && c <= 0x200F)
       || c === 0x2028 || c === 0x2029 || c === 0x202F || c === 0x205F || c === 0x2060 || c === 0x3000 || c === 0xFEFF) continue;
    return false;
  }
  return true;
}
function QRID_whenTrim(v){
  var s = String(v), i = 0, j = s.length;
  while(i < j && QRID_whenSpace(s.charCodeAt(i))) i++;
  while(j > i && QRID_whenSpace(s.charCodeAt(j - 1))) j--;
  return (i === 0 && j === s.length) ? s : s.slice(i, j);
}
/* Numeric compare (exact decimal) iff BOTH trimmed sides match QRID_WHEN_NUM_RE,
   else string compare (ASCII-identical to PHP strcmp): A-Z folded to a-z first
   by default, exact when caseSensitive is true (see Logic::compare). */
/* ASCII-only case fold, twin of the strtr map in Logic::compare. Not
   toLowerCase() on the whole string: its Unicode mappings differ from PHP's
   (U+0130 lowercases to two code units here), and the two runtimes must agree. */
function QRID_whenFoldCase(v){
  return v.replace(/[A-Z]+/g, function(m){ return m.toLowerCase(); });
}
/* Code-point string comparison, matching PHP strcmp's byte order (UTF-8 byte order
   preserves code-point order for valid text). JS "<"/">" compare UTF-16 code UNITS,
   which rank an astral (>U+FFFF) character before U+E000..U+FFFF where its code
   point ranks after — so an ordered comparison of a non-ASCII field value would
   otherwise disagree between the browser and the server (F7). ASCII/BMP results are
   identical to plain "<"/">", so only astral-plane values change behavior. */
function QRID_cmpStr(a, b){
  a = String(a); b = String(b);
  var i = 0, j = 0, na = a.length, nb = b.length;
  while(i < na && j < nb){
    var ca = a.codePointAt(i), cb = b.codePointAt(j);
    if(ca !== cb) return ca < cb ? -1 : 1;
    i += ca > 0xFFFF ? 2 : 1;
    j += cb > 0xFFFF ? 2 : 1;
  }
  var ra = na - i, rb = nb - j;   /* one side is exhausted: the shorter string sorts first */
  return ra < rb ? -1 : (ra > rb ? 1 : 0);
}
/* Exact decimal comparison of two NUM_RE-shaped strings: -1, 0 or 1. Pure
   string work — no Number, no BigInt — because parseFloat silently merged
   distinct values above 2^53 (9007199254740992 === 9007199254740993), so the
   documented [id]=[id_confirm] recipe accepted two different identifiers
   (H-03). Twin of Logic::decCmp in php/Logic.php; tests/when_fixture.json and
   tests/when_fuzz_php.php pin the pair. Equivalences that MUST survive:
   "02"="2", "2.50"="2.5", ".5"="0.5", "-0"="0". */
function QRID_whenDecCmp(a, b){
  var sa = 1, sb = 1;
  if(a.length && (a.charAt(0) === "-" || a.charAt(0) === "+")){ sa = a.charAt(0) === "-" ? -1 : 1; a = a.slice(1); }
  if(b.length && (b.charAt(0) === "-" || b.charAt(0) === "+")){ sb = b.charAt(0) === "-" ? -1 : 1; b = b.slice(1); }
  var da = a.split("."), db = b.split(".");
  var ia = da[0].replace(/^0+/, ""), ib = db[0].replace(/^0+/, "");
  var fa = QRID_trimZerosRight(da.length > 1 ? da[1] : "");
  var fb = QRID_trimZerosRight(db.length > 1 ? db[1] : "");
  var za = (ia === "" && fa === ""), zb = (ib === "" && fb === "");
  if(za && zb) return 0;                     /* zero is unsigned: -0 === 0 */
  if(za) return sb > 0 ? -1 : 1;
  if(zb) return sa > 0 ? 1 : -1;
  if(sa !== sb) return sa > 0 ? 1 : -1;
  var mag = 0;
  if(ia.length !== ib.length){
    mag = ia.length > ib.length ? 1 : -1;
  } else if(ia !== ib){
    mag = ia > ib ? 1 : -1;                  /* equal length => lexicographic is numeric */
  } else {
    var n = Math.max(fa.length, fb.length);
    while(fa.length < n) fa += "0";
    while(fb.length < n) fb += "0";
    if(fa !== fb) mag = fa > fb ? 1 : -1;
  }
  return sa > 0 ? mag : -mag;
}
/* @UVRANGE: a typed or saved number as a NUM_RE string, "" for blank, or null
   when it is not a number. One comma is the decimal mark on a *_comma_decimal
   field. A number with an exponent (2.5E1, which REDCap's number validation
   accepts) is written out. Twin of Logic::normalizeNumber. */
function QRID_rangeNumber(value, decimalComma){
  var v = QRID_whenTrim(value == null ? "" : String(value));
  if(v === "") return "";
  if(decimalComma && v.split(",").length === 2 && v.indexOf(".") < 0) v = v.replace(",", ".");
  if(QRID_WHEN_NUM_RE.test(v)) return v;
  var p = QRID_exponentParts(v);
  if(p === null) return null;
  /* Past 70 places a number is past every limit (at most 64 characters), so
     it is judged as 10^70 or 10^-71 with its sign. */
  if(p.digits !== "" && p.point > 71) p = { sign: p.sign, digits: "1", point: 71 };
  else if(p.digits !== "" && p.point < -70) p = { sign: p.sign, digits: "1", point: -70 };
  return QRID_partsText(p);
}
var QRID_MAX_PLAIN = 4096;
/* A number with an exponent written out digit for digit ("2.5E1" is "25",
   "5E0001" is "50"); null when not that shape or longer than QRID_MAX_PLAIN
   characters written out. Zero carries no sign. Twin of Logic::plainDecimal. */
function QRID_plainDecimal(s){
  var p = QRID_exponentParts(s);
  if(p === null || QRID_partsLength(p) > QRID_MAX_PLAIN) return null;
  return QRID_partsText(p);
}
/* A number with an exponent as 0.DIGITS x 10^POINT, or null. Twin of
   Logic::exponentParts. */
function QRID_exponentParts(s){
  var m = /^([+-]?)([0-9]*)(?:\.([0-9]*))?[eE]([+-]?)([0-9]+)$/.exec(String(s));
  if(!m) return null;
  var all = m[2] + (m[3] === undefined ? "" : m[3]);
  if(all === "") return null;
  var digits = all.replace(/^0+/, "");
  if(digits === "") return { sign: "", digits: "", point: 0 };
  var lead = all.length - digits.length;
  var e = m[5].replace(/^0+/, "");
  e = e.length > 9 ? 1000000000 : (e === "" ? 0 : parseInt(e, 10));
  return { sign: m[1] === "-" ? "-" : "", digits: QRID_trimZerosRight(digits),
           point: m[2].length - lead + (m[4] === "-" ? -e : e) };
}
function QRID_partsLength(p){
  var d = p.digits.length, pt = p.point;
  if(d === 0) return 1;
  return p.sign.length + (pt <= 0 ? 2 - pt + d : (pt >= d ? pt : d + 1));
}
function QRID_partsText(p){
  var d = p.digits, pt = p.point, t;
  if(d === "") return "0";
  if(pt <= 0) t = "0." + new Array(-pt + 1).join("0") + d;
  else if(pt >= d.length) t = d + new Array(pt - d.length + 1).join("0");
  else t = d.slice(0, pt) + "." + d.slice(pt);
  return p.sign + t;
}
/* A looked-up value (@UVEXISTS, @UVUNIQUE) without the space around it: space,
   tab, CR, LF, NUL, vertical tab and the no-break space. Twin of Logic::lookupTrim.
   An index scan from each end: a regex anchored at the end is retried from
   every position of a long run of spaces inside the value, which is quadratic. */
function QRID_lookupSpace(c){ return c === 32 || c === 9 || c === 13 || c === 10 || c === 0 || c === 11 || c === 160; }
function QRID_lookupTrim(v){
  var s = String(v), i = 0, j = s.length;
  while(i < j && QRID_lookupSpace(s.charCodeAt(i))) i++;
  while(j > i && QRID_lookupSpace(s.charCodeAt(j - 1))) j--;
  return (i === 0 && j === s.length) ? s : s.slice(i, j);
}
/* A number in one written form ("007", "7.0" and "7" are all "7"), or null.
   mark "comma" reads one comma as the decimal mark. Twin of Logic::numberKey. */
function QRID_numberKey(s, mark){
  var v = QRID_lookupTrim(s);
  if(mark === "comma" && v.split(",").length === 2 && v.indexOf(".") < 0) v = v.replace(",", ".");
  if(!QRID_WHEN_NUM_RE.test(v)){ v = QRID_plainDecimal(v); if(v === null) return null; }
  var m = /^([+-]?)([0-9]*)(?:\.([0-9]*))?$/.exec(v);
  if(!m || (m[2] === "" && !m[3])) return null;
  var whole = m[2].replace(/^0+/, "") || "0", frac = QRID_trimZerosRight(m[3] || "");
  if(whole === "0" && frac === "") return "0";
  return (m[1] === "-" ? "-" : "") + whole + (frac === "" ? "" : "." + frac);
}
/* The form two looked-up values are compared in: a number in one written form
   when the field holds numbers, else the text, A-Z folded when fold. No Unicode
   normalisation, as on the server. Twin of Logic::lookupKey. */
function QRID_lookupKey(s, fold, mark){
  var v = QRID_lookupTrim(s);
  if(mark === "point" || mark === "comma"){ var n = QRID_numberKey(v, mark); if(n !== null) return "#" + n; }
  return "=" + (fold ? QRID_whenFoldCase(v) : v);
}
/* The @UVRANGE verdict: { tier: ok|soft|hard|inert, reason }. Bounds inclusive,
   exact decimal comparison. Twin of Logic::rangeVerdict; tests/range_fixture.json
   pins both. */
function QRID_rangeVerdict(spec, value){
  var n = QRID_rangeNumber(value, !!spec.decimalComma);
  if(n === "") return { tier: "inert", reason: null };
  if(n === null) return { tier: "hard", reason: "not-a-number" };
  function b(k){ return (spec[k] != null && spec[k] !== "") ? String(spec[k]) : null; }
  if(b("hardLo") !== null && QRID_whenDecCmp(n, b("hardLo")) < 0) return { tier: "hard", reason: "hard-low" };
  if(b("hardHi") !== null && QRID_whenDecCmp(n, b("hardHi")) > 0) return { tier: "hard", reason: "hard-high" };
  if(b("softLo") !== null && QRID_whenDecCmp(n, b("softLo")) < 0) return { tier: "soft", reason: "soft-low" };
  if(b("softHi") !== null && QRID_whenDecCmp(n, b("softHi")) > 0) return { tier: "soft", reason: "soft-high" };
  return { tier: "ok", reason: null };
}
/* @UVRANGE growth references: the z-score of a measurement against an LMS
   table. Twin of php/GrowthReference.php; tests/growth_fixture.json and
   tests/who_golden.json pin both. A reference here is the page's copy
   (config.growth[id], GrowthReference::pageCopy): the index fields and the
   rows ("scale", "first", "male", "female"). */
var QRID_GROWTH_DAYS_PER_MONTH = 30.4375, QRID_GROWTH_MAX_Z_TEXT = 999.99;
/* The inputs a growth rule reads, as the suffix of its "range<Input>Op" keys. */
var QRID_GROWTH_INPUTS = ["Sex", "AgeDob", "AgeAt", "AgeDays", "AgeMonths", "By", "Measured"];
/* What a growth rule can return to fields ("return"): php/growth_outputs.json,
   read on the server by GrowthOutputs. */
/* @generated growth-outputs BEGIN — written from php/growth_outputs.json by tests/gen_mode_registry.cjs.
   Edit php/growth_outputs.json and re-run that script; never edit this block. */
var QRID_GROWTH_OUTPUTS = {
  "z": {"written":"number","needs":"measurement","places":"z","integer":false,"shapes":["value","column"],"label":"z-score"},
  "lower": {"written":"number","needs":"limit-low","places":"decimals","integer":true,"shapes":["value","column"],"unit":"measure","label":"lowest usual value"},
  "upper": {"written":"number","needs":"limit-high","places":"decimals","integer":true,"shapes":["value","column"],"unit":"measure","label":"highest usual value"},
  "range": {"written":"text","needs":"limit-any","places":"decimals","shapes":["value","column"],"label":"usual range"},
  "percentile": {"written":"number","needs":"measurement","places":"percentileDecimals","integer":true,"shapes":["value","column"],"label":"percentile"},
  "valueAtZ": {"written":"number","needs":"none","places":"decimals","integer":true,"param":{"min":"-6","max":"6","decimals":2},"shapes":["value","column","line"],"line":"z","unit":"measure","label":"value at z-score {param}"},
  "valueAtPercentile": {"written":"number","needs":"none","places":"decimals","integer":true,"param":{"min":"0.1","max":"99.9","decimals":2},"shapes":["value","column","line"],"line":"percentiles","unit":"measure","label":"value at percentile {param}"},
  "age": {"written":"number","needs":"none","places":"axis","integer":false,"shapes":["column"],"unit":"axis","label":"{axis}"},
  "value": {"written":"number","needs":"measurement","places":"typed","integer":false,"shapes":["column"],"unit":"measure","label":"{measure}"}
};
/* @generated growth-outputs END */
/* What WHO's correction for how the child was measured adds: to the
   measurement (corrects "measurement") or to the length or height (corrects
   "axis"), as the reference's "position" says. 0 when it corrects the other
   one, has nothing for this position (or position is null), or a "from" or
   "below" age excludes axis position x (null: not known). Server twin:
   GrowthReference::positionAdd. */
function QRID_growthPositionAdd(ref, position, x, corrects){
  var p = ref && ref.position;
  if(!p || position === null || !Object.prototype.hasOwnProperty.call(p, position) || p.corrects !== corrects) return 0;
  var s = p[position];
  if(s.from !== undefined && !(x !== null && x >= parseFloat(s.from))) return 0;
  if(s.below !== undefined && !(x !== null && x < parseFloat(s.below))) return 0;
  return parseFloat(s.add);
}
/* Where on the reference's axis a measurement sits. `input` holds what the
   rule supplied: days or months (a number as text), dobDays and atDays (whole
   days since 1970-01-01, null for a blank date), or by (cm, as text); comma
   reads a decimal comma in days, months or by. byAdd is added to by first
   (QRID_growthPositionAdd), so the "valid" range judges the corrected length.
   Returns { state: "ok", x } or { state: "blank"|"outside"|"invalid", why }. */
function QRID_growthAxis(ref, input){
  var comma = !!input.comma;
  function inRange(x, what){
    var v = ref.valid, ok = x >= parseFloat(v.min) &&
      (v.max !== undefined ? x <= parseFloat(v.max) : x < parseFloat(v.below));
    if(ok) return { state: "ok", x: x };
    var range = v.min + " to " + (v.max !== undefined ? v.max : "under " + v.below) + " " + ref.axisUnit;
    return { state: "outside", why: "the " + what + " is outside the reference (" + range + ")" };
  }
  var n;
  if(ref.axis !== "age"){
    var by = QRID_whenTrim(input.by == null ? "" : String(input.by));
    if(by === "") return { state: "blank", why: "the " + ref.axis + " is blank" };
    n = QRID_rangeNumber(by, comma);
    if(n === null || n === "") return { state: "invalid", why: "the " + ref.axis + " is not a number" };
    return inRange(input.byAdd ? parseFloat(n) + input.byAdd : parseFloat(n), ref.axis);
  }
  var x;
  if(input.hasOwnProperty("dobDays") || input.hasOwnProperty("atDays")){
    if(input.dobDays == null || input.atDays == null) return { state: "blank", why: "the date of birth or the date of the measurement is blank" };
    var days = input.atDays - input.dobDays;
    if(days < 0) return { state: "outside", why: "the measurement is dated before the birth" };
    x = ref.axisUnit === "days" ? days : days / QRID_GROWTH_DAYS_PER_MONTH;
  } else {
    var unit = input.days != null ? "days" : (input.months != null ? "months" : null);
    var raw = unit === null ? "" : QRID_whenTrim(String(input[unit]));
    if(raw === "") return { state: "blank", why: "the age is blank" };
    n = QRID_rangeNumber(raw, comma);
    if(n === null || n === "") return { state: "invalid", why: "the age is not a number" };
    var v = parseFloat(n);
    if(v < 0) return { state: "outside", why: "the age is below 0" };
    x = unit === ref.axisUnit ? v : (unit === "months" ? v * QRID_GROWTH_DAYS_PER_MONTH : v / QRID_GROWTH_DAYS_PER_MONTH);
  }
  return inRange(x, "age");
}
/* L, M and S at x for "male" or "female", or null when the table has no row. */
function QRID_growthLms(ref, sex, x){
  var rows = ref[sex], s = ref.scale;
  function row(key){ var i = key - ref.first; return (i >= 0 && i < rows.length) ? rows[i] : null; }
  if(ref.lookup === "round"){
    var k = x * s, f = Math.floor(k);
    return row(k - f >= 0.5 ? f + 1 : f);
  }
  if(ref.lookup === "floor") return row(Math.floor(x * s));
  /* linear, as WHO's own code does it */
  var lowKey = Math.floor(x * s), lo = row(lowKey);
  if(lo === null) return null;
  var diff = (x - lowKey / s) / (1 / s);
  if(!(diff > 0)) return lo;
  var hi = row(lowKey + 1);
  if(hi === null) return null;
  return [lo[0] + diff * (hi[0] - lo[0]), lo[1] + diff * (hi[1] - lo[1]), lo[2] + diff * (hi[2] - lo[2])];
}
/* The unrounded z-score, or null. An L nearer 0 than QRID_GROWTH_MIN_L (a
   row read between two of opposite sign) takes the L = 0 formula, where
   Math.pow(y / m, l) would be 1 for every y. Twin of GrowthReference::zRaw. */
var QRID_GROWTH_MIN_L = 0.000001;
function QRID_growthZRaw(y, lms, restricted){
  var l = lms[0], m = lms[1], s = lms[2], z, sd;
  if(Math.abs(l) < QRID_GROWTH_MIN_L){
    z = Math.log(y / m) / s;
    sd = function(k){ return m * Math.exp(s * k); };
  } else {
    z = (Math.pow(y / m, l) - 1) / (s * l);
    sd = function(k){ return m * Math.pow(1 + l * s * k, 1 / l); };
  }
  if(restricted && isFinite(z)){
    var sd3;
    if(z > 3){ sd3 = sd(3); z = 3 + (y - sd3) / (sd3 - sd(2)); }
    else if(z < -3){ sd3 = sd(-3); z = -3 + (y - sd3) / (sd(-2) - sd3); }
  }
  return isFinite(z) ? z : null;
}
/* z rounded to two decimals, half away from zero, as text; beyond
   +-QRID_GROWTH_MAX_Z_TEXT it is written as that bound. */
function QRID_growthZText(z){
  z = Math.max(-QRID_GROWTH_MAX_Z_TEXT, Math.min(QRID_GROWTH_MAX_Z_TEXT, z));
  var n = Math.floor(Math.abs(z) * 100 + 0.5);
  var frac = String(n % 100);
  return (z < 0 && n !== 0 ? "-" : "") + Math.floor(n / 100) + "." + (frac.length < 2 ? "0" + frac : frac);
}
/* A typed measurement: { state: "ok", y } | { state: "blank"|"not-a-number"|"not-positive" }. */
function QRID_growthMeasure(text, decimalComma, scale){
  var n = QRID_rangeNumber(text, !!decimalComma);
  if(n === "") return { state: "blank" };
  if(n === null) return { state: "not-a-number" };
  var y = parseFloat(n);
  if(!(y > 0)) return { state: "not-positive" };
  /* a rule's "measureScale": one double multiplication, as on the server */
  if(scale != null) y = y * parseFloat(scale);
  return { state: "ok", y: y };
}
/* "plausible-low" | "plausible-high" | null: y (in the reference's unit)
   against a rule's "plausible" limits, texts or null. Twin:
   GrowthReference::plausible. */
function QRID_growthPlausible(y, lo, hi){
  if(lo != null && y < parseFloat(lo)) return "plausible-low";
  if(hi != null && y > parseFloat(hi)) return "plausible-high";
  return null;
}
/* { state: "ok", z } | { state: "outside" } (the table has no row there) */
function QRID_growthZScore(ref, sex, x, y){
  var lms = QRID_growthLms(ref, sex, x);
  if(lms === null) return { state: "outside" };
  var z = QRID_growthZRaw(y, lms, ref.adjust === "who-restricted");
  /* no finite z (a measurement past what the formula holds): beyond every
     limit on its side of the median. Twin: GrowthReference::zScore. */
  if(z === null) z = y > lms[1] ? QRID_GROWTH_MAX_Z_TEXT : -QRID_GROWTH_MAX_Z_TEXT;
  return { state: "ok", z: QRID_growthZText(z) };
}
/* The measurement that scores z against L, M and S, or null when none above 0
   does: the inverse of QRID_growthZRaw, with the same restriction beyond +-3 SD.
   Twin of GrowthReference::valueAt. */
function QRID_growthValueAt(z, lms, restricted){
  var l = lms[0], m = lms[1], s = lms[2], y, sd, a;
  if(Math.abs(l) < QRID_GROWTH_MIN_L){
    sd = function(k){ return m * Math.exp(s * k); };
  } else {
    sd = function(k){ var b = 1 + l * s * k; return b > 0 ? m * Math.pow(b, 1 / l) : NaN; };
  }
  if(restricted && z > 3){ a = sd(3); y = a + (z - 3) * (a - sd(2)); }
  else if(restricted && z < -3){ a = sd(-3); y = a + (z + 3) * (sd(-2) - a); }
  else y = sd(z);
  return (isFinite(y) && y > 0) ? y : null;
}
/* The bound of one limit as a count of units of 10^-decimals of the measured
   field's unit: the smallest measurement the verdict passes for a low limit,
   the largest for a high one, or null. A measurement passes when its text,
   read as a typed value is (QRID_growthMeasure, times scale), scores a
   two-decimal z-score inside the limit, so a value at the bound passes and
   one unit beyond it does not. Found by halving between two measurements
   that bracket the edge. Twin of GrowthReference::bound. */
var QRID_GROWTH_MAX_BOUND_UNITS = 1e15, QRID_GROWTH_BOUND_BRACKETS = [0.02, 0.01, 0.006];
function QRID_growthBound(ref, sex, x, lim, decimals, low, scale){
  var lms = QRID_growthLms(ref, sex, x);
  if(lms === null) return null;
  var restricted = ref.adjust === "who-restricted";
  var f = (scale === null || scale === undefined) ? 1 : parseFloat(scale);
  lim = String(lim);
  function passes(k){
    if(k < 1) return false;     /* a measurement at or below 0 is none */
    var m = QRID_growthMeasure(QRID_growthUnitsText(k, decimals), false, scale);
    if(m.state !== "ok") return false;
    var z = QRID_growthZRaw(m.y, lms, restricted);
    if(z === null) z = m.y > lms[1] ? QRID_GROWTH_MAX_Z_TEXT : -QRID_GROWTH_MAX_Z_TEXT;
    var c = QRID_whenDecCmp(QRID_growthZText(z), lim);
    return low ? c >= 0 : c <= 0;
  }
  function units(z){
    var v = QRID_growthValueAt(z, lms, restricted);
    if(v === null) return null;
    var n = v / f * Math.pow(10, decimals);
    return (isFinite(n) && n <= QRID_GROWTH_MAX_BOUND_UNITS) ? n : null;
  }
  var inside = null, outside = null, b;
  for(b = 0; b < QRID_GROWTH_BOUND_BRACKETS.length; b++){
    var dz = QRID_GROWTH_BOUND_BRACKETS[b];
    if(inside === null) inside = units(parseFloat(lim) + (low ? dz : -dz));
    if(outside === null) outside = units(parseFloat(lim) + (low ? -dz : dz));
  }
  var pin, pout;
  if(low){
    if(inside === null) return null;
    pin = Math.ceil(inside) + 1;
    pout = outside === null ? 0 : Math.max(0, Math.floor(outside) - 1);
  } else {
    if(outside === null) return null;
    pout = Math.ceil(outside) + 1;
    pin = inside === null ? 1 : Math.max(1, Math.floor(inside) - 1);
  }
  if(Math.max(pin, pout) > QRID_GROWTH_MAX_BOUND_UNITS || !passes(pin) || passes(pout)) return null;
  while(Math.abs(pin - pout) > 1){
    var mid = Math.floor((pin + pout) / 2);
    if(passes(mid)) pin = mid;
    else pout = mid;
  }
  return pin;
}
/* units of 10^-decimals as text with all its decimals: (77, 1) is "7.7". */
function QRID_growthUnitsText(units, decimals){
  var a = Math.abs(units), sign = units < 0 ? "-" : "";
  if(decimals === 0) return sign + a;
  var p = Math.pow(10, decimals), f = String(a % p);
  return sign + Math.floor(a / p) + "." + new Array(decimals - f.length + 1).join("0") + f;
}
/* A number as text with the decimal mark of the field it is written to. */
function QRID_growthMarkText(text, comma){ return comma ? String(text).replace(/\./g, ",") : String(text); }
/* The outputs this engine computes: QRID_growthOutputs makes the first four,
   QRID_growthOutput the rest. Twin of GrowthReference::COMPUTED;
   tests/gen_mode_registry.cjs fails when the catalog names another. The
   decimals a rule may ask for are the server's too (GrowthReference
   MAX_DECIMALS, MAX_PERCENTILE_DECIMALS, DEFAULT_PERCENTILE_DECIMALS;
   tests/growth_outputs_php.php compares them). */
var QRID_GROWTH_COMPUTED = ["z", "lower", "upper", "range", "percentile", "valueAtZ", "valueAtPercentile", "age", "value"];
var QRID_GROWTH_MAX_DECIMALS = 6, QRID_GROWTH_MAX_PERCENTILE_DECIMALS = 3, QRID_GROWTH_DEFAULT_PERCENTILE_DECIMALS = 1;
/* A key read against the catalog (QRID_GROWTH_OUTPUTS): { of, param, spec },
   or null when it names no output, one this engine does not compute, one
   without the shape asked for ("value", a "return" key, by default;
   "column", a column of the page table), or its parameter is out of range.
   Twin of GrowthOutputs::parse, which says why a key is refused. */
function QRID_growthOutputOf(key, shape){
  if(typeof key !== "string") return null;
  var colon = key.indexOf(":");
  var id = colon < 0 ? key : key.slice(0, colon), param = colon < 0 ? null : key.slice(colon + 1);
  if(!Object.prototype.hasOwnProperty.call(QRID_GROWTH_OUTPUTS, id) || QRID_GROWTH_COMPUTED.indexOf(id) < 0) return null;
  var spec = QRID_GROWTH_OUTPUTS[id];
  if(!spec.shapes || spec.shapes.indexOf(shape || "value") < 0) return null;
  if(!spec.param) return param === null ? { of: id, param: null, spec: spec } : null;
  var d = spec.param.decimals;
  var re = new RegExp("^-?(?:0|[1-9][0-9]*)" + (d > 0 ? "(?:\\.[0-9]{1," + d + "})?" : "") + "$");
  if(param === null || !re.test(param) || QRID_whenDecCmp(param, spec.param.min) < 0
      || QRID_whenDecCmp(param, spec.param.max) > 0) return null;
  return { of: id, param: param, spec: spec };
}
/* One value other than the four QRID_growthOutputs makes: percentile (null
   without a measurement), valueAtZ or valueAtPercentile, age (the axis
   value, QRID_growthAxisText) or value (the measurement as typed, trimmed;
   null without one), as text with a decimal point, or null. places is the
   decimals it is written with. Twin of GrowthReference::output. */
function QRID_growthOutput(ref, sex, x, y, of, param, places, scale, typed){
  if(scale === undefined) scale = null;
  if(of === "age") return QRID_growthAxisText(x);
  if(of === "value"){
    var t = (typed === null || typeof typed === "undefined") ? "" : QRID_whenTrim(String(typed));
    return t === "" ? null : t;
  }
  if(of === "percentile") return (y === null || y === undefined) ? null : QRID_growthPercentile(ref, sex, x, y, places);
  var z = QRID_growthLineZ(of, param);
  return z === null ? null : QRID_growthValueAtText(ref, sex, x, z, places, scale);
}
/* The z-score output of stands for at parameter param (valueAtZ: the z-score;
   valueAtPercentile: the quantile of the percentile), or null. A chart curve
   is the measurement at this z-score. Twin of GrowthReference::lineZ. */
function QRID_growthLineZ(of, param){
  if(of === "valueAtZ") return parseFloat(param);
  if(of !== "valueAtPercentile") return null;
  var z = QRID_growthNormalQuantile(parseFloat(param) / 100);
  return isFinite(z) ? z : null;
}
/* An axis value as text, half away from zero to two decimals, no trailing
   zeros ("365", "12.5", "13.14"); null when not finite. Twin of
   GrowthReference::axisText. */
function QRID_growthAxisText(x){
  if(typeof x !== "number" || !isFinite(x)) return null;
  var n = QRID_growthRoundedUnits(Math.abs(x), 2);
  if(n === null) return null;
  var t = QRID_growthUnitsText(n, 2).replace(/0+$/, "").replace(/\.$/, "");
  return (x < 0 && n !== 0 ? "-" : "") + t;
}
/* The percentile of y: 100 times the normal distribution function of its
   unrounded z-score. Twin of GrowthReference::percentile. */
function QRID_growthPercentile(ref, sex, x, y, places){
  var lms = QRID_growthLms(ref, sex, x);
  if(lms === null) return null;
  var z = QRID_growthZRaw(y, lms, ref.adjust === "who-restricted");
  return QRID_growthPercentileText(z === null ? (y > lms[1] ? 1 : 0) : QRID_growthNormalCdf(z), places);
}
/* A probability as a percentile with places decimals, half away from zero,
   kept one unit inside 0 and 100 (0.1 to 99.9 at one decimal). Twin of
   GrowthReference::percentileText. */
function QRID_growthPercentileText(cum, places){
  if(typeof cum !== "number" || !isFinite(cum)) return null;
  var top = Math.pow(10, places + 2);
  var n = QRID_growthRoundedUnits(Math.max(0, Math.min(1, cum)) * 100, places);
  return QRID_growthUnitsText(Math.max(1, Math.min(top - 1, n)), places);
}
/* v (finite, 0 or more) as a count of units of 10^-d, or null: rounded to 15
   significant digits (a decimal tie such as a median of 9.165 stays a tie),
   then half away from zero to d decimals, both done here on the first 40
   digits of toExponential(39), which PHP's sprintf("%.39e") gives the same.
   Twin of GrowthReference::roundedUnits. */
function QRID_growthRoundedUnits(v, d){
  if(typeof v !== "number" || !isFinite(v) || v < 0) return null;
  if(v === 0) return 0;
  var s = v.toExponential(39), exp = parseInt(s.substring(s.indexOf("e") + 1), 10);
  var n15 = parseInt(s.charAt(0) + s.substring(2, 16), 10) + (s.charCodeAt(16) - 48 >= 5 ? 1 : 0);
  if(n15 >= 1e15){ n15 = 1e14; exp++; }
  var digits = String(n15), shift = exp - 14 + d;
  if(shift >= 0) return shift > 3 ? null : n15 * Math.pow(10, shift);
  var keep = 15 + shift;
  if(keep < 0) return 0;
  var n = keep === 0 ? 0 : parseInt(digits.substring(0, keep), 10);
  return digits.charCodeAt(keep) - 48 >= 5 ? n + 1 : n;
}
/* The measurement that scores z at x, in the measured field's unit, to places
   decimals, or null (also when it rounds to 0, or past
   QRID_GROWTH_MAX_BOUND_UNITS units, as a bound would). Twin of
   GrowthReference::valueAtText. */
function QRID_growthValueAtText(ref, sex, x, z, places, scale){
  var lms = QRID_growthLms(ref, sex, x);
  if(lms === null) return null;
  var v = QRID_growthValueAt(z, lms, ref.adjust === "who-restricted");
  if(v === null) return null;
  var f = (scale === null || scale === undefined) ? 1 : parseFloat(scale);
  var n = QRID_growthRoundedUnits(v / f, places);
  if(n === null || n < 1 || n > QRID_GROWTH_MAX_BOUND_UNITS) return null;
  return QRID_growthUnitsText(n, places);
}
/* The standard normal distribution function, lower tail: Cody's algorithm 715
   as R's pnorm() computes it, with the same constants and cut-offs; agrees
   with R 4.6.1 to 1e-15 relative, and gives 0 below x = -37.5193. Twin of
   GrowthReference::normalCdf; tests/growth_normal_golden.json pins both. */
var QRID_NORM_A = [2.2352520354606839287, 161.02823106855587881, 1067.6894854603709582, 18154.981253343561249,
                   0.065682337918207449113];
var QRID_NORM_B = [47.20258190468824187, 976.09855173777669322, 10260.932208618978205, 45507.789335026729956];
var QRID_NORM_C = [0.39894151208813466764, 8.8831497943883759412, 93.506656132177855979, 597.27027639480026226,
                   2494.5375852903726711, 6848.1904505362823326, 11602.651437647350124, 9842.7148383839780218,
                   1.0765576773720192317e-8];
var QRID_NORM_D = [22.266688044328115691, 235.38790178262499861, 1519.377599407554805, 6485.558298266760755,
                   18615.571640885098091, 34900.952721145977266, 38912.003286093271411, 19685.429676859990727];
var QRID_NORM_P = [0.21589853405795699, 0.1274011611602473639, 0.022235277870649807, 0.001421619193227893466,
                   2.9112874951168792e-5, 0.02307344176494017303];
var QRID_NORM_Q = [1.28426009614491121, 0.468238212480865118, 0.0659881378689285515, 0.00378239633202758244,
                   7.29751555083966205e-5];
function QRID_growthNormalCdf(x){
  if(x !== x) return NaN;
  var y = Math.abs(x), xnum, xden, xsq, del, temp, cum, i;
  if(y <= 0.67448975){
    xnum = 0; xden = 0;
    if(y > 1.1102230246251565e-16){
      xsq = x * x; xnum = QRID_NORM_A[4] * xsq; xden = xsq;
      for(i = 0; i < 3; i++){ xnum = (xnum + QRID_NORM_A[i]) * xsq; xden = (xden + QRID_NORM_B[i]) * xsq; }
    }
    return 0.5 + x * (xnum + QRID_NORM_A[3]) / (xden + QRID_NORM_B[3]);
  }
  if(y <= 5.656854249492380195206754896838){
    xnum = QRID_NORM_C[8] * y; xden = y;
    for(i = 0; i < 7; i++){ xnum = (xnum + QRID_NORM_C[i]) * y; xden = (xden + QRID_NORM_D[i]) * y; }
    temp = (xnum + QRID_NORM_C[7]) / (xden + QRID_NORM_D[7]);
    xsq = Math.floor(y * 16) / 16;
    del = (y - xsq) * (y + xsq);
    cum = Math.exp(-xsq * xsq * 0.5) * Math.exp(-del * 0.5) * temp;
    return x > 0 ? 1 - cum : cum;
  }
  if(-37.5193 < x && x < 8.2924){
    xsq = 1 / (x * x); xnum = QRID_NORM_P[5] * xsq; xden = xsq;
    for(i = 0; i < 4; i++){ xnum = (xnum + QRID_NORM_P[i]) * xsq; xden = (xden + QRID_NORM_Q[i]) * xsq; }
    temp = xsq * (xnum + QRID_NORM_P[4]) / (xden + QRID_NORM_Q[4]);
    temp = (0.398942280401432677939946059934 - temp) / y;
    xsq = (x < 0 ? -Math.floor(-x * 16) : Math.floor(x * 16)) / 16;
    del = (x - xsq) * (x + xsq);
    cum = Math.exp(-xsq * xsq * 0.5) * Math.exp(-del * 0.5) * temp;
    return x > 0 ? 1 - cum : cum;
  }
  return x > 0 ? 1 : 0;
}
/* The quantile of the standard normal distribution: Wichura's AS241 as R's
   qnorm() computes it, with the same constants and branches, agreeing with
   R 4.6.1 to 1e-15 relative; -Infinity at 0, Infinity at 1, NaN outside.
   Twin of GrowthReference::normalQuantile. */
function QRID_growthNormalQuantile(p){
  if(p !== p || p < 0 || p > 1) return NaN;
  if(p === 0) return -Infinity;
  if(p === 1) return Infinity;
  var q = p - 0.5, r, val;
  if(Math.abs(q) <= 0.425){
    r = 0.180625 - q * q;
    return q * (((((((r * 2509.0809287301226727 + 33430.575583588128105) * r + 67265.770927008700853) * r
              + 45921.953931549871457) * r + 13731.693765509461125) * r + 1971.5909503065514427) * r
              + 133.14166789178437745) * r + 3.387132872796366608)
      / (((((((r * 5226.495278852545925 + 28729.085735721942674) * r + 39307.89580009271061) * r
              + 21213.794301586595867) * r + 5394.1960214247511077) * r + 687.1870074920579083) * r
              + 42.313330701600911252) * r + 1);
  }
  r = Math.sqrt(-Math.log(q < 0 ? p : 1 - p));
  if(r <= 5){
    r -= 1.6;
    val = (((((((r * 7.7454501427834140764e-4 + 0.0227238449892691845833) * r + 0.24178072517745061177) * r
              + 1.27045825245236838258) * r + 3.64784832476320460504) * r + 5.7694972214606914055) * r
              + 4.6303378461565452959) * r + 1.42343711074968357734)
      / (((((((r * 1.05075007164441684324e-9 + 5.475938084995344946e-4) * r + 0.0151986665636164571966) * r
              + 0.14810397642748007459) * r + 0.68976733498510000455) * r + 1.6763848301838038494) * r
              + 2.05319162663775882187) * r + 1);
  } else {
    r -= 5;
    val = (((((((r * 2.01033439929228813265e-7 + 2.71155556874348757815e-5) * r + 0.0012426609473880784386) * r
              + 0.026532189526576123093) * r + 0.29656057182850489123) * r + 1.7848265399172913358) * r
              + 5.4637849111641143699) * r + 6.6579046435011037772)
      / (((((((r * 2.04426310338993978564e-15 + 1.4215117583164458887e-7) * r + 1.8463183175100546818e-5) * r
              + 7.868691311456132591e-4) * r + 0.0148753612908506148525) * r + 0.13692988092273580531) * r
              + 0.59983220655588793769) * r + 1);
  }
  return q < 0 ? -val : val;
}
/* What a rule that returns values writes for one child: { z, lower, upper,
   range }, each text or null (nothing to write). y is in the reference's
   unit; the bounds are in the measured field's (divided by scale). limits is
   { lo, hi }, each a z limit as text or null. The range names unit when
   given, else the reference's unit when there is no scale, else none. Twin
   of GrowthReference::outputs. */
function QRID_growthOutputs(ref, sex, x, y, limits, decimals, comma, scale, unit){
  var out = { z: null, lower: null, upper: null, range: null };
  if(scale === undefined) scale = null;
  if(y !== null && y !== undefined){
    var z = QRID_growthZScore(ref, sex, x, y);
    if(z.state === "ok") out.z = z.z;
  }
  var want = { lo: false, hi: false };
  var sides = [["lo", "lower", true], ["hi", "upper", false]];
  for(var i = 0; i < sides.length; i++){
    var lim = limits ? limits[sides[i][0]] : null;
    if(lim === null || lim === undefined || lim === "") continue;
    want[sides[i][0]] = true;
    var k = QRID_growthBound(ref, sex, x, lim, decimals, sides[i][2], scale);
    if(k !== null) out[sides[i][1]] = QRID_growthUnitsText(k, decimals);
  }
  if((want.lo || want.hi) && (!want.lo || out.lower !== null) && (!want.hi || out.upper !== null)){
    unit = (typeof unit === "string" && unit !== "") ? " " + unit : (scale === null ? " " + ref.unit : "");
    if(want.lo && want.hi){
      if(QRID_whenDecCmp(out.lower, out.upper) <= 0){
        out.range = QRID_growthMarkText(out.lower, comma) + " to " + QRID_growthMarkText(out.upper, comma) + unit;
      }
    } else if(want.lo){
      out.range = "at least " + QRID_growthMarkText(out.lower, comma) + unit;
    } else {
      out.range = "at most " + QRID_growthMarkText(out.upper, comma) + unit;
    }
  }
  return out;
}
/* A rule that returns values (@UVRANGE "return") writes them into other fields
   of the form. QRID_returnDepth bounds how far one write may set off another
   rule's write (a field one rule returns can be the measurement of another),
   so a cycle of rules ends instead of looping. */
var QRID_returnDepth = 0, QRID_RETURN_DEPTH_MAX = 4;
/* Whether two texts are the same number: "7.70" and "7.7", "7,7" and "7.7". */
function QRID_sameNumber(a, b){
  a = QRID_whenTrim(a).replace(",", "."); b = QRID_whenTrim(b).replace(",", ".");
  return QRID_WHEN_NUM_RE.test(a) && QRID_WHEN_NUM_RE.test(b) && QRID_whenDecCmp(a, b) === 0;
}
/* The element a returned value is written to: a dropdown's <select> (REDCap
   may put a hidden mirror input of the same name before it), else the input
   or textarea named after the field (for a radio, yes/no or true/false field
   that is the hidden mirror holding the code). */
function QRID_returnElement(name){
  var els = document.getElementsByName ? document.getElementsByName(name) : [];
  var input = null;
  for(var i = 0; i < els.length; i++){
    var tag = (els[i].tagName || "").toLowerCase();
    if(tag === "select") return els[i];
    if(!input && (tag === "input" || tag === "textarea")) input = els[i];
  }
  return input;
}
/* The option of a <select> that carries code, or null. */
function QRID_selectOption(sel, code){
  var opts = sel.options || [];
  for(var i = 0; i < opts.length; i++) if(opts[i] && opts[i].value === code) return opts[i];
  return null;
}
/* Put a returned value into its field; true when the field changed. Nothing
   is written when the page does not carry the field (another page of a
   survey) or it already holds the value, or the same number spelled another
   way (unless exact: a Text field that holds text, where "007" is not "7").
   A dropdown or a radio, yes/no or true/false field takes only a code it
   offers: when @HIDECHOICE or another rule (@UVCHOICES) took the option off
   the page, the field is emptied instead of keeping the previous entry's
   code, and its name goes into lost (when given) so the caller can say why.
   A dropdown's autocomplete companion shows the option's label. A radio gets
   the code in its hidden mirror and the matching button checked, every
   button unchecked for "". announce is false for the fill made when the page
   opens: it must not mark the form as changed, and the save writes the value
   either way. Otherwise REDCap hears an input and a change event, as from
   typing, and the calculations and branching logic that read the field run.
   Server twin: UniversalValidator::applyReturns. */
function QRID_writeReturned(name, text, announce, exact, lost){
  /* A form the person may only read, or a locked one, shows the record as
     saved (config.formReadOnly, EXS-2): nothing is written into it. */
  if(QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.formReadOnly === true) return false;
  var el = QRID_returnElement(name);
  if(!el || typeof el.value !== "string") return false;
  var cur = el.value;
  if(cur === text) return false;
  if(!exact && text !== "" && cur !== "" && QRID_sameNumber(cur, text)) return false;
  var isSelect = (el.tagName || "").toLowerCase() === "select";
  var radios = (!isSelect && document.getElementsByName) ? document.getElementsByName(name + "___radio") : [];
  var opt = null;   /* the option or button that carries the code */
  if(text !== ""){
    if(isSelect) opt = QRID_selectOption(el, text);
    /* A radio whose row an @UVCHOICES filter hid is not offered either: a
       dropdown's hidden option is parked off the list, a radio's row only
       hidden, and the save leaves both empty (CHO-2). */
    else for(var oi = 0; oi < radios.length && !opt; oi++){
      if(radios[oi].value === text && !(radios[oi].parentNode && radios[oi].parentNode.__qridHidden)) opt = radios[oi];
    }
    if(!opt && (isSelect || radios.length)){
      if(lost) lost.push(name);
      text = "";
      if(cur === "") return false;
    }
  }
  var shown = [];   /* the elements a person sees change */
  el.value = text;
  if(isSelect){
    var comp = typeof document.getElementById === "function" ? document.getElementById("rc-ac-input_" + name) : null;
    if(comp && typeof comp.value === "string") comp.value = opt ? String(opt.text != null ? opt.text : opt.value) : "";
  } else {
    for(var ri = 0; ri < radios.length; ri++){
      var on = text !== "" && radios[ri].value === text;
      if(radios[ri].checked !== on){ radios[ri].checked = on; shown.push(radios[ri]); }
    }
  }
  if(announce && typeof el.dispatchEvent === "function" && typeof Event === "function"){
    try {
      el.dispatchEvent(new Event("input", { bubbles: true }));
      el.dispatchEvent(new Event("change", { bubbles: true }));
      for(var si = 0; si < shown.length; si++){
        if(shown[si].checked && typeof shown[si].dispatchEvent === "function") shown[si].dispatchEvent(new Event("change", { bubbles: true }));
      }
    } catch(e){}
  }
  var w = typeof window !== "undefined" ? window : null;
  if(w){
    try { if(typeof w.calculate === "function") w.calculate(name); } catch(e){}
    try { if(typeof w.doBranching === "function") w.doBranching(name); } catch(e){}
  }
  return true;
}
function QRID_whenCompare(op, a, b, blank, caseSensitive){
  if(blank === undefined) blank = QRID_BLANK_PASSES;
  if(caseSensitive === undefined) caseSensitive = false;
  a = QRID_whenTrim(a); b = QRID_whenTrim(b);
  /* "exact": identity of the two strings, before any reading as numbers or
     folding of case (AST-2). Twin of Logic::compare. */
  if(caseSensitive === QRID_WHEN_EXACT && (op === "=" || op === "<>")) return (a === b) === (op === "=");
  if(QRID_WHEN_NUM_RE.test(a) && QRID_WHEN_NUM_RE.test(b)){
    var c = QRID_whenDecCmp(a, b);
    switch(op){
      case "=":  return c === 0;
      case "<>": return c !== 0;
      case ">":  return c > 0;
      case "<":  return c < 0;
      case ">=": return c >= 0;
      case "<=": return c <= 0;
    }
    return false;
  }
  /* MIXED DOMAINS in an ORDERED comparison are not comparable (M-02). Twin of
     Logic::compare — the comparator used to be chosen per PAIR, so "2"<="10",
     "10"<="1e1" and "2">"1e1" were all true at once. Ordering is defined only
     within a domain; one-of-each is false either way round, so no cycle forms.
     Equality is untouched. */
  /* EMPTY is absence, so an ORDERED comparison against it has NO ANSWER and
     `blank` — the caller's polarity, already flipped for every enclosing "not"
     — stands in. It used to fall through to code-point order, where "" sorts
     lowest, so [end]>=[start] passed on a blank start by luck while
     [start]<=[end] invented a violation (CRIT-01). = and <> are untouched:
     they answer by identity, and [field]<>'' is the "is this filled in" idiom.
     See Logic::compare, the normative twin. */
  if(!caseSensitive){ a = QRID_whenFoldCase(a); b = QRID_whenFoldCase(b); }
  var blankSide = (a === "" || b === "");
  var mixed = !blankSide &&
              (QRID_WHEN_NUM_RE.test(a) !== QRID_WHEN_NUM_RE.test(b));
  switch(op){
    case "=":  return a === b;
    case "<>": return a !== b;
    case ">":  return blankSide ? blank : (!mixed && QRID_cmpStr(a, b) > 0);
    case "<":  return blankSide ? blank : (!mixed && QRID_cmpStr(a, b) < 0);
    case ">=": return blankSide ? blank : (!mixed && QRID_cmpStr(a, b) >= 0);
    case "<=": return blankSide ? blank : (!mixed && QRID_cmpStr(a, b) <= 0);
  }
  return false;
}
/* Unique [field]/[field(code)] refs as [[field, codeOrNull], ...], first-
   appearance order, deduped per (field, code) pair. */
function QRID_whenRefs(ast){
  var out = [];
  var seen = Object.create(null);
  QRID_whenCollectRefs(ast, out, seen);
  return out;
}
function QRID_whenCollectRefs(ast, out, seen){
  if(ast[0] === "temporal"){
    function walk(n){
      if(!Array.isArray(n)) return;
      if(n[0] === "ref") {var k=n[1]+"|"+(n[2]===null?"":"("+n[2]+")");if(!seen[k]){seen[k]=true;out.push([n[1],n[2]]);}return;}
      if(n[0] === "guard") n[1].forEach(function(g){walk(["ref",g[0],null]);});
      n.forEach(function(c){if(Array.isArray(c))walk(c);});
    }
    walk(ast[1]); return;
  }
  switch(ast[0]){
    case "const":
      return;                    /* folded server-side: no field to watch */
    case "or":
    case "and":
      for(var i = 0; i < ast[1].length; i++) QRID_whenCollectRefs(ast[1][i], out, seen);
      return;
    case "not":
      QRID_whenCollectRefs(ast[1], out, seen);
      return;
    case "cmp":
      var ops = [ast[2], ast[3]];
      for(var j = 0; j < ops.length; j++){
        if(ops[j][0] === "ref"){
          var key = ops[j][1] + "|" + (ops[j][2] === null ? "" : "(" + ops[j][2] + ")");
          if(!seen[key]){ seen[key] = true; out.push([ops[j][1], ops[j][2]]); }
        }
      }
      return;
  }
}
/* Robust read of ONE checkbox option's checked state as "1"/"0", across REDCap
   renderings (LIVE-FOUND on REDCap 17.0.6, pid 149). A checkbox option is NOT a
   single element: REDCap keeps a HIDDEN companion input named
   __chk__<field>_RC_<code> whose VALUE is the code when checked and "" when not
   (type=hidden, so its .checked is meaningless/always false), plus the VISIBLE
   clickable <input type=checkbox> carrying id "id-__chk__<field>_RC_<code>"
   (shared name __chkn__<field>). Older REDCap rendered __chk__<field>_RC_<code>
   itself as the real checkbox. Reading .checked on the name blindly returned "0"
   for a CHECKED box on 17.x, so every [field(code)] reference silently evaluated
   false. Resolution order handles all shapes:
     1. a named __chk__…_RC_code that IS a checkbox  -> its .checked  (classic)
     2. a named __chk__…_RC_code that is hidden       -> value===code  (17.x mirror)
     3. the visible id-__chk__…_RC_code checkbox      -> its .checked  (fallback) */
function QRID_readCheckbox(f, code){
  var nm = "__chk__" + f + "_RC_" + code;
  var els = document.getElementsByName ? document.getElementsByName(nm) : [];
  for(var i = 0; i < els.length; i++){
    var e = els[i];
    if((e.tagName || "").toLowerCase() !== "input") continue;
    var t = (e.type || "").toLowerCase();
    if(t === "checkbox") return e.checked ? "1" : "0";
    if(t === "hidden") return (e.value != null && String(e.value) !== "") ? "1" : "0";
  }
  var vis = document.getElementById ? document.getElementById("id-" + nm) : null;
  if(vis && (vis.type || "").toLowerCase() === "checkbox") return vis.checked ? "1" : "0";
  return "0";
}
/* ---- "when" runtime gate: live DOM + snapshot resolution -------------------
   One shared registry: rules referencing the same field share its change
   listeners; each factory instance gets a small gate object from gateFor().
   Ref values are read by REDCap's NAME conventions via getElementsByName only
   (querySelector is off-limits — the DOM test stub has none): the value
   element is the input/textarea/select named <field> (REDCap keeps a hidden
   input named <field> updated for radio/yesno/truefalse; calc is a readonly
   input); the clickable radio group is named <field>___radio; a checkbox
   option is the input named __chk__<field>_RC_<code>. A ref element that
   never appears on this page falls back to the server-baked saved-value
   snapshot (config.whenValues), then '' — the same value REDCap branching
   would see. Calc fields update without DOM events, so a calc ref refreshes
   on the next event of any listened field or of the validated field itself
   (check()/render() re-consult the gate every time); the server audit is the
   backstop either way. */
var QRID_WHEN = (function(){
  var hooks = Object.create(null);    /* ref field -> [recheck callbacks] */
  var queued = Object.create(null);   /* listener target names already requested */
  var targets = [];                   /* targets still to bind: {name, field, events, tries} */
  var timer = null, mo = null;
  /* The element whose .value carries a field's current answer (select for
     dropdowns, hidden input for radio-family fields, plain input/textarea for
     text). Radio/checkbox inputs themselves are skipped — their group state is
     read through the hidden input / __chk__ elements instead. */
  function findValueEl(name){
    var els = document.getElementsByName ? document.getElementsByName(name) : [];
    for(var si = 0; si < els.length; si++){
      if((els[si].tagName || "").toLowerCase() === "select") return els[si];
    }
    for(var i = 0; i < els.length; i++){
      var tag = (els[i].tagName || "").toLowerCase();
      if(tag === "select" || tag === "textarea") return els[i];
      if(tag === "input"){
        var ty = (els[i].type || "").toLowerCase();
        if(ty !== "radio" && ty !== "checkbox") return els[i];
      }
    }
    return null;
  }
  /* (field, codeOrNull) -> current string value, read from the FORM. A field
     the page does not carry reads '' ('0' for a checkbox ref) — the server
     folds every comparison over such a field into a constant before the
     condition is injected (php/Logic.php fold()), so a ref that reaches here
     is one the browser is expected to be able to read. */
  function readRef(f, code){
    if(code !== null) return QRID_readCheckbox(f, code);
    var el = findValueEl(f);
    if(el) return el.value == null ? "" : String(el.value);
    return "";
  }
  /* readRef for a condition: a comparison sees a date as REDCap stores it,
     and a number as the number it is (12,5 as 12.5). */
  function readLogic(f, code){ return readRef(f, code); }
  readLogic.logicValue = QRID_logicValue;
  readLogic.numberValue = QRID_pageNumberValue;
  readLogic.isMissing = QRID_isMissingCode;
  /* Whether the page carries (field, codeOrNull) at all - a live lookup sends
     only values the page really has (QRID_condValues). */
  function has(f, code){
    if(code !== null && code !== undefined){
      var nm = "__chk__" + f + "_RC_" + code;
      if(document.getElementsByName && document.getElementsByName(nm).length) return true;
      return !!(document.getElementById && document.getElementById("id-" + nm));
    }
    return !!findValueEl(f);
  }
  function fire(f){
    var cbs = hooks[f];
    if(!cbs) return;
    for(var i = 0; i < cbs.length; i++){
      try { cbs[i](); } catch(e){}
    }
  }
  /* A value REDCap writes from script raises no event at all on 17.x: a calc
     result, the number of a slider, @CALCTEXT/@SETVALUE output. The input
     never fires input/change/blur, so a rule reading it (its own verdict, or
     a "when" gate) went on judging the old value until something else was
     typed. Each bound value element is compared with the value its last event
     (or its last poll) saw; a difference nobody announced fires its field.
     Typing is heard by the element's own input events, which record the value
     as known, so the poll never judges a number half typed. REDCap's Today and
     Now buttons set the value of a field that keeps the focus, and raise no
     change either. */
  var silent = [], silentTimer = null;
  function watchSilent(el, field){
    var ty = (el.type || "").toLowerCase();
    if(ty === "radio" || ty === "checkbox" || ty === "file" || ty === "submit" || ty === "button") return;
    if(typeof el.value !== "string" || el.__qridSilent) return;
    el.__qridSilent = true;
    el.__qridLast = el.value;
    silent.push({ el: el, field: field });
    if(silentTimer === null && typeof setInterval === "function"){
      silentTimer = setInterval(pollSilent, 400);
      if(silentTimer && typeof silentTimer.unref === "function") silentTimer.unref();
    }
  }
  function pollSilent(){
    var live = [];
    var doc = typeof document !== "undefined" ? document : null;
    for(var i = 0; i < silent.length; i++){
      var s = silent[i], el = s.el;
      /* a node REDCap rebuilt leaves a stale element behind: drop it, the
         sweep binds the new one */
      if(doc && doc.body && typeof doc.body.contains === "function" && !doc.body.contains(el)){ continue; }
      live.push(s);
      var v = el.value;
      if(v === el.__qridLast) continue;
      el.__qridLast = v;
      fire(s.field);
    }
    silent = live;
  }
  /* Bind the recheck trigger to every element carrying this target name.
     Returns true once at least one element is hooked (or was already). */
  function tryBind(t){
    var els = t.id ? [document.getElementById(t.id)] : (document.getElementsByName ? document.getElementsByName(t.name) : []);
    var bound = false;
    for(var i = 0; i < els.length; i++){
      var el = els[i];
      if(!el) continue;
      if(el.__qridWhenBound){ bound = true; continue; }
      if(!el.addEventListener) continue;
      el.__qridWhenBound = true;
      bound = true;
      var handler = function(){
        /* an event heard the new value: the silent-change poll must not
           announce it a second time */
        if(this && typeof this.value === "string") this.__qridLast = this.value;
        if(t.id) setTimeout(function(){ fire(t.field); }, 0);
        else fire(t.field);
      };
      watchSilent(el, t.field);
      /* REDCap's autocomplete can trigger jQuery change without a native
         event. jQuery handles both, so use one listener path, never both. */
      var jq = typeof window !== "undefined" && window.jQuery;
      if(jq && jq.fn && jq.fn.on) jq(el).on(t.events.join(".uvWhen ") + ".uvWhen", handler);
      else for(var k = 0; k < t.events.length; k++) el.addEventListener(t.events[k], handler);
    }
    return bound;
  }
  function stopSweep(){
    if(timer !== null && typeof clearInterval === "function"){ clearInterval(timer); }
    timer = null;
    if(mo){ mo.disconnect(); mo = null; }
  }
  /* Same late-render tolerance as the factories' boot(): retry each target on
     a 500ms interval (max 20 tries) plus a MutationObserver. A target that
     never appears is an off-page ref — the snapshot covers it, so giving up
     quietly is correct. */
  /* Only a TIMER tick counts as a try. requestField() used to sweep once per
     requested ref, so a rule watching 84 codes spent every pending target's
     20 tries in one synchronous burst, and a late-rendered autocomplete input
     then never bound at all. */
  function sweep(tick){
    var remaining = [];
    for(var i = 0; i < targets.length; i++){
      var t = targets[i];
      if(t.dead || bindTarget(t)) continue;
      if(tick) t.tries = (t.tries || 0) + 1;
      if((t.tries || 0) < 20) remaining.push(t);
    }
    targets = remaining;
    if(!targets.length) stopSweep();
    return !targets.length;
  }
  /* A field's own element tells which of its other targets can ever appear:
     radio buttons sit beside the hidden input that carries a radio's answer,
     and REDCap builds the autocomplete box over a dropdown's select. A text
     input or a textarea has neither, so once it binds those targets are
     dropped, and no later sweep looks for them again (PRF-4). */
  function bindTarget(t){
    if(!tryBind(t)) return false;
    if(t.siblings){
      var els = document.getElementsByName(t.name), hidden = false, select = false;
      for(var i = 0; i < els.length; i++){
        var tag = (els[i].tagName || "").toLowerCase();
        if(tag === "select") select = true;
        else if(tag === "input" && (els[i].type || "").toLowerCase() === "hidden") hidden = true;
      }
      if(!hidden) t.siblings.radio.dead = true;
      if(!select) t.siblings.ac.dead = true;
    }
    return true;
  }
  /* The new target, or null when that name was already asked for. */
  function queueTarget(name, field, events, byId){
    var key = (byId ? "id:" : "name:") + name;
    if(queued[key]) return null;
    queued[key] = true;
    return { name: name, id: byId ? name : null, field: field, events: events, tries: 0 };
  }
  /* Only the targets this call adds are looked up now. Sweeping every pending
     target on each call repeated every earlier failed lookup once per field,
     so a page of n referenced fields made about n squared lookups at boot
     (PRF-4). A target that is not on the page yet waits for the timer and the
     MutationObserver. */
  function requestField(f, code){
    var fresh;
    if(code !== null){
      // classic REDCap: __chk__…_RC_code is the real checkbox and fires events.
      // REDCap 17.x: that element is a hidden mirror (no events); the VISIBLE
      // checkbox shares the name __chkn__<field> — bind there so a live toggle
      // still re-evaluates the gate (LIVE-FOUND, see QRID_readCheckbox).
      fresh = [queueTarget("__chk__" + f + "_RC_" + code, f, ["change", "click"]),
               queueTarget("__chkn__" + f, f, ["change", "click"])];
    } else {
      var own = queueTarget(f, f, ["input", "change"]);
      var radio = queueTarget(f + "___radio", f, ["change", "click"]);
      var ac = queueTarget("rc-ac-input_" + f, f, ["autocompleteselect", "autocompletechange", "change"], true);
      if(own && radio && ac) own.siblings = { radio: radio, ac: ac };
      fresh = [own, radio, ac];
    }
    var lookups = typeof document !== "undefined" && !!document.getElementsByName;
    var waiting = false;
    for(var i = 0; i < fresh.length; i++){
      var t = fresh[i];
      if(!t) continue;
      if(lookups && (t.dead || bindTarget(t))) continue;
      targets.push(t);
      waiting = true;
    }
    if(!lookups || !waiting) return;
    if(timer === null && typeof setInterval === "function"){
      timer = setInterval(function(){ sweep(true); }, 500);
    }
    if(!mo && typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  /* Factory-facing API. Returns null when the rule has no condition (zero
     behavior change), else {active, onChange}.
     astRaw is the server's PRE-FOLDED condition (php/Logic.php fold()) and is
     authoritative when present; exprRaw (the condition text) is parsed here
     only when no AST was supplied — the window-config fallback and the test
     harnesses take that path. A condition that does not parse —
     near-impossible for stored rules, every configuration channel validates
     it — fails OPEN: the rule goes inert (never traps a save), the reason
     lands on the console, and the server audit (which skips the rule as a
     config error) keeps it from passing silently. */
  /* `blank` is the verdict an ordered comparison yields when an operand is
     blank (CRIT-01). It defaults to QRID_BLANK_INERT because this is the GATE
     factory: an unanswerable threshold cannot say the rule applies, so it does
     not fire. The one caller that is NOT a gate — the @UVASSERT test — passes
     QRID_BLANK_PASSES explicitly, so no gate site can acquire assert polarity
     by forgetting an argument. `caseSensitive` is the rule's own flag: every
     rule site passes cfg.caseSensitive === true, and a missing argument gets
     the module default (A-Z compare without regard to case). */
  function gateFor(exprRaw, astRaw, blank, caseSensitive){
    if(blank === undefined) blank = QRID_BLANK_INERT;
    if(caseSensitive === undefined) caseSensitive = false;
    var ast;
    if(astRaw && typeof astRaw === "object" && astRaw.length){
      ast = astRaw;
    } else {
      if(exprRaw === undefined || exprRaw === null || exprRaw === "") return null;
      var r = QRID_whenParse(typeof exprRaw === "string" ? exprRaw : String(exprRaw));
      if(!r.ok){
        try {
          if(typeof console !== "undefined" && console.error){
            console.error('Universal Field Validator: the "when" condition ' + r.error + ' — rule disabled: ' + exprRaw);
          }
        } catch(e){}
        return { active: function(){ return false; }, onChange: function(){} };
      }
      ast = r.ast;
    }
    var refs = QRID_whenRefs(ast);
    var unknown = false;
    return {
      refs: function(){ return refs; },
      unresolved: function(){ return unknown; },
      active: function(){
        try { var verdict = QRID_whenEvaluateWith(ast, readLogic, blank, caseSensitive); unknown = verdict === null; return verdict === true; }
        catch(e){ unknown = true; return false; } /* fail open: never trap a save on a gate bug */
      },
      onChange: function(cb){
        for(var i = 0; i < refs.length; i++){
          var f = refs[i][0];
          if(!hooks[f]) hooks[f] = [];
          /* One callback is registered once per field however many gates hand
             it over. A factory watching N codes of one field used to be called
             N times for every single change of that field. */
          if(hooks[f].indexOf(cb) < 0) hooks[f].push(cb);
          requestField(f, refs[i][1]);
        }
      }
    };
  }
  return { gateFor: gateFor, readRef: readRef, has: has };
})();
/* The values this page reads for the fields its branch conditions test, sent
   with a live lookup (unique-check, exists-check) so the server answers from
   the branch the page is enforcing (activeRuleFor), not from the one the SAVED
   record would select. Only fields present on the page are sent; the server
   keeps the saved value for any other. Checkbox options go as code -> "1"/"0". */
function QRID_condValues(variants){
  var out = {};
  for(var i = 0; i < variants.length; i++){
    var g = variants[i].gate;
    if(!g || typeof g.refs !== "function") continue;
    var refs = g.refs();
    for(var j = 0; j < refs.length; j++){
      var f = refs[j][0], code = refs[j][1];
      if(typeof f !== "string" || !QRID_WHEN.has(f, code)) continue;
      if(code === null || code === undefined){ out[f] = QRID_logicValue(f, QRID_WHEN.readRef(f, null)); continue; }
      if(typeof out[f] !== "object" || out[f] === null) out[f] = {};
      out[f][code] = QRID_readCheckbox(f, code);
    }
  }
  return out;
}
/* A small answer cache for the live lookups: the payload (value, match or
   "with" values, branch values) -> the server's answer. Several entries, so
   flipping a selector back and forth or retyping a value asks once. */
function QRID_answerCache(max){
  var map = Object.create(null), order = [];
  return {
    get: function(k){ return Object.prototype.hasOwnProperty.call(map, k) ? map[k] : null; },
    put: function(k, v){
      if(!Object.prototype.hasOwnProperty.call(map, k)){ order.push(k); if(order.length > max) delete map[order.shift()]; }
      map[k] = v;
    }
  };
}
/* How long a save waits on a lookup still in flight before the lookup counts
   as unanswered (QRID_registerBlocker holds the save meanwhile). */
var QRID_PENDING_MS = 10000;
/* How long Save trusts a cached answer that blocks it ("used", "not found").
   Older, Save asks the server again, since another record may have changed
   in the meantime, and holds the save until the answer lands. A fresh answer
   decides the next click, so a second click gets the "Save anyway?" dialog
   or the block message instead of another wait. */
var QRID_RECHECK_MS = 30000;
/* A copy of a lookup answer, stamped with the time it was given. */
function QRID_stampAnswer(resp){
  var o = {};
  for(var k in resp){ if(Object.prototype.hasOwnProperty.call(resp, k)) o[k] = resp[k]; }
  o.__at = Date.now();
  return o;
}
/* Whether Save asks again about a cached answer given QRID_RECHECK_MS ago or earlier. */
function QRID_answerStale(entry){
  return !entry || typeof entry.__at !== "number" || Date.now() - entry.__at >= QRID_RECHECK_MS;
}
/* ---- optional save blocking (shared by both factories) ---------------------
   blockSave: "off" (warn-only) | "confirm" (Save anyway? dialog) | "hard"
   (refuse the BROWSER save until fixed — it cannot stop API/import writes;
   the server audit is the net for those). Guards real form submits, REDCap's
   save buttons (which submit programmatically and never fire a submit event)
   and every call of REDCap's dataEntrySubmit, which the Enter key in a text
   field makes (QRID_guardDataEntrySubmit). Native alert/confirm are used
   deliberately: they are modal and announced by assistive tech without
   custom focus management. */
function QRID_registerBlocker(input, fieldName, blockMode){
  /* A field the user cannot edit must never trap the save: readonly/disabled
     inputs (e.g. @READONLY) keep their message but are exempt from blocking
     (UX-003) — the server audit still sees the value. The test is the shared
     one, which also reads a radio's or checkbox's options (REQ-2). A plain
     guard item has no DOM: its factory asked the same test already, and a
     @UVEXISTS item on a read-only field still registers on purpose, to hold
     the save while a lookup that fills fields is on its way. */
  if(input.getAttribute && QRID_fieldLocked(input, fieldName)) return;
  input.__qridBlockMode = blockMode;
  input.__qridFieldName = fieldName;
  /* a guard item is a plain object that arrives with the label its factory
     read from the field's row: asking the object (it has no DOM) would
     overwrite that label with the field name, and the save dialog would
     list "uw_live" instead of the question the person sees */
  if(!input.__qridFieldLabel || input.__qridFieldLabel === fieldName){
    input.__qridFieldLabel = QRID_fieldLabel(input, fieldName);
  }
  UV_guard.items.push(input);
  if(UV_guard.armed) return;
  UV_guard.armed = true;
  /* true when the save may go on, false when the guard held it. $by is the
     button or element that asked for the save, when known: on a survey,
     Previous Page and Save & Return Later save without finishing the
     response, and the rules that wait for a complete response (REQ-7,
     php/modes.json "surveySave") do not hold them. */
  function guard(e, by){
    var partial = QRID_partialSave(by);
    /* the click guard runs before the programmatic submit it triggers; a
       user who already chose "Save anyway" must not be asked twice */
    if(UV_guard.passUntil && new Date().getTime() < UV_guard.passUntil) return true;
    /* Branch rules overwrite __qridBlockMode per check with the ACTIVE
       branch's mode, which may be "off" — such a field never holds the save.
       (Pre-0.9.0 configs never register "off" items, so this filter is a
       no-op for them.) */
    /* A verdict is only as fresh as the last DOM event. A controlling field
       changed without one (a calc, REDCap's radio "reset" link, a script) left a
       stale flag behind: a save blocked on a field that was already fine, or let
       through on one that no longer was. Items that can re-judge themselves do
       so before the guard reads them. */
    for(var ri = 0; ri < UV_guard.items.length; ri++){
      var item = UV_guard.items[ri];
      if(item && typeof item.__qridRecheck === "function"){ try { item.__qridRecheck(); } catch(_r){} }
    }
    /* Asked again here, not only at bind: a field the person cannot change
       (REDCap may apply @READONLY after the engine bound it, REQ-2) or cannot
       see (its row hidden by branching logic or @HIDDEN, REQ-1) never holds
       the save. The notice stays; the server audit still sees the value. */
    var bad = UV_guard.items.filter(function(el){
      if(!el.__qridInvalid || el.__qridBlockMode === "off") return false;
      if(partial && QRID_PARTIAL_SAVE.types.indexOf(el.__qridType) >= 0) return false;
      var anchor = el.__qridLabelEl || (el.getAttribute ? el : null);
      return !(anchor && (QRID_fieldLocked(anchor, el.__qridFieldName) || QRID_fieldHidden(anchor)));
    });
    /* A lookup still in flight holds the save. Clicking Save blurs the field,
       and the blur is when a lookup asks: letting the save through would
       decide it before the answer that may block it arrives. Only items whose
       active rule can block, or fills fields from the answer (@UVEXISTS
       "return"), set __qridPending, and they drop it when the answer comes
       or after QRID_PENDING_MS. */
    var pend = UV_guard.items.filter(function(el){
      return el.__qridPending && bad.indexOf(el) < 0 && !(partial && QRID_PARTIAL_SAVE.types.indexOf(el.__qridType) >= 0);
    });
    if(!bad.length && !pend.length) return true;
    var label = QRID_guardLabel;
    /* one label a line (QRID_fieldLabel folds a label's own line breaks):
       a label may hold a comma, so a list joined by ", " reads as more
       fields than it names */
    var names = bad.map(label).concat(pend.map(function(el){ return label(el) + " (still being checked)"; }))
      .map(function(t){ return "\n• " + t; }).join("");
    var hard = pend.length > 0 || bad.some(function(el){ return el.__qridBlockMode === "hard"; });
    if(hard){
      e.preventDefault();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      window.alert(bad.length ? "Cannot save yet — please fix the flagged field(s):" + names
                              : "Cannot save yet — please wait a moment and save again:" + names);
      try { (bad[0] || pend[0]).focus(); } catch(_f){}
      return false;
    }
    /* An empty required field failed no validation: name what it is (REQ-13). */
    var onlyBlank = bad.length > 0 && bad.every(function(el){ return el.__qridType === "required"; });
    if(window.confirm((onlyBlank ? "These fields are still empty:" : "Validation FAILED for:") + names + "\n\nSave anyway?")){
      UV_guard.passUntil = new Date().getTime() + 2000;
      return true;
    }
    e.preventDefault();
    if(e.stopImmediatePropagation) e.stopImmediatePropagation();
    return false;
  }
  document.addEventListener("submit", function(e){ return guard(e, e && e.submitter); }, true);
  document.addEventListener("click", function(e){
    var t = e.target;
    while(t && t.getAttribute){
      var nm = (t.getAttribute("name") || t.id || "");
      if(nm.indexOf("submit-btn-save") === 0){
        /* A click the guard let through goes on, in this same task, to the
           button's own onclick, dataEntrySubmit(this). The verdict cannot
           change in between, so the wrapper does not judge again; a re-run
           would repeat every recheck. */
        if(guard(e, t)){
          UV_guard.clickPass = true;
          setTimeout(function(){ UV_guard.clickPass = false; }, 0);
        }
        return;
      }
      t = t.parentNode;
    }
  }, true);
  QRID_guardDataEntrySubmit(guard);
}
/* The survey buttons that save a response without finishing it, and the rule
   types that do not hold them (REQ-7). The server sends both on a survey page
   from php/modes.json (common.partialSaveButtons, "surveySave"). */
var QRID_PARTIAL_SAVE = (function(){
  var p = QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.partialSave;
  return {
    buttons: (p && Array.isArray(p.buttons)) ? p.buttons : [],
    types: (p && Array.isArray(p.types)) ? p.types : []
  };
})();
/* Whether the element that asked for a save is one of those buttons. */
function QRID_partialSave(by){
  if(!QRID_PARTIAL_SAVE.buttons.length || !by) return false;
  var nm = (by.getAttribute ? (by.getAttribute("name") || by.id || "") : (by.name || by.id || ""));
  return typeof nm === "string" && QRID_PARTIAL_SAVE.buttons.indexOf(nm) >= 0;
}
/* REDCap's own way into a data-entry save (LIVE-1). Pressing Enter in a text
   field runs a keydown handler that blurs the field and then calls
   dataEntrySubmit($('form#form :input[name="submit-btn-saverecord"]'))
   directly (REDCap 13 to 17). dataEntrySubmit ends in document.form.submit(),
   so there is no click on a save button and no submit event: neither listener
   above saw it, and a hard block saved anyway. Barcode scanners send Enter
   after each scan. Scripts and jQuery .trigger("click") reach the same
   function, because jQuery runs the button's inline onclick before the native
   click.
   The handler looks dataEntrySubmit up as a global when it runs, so replacing
   window.dataEntrySubmit puts the guard in front of every caller. A held save
   returns false and the original never runs. REDCap defines the function in a
   script loaded before this one, on data-entry forms and surveys alike. On a
   page still loading, DOMContentLoaded and load try again for a function
   defined later; a loaded page without it is left alone. */
function QRID_guardDataEntrySubmit(guard){
  function wrap(){
    var orig = window.dataEntrySubmit;
    if(typeof orig !== "function") return false;
    if(orig.__qridGuarded) return true;
    var guarded = function(ob){
      if(!UV_guard.clickPass){
        var ev = { type: "dataEntrySubmit", preventDefault: function(){}, stopImmediatePropagation: function(){} };
        if(!guard(ev, ob && ob.jquery ? ob[0] : ob)) return false;
      }
      return orig.apply(this, arguments);
    };
    guarded.__qridGuarded = true;
    window.dataEntrySubmit = guarded;
    return true;
  }
  if(wrap() || document.readyState === "complete") return;
  if(document.readyState === "loading" && typeof document.addEventListener === "function"){
    document.addEventListener("DOMContentLoaded", wrap);
  }
  if(typeof window.addEventListener === "function") window.addEventListener("load", wrap);
}
/* Wire one message region under one input: polite live region + programmatic
   error relationship, so dynamic verdicts are exposed to assistive technology
   (WCAG 2.2 SC 4.1.3 / F103 — A11Y-001). */
/* $mode namespaces the region, because MODES COMPOSE on one field: a check rule
   and a constraint rule may both govern [study_id], and each attaches its own
   region. They all used to be given the same id, so a field under two rules got
   two elements with one DOM id and an aria-describedby naming it twice — invalid
   HTML, and an ambiguous programmatic relationship for a screen reader. The
   suffix follows the precedent QRID_attachErrorRegion already set with "-cfg".
   The check mode keeps the bare id: it is the original region, it is what
   tests/a11y_dom_js.cjs pins byte-for-byte, and single/pooled are mutually
   exclusive on a field so they can share it. */
function QRID_attachMsgRegion(input, fieldName, mode){
  var id = QRID_msgId(fieldName) + (mode ? "-" + mode : "");
  var msg = document.createElement("div");
  msg.style.display = "none";
  msg.id = id;
  if(msg.setAttribute){
    msg.setAttribute("role", "status");
    msg.setAttribute("aria-live", "polite");
    msg.setAttribute("aria-atomic", "true");
  }
  input.parentNode.insertBefore(msg, input.nextSibling);
  if(input.setAttribute && input.getAttribute){
    var desc = input.getAttribute("aria-describedby") || "";
    /* Never list the same region twice — a re-attach, or two modes racing, must
       not grow the attribute. */
    var seen = desc ? desc.split(/\s+/) : [];
    var dup = false;
    for(var i = 0; i < seen.length; i++) if(seen[i] === id) dup = true;
    if(!dup) input.setAttribute("aria-describedby", desc ? desc + " " + id : id);
  }
  return msg;
}
/* ---- composite per-field presentation state -------------------------------
   The outline and aria-invalid live on the INPUT, which is shared by every mode
   attached to it, so the last mode to run used to decide what the field looked
   like. A field failing its format check could be painted green and announced
   as valid because a constraint rule on the same field happened to be satisfied
   — while the check rule's blocker still, correctly, refused the save.
   State is therefore kept PER MODE and reduced:
     outline      bad (red) > info (blue) > ok (green) > nothing
     aria-invalid any mode bad -> "true"; else any mode with an opinion ->
                  "false"; else the attribute is removed.
   "info" is deliberately not an opinion about validity (it is the uniqueness
   check saying "asking the server"), which keeps today's behaviour of clearing
   aria-invalid while a check is in flight. "warn" is the same: a lookup that
   could not be completed says nothing about the value.
   Save-blocking is NOT routed through here: each mode keeps its own guard item,
   which is what makes composition correct in the first place. */
var QRID_OUTLINE = { bad: "2px solid #c62828", warn: "2px solid #b7791f", info: "2px solid #0067c0", ok: "2px solid #2e9e44" };
function QRID_setModeState(input, mode, kind){   /* "bad" | "ok" | "warn" | "info" | null */
  if(!input) return;
  var st = input.__qridModeState;
  if(!st){ st = {}; input.__qridModeState = st; }
  if(kind === null || kind === undefined) delete st[mode]; else st[mode] = kind;
  var bad = false, warn = false, info = false, ok = false;
  for(var k in st){
    if(!Object.prototype.hasOwnProperty.call(st, k)) continue;
    if(st[k] === "bad") bad = true;
    else if(st[k] === "warn") warn = true;
    else if(st[k] === "info") info = true;
    else if(st[k] === "ok") ok = true;
  }
  input.style.outline = bad ? QRID_OUTLINE.bad
                     : warn ? QRID_OUTLINE.warn
                     : info ? QRID_OUTLINE.info
                     : ok   ? QRID_OUTLINE.ok : "";
  QRID_setInvalidState(input, bad ? true : (ok ? false : null));
  /* An autocomplete dropdown's <select> is hidden; what the user sees is its
     companion text input (QRID_followWidget). */
  var comp = input.__qridCompanion;
  if(comp && comp.style){
    comp.style.outline = input.style.outline;
    QRID_setInvalidState(comp, bad ? true : (ok ? false : null));
  }
}
/* Autocomplete dropdowns: REDCap pairs the hidden <select> with a companion
   text input (id rc-ac-input_<field>) that its widget finds right NEXT TO the
   select, so a message region left after the select sits between the two
   (reports/choices-dropdown-review-2026-09-17.md; @UVCHOICES does the same).
   Move the region after the companion's wrapper, describe the companion, and
   have QRID_setModeState paint the companion. The companion can render after
   the field binds, so callers run this on every check; it does nothing once
   done or when the field has no companion. */
function QRID_followWidget(input, msg, fieldName){
  if(!document.getElementById) return;
  var ac = document.getElementById("rc-ac-input_" + fieldName);
  if(!ac || msg.__qridBeside === ac) return;
  msg.__qridBeside = ac;
  input.__qridCompanion = ac;
  var host = ac.parentNode;
  if(host && host.parentNode) host.parentNode.insertBefore(msg, host.nextSibling);
  if(ac.setAttribute && ac.getAttribute){
    var desc = ac.getAttribute("aria-describedby") || "";
    if((" " + desc + " ").indexOf(" " + msg.id + " ") < 0)
      ac.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
  }
  if(ac.style) ac.style.outline = input.style.outline;
}
function QRID_setInvalidState(input, state){  /* true | false | null (empty field) */
  if(!input.setAttribute) return;
  if(state === null){ if(input.removeAttribute) input.removeAttribute("aria-invalid"); return; }
  input.setAttribute("aria-invalid", state ? "true" : "false");
}
/* Configuration-error message directly under an affected field (UX-001: the
   error belongs where the designer will look). Survey respondents get a
   generic line instead of technical detail; staff see the full message. A
   separate bound-marker and region id keep this from ever claiming the field
   against a live validator. Returns false when the field is not on this page
   (caller falls back to the page-level notice). A dropdown has no text input,
   so its error went only to the page-level notice (CHO-10): the select is the
   anchor then, and the region goes at the end of its cell, which keeps the
   select next to the companion input REDCap's autocomplete builds after it.
   Every mode shares the region, so its label names none of them. */
function QRID_attachErrorRegion(fieldName, configError){
  var input = QRID_findField(fieldName) || QRID_findAnchor(fieldName);
  if(!input || !input.parentNode) return false;
  if(input.getAttribute && input.getAttribute("data-qrid-err-bound")) return true;
  if(input.setAttribute) input.setAttribute("data-qrid-err-bound", "1");
  var msg = document.createElement("div");
  msg.id = QRID_msgId(fieldName) + "-cfg";
  if(msg.setAttribute){
    msg.setAttribute("role", "status");
    msg.setAttribute("aria-live", "polite");
    msg.setAttribute("aria-atomic", "true");
  }
  msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
    "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
  if((input.tagName || "").toLowerCase() === "select") input.parentNode.appendChild(msg);
  else input.parentNode.insertBefore(msg, input.nextSibling);
  if(input.setAttribute && input.getAttribute){
    var desc = input.getAttribute("aria-describedby") || "";
    input.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
  }
  msg.innerHTML = QRID_IS_SURVEY
    ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
    : "&#9888; Validator configuration error: " + QRID_escapeHtml(configError);
  return true;
}
/* Show a configuration error that has no field widget to sit under — a rule whose
   fields were all mis-typed / not on the form, or an @UVALIDATE tag on a non-text
   field (dropdown, radio, calc) where there is no <input>/<textarea> — in a single
   page-level notice, so it is never silently lost. Deduplicated by message.
   Muted on surveys: respondents cannot act on configuration detail, and field
   names/technical text do not belong on a public page (UX-001) — the same
   errors stay fully visible to staff on data-entry forms and in the module log. */
function QRID_configErrorNotice(message){
  if(QRID_IS_SURVEY) return;
  if(typeof document === "undefined" || !document.body) return;
  var box = document.getElementById("uvalidate-config-errors");
  if(!box){
    box = document.createElement("div");
    box.id = "uvalidate-config-errors";
    box.style.cssText = "margin:8px 0;padding:8px 12px;border-radius:4px;font-family:inherit;" +
      "font-size:13px;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828;";
    box.innerHTML = "<b>&#9888; Universal Field Validator — configuration error(s):</b>";
    var host = document.getElementById("form") || document.querySelector("form");
    if(host && host.parentNode) host.parentNode.insertBefore(box, host);
    else document.body.insertBefore(box, document.body.firstChild);
  }
  var seen = box.getAttribute("data-msgs") || "";
  if(seen.indexOf("" + message + "") >= 0) return;       /* already shown */
  box.setAttribute("data-msgs", seen + "" + message + "");
  var line = document.createElement("div");
  line.style.marginTop = "4px";
  line.innerHTML = "&bull; " + QRID_escapeHtml(message);
  box.appendChild(line);
}
/* ---- single-field validator (factory: one instance per config/rule) ---- */
/* ---- branched validation: shared variant plumbing (module feature) ---------
   A rule may arrive as a per-field BRANCH rule ({fields:[f], branches:[...]})
   built by php/Branching.php — several conditional rules sharing one field.
   Each branch is built into a VARIANT by the factory's makeVariant() (the
   whole mode-resolution + verdict closure, verbatim); a plain rule is exactly
   one variant, so the legacy path is byte-identical. Runtime semantics are
   specified normatively in php/Branching.php: one active variant validates;
   none -> the else variant if present, otherwise inert; more than one -> a
   conflict (a configuration problem — shown, never blocking, never guessed).
   tests/branch_dom_js.cjs locks the client side of the same scenario table
   tests/branching_php.php and tests/hook_php.php lock server-side. */
function QRID_buildVariants(cfg, makeVariant){
  var variants = [], elseVariant = null;
  /* A rule deferred only because the page could not carry its data
     (deferredOnSave: checked when the record is saved) still has active
     variants, so a mode can check what needs no such data. */
  var unresolved = !!cfg.deferred && cfg.deferredOnSave !== true;
  /* Branch groups (php/Branching.php): a mode whose branches split by the
     value of one key (@UVRANGE: its reference) resolves them once per group,
     with an else per group. The dispatcher names the key (cfgFor). */
  var groupKey = (typeof cfg.branchGroup === "string" && cfg.branchGroup !== "") ? cfg.branchGroup : null;
  var groups = [], elseByGroup = Object.create(null), seenGroup = Object.create(null);
  /* A group whose branch selector the server could not resolve
     (selectorBlocked, UniversalValidator::foldRuleConditions): every branch of
     it is deferred, and its first variant stands for the group, so the page
     names the deferral even when none of its conditions holds. */
  var blockedGroups = Object.create(null);
  function build(input){
    var v = makeVariant(input);
    v.unavailable = !!input.deferred && v.deferred === undefined;
    if(input.deferred || (input.snapshotFields && input.snapshotFields.length)
        || (cfg.snapshotFields && cfg.snapshotFields.length)) v.blockSave = "off";
    var g = groupKey && input[groupKey] != null ? String(input[groupKey]) : "";
    v.group = g;
    if(!seenGroup[g]){ seenGroup[g] = true; groups.push(g); }
    if(input.selectorBlocked === true && !blockedGroups[g]) blockedGroups[g] = v;
    return v;
  }
  var elses = [];
  if(cfg.branches && cfg.branches.length){
    for(var i = 0; i < cfg.branches.length; i++){
      var v = build(cfg.branches[i]);
      if(v.when === null){
        if(!elseVariant) elseVariant = v;
        if(!elseByGroup[v.group]) elseByGroup[v.group] = v;
        elses.push(v);
      }
      else variants.push(v);
    }
  } else {
    variants = [build(cfg)];
  }
  var all = elses.length ? variants.concat(elses) : variants;
  var configError = "";
  for(var c = 0; c < all.length; c++){
    if(all[c].configError){ configError = all[c].configError; break; }
  }
  var firstBlock = "off";
  for(var b = 0; b < all.length; b++){
    if(all[b].blockSave !== "off"){ firstBlock = all[b].blockSave; break; }
  }
  return { variants: variants, elseVariant: elseVariant, all: all,
           grouped: groups.length > 1, groups: groups, elseByGroup: elseByGroup, blockedGroups: blockedGroups,
           configError: configError, firstBlock: firstBlock, unresolved: unresolved, config: cfg };
}
/* Stands in for a branch group whose selector this page cannot resolve right
   now (a gate that answers "unknown"): the group's own deferral line, while the
   other groups judge. The post-save audit reads the saved values. */
function QRID_dynamicDeferralVariant(group){
  return { group: group, deferred: true, onSave: false, checkedOnSave: true, deferredWhy: [QRID_DYNAMIC_DEFERRAL],
           gate: null, watch: [], blockSave: "off", measureBlock: "off", when: null };
}
/* The variants whose gate is true right now; the else variant only when no
   conditional one is active. A gate-less variant (legacy rule without "when")
   is always active. With branch groups this holds per group: the result has
   one variant per group that applies, and two active variants of ONE group
   come back alone, marked "conflict". A selector that cannot be resolved
   (the server's selectorBlocked, or a gate that answers "unknown" here)
   belongs to its own group: that group contributes a deferred variant, and the
   other groups still judge. */
function QRID_activeVariants(vs){
  vs.config.dynamicDeferred = false;
  if(vs.unresolved && !vs.configError) return [];
  var act = [], unknown = Object.create(null);
  for(var i = 0; i < vs.variants.length; i++){
    var g = vs.variants[i].gate;
    if(!g || g.active()) act.push(vs.variants[i]);
    if(g && g.unresolved && g.unresolved()){
      if(!vs.grouped){ vs.config.dynamicDeferred = true; return []; }
      unknown[vs.variants[i].group] = true;
    }
  }
  if(vs.grouped){
    var byGroup = Object.create(null), gi, k, gk;
    var blocked = vs.blockedGroups || Object.create(null);
    for(k = 0; k < act.length; k++) (byGroup[act[k].group] = byGroup[act[k].group] || []).push(act[k]);
    for(gi = 0; gi < vs.groups.length; gi++){
      gk = vs.groups[gi];
      var list = byGroup[gk];
      /* A group the server set aside (blocked) defers as a whole: its
         conditions choose nothing, so two of them true is not a conflict, and
         the server skips it too (ruleFindings). A group with a gate that
         answers "unknown" here is different: when two of its other gates are
         true, the server, which reads the unknown one, sees a conflict too. */
      if(list && list.length > 1 && !blocked[gk]){ var clash = list.slice(0, 2); clash.conflict = true; return clash; }
    }
    act = [];
    for(gi = 0; gi < vs.groups.length; gi++){
      gk = vs.groups[gi];
      var one = blocked[gk] ? blocked[gk]
              : unknown[gk] ? QRID_dynamicDeferralVariant(gk)
              : byGroup[gk] ? byGroup[gk][0] : vs.elseByGroup[gk];
      if(one) act.push(one);
    }
  } else if(!act.length && vs.elseVariant) act = [vs.elseVariant];
  for(var a = 0; a < act.length; a++) if(act[a].unavailable) return [];
  return act;
}
/* More than one branch condition is true at once: a configuration problem,
   not a data problem — show it (generic on surveys), validate nothing, and
   NEVER hold the save (the server audit logs the same conflict). */
function QRID_renderConflict(msg, input, act, mode){
  msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
    "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
  msg.innerHTML = QRID_IS_SURVEY
    ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
    : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
      QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
      '". The value was <b>NOT</b> validated; make the conditions mutually exclusive.';
  /* The input IS the guard item of the check mode only. Every other mode keeps
     its own guard item and releases it itself; clearing the input here lifted
     an @UVALIDATE block that had nothing to do with this rule. */
  if((mode || "check") === "check") input.__qridInvalid = false;
  QRID_setModeState(input, mode || "check", null);
}
/* NO variant is active AND the server deferred the whole rule. That is not the
   ordinary "no branch applies here" — it means every branch SELECTOR rested on
   a value the server could not resolve, so no branch can be chosen and the
   field stopped being checked. Falling straight through to inert() left the
   person typing with no indication whatsoever that validation had gone away
   (M-01), which is the silent failure the reasons exist to prevent.
   Survey respondents still get nothing: the reasons name other instruments and
   fields they must not learn about, and they could not act on a design problem
   anyway. Returns true when it rendered the notice; false leaves the caller's
   own inert path untouched, so an ordinary inapplicable rule is unchanged. */
function QRID_renderRuleDeferral(msg, input, cfg, mode){
  if(QRID_IS_SURVEY || !cfg || (!cfg.deferred && !cfg.dynamicDeferred)) return false;
  if(cfg.dynamicDeferred){ QRID_renderDeferralNotice(msg, input, [QRID_DYNAMIC_DEFERRAL], mode, true); return true; }
  var why = cfg.deferredWhy;
  if(!why || !why.length) return false;
  QRID_renderDeferralNotice(msg, input, why, mode);
  return true;
}
/* A match key typed on this page names other saved data than the page was given. */
var QRID_DYNAMIC_DEFERRAL = "Current values cannot resolve this comparison. Save and reload to refresh matched data.";
/* The one wording for "this rule is not being checked", shared by the rule-level
   notice above and every validator's per-variant deferral. It was wrong once
   already — it promised the value would still be checked after saving, which the
   audit does not do for an unresolved reference — so there is exactly one copy.
   checkedOnSave: only this page could not resolve it (a match key typed here);
   the post-save audit reads the saved values and does check it. */
function QRID_renderDeferralNotice(msg, input, why, mode, checkedOnSave, subject){
  msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
    "font-size:13px;font-family:inherit;border:1px solid #d9c48a;background:#fdf8e6;color:#7a5c00";
  msg.innerHTML = "&#9888; " + QRID_deferralText(why, checkedOnSave, subject);
  QRID_setModeState(input, mode || "check", null);
}
/* The notice's text, escaped, without its icon: also one line of a note with
   several (@UVRANGE with several references). subject, escaped HTML, names
   what is not checked when it is one part of a field ('Reference "x" is'). */
function QRID_deferralText(why, checkedOnSave, subject){
  return (subject ? subject + " not being checked — " : "This rule is not being checked — ") + QRID_escapeHtml(why.join(" ")) +
    (checkedOnSave ? " It is checked after the record is saved."
                   : " It is not checked after saving either; the study team needs to correct the rule.");
}
function QRIDSingleInit(QRID_CONFIG){

  function styleMsg(el, kind){   /* kind: true=ok, false=bad, "info"=typing, "err"=config error */
    var c = (kind === true) ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "info") ? "#c9dbf5;background:#eef3fb;color:#3a567f"
          : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }

  /* ---- one validation VARIANT: the whole mode-resolution + verdict closure,
     built once per rule (legacy) or once per branch (branch rules). ---- */
  function makeVariant(cfg){

  /* ---- resolve the accepted ID formats from the config ----
     One rule = one or more ALTERNATES, each with its own pattern and its own
     check algorithm. A legacy scalar rule normalizes to exactly one alternate,
     so the verdict below is a single loop rather than two code paths. */
  var configError = cfg.configErrorOverride || "";
  var A0 = QRID_alternatesOf(cfg);
  configError = configError || A0.error;
  var ALTS = A0.list, HAS_ALTS = !!(cfg.alternates != null && cfg.alternates !== "");
  if(!configError && HAS_ALTS && cfg.idPattern){
    configError = "a rule with \"alternates\" must not also set a rule-level \"pattern\" — " +
      "give each alternate its own.";
  }
  for(var ai = 0; ai < ALTS.length && !configError; ai++){
    var A = ALTS[ai], an = QRID_altName(A, ai);
    if(A.algorithm !== "none" && !Q.ALGORITHMS[A.algorithm]){
      configError = "Unknown algorithm \"" + A.algorithm + "\"" +
        (HAS_ALTS ? " on " + an : "") + ". Valid: " + Object.keys(Q.ALGORITHMS).join(", ") + ".";
      break;
    }
    A.check = (A.algorithm !== "none");
    if(A.pattern){
      var gA = QRID_gatePattern(A.pattern, HAS_ALTS ? an : "");
      if(gA.error){ configError = gA.error; break; }
      A.re = gA.re;
    } else { A.re = null; }
    A.regexOnly = (!A.check && !!A.re);
    if(!A.check && !A.regexOnly){
      configError = (HAS_ALTS ? an + ": algorithm" : "algorithm") + " is \"none\" and no " +
        (HAS_ALTS ? "pattern" : "idPattern") + " is set — nothing to validate. " +
        "Set the check algorithm your IDs were minted with, or (for a legacy project " +
        "without check characters) set " + (HAS_ALTS ? "pattern" : "idPattern") + " to your ID regex.";
      break;
    }
    A.source = A.source || "normalized_id";
    if(A.check && A.source !== "normalized_id" && A.source !== "digits_only" && A.source !== "sequence_only"){
      configError = 'Unknown source "' + A.source + '"' + (HAS_ALTS ? " on " + an : "") +
        " — use normalized_id, digits_only or sequence_only.";
      break;
    }
    A.scheme = A.check ? Q.makeScheme({
      /* explicit fallbacks: an absent key must not override makeScheme's
         defaults with undefined (Object.assign copies undefined values) */
      algorithm: A.algorithm, source: A.source,
      placement: "append", delimiter: "-",
      normalize_rules: { strip_delimiters: A.strip || "", uppercase: true,
                         unify_unicode_dashes: true, keep_only: null },
      enabled: true
    }) : null;
    A.nCheck = A.check ? Q.ALGORITHMS[A.algorithm].nCheckChars : 0;
  }
  var CHECK_MODE = false, REGEX_ONLY = false, MIXED = false;
  if(!configError){
    var nChk = 0;
    for(var si = 0; si < ALTS.length; si++) if(ALTS[si].check) nChk++;
    CHECK_MODE = nChk > 0;
    REGEX_ONLY = nChk === 0;
    MIXED      = nChk > 0 && nChk < ALTS.length;
  }
  /* regex target: trimmed + uppercased + unicode dashes unified, but separators
     KEPT — user patterns are written against the printed form (FC1-1001). */
  var REGEX_RULES = { strip_delimiters: "", uppercase: true,
                      unify_unicode_dashes: true, keep_only: null };

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
  /* Per-alternate atom chains. A hand-written alternation pattern kills guidance
     outright (tokenizePattern bails on "|"), but each alternate carries its OWN
     clean pattern, so a multi-format rule gets guidance back — narrowed to the
     one alternate still in play. */
  if(!configError){
    for(var ti = 0; ti < ALTS.length; ti++)
      ALTS[ti].atoms = ALTS[ti].re ? tokenizePattern(ALTS[ti].pattern) : null;
  }
  var ANY_ATOMS = false;
  for(var ti2 = 0; ti2 < ALTS.length; ti2++) if(ALTS[ti2].atoms) ANY_ATOMS = true;
  function atomPhrase(count, a){
    return (count > 1 ? count + " x " : "") + a.desc;
  }
  function remainingText(atoms, atomIdx, stillNeeded){
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
  function walkPattern(s, atoms){
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
  function suggestion(raw, A){
    /* Offered only for ONE candidate format: with two shapes matched, naming
       either one's check character is a coin flip presented as advice. */
    if(!cfg.suggestFix || !A || !A.check || A.source !== "normalized_id" || A.nCheck < 1) return "";
    try {
      var norm = Q.normalize(raw, A.scheme.normalize_rules);
      if(norm.length <= A.nCheck) return "";
      var expected = Q.ALGORITHMS[A.algorithm].compute(norm.slice(0, -A.nCheck));
      return ' If everything before the last ' + (A.nCheck > 1 ? A.nCheck + ' characters' : 'character') +
        ' is correct, the ID should end in <b style="font-family:monospace">' + QRID_escapeHtml(expected) + "</b>." +
        " Otherwise the typo is earlier in the ID.";
    } catch(e){ return ""; }
  }
  function nameOf(A, i){ return QRID_escapeHtml(A.label ? A.label : "format " + (i + 1)); }
  function verdict(v, isFinal){
    /* returns {ok: true|false|"info", html} for a non-empty value.
       Three passes, in the order that tells the fielder WHICH kind of mistake
       they made: 1) does any format accept it, 2) did a format's SHAPE match
       but its check character fail, 3) no shape matched — guide the typing.
       For a single-format rule each pass collapses to today's branch, so the
       wording is unchanged. */
    var normR = Q.normalize(v, REGEX_RULES).trim();
    var i, A, shapeFailed = [];

    /* ---- pass 1: acceptance, by shape ownership ----
       A value whose shape matches ANY check-bearing alternate belongs to that
       family and must pass its check character. Format-only alternates are
       consulted only when no check-bearing shape matched, so they can never
       accept a mis-scanned check-bearing ID, whatever the patterns overlap and
       whatever the declaration order. Twin of validateSingleField (php). */
    for(i = 0; i < ALTS.length; i++){
      A = ALTS[i];
      if(A.regexOnly) continue;
      if(A.re && !A.re.test(normR)) continue;
      if(Q.validateIdCheck(v, A.scheme)){
        if(!A.re) return { ok: true, html: "&#10003; ID verified &mdash; the check character matches." };
        return { ok: true, html: "&#10003; " + (MIXED ? nameOf(A, i) + ": format" : "Format") +
          " OK <b>and</b> check character verified." };
      }
      shapeFailed.push(A);           /* shape matched (or no shape to match), check did not */
    }
    for(i = 0; i < ALTS.length && !shapeFailed.length; i++){
      A = ALTS[i];
      if(!A.regexOnly || !A.re.test(normR)) continue;
      return { ok: true, html: "&#10003; " + (MIXED ? nameOf(A, i) + " format OK" : "ID format OK") +
        ". (" + (MIXED ? "IDs in this format carry" : "This project's IDs carry") + " no check " +
        "character, so typos that keep the format cannot be detected.)" };
    }

    /* A full-width digit or an invisible character left by a copy and paste
       reads as a check-character typo below; name it instead (LIVE-5). No
       format accepted the value, and every ID alphabet is printable ASCII. */
    if(/[^\x20-\x7E]/.test(String(v).trim())){
      return { ok: false, html: "&#10007; This value contains a character that is not allowed in an ID, such " +
        "as a full-width or accented letter or digit, or an invisible character that a copy and paste can " +
        "leave. Please re-type it." };
    }

    /* ---- pass 2: a shape matched but its check character failed ---- */
    if(shapeFailed.length){
      var only = shapeFailed.length === 1 ? shapeFailed[0] : null;
      if(only && !only.re){
        return { ok: false, html: "&#10007; This ID's check character does <b>not</b> match &mdash; " +
          "probably a typo or mis-scan. Please re-scan or re-type it." + suggestion(v, only) };
      }
      return { ok: false, html: "&#10007; The format is correct, but the CHECK character does " +
        "<b>not</b> match &mdash; one of the characters is mistyped. Please re-scan or re-type it." +
        suggestion(v, only) };
    }

    /* ---- pass 3: nothing matched — narrow the guidance to ONE format ---- */
    if(ANY_ATOMS){
      var live = [], mism = null;
      for(i = 0; i < ALTS.length; i++){
        if(!ALTS[i].atoms) continue;
        var w = walkPattern(normR, ALTS[i].atoms);
        if(w.state === "partial") live.push({ a: ALTS[i], i: i, w: w });
        else if(w.state === "mismatch" && !mism) mism = w;
      }
      if(live.length === 1){
        /* rem is built from the configured pattern (atom .desc, which may
           echo a raw [class] body) — escape before it reaches innerHTML. */
        var rem = QRID_escapeHtml(remainingText(live[0].a.atoms, live[0].w.atomIdx, live[0].w.needed));
        var who = MIXED || ALTS.length > 1 ? nameOf(live[0].a, live[0].i) + " &mdash; remaining: "
                                           : "remaining: ";
        if(!isFinal) return { ok: "info", html: "&#8230; format OK so far &mdash; " + who + "<b>" + rem + "</b>." };
        return { ok: false, html: "&#10007; FORMAT error &mdash; the ID is incomplete. Still " +
          (MIXED || ALTS.length > 1 ? "matching " + nameOf(live[0].a, live[0].i) + ", remaining: "
                                    : "remaining: ") + "<b>" + rem + "</b>." };
      }
      if(live.length > 1){
        /* Several formats are still possible, so no single remaining-characters
           hint is true yet. Name the candidates instead of guessing one. */
        var names = live.map(function(x){ return nameOf(x.a, x.i); }).join(", ");
        if(!isFinal) return { ok: "info",
          html: "&#8230; format OK so far &mdash; still matching: <b>" + names + "</b>." };
        return { ok: false, html: "&#10007; FORMAT error &mdash; the ID is incomplete. Still " +
          "matching: <b>" + names + "</b>." };
      }
      if(mism && ALTS.length === 1){
        return { ok: false, html: "&#10007; FORMAT error at character " + (mism.pos + 1) +
          ": expected " + QRID_escapeHtml(mism.expected) + ", got <b style=\"font-family:monospace\">" +
          QRID_escapeHtml(mism.got) + "</b>." +
          (CHECK_MODE ? " (The check character is only tested once the format is right.)" : "") };
      }
    }
    if(ALTS.length > 1){
      var all = ALTS.map(function(x, k){ return nameOf(x, k); }).join(", ");
      return { ok: false, html: "&#10007; FORMAT error &mdash; this matches none of this field's " +
        "accepted ID formats (<b>" + all + "</b>). Please re-scan or re-type it." };
    }
    return { ok: false, html: "&#10007; FORMAT error &mdash; this does <b>not</b> match this " +
      "project's ID format. Please re-scan or re-type it." };
  }

  var BLOCK = cfg.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  /* optional "when" condition: null when absent (no behavior change) */
  var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
  return { configError: configError, verdict: verdict, gate: GATE, blockSave: BLOCK,
           when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
           mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY, guidance: ANY_ATOMS,
                   mixed: MIXED, alternates: ALTS.length } };
  }
  /* ---- end makeVariant ---- */

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var IS_BRANCH = !!(QRID_CONFIG.branches && QRID_CONFIG.branches.length);
  var ANY_BLOCK = VS.firstBlock !== "off";

  function attach(fieldName){
    var input = QRID_findField(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound")) return true;  /* idempotent */
    if(input.setAttribute) input.setAttribute("data-qrid-bound", "1");
    var msg = QRID_attachMsgRegion(input, fieldName);
    function check(isFinal){
      /* which variant applies right now? (a legacy rule = one variant whose
         gate, if any, is its "when"; a branch rule = one per branch) */
      var act = QRID_activeVariants(VS);
      if(!act.length){
        /* no condition is true -> the field is inert: clear any verdict and
           never hold the save (the submit guard reads only __qridInvalid).
           Unless no branch could be CHOSEN at all, which is a rule problem and
           has to be said out loud (M-01). */
        input.__qridInvalid = false;
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "check")){ QRID_setModeState(input, "check", null); return; }
        msg.style.display = "none"; QRID_setModeState(input, "check", null);
        QRID_setModeState(input, "check", null);
        return;
      }
      if(act.length > 1){
        QRID_renderConflict(msg, input, act, "check");
        return;
      }
      var V = act[0];
      input.__qridBlockMode = V.blockSave;   /* the ACTIVE variant governs blocking */
      var v = (input.value || "").trim();
      if(!v || QRID_isMissingCode(v)){
        msg.style.display = "none"; QRID_setModeState(input, "check", null); input.__qridInvalid = false;
        QRID_setModeState(input, "check", null);
        return;
      }
      if(v.length > QRID_MAX_SINGLE_LEN){
        styleMsg(msg, false);
        msg.innerHTML = "&#10007; This value is too long for an ID field (over " +
          QRID_MAX_SINGLE_LEN + " characters) — validation skipped.";
        QRID_setModeState(input, "check", "bad"); input.__qridInvalid = true;
        QRID_setModeState(input, "check", "bad");
        return;
      }
      var r = V.verdict(v, !!isFinal);
      styleMsg(msg, r.ok);
      QRID_setModeState(input, "check", (r.ok === true) ? "ok"
                          : (r.ok === "info") ? "info" : "bad");
      input.__qridInvalid = (r.ok !== true);          /* "info" (still typing) also blocks a save */
      QRID_setModeState(input, "check", (r.ok === true) ? "ok" : ((r.ok === "info") ? "info" : "bad"));    /* "info" is not announced as an error yet */
      msg.innerHTML = r.html;
    }
    var debounced = QRID_debounced(function(){ check(false); });
    input.addEventListener("input", debounced);
    input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(true); });
    input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(true); });
    /* a condition flip (true<->false) re-runs the full check for this field */
    for(var gi = 0; gi < VS.variants.length; gi++){
      if(VS.variants[gi].gate) VS.variants[gi].gate.onChange(function(){ check(true); });
    }
    /* register BEFORE the initial check: check() stamps the ACTIVE variant's
       blockSave on the input, and registration must never clobber it */
    if(ANY_BLOCK && !configError){
      /* re-judged at save: a value or a "when" controller changed without an
         event (a calc, a script) must not leave a stale verdict (VAL-6) */
      input.__qridRecheck = function(){ if(debounced.cancel) debounced.cancel(); check(true); };
      QRID_registerBlocker(input, fieldName, VS.firstBlock);
    }
    check(true);
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    if(IS_BRANCH){
      UV_validators[f] = { type: "single", branch: true,
        branches: VS.all.map(function(V){ return { when: V.when, mode: V.mode,
          test: function(v, isFinal){ return QRID_isMissingCode(v) ? null : V.verdict(v, isFinal); } }; }),
        active: function(){ return QRID_activeVariants(VS); } };
      return;
    }
    var V0 = VS.all[0];
    UV_validators[f] = { type: "single",
      mode: { check: V0.mode.check, regexOnly: V0.mode.regexOnly, configError: configError,
              guidance: V0.mode.guidance, blockSave: V0.blockSave },
      test: function(v, isFinal){ return (configError || QRID_isMissingCode(v)) ? null : V0.verdict(v, isFinal !== false); } };
  });
  function boot(){
    if(configError){
      /* Put the error on the affected field(s) when they are on this page —
         that is where the designer will look (UX-001); fields not present
         fall back to ONE page-level notice (muted on surveys). Nothing to
         validate either way, so no retry/observer is armed. */
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }   /* prevent observer leak */
    function sweep(){
      pending = pending.filter(function(f){ return !attach(f); });
      if(pending.length === 0){ stop(); return true; }
      return false;
    }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){                 /* REDCap builds the form progressively */
      tries++;
      if(sweep() || tries >= 20){ clearInterval(timer); stop(); }  /* give up -> disconnect */
    }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });   /* late-rendered fields */
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- constraint validator (@UVASSERT) ------------------------------------
   The field is invalid unless its "assert" condition holds — the shared
   when-engine used as a TEST here, not just an applicability gate. Reuses the
   branch plumbing (QRID_buildVariants/activeVariants), the a11y status region
   and the save guard. An EMPTY field is inert (emptiness is @UVREQUIRED's job,
   not a constraint's). The verdict wording is the designer's own "message"
   (only they can word an arbitrary relationship), with a generic fallback.
   tests/constraint_dom_js.cjs locks this DOM contract; php/AnnotationRules.php
   (parser) and UniversalValidator::auditRule (server audit) are the twins. */
function QRIDConstraintInit(QRID_CONFIG){
  function styleMsg(el, ok){
    var c = ok ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
               : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    /* the assert is the TEST: active()===true means the value is VALID. A rule
       that reaches the client has been syntax-validated by every config
       channel, so gateFor returns a real gate; null only when there is no
       condition at all, which is a configuration error for a constraint. */
    /* The ONE non-gate use of gateFor: this is the constraint's TEST, where
       true means VALID. An operand that has not been entered cannot violate a
       constraint, so an unanswerable ordered comparison passes rather than
       inventing a violation (CRIT-01) — the opposite of a "when" gate four
       lines below, which stays on the inert default. */
    /* Text matches case-insensitively ('Yes' = 'yes') unless the rule sets
       "caseSensitive": true — strictly true, as the server stores it. The same
       flag governs this rule's "when" gate below. "exact": true makes = and <>
       of the test compare character for character (AST-2); the gate keeps the
       caseSensitive flag. */
    var assertGate = configError ? null : QRID_WHEN.gateFor(cfg.assert, cfg.assertAst, QRID_BLANK_PASSES,
                                                            cfg.exact === true ? QRID_WHEN_EXACT : cfg.caseSensitive === true);
    if(!configError && !assertGate){
      configError = "@UVASSERT has no condition to check — set an \"assert\" such as [end_date]>=[start_date].";
    }
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    /* DEFERRED (server set it): this assert compares against an off-page field
       the viewer may not read, so the condition had to be frozen at page-load
       truth. A frozen verdict is wrong in BOTH directions once the user types —
       it would flag a correct value and pass a wrong one — so the browser states
       no verdict at all and never blocks. Where the reference itself could not be
       RESOLVED nothing re-checks it later either — the audit reports the rule as
       unconfigurable and returns — which is why the notice says so plainly rather
       than promising a post-save check. See php/Logic.php fold(). */
    var DEFERRED = !configError && !!cfg.deferred;
    if(DEFERRED) BLOCK = "off";
    /* ADVISORY-ONLY CROSS-FORM (policy decision, 1.6.0).
       An off-page operand is resolved ONCE, when the page is built. Nothing can
       refresh it while the page is open, so a concurrent edit on the other form
       makes this verdict stale — and a stale verdict that PASSES is silent, while
       a stale one that FAILS was a dead end. redcap_save_record runs after the
       write and cannot recover either case.
       A snapshot therefore never drives a save block. Cross-form rules give live
       feedback as you type; the post-save audit and the Validation scan RECORD
       violations after the fact, which is a detection record and not enforcement.
       The module does not claim a guarantee a page-load snapshot cannot support.
       This covers the applicability GATE as well as the assert: a stale "when"
       switches a rule on or off, which is as wrong as a stale verdict.
       Same-instrument rules are unaffected — both sides are live in the DOM, so
       nothing there is a snapshot. */
    var SNAPSHOT_ADVISORY = !configError && cfg.snapshotFields && cfg.snapshotFields.length;
    if(SNAPSHOT_ADVISORY) BLOCK = "off";
    /* Off-page operands are resolved once, when the page is built. Naming them
       on a failure lets the user tell a real violation from a stale snapshot
       (someone edited the other form in another tab) and reload — without it a
       wrong hard block is a dead end with no explanation (M-02). */
    var SNAPSHOT = (!configError && cfg.snapshotFields && cfg.snapshotFields.length)
                   ? cfg.snapshotFields : null;
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);   /* applicability */
    return { configError: configError, assertGate: assertGate, gate: GATE, blockSave: BLOCK,
             deferred: DEFERRED, snapshot: SNAPSHOT,
             deferredWhy: (cfg.deferredWhy && cfg.deferredWhy.length) ? cfg.deferredWhy : null,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             mode: { constraint: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = VS.firstBlock !== "off";

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-c")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-c", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "c");
    /* Own guard item — a WRAPPER, not the input element. A check rule (or a
       later required/unique rule) may sit on the SAME input; each mode keeps an
       INDEPENDENT invalid/block state so they compose instead of stomping one
       shared flag. Readonly/disabled fields keep their message but never trap
       the save (the anchor's state is copied onto the wrapper). */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} },
                /* re-judged at save: a referenced value changed without an
                   event (a calc, a radio reset) must not leave a stale
                   verdict (AST-7) */
                __qridRecheck: function(){ check(); } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function inert(){ msg.style.display = "none"; QRID_setModeState(input, "c", null); setGuard(false); }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){
        /* no applicability gate true -> inert, unless no branch could be CHOSEN
           at all, which is a rule problem and has to be said out loud (M-01). */
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "c")){ setGuard(false); QRID_setModeState(input, "c", null); return; }
        inert(); return;
      }
      if(act.length > 1){                               /* branch conflict: show, validate nothing, never block */
        styleMsg(msg, false);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The value was <b>NOT</b> validated; make the conditions mutually exclusive.';
        QRID_setModeState(input, "c", null); setGuard(false); return;
      }
      var V = act[0];
      /* Frozen assert: state no VERDICT rather than a stale one (see
         makeVariant). But say WHY, on staff forms: the server builds a
         human-readable reason ("… is on a different repeating instrument",
         "… is not collected in this event") and silently discarding it is the
         same M-05 silent failure the reason exists to prevent — the rule stops
         checking and the only trace is a module-log entry the person typing the
         value never sees. Survey respondents still get nothing: the reasons name
         other instruments and fields they must not learn about, and they could
         not act on a design problem anyway. Never blocks either way. */
      if(V.deferred){
        if(V.deferredWhy && !QRID_IS_SURVEY){
          /* Wording matters and was wrong once already. A rule deferred for an
             UNRESOLVED reference is NOT re-checked after the save: the audit
             emits an "unconfigurable" note and returns, and an unreadable read
             aborts the audit outright. Promising a later check would be the same
             overstatement as calling the deferred path "enforcement". Say what is
             true: this rule is not checking anything, anywhere, until the study
             team fixes the configuration. */
          QRID_renderDeferralNotice(msg, input, V.deferredWhy, "c");
          setGuard(false); QRID_setModeState(input, "c", null);
          return;
        }
        inert(); return;
      }
      var val = QRID_WHEN.readRef(fieldName, null);
      /* Empty field is inert. Blankness MUST use the same charlist the two
         evaluators trim with before comparing (QRID_whenTrim / Logic::compare),
         not JS String.trim: String.trim also strips Unicode spaces such as
         U+00A0, so a host value of " " went inert in the browser while the
         server compared it untrimmed and logged a violation (M-04). */
      if(val === null || QRID_whenTrim(val) === "" || QRID_isMissingCode(val)){ inert(); return; }
      var ok = true;
      try { ok = V.assertGate.active(); } catch(e){ ok = true; }
      if(V.assertGate.unresolved && V.assertGate.unresolved()){
        inert();
        if(!QRID_IS_SURVEY) QRID_renderDeferralNotice(msg, input, [QRID_DYNAMIC_DEFERRAL], "c", true);
        return;
      }          /* fail open: a gate bug never traps a save */
      styleMsg(msg, ok);
      QRID_setModeState(input, "c", ok ? "ok" : "bad");
      setGuard(!ok, V.blockSave);
      QRID_setModeState(input, "c", ok ? "ok" : "bad");
      var base = V.message ? QRID_ruleHtml(V, "message")
            : (QRID_IS_SURVEY ? "This entry is not valid together with the other answers."
                              : "This value fails its validation rule for this field.");
      /* Text in a number field fails a comparison such as [dose]<=100, and the
         rule's own message ("above the maximum") then names the wrong cause
         (LIVE-5). */
      if(!ok && Object.prototype.hasOwnProperty.call(QRID_NUMBER_MARKS, fieldName)
         && QRID_rangeNumber(val, QRID_NUMBER_MARKS[fieldName] === "comma") === null){
        base = "This value is not a number.";
      }
      /* Survey respondents get no field names or technical detail, as elsewhere. */
      if(!ok && V.snapshot && !QRID_IS_SURVEY){
        base += ' <span style="opacity:.8">(compared against ' +
          QRID_escapeHtml(V.snapshot.join(", ")) +
          ", read when this page was opened — reload if it has changed since." +
          " This check does not block saving; it is re-checked after the save.)</span>";
      }
      msg.innerHTML = ok ? "&#10003; OK." : "&#10007; " + base;
    }
    var debounced = QRID_debounced(function(){ check(); });
    if(input.addEventListener){
      input.addEventListener("input", debounced);
      input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(); });
      input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(); });
    }
    /* re-check when ANY referenced field (of an applicability gate or an assert)
       changes — this covers the validated field itself and every other ref */
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(function(){ check(); });
      if(VS.all[gi].assertGate) VS.all[gi].assertGate.onChange(function(){ check(); });
    }
    check();
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "constraint", mode: { constraint: true, configError: configError },
      test: function(){ var a = QRID_activeVariants(VS); if(a.length !== 1 || QRID_isMissingCode(QRID_WHEN.readRef(f, null))) return null; return a[0].assertGate.active(); } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- window validator (@UVWINDOW) -----------------------------------------
   A date must fall inside a window around another date ("from": a field,
   "today", "now" or a written date) or inside a calendar period ("period"),
   and/or not after today ("notFuture") or before it ("notPast"). The verdict
   is QRID_windowVerdict, the twin of
   TemporalLogic::windowVerdict (tests/window_fixture.json drives both), and
   UniversalValidator::findingsWindow is the server side of this factory.
   The "from" date arrives FOLDED (UniversalValidator::foldRuleConditions):
   ["ref", field, null] when it is on this page and read as typed, or
   ["lit", Y-M-D] when it is another form's saved value this user may read —
   a snapshot, so the rule never blocks. Anything else arrives deferred.
   "Today" is the SERVER's (config.clock, advanced by QRID_clockNow), so a
   computer whose clock is a day ahead cannot accept tomorrow's date. A date
   and time may run up to QRID_clockLenient's margin past the server's "now",
   so the Now button on a computer whose clock is a little fast still passes.
   A value that is not yet a whole date (being typed, or 31-02) shows no
   verdict: REDCap's own date check speaks to that.
   The parts judged against today (a "from" of today or now, notPast) judge a
   new or changed value only (config.windowSaved holds the saved values): a
   saved value is judged against the day it was saved, after saving
   (UniversalValidator::findingsWindow), so opening an old record never flags
   it. notFuture still flags an unchanged value after today, as it was after
   every earlier day too, but a pass of an unchanged value shows no green OK:
   the value may have been in the future on the day it was saved, which the
   audit judges (WIN-2). tests/window_dom_js.cjs locks this factory. */
function QRIDWindowInit(QRID_CONFIG){
  function styleMsg(el, ok){
    var c = ok ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
               : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }
  function bound(v){ return (typeof v === "number" && isFinite(v) && Math.floor(v) === v) ? v : null; }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var lo = bound(cfg.windowLo), hi = bound(cfg.windowHi);
    /* A calendar period (week, month, quarter, year) holding the "from" date,
       moved by offLo..offHi periods. */
    var period = null;
    if(cfg.windowPeriod != null){
      if(["week", "month", "quarter", "year"].indexOf(cfg.windowPeriod) >= 0) period = cfg.windowPeriod;
      else if(!configError) configError = '"period" must be week, month, quarter or year.';
    }
    var offLo = cfg.windowOffLo == null ? 0 : bound(cfg.windowOffLo);
    var offHi = cfg.windowOffHi == null ? 0 : bound(cfg.windowOffHi);
    if(!configError && (offLo === null || offHi === null || offLo > offHi)){
      configError = '"offset" must be a whole number of periods, or [first, last].';
    }
    var hasWindow = lo !== null || hi !== null || period !== null;
    var notFuture = cfg.windowNotFuture === true, notPast = cfg.windowNotPast === true;
    /* "from" as "today", "now" or a written date (windowAnchor), not a field. */
    var anchorKw = (typeof cfg.windowAnchor === "string" && cfg.windowAnchor !== "") ? cfg.windowAnchor : null;
    var anchorType = anchorKw === null ? null : QRID_keywordAnchorType(anchorKw);
    if(!configError && anchorKw !== null && anchorType === null) configError = '@UVWINDOW cannot read its "from" date.';
    var clockAnchor = anchorKw === "today" || anchorKw === "now";
    /* A deferred stub (the page could not be given this rule) carries no bounds
       or date type: it reports its deferral, not a configuration error. */
    if(!configError && !cfg.deferred && !hasWindow && !notFuture && !notPast){
      configError = '@UVWINDOW has nothing to check — set a "from" date with a "window" or a "period", "notFuture": true or "notPast": true.';
    }
    if(!configError && !cfg.deferred && (cfg.dateType !== "date" && cfg.dateType !== "datetime" && cfg.dateType !== "datetime_seconds")){
      configError = "@UVWINDOW needs a date field — this field's date type is unknown.";
    }
    /* The anchor. A rule the server built always carries windowFromOp unless it
       is deferred or counts from today, now or a written date (["anchor", it]);
       the plain-text fallback serves hand-written configs and the test
       harnesses, and reads only a plain [field] of this page. */
    var fromOp = null;
    if(!configError && hasWindow){
      var op = cfg.windowFromOp;
      if(anchorKw !== null) fromOp = ["anchor", anchorKw];
      /* "withheld": the server sent nothing of a "from" date this viewer may not read. */
      else if(op && op.length && (op[0] === "ref" || op[0] === "lit" || op[0] === "withheld")) fromOp = op;
      else if(typeof cfg.windowFrom === "string" && cfg.windowFrom !== ""){
        var p = QRID_whenParse(cfg.windowFrom + "=''");
        if(p.ok && p.ast[0] === "cmp" && p.ast[2][0] === "ref" && p.ast[2][2] === null) fromOp = ["ref", p.ast[2][1], null];
      }
      if(!fromOp && !cfg.deferred) configError = '@UVWINDOW cannot read its "from" date.';
    }
    var DEFERRED = !configError && !!cfg.deferred;
    /* A "from" date read when the page was built can go stale. QRID_buildVariants
       turns blockSave off for it, and for a deferred rule, so neither blocks. */
    var SNAPSHOT = (!configError && cfg.snapshotFields && cfg.snapshotFields.length) ? cfg.snapshotFields : null;
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);   /* applicability */
    /* A "from" field on this page re-checks the window when it changes. */
    var FROM_WATCH = (fromOp && fromOp[0] === "ref") ? QRID_WHEN.gateFor(null, ["cmp", "=", fromOp, ["lit", ""]]) : null;
    return { configError: configError, gate: GATE, fromWatch: FROM_WATCH, blockSave: BLOCK,
             /* "future" and "past" never depend on the "from" date, so a snapshot
                or withheld anchor (which turns blockSave off) does not soften
                them. A condition read when the page was built (snapshotGate)
                does: whether the rule applies may have changed since. */
             futureBlock: cfg.snapshotGate === true ? "off" : BLOCK,
             snapshotGate: cfg.snapshotGate === true,
             notFutureOff: (cfg.windowNotFutureOff && cfg.windowNotFutureOff.length) ? cfg.windowNotFutureOff : null,
             windowWithheld: !!(fromOp && fromOp[0] === "withheld"),
             /* why a withheld "from" date was withheld when it was not for rights:
                it could not be resolved (the server's windowFromOpWhy) */
             withheldWhy: (cfg.windowFromOpWhy && cfg.windowFromOpWhy.length) ? cfg.windowFromOpWhy : null,
             deferred: DEFERRED, snapshot: SNAPSHOT,
             deferredWhy: (cfg.deferredWhy && cfg.deferredWhy.length) ? cfg.deferredWhy : null,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             spec: { lo: lo, hi: hi, unit: (typeof cfg.windowUnit === "string" && cfg.windowUnit !== "") ? cfg.windowUnit : "days",
                     period: period, offLo: offLo, offHi: offHi, weekStart: cfg.windowWeekStart === "sunday" ? "sunday" : "monday",
                     notFuture: notFuture, notPast: notPast, type: cfg.dateType,
                     fromType: anchorKw !== null ? anchorType : (cfg.fromType == null ? null : cfg.fromType) },
             fromOp: fromOp,
             anchorKw: anchorKw, clockAnchor: clockAnchor,
             /* the parts judged against today: a "from" of today or now, notPast, notFuture */
             todayRelative: clockAnchor || notPast || notFuture,
             fromFormat: (fromOp && fromOp[0] === "ref") ? (cfg.fromFormat || "ymd") : "ymd",
             fromName: anchorKw === null ? ((typeof cfg.windowFrom === "string") ? cfg.windowFrom : "")
                     : clockAnchor ? anchorKw : QRID_temporalFormat(anchorKw, anchorType, cfg.dateFormat || "ymd"),
             dateFormat: cfg.dateFormat || "ymd",
             mode: { window: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  /* The block for a date judged against the clock alone (notFuture, notPast). */
  var FUTURE_BLOCK = "off";
  for(var fbi = 0; fbi < VS.all.length; fbi++){
    var fv = VS.all[fbi];
    if(!fv.configError && !fv.deferred && (fv.spec.notFuture || fv.spec.notPast) && fv.futureBlock !== "off"){ FUTURE_BLOCK = fv.futureBlock; break; }
  }
  var ANY_BLOCK = VS.firstBlock !== "off" || FUTURE_BLOCK !== "off";

  /* Whether the field's value is new or changed since the page was built.
     config.windowSaved holds the saved value of each field a today-relative
     part judges; only a new or changed value is judged in full against today,
     as a saved one was judged against the day it was saved, after saving. A field
     the server could not read (null) counts as unchanged, so nothing is
     judged against today there. Without windowSaved (a record not saved yet,
     a hand-written config) every value is new. */
  function isNewValue(V, fieldName, val){
    var saved = QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.windowSaved;
    if(!saved || typeof saved !== "object" || !Object.prototype.hasOwnProperty.call(saved, fieldName)) return true;
    var s = saved[fieldName];
    if(typeof s !== "string") return false;
    var cur = typeof val === "string" ? QRID_whenTrim(val) : "";
    var a = QRID_temporalDate(cur, V.spec.type, V.dateFormat), b = QRID_temporalDate(QRID_whenTrim(s), V.spec.type, "ymd");
    if(a && b) return a.value !== b.value;
    return cur !== QRID_whenTrim(s);
  }
  /* One variant's verdict for the field's current value. A "from" that names the
     field itself (the extended path folds [baseline_arm_1][visit_date] to a live
     ref on the baseline visit) is not an anchor: no window applies there. A
     "from" of "now" is the span QRID_clockLenient gives, so the window's early
     end counts from pastNow and its late end from futureNow. A period counted
     from "now" takes the plain now: a period is whole calendar days, and a
     margin would let it reach into the period before or after. r.skipped marks
     a value whose today-relative parts were left out (an unchanged value):
     notPast and a window counted from today or now are dropped, notFuture is
     kept (a value after today was after its save day too), and a pass shows
     no green OK. */
  function verdictOf(V, fieldName){
    var val = QRID_WHEN.readRef(fieldName, null);
    var spec = V.spec, op = V.fromOp, anchor = null, anchorHi, skipped = false;
    if(V.todayRelative && !isNewValue(V, fieldName, val)){
      spec = {};
      for(var k in V.spec) if(Object.prototype.hasOwnProperty.call(V.spec, k)) spec[k] = V.spec[k];
      spec.notPast = false;
      if(V.clockAnchor){ spec.lo = null; spec.hi = null; spec.period = null; }
      skipped = true;
    }
    var windowed = spec.lo !== null || spec.hi !== null || spec.period !== null;
    var clock = (spec.notFuture || spec.notPast || (V.clockAnchor && windowed))
      ? QRID_clockNow(QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.clock) : null;
    var judge = (clock && V.spec.type !== "date")
      ? QRID_clockLenient(clock, QRID_deviceClockError(QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.clock)) : clock;
    if(op && op[0] === "ref") anchor = op[1] === fieldName ? "" : QRID_WHEN.readRef(op[1], null);
    else if(op && op[0] === "lit") anchor = String(op[1]);
    /* A "from" date marked with a Missing Data Code was not entered. */
    if(typeof anchor === "string" && QRID_isMissingCode(anchor)) anchor = "";
    anchorHi = anchor;
    if(op && op[0] === "anchor" && windowed){
      if(op[1] === "today"){ anchor = judge ? judge.today : false; anchorHi = anchor; }
      else if(op[1] === "now" && spec.period !== null){ anchor = judge ? judge.now : false; anchorHi = anchor; }
      else if(op[1] === "now"){
        anchor = judge ? (judge.pastNow != null ? judge.pastNow : judge.now) : false;
        anchorHi = judge ? (judge.futureNow != null ? judge.futureNow : judge.now) : false;
      }
      else { anchor = op[1]; anchorHi = anchor; }
    }
    var r = QRID_windowVerdictSpread(spec, val, V.dateFormat, anchor, anchorHi, V.fromFormat, judge);
    r.clock = clock;
    r.anchor = anchor;
    r.skipped = skipped;
    return r;
  }
  /* A period by name: "last month", "the month of [visit_date]". */
  function periodLabel(V){
    return QRID_windowPeriodLabel(V.spec.period, V.spec.offLo, V.spec.offHi, V.fromName, V.clockAnchor);
  }
  /* The default wording names the allowed dates the way the field shows them.
     Survey respondents are not told which field the window counts from; a
     window counted from today, now or a written date names it. */
  function describe(V, r){
    var t = V.spec.type, f = V.dateFormat;
    if(r.verdict === "future"){
      if(t !== "date") return "This date and time is in the future.";
      return "This date is after today" + (r.clock ? " (" + QRID_escapeHtml(QRID_temporalFormat(r.clock.today, "date", f)) + ")" : "") + ".";
    }
    if(r.verdict === "past"){
      if(t !== "date") return "This date and time is in the past.";
      return "This date is before today" + (r.clock ? " (" + QRID_escapeHtml(QRID_temporalFormat(r.clock.today, "date", f)) + ")" : "") + ".";
    }
    var named = V.anchorKw !== null || !QRID_IS_SURVEY;
    if(V.spec.period !== null && r.earliest !== null && r.latest !== null){
      /* a period is whole days: its dates are shown without a time */
      var pe = QRID_escapeHtml(QRID_temporalFormat(r.earliest.slice(0, 10), "date", f));
      var pl = QRID_escapeHtml(QRID_temporalFormat(r.latest.slice(0, 10), "date", f));
      return named ? "This date must be in " + QRID_escapeHtml(periodLabel(V)) + ": " + pe + " to " + pl + "."
                   : "This date must be between " + pe + " and " + pl + ".";
    }
    var e = r.earliest !== null ? QRID_escapeHtml(QRID_temporalFormat(r.earliest, t, f)) : null;
    var l = r.latest !== null ? QRID_escapeHtml(QRID_temporalFormat(r.latest, t, f)) : null;
    var text = (e && l) ? "This date must be between " + e + " and " + l + "."
             : e ? "This date must be on or after " + e + "."
             : l ? "This date must be on or before " + l + "."
             : "This date is outside its allowed window.";
    if(named && V.fromName && V.spec.period === null){
      text += ' <span style="opacity:.8">(' + QRID_escapeHtml(QRID_windowSpanLabel(V.spec.lo, V.spec.hi, V.spec.unit, V.fromName)) + ")</span>";
    }
    return text;
  }

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-w")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-w", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "w");
    /* Own guard item (see QRIDConstraintInit). __qridRecheck re-judges at save
       time, so a page left open past midnight is checked against the day it
       is saved on, not the day the value was typed. */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} },
                __qridRecheck: function(){ check(); } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock !== "off" ? VS.firstBlock : FUTURE_BLOCK);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function inert(){ msg.style.display = "none"; setGuard(false); QRID_setModeState(input, "w", null); }
    /* The window part was not sent to this page. Staff are told it is checked
       on save; a survey respondent is told nothing. */
    function withheld(V, r){
      if(QRID_IS_SURVEY){ inert(); return; }
      if(V.withheldWhy){
        /* The "from" date cannot be resolved: the window part is checked
           nowhere, but a date after today is still judged, and a date before
           today too when the value is new (an unchanged one skips notPast). */
        var np = V.spec.notPast && !r.skipped;
        msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
          "font-size:13px;font-family:inherit;border:1px solid #d9c48a;background:#fdf8e6;color:#7a5c00";
        msg.innerHTML = "&#9888; The window counted from " + QRID_escapeHtml(V.fromName || "another date") +
          " is not checked on this page — " + QRID_escapeHtml(V.withheldWhy.join(" ")) +
          (V.spec.notFuture && np ? " A date other than today is still flagged."
           : V.spec.notFuture ? " A date after today is still flagged."
           : np ? " A date before today is still flagged." : "");
        setGuard(false); QRID_setModeState(input, "w", null);
        return;
      }
      msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
        "font-size:13px;font-family:inherit;border:1px solid #d9c48a;background:#fdf8e6;color:#7a5c00";
      msg.innerHTML = "&#9888; The window counted from " + QRID_escapeHtml(V.fromName || "another date") +
        " is not checked on this page: that date is on a form you cannot view here." +
        " It is checked when the record is saved.";
      setGuard(false); QRID_setModeState(input, "w", null);
    }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "w")){ setGuard(false); QRID_setModeState(input, "w", null); return; }
        inert(); return;
      }
      if(act.length > 1){ QRID_renderConflict(msg, input, act, "w"); setGuard(false); return; }
      var V = act[0];
      if(V.deferred){
        if(V.deferredWhy && !QRID_IS_SURVEY){
          QRID_renderDeferralNotice(msg, input, V.deferredWhy, "w");
          setGuard(false); QRID_setModeState(input, "w", null);
          return;
        }
        inert(); return;
      }
      if(QRID_isMissingCode(QRID_WHEN.readRef(fieldName, null))){ inert(); return; }
      var r;
      try { r = verdictOf(V, fieldName); } catch(e){ inert(); return; }   /* fail open: a bug never traps a save */
      /* judged against the clock alone: never depends on the "from" date */
      var clockHit = r.verdict === "future" || r.verdict === "past";
      if(r.verdict === "unknown" && !QRID_IS_SURVEY && V.fromOp && V.fromOp[0] === "lit"
         && typeof r.anchor === "string" && QRID_whenTrim(r.anchor) !== ""
         && QRID_temporalDate(QRID_whenTrim(String(QRID_WHEN.readRef(fieldName, null))), V.spec.type, V.dateFormat)){
        /* The value reads, but the saved "from" date does not (it came in some
           way that skipped REDCap's own check): staff are told. */
        msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
          "font-size:13px;font-family:inherit;border:1px solid #d9c48a;background:#fdf8e6;color:#7a5c00";
        msg.innerHTML = "&#9888; The window counted from " + QRID_escapeHtml(V.fromName || "another date") +
          " is not checked: its saved value is not a date this rule can read.";
        setGuard(false); QRID_setModeState(input, "w", null);
        return;
      }
      if(r.verdict === "unknown" || (r.verdict === "inert" && !V.windowWithheld)){ inert(); return; }
      if(!clockHit && V.windowWithheld){
        /* inert here can only mean "nothing else to check": the value is typed */
        if(QRID_whenTrim(String(QRID_WHEN.readRef(fieldName, null))) === ""){ inert(); return; }
        withheld(V, r); return;
      }
      if(r.verdict !== "ok" && !clockHit && r.verdict !== "window-early" && r.verdict !== "window-late"){ inert(); return; }
      var ok = r.verdict === "ok";
      /* An unchanged value had its today-relative parts left out: a green OK
         would claim a check that did not run, so a pass shows nothing. */
      if(ok && r.skipped){ inert(); return; }
      styleMsg(msg, ok);
      QRID_setModeState(input, "w", ok ? "ok" : "bad");
      setGuard(!ok, clockHit ? V.futureBlock : V.blockSave);
      var base = V.message ? QRID_ruleHtml(V, "message") : describe(V, r);
      /* Why a verdict that rests on a value read when the page was opened does
         not block: named by what read it, the "from" date or the condition
         that decides whether the rule applies (snapshotGate). */
      if(!ok && V.snapshot && !QRID_IS_SURVEY && (!clockHit || V.snapshotGate)){
        base += ' <span style="opacity:.8">(' + (V.snapshotGate ? "based on " : "counted from ") +
          QRID_escapeHtml(V.snapshot.join(", ")) +
          ", read when this page was opened — reload if it has changed since." +
          " This check does not block saving; it is re-checked after the save.)</span>";
      }
      var note = (V.notFutureOff && !QRID_IS_SURVEY)
        ? ' <span style="opacity:.8">(' + QRID_escapeHtml(V.notFutureOff.join(" ")) + " Only the window is checked.)</span>" : "";
      msg.innerHTML = (ok ? "&#10003; OK." : "&#10007; " + base) + note;
    }
    var debounced = QRID_debounced(function(){ check(); });
    if(input.addEventListener){
      input.addEventListener("input", debounced);
      input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(); });
      input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(); });
    }
    /* A jQuery-only change (REDCap's date picker, its Today and Now buttons, a
       script) reaches the when-registry, not these native listeners. */
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(function(){ check(); });
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(function(){ check(); });
      if(VS.all[gi].fromWatch) VS.all[gi].fromWatch.onChange(function(){ check(); });
    }
    check();
    return true;
  }
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "window", mode: { window: true, configError: configError },
      test: function(){
        var a = QRID_activeVariants(VS);
        if(a.length !== 1 || a[0].deferred) return null;
        if(QRID_isMissingCode(QRID_WHEN.readRef(f, null))) return null;
        var r = verdictOf(a[0], f), v = r.verdict;
        if(v === "ok" && (a[0].windowWithheld || r.skipped)) return null;   /* a part was never checked */
        return v === "ok" ? true : (v === "future" || v === "past" || v === "window-early" || v === "window-late") ? false : null;
      } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- @UVRANGE "show": the growth chart and the page table -----------------
   Page only: nothing here is saved, and nothing here changes a verdict. The
   server sends the reference curves (UniversalValidator::attachGrowthCurves,
   built by GrowthCurve::build) and the rows of a table of reference values;
   the browser computes only this visit's point, with the functions the
   verdict uses, and moves only the marker when the value changes. Every
   element is made with createElement(NS) and filled with textContent, never
   innerHTML, and styled with SVG presentation attributes or the style object,
   never a style attribute, so a strict style-src policy keeps it working.
   tests/growth_chart_dom_js.cjs locks it. */
var QRID_SVG_NS = "http://www.w3.org/2000/svg";
/* The chart's geometry, in the SVG's own units. plotWidth, plotHeight,
   maxZoom and yFloor are the twins of GrowthCurve PLOT_WIDTH, PLOT_HEIGHT,
   MAX_ZOOM and Y_FLOOR, which bound the error of the curves the server
   simplifies (tests/growth_curve_php.php compares them). width and
   plotWidth are the widest: a chart fits the field's cell, from minWidth to
   width units wide, drawn one unit to a pixel so its text keeps its size;
   the error bound is vertical and does not depend on the width. */
var QRID_CHART = { width: 640, minWidth: 320, height: 380, left: 56, right: 72, top: 20, plotWidth: 512, plotHeight: 312,
                   maxZoom: 8, yFloor: 0.1 };
/* Run after QRID_relabelTexts swaps the marked texts: a text made of several
   labels and numbers (a chart's description) is written again. */
var UV_relabelHooks = [];
/* A tick step for range spread over about count steps: 1, 2 or 5 times a
   power of ten. The ratio is compared with a margin, so a range that is a
   whole number of steps (0.3 over 6) is not pushed to the next step by the
   error of a division. */
function QRID_chartStep(range, count){
  if(!(range > 0) || !isFinite(range)) return 1;
  var raw = range / count, mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10 + 1e-9)), f = raw / mag;
  return (f <= 1 + 1e-9 ? 1 : f <= 2 + 1e-9 ? 2 : f <= 5 + 1e-9 ? 5 : 10) * mag;
}
/* The ticks from lo to hi at step: whole multiples of step, as { v, text },
   with the decimals the step has and decimal mark comma. */
function QRID_chartTicks(lo, hi, step, comma){
  var places = Math.max(0, -Math.floor(Math.log(step) / Math.LN10 + 1e-9));
  var out = [], i0 = Math.ceil(lo / step - 1e-9), i1 = Math.floor(hi / step + 1e-9);
  for(var i = i0; i <= i1 && out.length < 60; i++){
    var t = (i * step).toFixed(places);
    if(/^-[0.]+$/.test(t)) t = t.slice(1);
    out.push({ v: i * step, text: QRID_growthMarkText(t, comma) });
  }
  return out;
}
/* Days in each unit an age axis may count in. */
var QRID_CHART_DAYS = { days: 1, weeks: 7, months: QRID_GROWTH_DAYS_PER_MONTH, years: QRID_GROWTH_DAYS_PER_MONTH * 12 };
/* How the x axis counts: a tick at t stands for axis value t * div, in the
   unit name ("months"; null for a length or height, counted in its own
   unit, word). An age counts in the reference's "chartUnit" when it names
   one, else in months while the window is under two years wide and in years
   above. Twin of GrowthReference::CHART_UNITS. */
function QRID_chartXUnit(ref, lo, hi){
  if(ref.axis !== "age") return { div: 1, word: ref.axisUnit, name: null };
  var perDay = ref.axisUnit === "days" ? 1 : 1 / QRID_GROWTH_DAYS_PER_MONTH;
  var name = (typeof ref.chartUnit === "string" && Object.prototype.hasOwnProperty.call(QRID_CHART_DAYS, ref.chartUnit))
    ? ref.chartUnit : ((hi - lo) / perDay / QRID_GROWTH_DAYS_PER_MONTH < 24 ? "months" : "years");
  return { div: QRID_CHART_DAYS[name] * perDay, word: name, name: name };
}
/* The axis value the reference reads for x: the row at or nearest x for a
   "floor" or "round" lookup (where its curves are exact), x itself for
   "linear". This visit's point is drawn there, on the step the check reads.
   Twin of QRID_growthLms's choice of row. */
function QRID_growthReadX(ref, x){
  var s = ref.scale, k = x * s, f = Math.floor(k);
  if(ref.lookup === "round") return (k - f >= 0.5 ? f + 1 : f) / s;
  if(ref.lookup === "floor") return f / s;
  return x;
}
/* A reference's whole span, [lo, hi]: its "valid" range, a "below" counting
   as the end. Twin of GrowthCurve::span for "auto". */
function QRID_growthSpan(ref){
  var v = ref.valid;
  return [parseFloat(v.min), parseFloat(v.max !== undefined ? v.max : v.below)];
}
/* The window an "auto" chart shows for a point at x: from the start of the
   span to a quarter past the point, never narrower than 1/maxZoom of the
   span nor past its end; the whole span without a point. */
function QRID_chartWindow(span, x){
  var lo = span[0], hi = span[1];
  if(x === null || x === undefined || !isFinite(x)) return [lo, hi];
  return [lo, Math.min(hi, lo + Math.max((hi - lo) / QRID_CHART.maxZoom, (x - lo) * 1.25))];
}
/* One curve (GrowthCurve::build segments: [key, units, key, units, ...]) as
   polylines of [x, y] inside [lo, hi], cut at the window's edges. */
function QRID_chartClip(segs, xScale, d, lo, hi){
  var out = [], p = Math.pow(10, d);
  for(var s = 0; s < (segs ? segs.length : 0); s++){
    var a = segs[s], pts = [], x0 = null, y0 = null;
    for(var i = 0; i + 1 < a.length; i += 2){
      var x1 = a[i] / xScale, y1 = a[i + 1] / p;
      if(a.length === 2){ if(x1 >= lo && x1 <= hi) pts.push([x1, y1]); break; }
      /* a segment with some length inside the window; one that only touches
         an edge adds nothing the next or the last one does not */
      if(x0 !== null && x1 > lo && x0 < hi){
        if(!pts.length) pts.push(x0 < lo ? [lo, y0 + (y1 - y0) * (lo - x0) / (x1 - x0)] : [x0, y0]);
        pts.push(x1 > hi ? [hi, y0 + (y1 - y0) * (hi - x0) / (x1 - x0)] : [x1, y1]);
      }
      x0 = x1; y0 = y1;
    }
    if(pts.length) out.push(pts);
  }
  return out;
}
/* The y axis for polylines: their lowest and highest value, at least yFloor
   of the highest apart (GrowthCurve::minRange counts on this), widened to
   whole ticks. { lo, hi, step }, or null without a point. */
function QRID_chartYRange(polys){
  var lo = Infinity, hi = -Infinity;
  for(var a = 0; a < polys.length; a++) for(var b = 0; b < polys[a].length; b++){
    for(var c = 0; c < polys[a][b].length; c++){
      var y = polys[a][b][c][1];
      if(y < lo) lo = y;
      if(y > hi) hi = y;
    }
  }
  if(!(hi >= lo)) return null;
  var floor = QRID_CHART.yFloor * Math.abs(hi);
  if(hi - lo < floor){ var mid = (hi + lo) / 2; lo = mid - floor / 2; hi = mid + floor / 2; }
  if(!(hi > lo)){ lo -= 0.5; hi += 0.5; }
  var step = QRID_chartStep(hi - lo, 6);
  return { lo: Math.floor(lo / step + 1e-9) * step, hi: Math.ceil(hi / step - 1e-9) * step, step: step };
}
/* A rule's "show" as the server parsed it (AnnotationRules::parseShow), or
   null when it is missing or not that shape: { chart: { lines: { kind,
   values }, bands, y, span, open, labels } | null, table: { rows, step, span,
   columns, open, labels } | null }. */
function QRID_showOf(s){
  if(!s || typeof s !== "object" || s.length !== undefined) return null;
  function texts(a){ if(!a || a.length === undefined) return false; for(var i = 0; i < a.length; i++) if(typeof a[i] !== "string") return false; return true; }
  function labels(l){ return !l || typeof l !== "object" || l.length !== undefined ? {} : l; }
  function span(v){ return (v && v.length === 2 && texts(v)) ? v : null; }
  var out = { chart: null, table: null };
  var c = s.chart;
  if(c && typeof c === "object" && c.lines && typeof c.lines === "object" && QRID_growthLineOf(c.lines.kind) !== null
      && texts(c.lines.values) && c.lines.values.length > 0 && (c.y === "value" || c.y === "z")){
    out.chart = { lines: { kind: c.lines.kind, values: c.lines.values.slice() }, bands: c.bands === true, y: c.y,
                  span: span(c.span), open: typeof c.open === "boolean" ? c.open : null, labels: labels(c.labels) };
  }
  var t = s.table;
  if(t && typeof t === "object" && (t.rows === "history" || t.rows === "curve") && texts(t.columns) && t.columns.length > 0){
    out.table = { rows: t.rows, step: typeof t.step === "string" ? t.step : null, span: span(t.span), columns: t.columns.slice(),
                  open: typeof t.open === "boolean" ? t.open : null, labels: labels(t.labels) };
  }
  return (out.chart || out.table) ? out : null;
}
/* A drawing coordinate as text, to a tenth of a unit. */
function QRID_chartNum(v){ var t = (Math.round(v * 10) / 10).toFixed(1); return t === "-0.0" ? "0.0" : t; }
function QRID_svgEl(tag, attrs, parent){
  var e = document.createElementNS(QRID_SVG_NS, tag);
  for(var k in attrs) if(Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, String(attrs[k]));
  if(parent) parent.appendChild(e);
  return e;
}
function QRID_emptyEl(el){ while(el.firstChild) el.removeChild(el.firstChild); }
/* An HTML element under parent, styled through the style object (never a
   style attribute). */
function QRID_htmlEl(tag, css, parent){
  var e = document.createElement(tag);
  if(e.style) for(var k in css) if(Object.prototype.hasOwnProperty.call(css, k)) e.style[k] = css[k];
  if(parent) parent.appendChild(e);
  return e;
}
/* The colour of a curve by its z-score: the median green, +-2 SD red, +-3 SD
   and beyond black, the rest grey (the WHO charts' convention). */
function QRID_chartStroke(z){
  var a = Math.abs(z);
  return a < 0.005 ? "#2e7d32" : (a >= 2.995 ? "#212121" : (a >= 1.995 ? "#c62828" : "#607d8b"));
}
/* The catalog output whose "line" name is kind ("z" -> "valueAtZ"). */
function QRID_growthLineOf(kind){
  for(var id in QRID_GROWTH_OUTPUTS){
    if(Object.prototype.hasOwnProperty.call(QRID_GROWTH_OUTPUTS, id) && QRID_GROWTH_OUTPUTS[id].line === kind) return id;
  }
  return null;
}
function QRID_capital(t){ t = String(t || ""); return t.charAt(0).toUpperCase() + t.slice(1); }
/* A label of a chart or a table: the rule's own (show.<part>.labels.<name>,
   a textKeys path, so possibly with other languages under V.i18n) in the
   page's language, else def. With el, the element gets the text, and a text
   with other languages is marked for QRID_relabelTexts. */
function QRID_showLabel(V, part, name, def, el){
  var labels = (V.show[part] && V.show[part].labels) || {};
  var own = typeof labels[name] === "string" && labels[name] !== "";
  var text = own ? labels[name] : def, key = "show." + part + ".labels." + name;
  var map = (own && V.i18n && typeof V.i18n[key] === "object") ? V.i18n[key] : null;
  var shown = map ? QRID_pickText(text, map) : text;
  if(el){
    if(map){ el.setAttribute("data-uv-text", String(QRID_textId(V, key, text, map))); QRID_watchLang(); }
    el.textContent = shown;
  }
  return shown;
}
/* Whether the rule gives one of part's labels in other languages. */
function QRID_showTranslated(V, part){
  if(!V.i18n) return false;
  for(var k in V.i18n){
    if(Object.prototype.hasOwnProperty.call(V.i18n, k) && k.indexOf("show." + part + ".labels.") === 0) return true;
  }
  return false;
}
/* The unit the measured field holds: "unit" (beside "measureScale"), else
   the reference's own when there is no scale, else none. */
function QRID_growthFieldUnit(V){
  return V.returnUnit !== null ? V.returnUnit : (V.scale === null ? (V.growth.ref.unit || "") : "");
}
/* The header of a page table column: the catalog's label, its slots filled
   from the reference ({param}, {measure}, {axis}), a value in the field's
   unit followed by it. */
function QRID_growthColumnLabel(V, of){
  var ref = V.growth.ref, unit = QRID_growthFieldUnit(V), spec = of.spec, t = spec.label;
  var measure = QRID_capital(ref.measure) + (unit ? " (" + unit + ")" : "");
  var hasMeasure = t.indexOf("{measure}") >= 0;
  t = t.replace("{param}", of.param === null ? "" : QRID_growthMarkText(of.param, V.spec.decimalComma))
       .replace("{measure}", measure).replace("{axis}", QRID_capital(ref.axis) + " (" + ref.axisUnit + ")");
  if(spec.unit === "measure" && !hasMeasure && unit) t += " (" + unit + ")";
  return QRID_capital(t);
}
/* This visit as a chart and a table see it, for variant V, the inputs'
   point (growthPoint) and the typed value: { state, why, sex, x, yRef (in
   the reference's unit, for the z-score), y (in the field's unit, for the
   chart), z, pct, typed, measure (QRID_growthMeasure's state) }, each null
   when there is none; null when an input is not on this page. yRef and y
   carry the point's correction for how the child was measured, so the
   point sits at its z-score among the curves; typed stays as typed. */
function QRID_growthChartPoint(V, point, typed){
  if(!point || point.state === "withheld") return null;
  var ref = V.growth.ref, out = { state: point.state, why: point.why || null, sex: point.sex || null,
    x: point.state === "ok" ? point.x : null, yRef: null, y: null, z: null, pct: null, typed: typed, measure: null };
  var m = QRID_growthMeasure(typed, V.spec.decimalComma, V.scale);
  out.measure = m.state;
  if(m.state === "ok"){
    var add = (point.state === "ok" && point.yAdd) ? point.yAdd : 0;
    out.yRef = m.y + add;
    out.y = parseFloat(QRID_rangeNumber(typed, V.spec.decimalComma)) + (add && V.scale !== null ? add / parseFloat(V.scale) : add);
  }
  if(out.sex && out.x !== null && out.yRef !== null){
    var z = QRID_growthZScore(ref, out.sex, out.x, out.yRef);
    if(z.state === "ok") out.z = z.z;
    out.pct = QRID_growthPercentile(ref, out.sex, out.x, out.yRef, V.percentileDecimals);
  }
  return out;
}
/* Why a chart or a table has nothing to draw for the sex of point pt (no
   sex, or a code that is neither), in a sentence; null when it has a sex. */
function QRID_growthNoSex(pt, what){
  if(pt && pt.sex) return null;
  if(!pt || !pt.why || pt.why === "the sex is blank") return "The " + what + " show once the sex is entered.";
  return "No " + what + " to show: " + pt.why + ".";
}
/* A growth chart for variant V (its "show" "chart"): a <details> holding a
   heading (the title, the source and the sex, the legend) and an SVG with
   role="img", a <title> and a <desc> that says what it shows and where this
   visit lies. width() gives the room the field's cell has (0 when unknown):
   the chart is that wide, within QRID_CHART's minWidth and width. Returns
   { el, update(point) }: update draws the curves again only when the sex or
   the window changes, else moves the marker. */
function QRID_growthChart(V, uid, width){
  var C = QRID_CHART, ref = V.growth.ref, show = V.show.chart, comma = V.spec.decimalComma;
  var all = (QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.growthCurves) || null;
  var set = (all && typeof V.curveKey === "string" && Object.prototype.hasOwnProperty.call(all, V.curveKey)) ? all[V.curveKey] : null;
  var isZ = show.y === "z", lineOf = QRID_growthLineOf(show.lines.kind);
  var zs = [];
  for(var li = 0; li < show.lines.values.length; li++) zs.push(QRID_growthLineZ(lineOf, show.lines.values[li]));
  var span = (set && set.span) ? [set.span[0], set.span[1]]
    : (show.span ? [parseFloat(show.span[0]), parseFloat(show.span[1])] : QRID_growthSpan(ref));
  var band = show.bands ? [V.spec.softLo !== undefined ? parseFloat(V.spec.softLo) : null,
                           V.spec.softHi !== undefined ? parseFloat(V.spec.softHi) : null] : null;
  var unit = QRID_growthFieldUnit(V);

  var details = document.createElement("details");
  details.open = show.open !== null && show.open !== undefined ? show.open === true : !QRID_IS_SURVEY;
  var summary = document.createElement("summary");
  QRID_showLabel(V, "chart", "summary", "Growth chart", summary);
  details.appendChild(summary);
  /* the heading is HTML, so a long title wraps instead of running into the
     legend; the SVG's own <title> and <desc> carry it for a screen reader */
  var head = QRID_htmlEl("div", { margin: "4px 0 2px 0", fontFamily: "sans-serif", lineHeight: "1.3" }, details);
  head.setAttribute("aria-hidden", "true");
  var heading = QRID_htmlEl("div", { fontSize: "14px", fontWeight: "bold", color: "#212121" }, head);
  QRID_showLabel(V, "chart", "title", ref.title, heading);
  var sub = QRID_htmlEl("div", { fontSize: "12px", color: "#616161" }, head);
  var legend = QRID_htmlEl("div", { fontSize: "12px", color: "#212121" }, head);
  var legPoint = QRID_htmlEl("span", { marginRight: "12px", whiteSpace: "nowrap" }, legend);
  QRID_htmlEl("span", { color: "#1565c0", marginRight: "4px" }, legPoint).textContent = "●";
  QRID_showLabel(V, "chart", "point", "This visit", QRID_htmlEl("span", {}, legPoint));
  var legBand = QRID_htmlEl("span", { whiteSpace: "nowrap" }, legend);
  legBand.hidden = true;
  QRID_htmlEl("span", { color: "#81c784", marginRight: "4px" }, legBand).textContent = "■";
  QRID_showLabel(V, "chart", "band", "Usual range", QRID_htmlEl("span", {}, legBand));
  var svg = QRID_svgEl("svg", { role: "img", width: "100%", "aria-labelledby": uid + "-title", "aria-describedby": uid + "-desc",
    "font-family": "sans-serif", "font-size": "11" }, details);
  var tEl = QRID_svgEl("title", { id: uid + "-title" }, svg), dEl = QRID_svgEl("desc", { id: uid + "-desc" }, svg);
  var bg = QRID_svgEl("rect", { x: 0, y: 0, height: C.height, fill: "#ffffff" }, svg);
  var clip = QRID_svgEl("clipPath", { id: uid + "-clip" }, QRID_svgEl("defs", {}, svg));
  var clipRect = QRID_svgEl("rect", { x: C.left, y: C.top, height: C.plotHeight }, clip);
  var gGrid = QRID_svgEl("g", { stroke: "#e0e0e0", "stroke-width": "1" }, svg);
  var gTicks = QRID_svgEl("g", { fill: "#424242" }, svg);
  var gPlot = QRID_svgEl("g", { "clip-path": "url(#" + uid + "-clip)" }, svg);
  var gBand = QRID_svgEl("g", {}, gPlot), gCurves = QRID_svgEl("g", { fill: "none" }, gPlot);
  var frame = QRID_svgEl("rect", { x: C.left, y: C.top, height: C.plotHeight, fill: "none", stroke: "#9e9e9e" }, svg);
  var gLines = QRID_svgEl("g", { fill: "#424242", "font-size": "10" }, svg);
  var xTitle = QRID_svgEl("text", { y: C.height - 8, "text-anchor": "middle", fill: "#424242" }, svg);
  var yTitle = QRID_svgEl("text", { x: 14, y: C.top + C.plotHeight / 2, "text-anchor": "middle", fill: "#424242",
    transform: "rotate(-90 14 " + (C.top + C.plotHeight / 2) + ")" }, svg);
  QRID_showLabel(V, "chart", "y", isZ ? "z-score" : QRID_capital(ref.measure) + (unit ? " (" + unit + ")" : ""), yTitle);
  var linesHead = QRID_svgEl("text", { y: C.top - 6, "font-size": "10", fill: "#616161" }, svg);
  QRID_showLabel(V, "chart", "lines", show.lines.kind === "percentiles" ? "Percentiles" : "z-scores", linesHead);
  var empty = QRID_svgEl("text", { y: C.top + C.plotHeight / 2, "text-anchor": "middle", fill: "#757575", visibility: "hidden" }, svg);
  var marker = QRID_svgEl("circle", { r: 4, fill: "#1565c0", stroke: "#ffffff", "stroke-width": "1.5", visibility: "hidden" }, svg);
  var mTitle = QRID_svgEl("title", {}, marker);
  var arrow = QRID_svgEl("polygon", { fill: "#1565c0", visibility: "hidden" }, svg);
  var aTitle = QRID_svgEl("title", {}, arrow);

  /* G: the geometry this chart is drawn with, its width fitted to the cell */
  var G = null;
  /* the width the cell gives the chart now, from minWidth to width; the
     last one when the cell cannot tell */
  function fitWidth(){
    var w = 0;
    try { w = Math.floor(width ? width() : 0) || 0; } catch(e){ w = 0; }
    return w > 0 ? Math.max(C.minWidth, Math.min(C.width, w)) : (G ? G.width : C.width);
  }
  function layout(){
    var W = fitWidth();
    if(G && G.width === W) return;
    G = { width: W, plotWidth: W - C.left - C.right };
    svg.setAttribute("viewBox", "0 0 " + W + " " + C.height);
    if(svg.style) svg.style.maxWidth = W + "px";
    bg.setAttribute("width", String(W));
    clipRect.setAttribute("width", String(G.plotWidth));
    frame.setAttribute("width", String(G.plotWidth));
    xTitle.setAttribute("x", QRID_chartNum(C.left + G.plotWidth / 2));
    linesHead.setAttribute("x", QRID_chartNum(C.left + G.plotWidth + 4));
    empty.setAttribute("x", QRID_chartNum(C.left + G.plotWidth / 2));
  }
  var drawn = { sex: undefined, win: null, y: null, xu: null, width: null };
  var fitted = false, last = null;
  function px(x){ return C.left + (x - drawn.win[0]) / (drawn.win[1] - drawn.win[0]) * G.plotWidth; }
  function py(y){ return C.top + C.plotHeight - (y - drawn.y.lo) / (drawn.y.hi - drawn.y.lo) * C.plotHeight; }
  /* the words of the x axis's unit and of a sex, in the page's language
     where the rule gives them */
  function unitWord(xu){ return xu.name ? QRID_showLabel(V, "chart", xu.name, xu.word) : xu.word; }
  function sexWord(sex){ return QRID_showLabel(V, "chart", sex, sex); }
  /* the curves of one sex in the window: { lines: [{ z, text, polys }],
     band: [lo polys, hi polys], extent: [polys, ...] }. extent is the
     reference at z -2 and 2 (GrowthCurve::EXTENT_Z), sent when the lines do
     not reach it: read for the y axis, never drawn. */
  function curvesFor(sex, win){
    var out = { lines: [], band: [null, null], extent: [] };
    if(isZ){
      for(var i = 0; i < zs.length; i++){
        if(zs[i] !== null) out.lines.push({ z: zs[i], text: show.lines.values[i], polys: [[[win[0], zs[i]], [win[1], zs[i]]]] });
      }
      if(band) for(var b = 0; b < 2; b++) if(band[b] !== null) out.band[b] = [[[win[0], band[b]], [win[1], band[b]]]];
      return out;
    }
    var s = (set && sex) ? set[sex] : null;
    if(!s) return null;
    for(var j = 0; j < zs.length; j++){
      out.lines.push({ z: zs[j], text: show.lines.values[j], polys: QRID_chartClip(s.lines[j], set.xScale, s.d, win[0], win[1]) });
    }
    if(set.band) for(var k = 0; k < 2; k++){
      if(set.band[k] !== null && set.band[k] !== undefined) out.band[k] = QRID_chartClip(s.lines[set.band[k]], set.xScale, s.d, win[0], win[1]);
    }
    if(s.extent) for(var e = 0; e < s.extent.length; e++){
      if(s.extent[e]) out.extent.push(QRID_chartClip(s.extent[e], set.xScale, s.d, win[0], win[1]));
    }
    return out;
  }
  function draw(sex, win, pt){
    layout();
    QRID_emptyEl(gGrid); QRID_emptyEl(gTicks); QRID_emptyEl(gBand); QRID_emptyEl(gCurves); QRID_emptyEl(gLines);
    legBand.hidden = true;
    drawn.sex = sex; drawn.win = win; drawn.width = G.width;
    drawn.xu = QRID_chartXUnit(ref, win[0], win[1]);
    xText();
    var cv = (isZ || sex) ? curvesFor(sex, win) : null;
    var polys = [];
    if(cv){
      for(var i = 0; i < cv.lines.length; i++) polys.push(cv.lines[i].polys);
      for(var b = 0; b < 2; b++) if(cv.band[b]) polys.push(cv.band[b]);
      /* a z-score axis shows at least -3 to 3, so most points fall inside;
         an axis of measurements at least the reference at z -2 to 2 */
      if(isZ) polys.push([[[win[0], -3], [win[1], 3]]]);
      for(var ex = 0; ex < cv.extent.length; ex++) polys.push(cv.extent[ex]);
    }
    drawn.y = QRID_chartYRange(polys);
    if(drawn.y && isZ){ drawn.y = { lo: drawn.y.lo - drawn.y.step, hi: drawn.y.hi + drawn.y.step, step: drawn.y.step }; }
    if(!drawn.y){
      empty.textContent = (isZ || sex) ? "No reference curves to show here." : QRID_growthNoSex(pt, "curves");
      empty.setAttribute("visibility", "visible");
      drawn.y = null;
      return;
    }
    empty.setAttribute("visibility", "hidden");
    var xt = QRID_chartTicks(win[0] / drawn.xu.div, win[1] / drawn.xu.div,
      QRID_chartStep((win[1] - win[0]) / drawn.xu.div, Math.max(4, Math.round(8 * G.plotWidth / C.plotWidth))), comma);
    for(var t = 0; t < xt.length; t++){
      var X = QRID_chartNum(px(xt[t].v * drawn.xu.div));
      QRID_svgEl("line", { x1: X, x2: X, y1: C.top, y2: C.top + C.plotHeight }, gGrid);
      QRID_svgEl("text", { x: X, y: C.top + C.plotHeight + 14, "text-anchor": "middle" }, gTicks).textContent = xt[t].text;
    }
    var yt = QRID_chartTicks(drawn.y.lo, drawn.y.hi, drawn.y.step, comma);
    for(var u = 0; u < yt.length; u++){
      var Y = QRID_chartNum(py(yt[u].v));
      QRID_svgEl("line", { x1: C.left, x2: C.left + G.plotWidth, y1: Y, y2: Y }, gGrid);
      QRID_svgEl("text", { x: C.left - 4, y: QRID_chartNum(py(yt[u].v) + 3.5), "text-anchor": "end" }, gTicks).textContent = yt[u].text;
    }
    if((cv.band[0] || cv.band[1]) && bandPath(cv.band)) legBand.hidden = false;
    /* a curve that reaches the window's end is named at the right edge; a
       table may end one row before its "below", so the last row counts */
    var reach = drawn.win[1] - (isZ || !set ? 0 : 1 / set.xScale) - 1e-9;
    var ends = [];
    for(var c = 0; c < cv.lines.length; c++){
      var L = cv.lines[c], d = pathOf(L.polys);
      if(d) QRID_svgEl("path", { d: d, stroke: QRID_chartStroke(L.z), "stroke-width": Math.abs(L.z) < 0.005 ? "1.6" : "1" }, gCurves);
      var lp = L.polys.length ? L.polys[L.polys.length - 1] : null;
      var end = lp ? lp[lp.length - 1] : null;
      if(end && end[0] >= reach) ends.push({ y: py(end[1]), text: QRID_growthMarkText(L.text, comma) });
    }
    /* the names kept 11 units apart inside the plot's height: pushed down
       from the top, then back up from the bottom */
    var gap = 11, top = C.top + 4, bottom = C.top + C.plotHeight - 4;
    ends.sort(function(a, b){ return a.y - b.y; });
    for(var e = 0; e < ends.length; e++){
      ends[e].y = Math.max(ends[e].y, e ? ends[e - 1].y + gap : top);
    }
    for(var e2 = ends.length - 1; e2 >= 0; e2--){
      ends[e2].y = Math.min(ends[e2].y, e2 < ends.length - 1 ? ends[e2 + 1].y - gap : bottom);
    }
    for(var f = 0; f < ends.length; f++){
      QRID_svgEl("text", { x: QRID_chartNum(C.left + G.plotWidth + 4), y: QRID_chartNum(ends[f].y + 3.5) }, gLines).textContent = ends[f].text;
    }
  }
  function pathOf(polys){
    var d = "";
    for(var p = 0; p < polys.length; p++){
      for(var q = 0; q < polys[p].length; q++) d += (q ? "L" : "M") + QRID_chartNum(px(polys[p][q][0])) + " " + QRID_chartNum(py(polys[p][q][1]));
    }
    return d;
  }
  /* the soft range shaded: between the two band curves, or from one to the
     plot's edge when the rule has a limit on one side only. False when
     nothing is shaded (a band curve with a gap, or none in the window). */
  function bandPath(bp){
    var lo = bp[0] && bp[0].length === 1 ? bp[0][0] : null, hi = bp[1] && bp[1].length === 1 ? bp[1][0] : null;
    if((bp[0] && !lo) || (bp[1] && !hi) || (!lo && !hi)) return false;   /* a curve with a gap: no band */
    var w = drawn.win;
    if(!lo) lo = [[w[0], drawn.y.lo], [w[1], drawn.y.lo]];
    if(!hi) hi = [[w[0], drawn.y.hi], [w[1], drawn.y.hi]];
    var d = "";
    for(var i = 0; i < lo.length; i++) d += (i ? "L" : "M") + QRID_chartNum(px(lo[i][0])) + " " + QRID_chartNum(py(lo[i][1]));
    for(var j = hi.length - 1; j >= 0; j--) d += "L" + QRID_chartNum(px(hi[j][0])) + " " + QRID_chartNum(py(hi[j][1]));
    QRID_svgEl("path", { d: d + "Z", fill: "#c8e6c9", "fill-opacity": "0.7", stroke: "none" }, gBand);
    return true;
  }
  /* the x axis's title: the rule's "x" with "{unit}" filled in, or the axis
     and its unit */
  function xText(){
    if(!drawn.xu) return;
    xTitle.textContent = QRID_showLabel(V, "chart", "x", QRID_capital(ref.axis) + " ({unit})").split("{unit}").join(unitWord(drawn.xu));
  }
  function subText(sex){
    sub.textContent = (V.chartSource ? V.chartSource : "") + (sex ? (V.chartSource ? " · " : "") + sexWord(sex) : "");
  }
  /* the words for this visit, in the page's language where the rule gives them */
  function pointText(pt){
    var name = QRID_showLabel(V, "chart", "point", "This visit");
    if(!pt || pt.x === null || pt.y === null) return null;
    var xu = drawn.xu || QRID_chartXUnit(ref, span[0], span[1]);
    var xs = QRID_growthMarkText(QRID_growthAxisText(pt.x / xu.div) || "", comma);
    return name + ": " + QRID_whenTrim(String(pt.typed)) + (unit ? " " + unit : "") + " at " + xs + " " + unitWord(xu)
      + (pt.z !== null ? ", z-score " + QRID_growthMarkText(pt.z, comma) : "")
      + (pt.pct !== null ? ", percentile " + QRID_growthMarkText(pt.pct, comma) : "");
  }
  /* why this visit has no point, in a sentence */
  function noPoint(pt){
    if(!pt) return "No measurement to show yet.";
    if(pt.x === null){
      if(pt.state === "typing") return "This visit is placed once its dates are complete.";
      return pt.why ? "This visit is not placed: " + pt.why + "." : "This visit is not placed yet.";
    }
    if(pt.measure === "not-a-number") return "The measurement is not a number.";
    if(pt.measure === "not-positive") return "The measurement is not above 0.";
    return "No measurement to show yet.";
  }
  function describe(pt, outside){
    var head = QRID_showLabel(V, "chart", "title", ref.title);
    tEl.textContent = head;
    var vals = [];
    for(var i = 0; i < show.lines.values.length; i++) vals.push(QRID_growthMarkText(show.lines.values[i], comma));
    var p = pointText(pt);
    dEl.textContent = head + "." + (sub.textContent ? " " + sub.textContent + "." : "") + " "
      + QRID_showLabel(V, "chart", "lines", show.lines.kind === "percentiles" ? "Percentiles" : "z-scores") + ": " + vals.join(", ") + "."
      + " " + (p ? p + "." : noPoint(pt))
      + (outside ? " This visit lies outside the chart; an arrow at its edge points to it." : "");
    mTitle.textContent = p || "";
    aTitle.textContent = p ? p + " (outside the chart)" : "";
  }
  function place(pt){
    marker.setAttribute("visibility", "hidden");
    arrow.setAttribute("visibility", "hidden");
    if(!pt || pt.x === null || !drawn.y) return false;
    var v = isZ ? (pt.z === null ? null : parseFloat(pt.z)) : pt.y;
    if(v === null || !isFinite(v)) return false;
    /* on a chart of measurements the point sits on the row the check reads */
    var X = px(isZ ? pt.x : QRID_growthReadX(ref, pt.x)), Y = py(v);
    var L = C.left, R = C.left + G.plotWidth, T = C.top, B = C.top + C.plotHeight;
    if(X >= L && X <= R && Y >= T && Y <= B){
      marker.setAttribute("cx", QRID_chartNum(X));
      marker.setAttribute("cy", QRID_chartNum(Y));
      marker.setAttribute("visibility", "visible");
      return false;
    }
    /* outside: an arrow at the nearest edge, pointing out */
    var cx = Math.max(L + 7, Math.min(R - 7, X)), cy = Math.max(T + 7, Math.min(B - 7, Y)), pts;
    if(X < L) pts = [[L, cy], [L + 9, cy - 5], [L + 9, cy + 5]];
    else if(X > R) pts = [[R, cy], [R - 9, cy - 5], [R - 9, cy + 5]];
    else if(Y < T) pts = [[cx, T], [cx - 5, T + 9], [cx + 5, T + 9]];
    else pts = [[cx, B], [cx - 5, B - 9], [cx + 5, B - 9]];
    var s = "";
    for(var i = 0; i < pts.length; i++) s += (i ? " " : "") + QRID_chartNum(pts[i][0]) + "," + QRID_chartNum(pts[i][1]);
    arrow.setAttribute("points", s);
    arrow.setAttribute("visibility", "visible");
    return true;
  }
  function update(pt){
    last = pt;
    var sex = pt ? pt.sex : null, win = drawn.win;
    if(show.span) win = span;
    else if(pt && pt.x !== null){
      /* refit when the point leaves the window, or would fit one less than
         half as wide (a corrected age): not at every small change */
      var fit = QRID_chartWindow(span, pt.x);
      if(!fitted || win === null || pt.x < win[0] || pt.x > win[1] || (fit[1] - fit[0]) * 2 < win[1] - win[0]){ win = fit; fitted = true; }
    }
    else if(win === null) win = QRID_chartWindow(span, null);
    subText(sex);
    /* drawn again for another sex or window, or when the cell's width
       changed since (a resized window), so the chart fits it again */
    if(sex !== drawn.sex || drawn.win === null || win[0] !== drawn.win[0] || win[1] !== drawn.win[1]
        || fitWidth() !== drawn.width) draw(sex, win, pt);
    else if(!drawn.y && !isZ && !sex) empty.textContent = QRID_growthNoSex(pt, "curves");
    describe(pt, place(pt));
  }
  if(QRID_showTranslated(V, "chart")){
    UV_relabelHooks.push(function(){
      if(!drawn.win) return;
      xText(); subText(drawn.sex);
      describe(last, arrow.getAttribute("visibility") === "visible");
    });
  }
  return { el: details, update: update };
}
/* A page table for variant V (its "show" "table"): this visit's row
   ("rows":"history"), or the reference values the server sent for the
   child's sex ("rows":"curve", config.growthCurveTables). Returns
   { el, update(point) }. */
function QRID_growthTable(V){
  var show = V.show.table, ref = V.growth.ref, comma = V.spec.decimalComma, curve = show.rows === "curve";
  var allRows = (QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.growthCurveTables) || null;
  var sent = (allRows && typeof V.tableKey === "string" && Object.prototype.hasOwnProperty.call(allRows, V.tableKey)) ? allRows[V.tableKey] : null;
  /* each column keeps its place in the rows the server sent, so a column
     this page cannot read leaves the others under their own headers */
  var cols = [];
  for(var c = 0; c < show.columns.length; c++){
    var of = QRID_growthOutputOf(show.columns[c], "column");
    if(of) cols.push({ key: show.columns[c], of: of, at: c });
  }
  var details = document.createElement("details");
  details.open = show.open !== null && show.open !== undefined ? show.open === true : !QRID_IS_SURVEY;
  QRID_showLabel(V, "table", "summary", curve ? "Reference values" : "This visit", details.appendChild(document.createElement("summary")));
  var table = QRID_htmlEl("table", { borderCollapse: "collapse", fontSize: "13px", margin: "4px 0" }, details);
  /* over the rows: Bootstrap (REDCap 17) puts every caption under its table */
  QRID_showLabel(V, "table", "caption", ref.title, QRID_htmlEl("caption", { captionSide: "top" }, table));
  var hr = table.appendChild(document.createElement("thead")).appendChild(document.createElement("tr"));
  for(var h = 0; h < cols.length; h++){
    var th = QRID_htmlEl("th", { padding: "2px 8px", borderBottom: "1px solid #9e9e9e", textAlign: "right" }, hr);
    th.setAttribute("scope", "col");
    QRID_showLabel(V, "table", cols[h].key, QRID_growthColumnLabel(V, cols[h].of), th);
  }
  var body = table.appendChild(document.createElement("tbody"));
  var shownSex;
  function row(cells){
    var tr = body.appendChild(document.createElement("tr"));
    for(var i = 0; i < cells.length; i++){
      var td = QRID_htmlEl("td", { padding: "2px 8px", textAlign: "right" }, tr);
      /* a note in place of the rows spans them all */
      if(cells.length === 1 && cols.length > 1) td.setAttribute("colspan", String(cols.length));
      td.textContent = cells[i];
    }
  }
  /* a number in the field's decimal mark; "range" was written in it already */
  function mark(col, t){ return (t === null || t === undefined) ? "" : (col.of.spec.written === "number" && col.of.of !== "value" ? QRID_growthMarkText(t, comma) : t); }
  function visit(pt){
    var out = [], o = null, sex = pt ? pt.sex : null, x = pt ? pt.x : null;
    if(sex && x !== null){
      var lim = V.returnLimits === "hard" ? { lo: V.spec.hardLo, hi: V.spec.hardHi } : { lo: V.spec.softLo, hi: V.spec.softHi };
      o = QRID_growthOutputs(ref, sex, x, pt.yRef, { lo: lim.lo === undefined ? null : lim.lo, hi: lim.hi === undefined ? null : lim.hi },
        V.decimals, comma, V.scale, V.returnUnit);
    }
    for(var i = 0; i < cols.length; i++){
      var of = cols[i].of, t = null;
      if(o !== null && Object.prototype.hasOwnProperty.call(o, of.of)) t = o[of.of];
      else if(o !== null){
        var places = of.spec.places === "z" ? 2 : (of.spec.places === "percentileDecimals" ? V.percentileDecimals : V.decimals);
        t = QRID_growthOutput(ref, sex, x, pt.yRef, of.of, of.param, places, V.scale, pt.yRef === null ? null : pt.typed);
      }
      out.push(mark(cols[i], t));
    }
    return out;
  }
  function update(pt){
    var sex = pt ? pt.sex : null;
    if(curve){
      var note = QRID_growthNoSex(pt, "values");
      var key = sex || note;
      if(key === shownSex) return;
      shownSex = key;
      QRID_emptyEl(body);
      var rows = (sent && sex && sent[sex]) || null;
      if(!rows){ row([sex ? "No reference values to show here." : note]); return; }
      for(var r = 0; r < rows.length; r++){
        var cells = [];
        for(var k = 0; k < cols.length; k++) cells.push(mark(cols[k], rows[r][cols[k].at]));
        row(cells);
      }
      return;
    }
    QRID_emptyEl(body);
    row(visit(pt));
  }
  return { el: details, update: update };
}
/* ---- range validator (@UVRANGE) --------------------------------------------
   Two tiers of plausibility limits for a number. Outside "soft" the value is
   unusual: an amber note, and with softBlock "confirm" the save asks first.
   Outside "hard", or not a number, it is implausible: a red note, and the save
   is held (hardBlock "hard") or asks (hardBlock "confirm"). A value inside the
   soft range says nothing: a green OK on every number would be noise. A calc
   (rangeComputed) and a read-only input never hold the save, nobody typed them.
   The verdict is QRID_rangeVerdict, the twin of Logic::rangeVerdict
   (tests/range_fixture.json); UniversalValidator::findingsRange is the server
   twin. The note appears when the field is left, not while a number is being
   typed: "1" on the way to "12" is not a finding. */
function QRIDRangeInit(QRID_CONFIG){
  var NUM = /^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/;
  function styleMsg(el, kind){   /* "warn" | "bad" */
    var c = (kind === "warn") ? "#d9c48a;background:#fdf8e6;color:#7a5c00"
                              : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var SOFT = cfg.rangeSoftBlock || "confirm", HARD = cfg.rangeHardBlock || "hard";
    if(!configError && SOFT !== "off" && SOFT !== "confirm"){
      configError = 'softBlock must be "off" or "confirm" — got "' + SOFT + '".';
    }
    if(!configError && HARD !== "confirm" && HARD !== "hard"){
      configError = 'hardBlock must be "confirm" or "hard" — got "' + HARD + '".';
    }
    var spec = { decimalComma: cfg.decimalComma === true };
    var keys = { softLo: "rangeSoftLo", softHi: "rangeSoftHi", hardLo: "rangeHardLo", hardHi: "rangeHardHi" };
    var any = false;
    for(var k in keys){
      if(!Object.prototype.hasOwnProperty.call(keys, k)) continue;
      var b = cfg[keys[k]];
      if(b === undefined || b === null || b === "") continue;
      if(typeof b !== "string" || !NUM.test(b)){
        if(!configError) configError = "@UVRANGE limit " + k + " is not a number.";
        continue;
      }
      spec[k] = b; any = true;
    }
    /* Values the rule writes to other fields of the form ("return"): value name
       -> field name, which the server checked against the data dictionary
       (UniversalValidator::returnTargetsProblem). A shape that is not
       understood is a configuration error here, never a guess. A key names an
       output of the catalog (QRID_growthOutputOf); a rule whose values are
       none of them made from a limit judges none, so it needs none. */
    var returns = null, retOf = {}, retLimits = "soft", retDecimals = 1,
        retPctDecimals = QRID_GROWTH_DEFAULT_PERCENTILE_DECIMALS, retComma = {};
    if(cfg.rangeReturn !== undefined && cfg.rangeReturn !== null){
      var rr = cfg.rangeReturn, rkeys = (typeof rr === "object" && !rr.length) ? Object.keys(rr) : [];
      returns = {};
      for(var ri = 0; ri < rkeys.length; ri++){
        var rv = rkeys[ri], rt = rr[rv], rof = QRID_growthOutputOf(rv);
        if(rof === null || typeof rt !== "string" || rt === ""){
          if(!configError) configError = '@UVRANGE "return" is not understood.';
          continue;
        }
        returns[rv] = rt;
        retOf[rv] = rof;
      }
      if(!rkeys.length && !configError) configError = '@UVRANGE "return" names no field.';
      if(typeof cfg.rangeReference !== "string" || cfg.rangeReference === ""){
        if(!configError) configError = '@UVRANGE "return" needs a growth "reference".';
      }
      if(cfg.rangeReturnComma && typeof cfg.rangeReturnComma === "object"){
        for(var rc in cfg.rangeReturnComma){
          if(Object.prototype.hasOwnProperty.call(cfg.rangeReturnComma, rc)) retComma[rc] = cfg.rangeReturnComma[rc] === true;
        }
      }
    }
    /* How the values a rule returns or its page table shows are made: the
       limits they come from, and their decimals. */
    if(cfg.rangePercentileDecimals !== undefined){
      if(typeof cfg.rangePercentileDecimals !== "number" || cfg.rangePercentileDecimals % 1 !== 0
          || cfg.rangePercentileDecimals < 0 || cfg.rangePercentileDecimals > QRID_GROWTH_MAX_PERCENTILE_DECIMALS){
        if(!configError) configError = '@UVRANGE "percentileDecimals" must be a whole number from 0 to '
          + QRID_GROWTH_MAX_PERCENTILE_DECIMALS + '.';
      } else retPctDecimals = cfg.rangePercentileDecimals;
    }
    if(cfg.rangeReturnLimits !== undefined && cfg.rangeReturnLimits !== "soft" && cfg.rangeReturnLimits !== "hard"){
      if(!configError) configError = '@UVRANGE "returnLimits" must be "soft" or "hard".';
    } else if(cfg.rangeReturnLimits === "hard") retLimits = "hard";
    if(cfg.rangeDecimals !== undefined){
      if(typeof cfg.rangeDecimals !== "number" || cfg.rangeDecimals % 1 !== 0 || cfg.rangeDecimals < 0
          || cfg.rangeDecimals > QRID_GROWTH_MAX_DECIMALS){
        if(!configError) configError = '@UVRANGE "decimals" must be a whole number from 0 to ' + QRID_GROWTH_MAX_DECIMALS + '.';
      } else retDecimals = cfg.rangeDecimals;
    }
    var onlyZ = returns !== null && Object.keys(retOf).length > 0;
    for(var rok in retOf){
      if(Object.prototype.hasOwnProperty.call(retOf, rok) && retOf[rok].spec.needs.indexOf("limit-") === 0) onlyZ = false;
    }
    /* A growth rule that only shows (a chart, a page table) and returns
       nothing made from a limit judges nothing either, as the server takes it
       (AnnotationRules: $limitFree). A column made from a limit the server
       refuses on its own. */
    var showOnly = typeof cfg.rangeReference === "string" && cfg.rangeReference !== ""
      && cfg.rangeShow !== null && typeof cfg.rangeShow === "object" && Object.keys(cfg.rangeShow).length > 0
      && (returns === null || onlyZ);
    /* A deferred stub (the page could not be given this rule) carries no limits:
       it reports its deferral, not a configuration error. */
    if(!configError && !cfg.deferred && !any && !onlyZ && !showOnly) configError = "@UVRANGE has no limits to check.";
    /* growth only: "plausible" in the reference's unit, and "measureScale" */
    var opt = function(key){
      var v = cfg[key];
      if(v === undefined || v === null || v === "") return null;
      if(typeof v !== "string" || !NUM.test(v)){ if(!configError) configError = "@UVRANGE " + key + " is not a number."; return null; }
      return v;
    };
    var plausibleLo = opt("rangePlausibleLo"), plausibleHi = opt("rangePlausibleHi"), scale = opt("rangeMeasureScale");
    /* A growth reference: the limits are z-scores, read with the page's copy of
       the table (config.growth) and the inputs the server folded into ops. */
    var growth = null;
    if(typeof cfg.rangeReference === "string" && cfg.rangeReference !== ""){
      var tables = (QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.growth) || null;
      var gref = (tables && Object.prototype.hasOwnProperty.call(tables, cfg.rangeReference)) ? tables[cfg.rangeReference] : null;
      var ops = {}, withheld = false, okOp = function(op){ return op && op.length && (op[0] === "ref" || op[0] === "lit" || op[0] === "withheld"); };
      for(var gi = 0; gi < QRID_GROWTH_INPUTS.length; gi++){
        var op = cfg["range" + QRID_GROWTH_INPUTS[gi] + "Op"];
        if(okOp(op)) ops[QRID_GROWTH_INPUTS[gi]] = op;
        if(okOp(op) && op[0] === "withheld") withheld = true;
      }
      if(!configError && !cfg.deferred){
        /* A withheld input means no z-score on this page, so the server sends
           no table for it (UniversalValidator::attachGrowthTables); nor for a
           rule or branch whose condition it settled false (growthIdle), which
           can never apply here. */
        if(!gref && !withheld && cfg.growthIdle !== true) configError = '@UVRANGE growth reference "' + cfg.rangeReference + '" was not sent to this page.';
        else if(!ops.Sex || !(ops.AgeDob && ops.AgeAt || ops.AgeDays || ops.AgeMonths || ops.By)){
          configError = "@UVRANGE cannot read the inputs of its growth reference.";
        }
      }
      /* sex codes: letter case ignored unless caseSensitive (twin: GrowthReference::sexOf) */
      growth = { ref: gref, ops: ops, male: String(cfg.rangeMale), female: String(cfg.rangeFemale),
                 lying: cfg.rangeLying === undefined ? null : String(cfg.rangeLying),
                 standing: cfg.rangeStanding === undefined ? null : String(cfg.rangeStanding),
                 fold: cfg.caseSensitive !== true,
                 dobType: cfg.rangeDobType || "date", dobFormat: cfg.rangeDobFormat || "ymd",
                 atType: cfg.rangeAtType || "date", atFormat: cfg.rangeAtFormat || "ymd",
                 comma: cfg.rangeAxisComma === true };
    }
    var DEFERRED = !configError && !!cfg.deferred;
    /* deferred only because the page could not carry the table (or the rule's
       rule-level mark says so): the save checks it, and the measurement itself
       is still checked here */
    var ON_SAVE = DEFERRED && (cfg.deferredOnSave === true || QRID_CONFIG.deferredOnSave === true);
    var SNAPSHOT = (!configError && cfg.snapshotFields && cfg.snapshotFields.length) ? cfg.snapshotFields : null;
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
    /* An input on this page re-checks the value when it changes. */
    var WATCH = [];
    if(growth && !configError){
      for(var wk in growth.ops){
        if(Object.prototype.hasOwnProperty.call(growth.ops, wk) && growth.ops[wk][0] === "ref"){
          var w = QRID_WHEN.gateFor(null, ["cmp", "=", growth.ops[wk], ["lit", ""]]);
          if(w) WATCH.push(w);
        }
      }
    }
    /* blockSave is what QRID_buildVariants reads and turns "off" for a deferred
       rule or one whose branch was chosen from values read when the page opened;
       the tier's own setting applies only while it is not "off". A rule with
       event or instance references arrives marked extendedAdvisory
       (TemporalRules::compile): it never blocks here, and the save re-checks it.
       cfg.blockSave itself is never read: @UVRANGE refuses the key, so the
       value here is the page-wide default ("off"), which cfgFor fills in. */
    /* "Not a number" and "0 or below" need no input, table or limit: they keep
       the rule's hard setting when its inputs are saved values from another
       form or its table could not be sent. Not when what decides whether the
       rule applies at all was read when the page opened (snapshotGate), nor
       for a calc or an event/instance rule, which never block. */
    var MEASURE_BLOCK = (cfg.rangeComputed === true || cfg.extendedAdvisory === true || cfg.snapshotGate === true
      || (DEFERRED && !ON_SAVE)) ? "off" : HARD;
    return { configError: configError, gate: GATE, watch: WATCH, growth: growth,
             returns: configError ? null : returns, returnOf: retOf, returnLimits: retLimits, decimals: retDecimals,
             percentileDecimals: retPctDecimals, returnComma: retComma,
             /* the unit "range" is written in: "unit" (allowed with a reference
                only beside "measureScale"), else the reference's own when the
                field holds that unit, else none (GrowthReference::outputs) */
             returnUnit: (typeof cfg.rangeUnit === "string" && cfg.rangeUnit !== "") ? cfg.rangeUnit : null,
             blockSave: (cfg.rangeComputed === true || DEFERRED || cfg.extendedAdvisory === true) ? "off" : HARD,
             measureBlock: MEASURE_BLOCK,
             softBlock: SOFT, hardBlock: HARD,
             deferred: DEFERRED, onSave: ON_SAVE, snapshot: SNAPSHOT, snapshotGate: cfg.snapshotGate === true,
             deferredWhy: (cfg.deferredWhy && cfg.deferredWhy.length) ? cfg.deferredWhy
                        : (ON_SAVE && QRID_CONFIG.deferredWhy && QRID_CONFIG.deferredWhy.length) ? QRID_CONFIG.deferredWhy : null,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             spec: spec,
             plausibleLo: plausibleLo, plausibleHi: plausibleHi, scale: scale,
             plausibleText: (typeof cfg.rangePlausibleText === "string") ? cfg.rangePlausibleText : "",
             softText: (typeof cfg.rangeSoftText === "string") ? cfg.rangeSoftText : "",
             hardText: (typeof cfg.rangeHardText === "string") ? cfg.rangeHardText : "",
             /* "show": what the page draws, never part of the verdict, so a
                shape not understood shows nothing instead of an error */
             show: (growth && !configError) ? QRID_showOf(cfg.rangeShow) : null,
             curveKey: typeof cfg.growthCurveKey === "string" ? cfg.growthCurveKey : null,
             tableKey: typeof cfg.growthTableKey === "string" ? cfg.growthTableKey : null,
             chartWhy: (cfg.growthChartWhy && typeof cfg.growthChartWhy === "object") ? cfg.growthChartWhy : null,
             chartSource: typeof cfg.growthChartSource === "string" ? cfg.growthChartSource : "",
             mode: { range: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = false;
  for(var abi = 0; abi < VS.all.length; abi++){
    if(VS.all[abi].blockSave !== "off" || VS.all[abi].measureBlock !== "off"){ ANY_BLOCK = true; break; }
  }
  /* The fields this rule returns values to ("return"), per branch group (one
     group per reference: QRID_buildVariants). A group owns the fields of
     every one of its branches: the ones its active branch does not fill are
     emptied. The server refuses two groups that share a field
     (UniversalValidator::markReturnClashes), since both apply at once. */
  var OWNED = Object.create(null), OWNED_ANY = false;
  for(var obi = 0; obi < VS.all.length; obi++){
    var ret = VS.all[obi].returns, og = VS.all[obi].group || "";
    if(!ret) continue;
    var olist = OWNED[og] || (OWNED[og] = []);
    for(var ork in ret){
      if(Object.prototype.hasOwnProperty.call(ret, ork) && olist.indexOf(ret[ork]) < 0){ olist.push(ret[ork]); OWNED_ANY = true; }
    }
  }
  function measureReason(r){ return r.reason === "not-a-number" || r.reason === "not-positive"; }
  function blockFor(V, tier, reason){
    if(reason === "not-a-number" || reason === "not-positive") return V.measureBlock;
    if(V.blockSave === "off") return "off";
    return tier === "soft" ? V.softBlock : V.hardBlock;
  }
  /* The measurement alone, for a variant that cannot be judged further on
     this page: { tier: "hard", reason } when it is not a number or 0 or
     below, else null. A plain rule's number check is QRID_rangeVerdict's. */
  function measureOnly(V, typed){
    if(V.growth){
      var m = QRID_growthMeasure(typed, V.spec.decimalComma, V.scale);
      return (m.state === "not-a-number" || m.state === "not-positive") ? { tier: "hard", reason: m.state } : null;
    }
    var r = QRID_rangeVerdict({ decimalComma: V.spec.decimalComma }, typed);
    return (r.tier === "hard" && r.reason === "not-a-number") ? r : null;
  }
  function describe(V, r){
    if(V.message) return QRID_ruleHtml(V, "message");
    if(r.reason === "not-a-number") return "This is not a number.";
    if(r.reason === "not-positive") return "A measurement must be above 0.";
    if(r.reason === "plausible-low" || r.reason === "plausible-high"){
      /* no z-score, so the rule's "plausible" range judged it; why there is
         none is for staff only, as the grey notes are */
      var pn = [];
      if(V.plausibleText) pn.push("allowed " + V.plausibleText);
      if(!QRID_IS_SURVEY) pn.push("no z-score on " + V.growth.ref.title + (r.why ? ": " + r.why : ""));
      return (r.reason === "plausible-low" ? "This value is below the plausible range" : "This value is above the plausible range") +
        (pn.length ? " (" + QRID_escapeHtml(pn.join("; ")) + ")" : "") + ".";
    }
    var t = r.tier === "soft" ? V.softText : V.hardText;
    var low = r.reason === "soft-low" || r.reason === "hard-low";
    var text = r.tier === "soft"
      ? (low ? "This value is lower than usual" : "This value is higher than usual")
      : (low ? "This value is below the plausible range" : "This value is above the plausible range");
    var notes = [];
    /* the reference's unit, so a value typed in another unit shows why its z-score is extreme */
    if(r.z !== undefined) notes.push("z-score " + r.z + " on " + V.growth.ref.title +
      (V.growth.ref.unit ? ", in " + V.growth.ref.unit + (V.scale !== null ? " after × " + V.scale : "") : ""));
    if(t) notes.push((r.tier === "soft" ? "expected " : "allowed ") + t);
    return text + (notes.length ? " (" + QRID_escapeHtml(notes.join("; ")) + ")" : "") + ".";
  }
  /* One input of a growth rule as text, "" when blank or marked with a Missing
     Data Code; null when the server withheld it from this page. */
  function growthInput(op){
    if(!op) return "";
    if(op[0] === "withheld") return null;
    var v = op[0] === "ref" ? QRID_WHEN.readRef(op[1], null) : op[1];
    v = (v == null || typeof v === "object") ? "" : QRID_whenTrim(String(v));
    return QRID_isMissingCode(v) ? "" : v;
  }
  /* A date input as whole days since 1970-01-01: null when blank, undefined
     when it is not (yet) a whole date. A live field reads in its own format,
     a saved value as Y-M-D. */
  function growthDays(op, type, format){
    var v = growthInput(op);
    if(v === null) return undefined;
    if(v === "") return null;
    var p = QRID_temporalDate(v, type, op[0] === "ref" ? format : "ymd");
    return p ? Math.floor(p.seconds / 86400) : undefined;
  }
  /* Where a growth rule's inputs place the child: { state: "ok", sex, x,
     yAdd } (yAdd: what the measurement gets added before it is scored, WHO's
     correction for how the child was measured; one of the length is in x),
     { state: "withheld" } when an input was not sent to this page,
     { state: "typing" } while a date on this page is not yet a whole date,
     or { state: "inert", why } (a blank or unreadable input, a sex code that
     is neither, outside the reference). Once the sex is known, "typing" and
     "inert" carry it too, so a chart can draw that sex's curves. Server
     twin: GrowthReference::inputsFor. */
  function growthPoint(V){
    var g = V.growth;
    var withheld = false, ins = {}, k;
    for(var gi = 0; gi < QRID_GROWTH_INPUTS.length; gi++){
      k = QRID_GROWTH_INPUTS[gi];
      if(!g.ops[k]) continue;
      ins[k] = growthInput(g.ops[k]);
      if(ins[k] === null) withheld = true;
    }
    if(withheld) return { state: "withheld" };
    var sexKey = function(v){ return g.fold ? QRID_whenFoldCase(v) : v; };
    var sex = sexKey(ins.Sex) === sexKey(g.male) ? "male" : (sexKey(ins.Sex) === sexKey(g.female) ? "female" : null);
    if(sex === null){
      return { state: "inert", why: ins.Sex === "" ? "the sex is blank"
        : "the sex code is neither " + g.male + " (male) nor " + g.female + " (female)" };
    }
    /* "measured": a blank value or a code that is neither corrects nothing
       (twin: GrowthReference::positionOf) */
    var position = null;
    if(g.ops.Measured && ins.Measured !== ""){
      if(g.lying !== null && sexKey(ins.Measured) === sexKey(g.lying)) position = "lying";
      else if(g.standing !== null && sexKey(ins.Measured) === sexKey(g.standing)) position = "standing";
    }
    var input = { comma: g.comma, byAdd: QRID_growthPositionAdd(g.ref, position, null, "axis") };
    if(g.ops.AgeDob){
      input.dobDays = growthDays(g.ops.AgeDob, g.dobType, g.dobFormat);
      input.atDays = growthDays(g.ops.AgeAt, g.atType, g.atFormat);
      if(input.dobDays === undefined || input.atDays === undefined){
        var dobBad = input.dobDays === undefined;
        /* A date on this page is re-read at every keystroke: one still being
           typed says nothing. A saved one the rule cannot read gets the
           server's words (GrowthReference::inputsFor). */
        if((dobBad ? g.ops.AgeDob : g.ops.AgeAt)[0] !== "lit") return { state: "typing", sex: sex };
        return { state: "inert", sex: sex, why: dobBad ? "the date of birth is not a date this rule can read"
                                                       : "the date of the measurement is not a date this rule can read" };
      }
    } else {
      if(g.ops.AgeDays) input.days = ins.AgeDays;
      if(g.ops.AgeMonths) input.months = ins.AgeMonths;
      if(g.ops.By) input.by = ins.By;
    }
    var a = QRID_growthAxis(g.ref, input);
    if(a.state !== "ok") return { state: "inert", why: a.why, sex: sex };
    return { state: "ok", sex: sex, x: a.x, yAdd: QRID_growthPositionAdd(g.ref, position, a.x, "measurement") };
  }
  /* The verdict of a growth rule: the measurement first (not a number, at or
     below 0), then the z-score's tier against the limits. { tier: "withheld" }
     when an input was not sent to this page. With no z-score (an input blank
     or unreadable, a sex code that is neither, outside the reference) the
     "plausible" range judges ({ tier: "hard", reason: "plausible-low" |
     "plausible-high", why }), else { tier: "inert", why }. Server twin:
     UniversalValidator::findingsRange and growthInputs. */
  function growthVerdict(V, typed){
    var g = V.growth;
    var m = QRID_growthMeasure(typed, V.spec.decimalComma, V.scale);
    var noZ = function(why){
      var p = QRID_growthPlausible(m.y, V.plausibleLo, V.plausibleHi);
      return p ? { tier: "hard", reason: p, why: why } : { tier: "inert", why: why };
    };
    if(m.state === "blank") return { tier: "inert", why: null };
    if(m.state !== "ok") return { tier: "hard", reason: m.state };
    var p = growthPoint(V);
    if(p.state === "withheld") return { tier: "withheld" };
    if(p.state === "typing") return { tier: "inert", why: null };
    if(p.state !== "ok") return noZ(p.why);
    var z = QRID_growthZScore(g.ref, p.sex, p.x, m.y + p.yAdd);
    /* only "outside" (no row there): a server-sent table covers its "valid"
       range (GrowthReference::table), so this is a damaged reference */
    if(z.state !== "ok") return { tier: "inert", why: "the reference has no row at this " + (g.ref.axis === "age" ? "age" : g.ref.axis) };
    var r = QRID_rangeVerdict({ softLo: V.spec.softLo, softHi: V.spec.softHi, hardLo: V.spec.hardLo, hardHi: V.spec.hardHi }, z.z);
    r.z = z.z;
    return r;
  }
  /* What a growth rule returns (@UVRANGE "return") as field name -> text, where
     "" means nothing to report: an input is blank or outside the reference, or
     the value cannot be made (no measurement for the z-score, no limit).
     null when an input is on a form this page does not carry: the fields keep
     what they hold, and the save fills them. The bounds need no measurement.
     Server twin: UniversalValidator::returnsRange. */
  function returnValues(V, typed){
    if(!V.growth || !V.growth.ref) return null;
    var p = growthPoint(V);
    if(p.state === "withheld") return null;
    var o = null, y = null;
    if(p.state === "ok"){
      var m = QRID_growthMeasure(typed, V.spec.decimalComma, V.scale);
      var lim = V.returnLimits === "hard" ? { lo: V.spec.hardLo, hi: V.spec.hardHi } : { lo: V.spec.softLo, hi: V.spec.softHi };
      /* the z-score and percentile score the corrected measurement; the
         bounds are the reference's (twin: returnsRange) */
      y = m.state === "ok" ? m.y + p.yAdd : null;
      o = QRID_growthOutputs(V.growth.ref, p.sex, p.x, y,
        { lo: lim.lo === undefined ? null : lim.lo, hi: lim.hi === undefined ? null : lim.hi },
        V.decimals, V.returnComma.range === true, V.scale, V.returnUnit);
    }
    var out = {};
    for(var k in V.returns){
      if(!Object.prototype.hasOwnProperty.call(V.returns, k)) continue;
      var of = V.returnOf[k], t = null;
      if(o !== null && Object.prototype.hasOwnProperty.call(o, of.of)) t = o[of.of];
      else if(o !== null){
        var places = of.spec.places === "z" ? 2 : (of.spec.places === "percentileDecimals" ? V.percentileDecimals : V.decimals);
        t = QRID_growthOutput(V.growth.ref, p.sex, p.x, y, of.of, of.param, places, V.scale);
      }
      if(t === null || t === undefined) t = "";
      /* a text (range) was made in its field's decimal mark; a number is marked here */
      out[V.returns[k]] = (of.spec.written !== "number" || t === "") ? t : QRID_growthMarkText(t, V.returnComma[k] === true);
    }
    return out;
  }
  function verdictOf(V, typed){
    return V.growth ? growthVerdict(V, typed) : QRID_rangeVerdict(V.spec, typed);
  }

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-n")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-n", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "n");
    /* The growth charts and page tables ("show"), after the note: one panel
       per variant that shows something, in the rule's order, filled the
       first time its variant applies and hidden while it does not. */
    var panels = [], chartBox = null;
    for(var pvi = 0; pvi < VS.all.length; pvi++){
      if(!VS.all[pvi].show || configError) continue;
      if(!chartBox){
        chartBox = document.createElement("div");
        chartBox.id = "uvalidate-chart-" + String(fieldName).replace(/[^A-Za-z0-9_-]/g, "_");
        msg.parentNode.insertBefore(chartBox, msg.nextSibling);
      }
      var holder = chartBox.appendChild(document.createElement("div"));
      holder.hidden = true;
      panels.push({ V: VS.all[pvi], holder: holder, parts: null, uid: chartBox.id + "-" + panels.length });
    }
    /* the room the field's cell gives a chart, 0 when it cannot be told */
    function chartWidth(){ return (chartBox && chartBox.clientWidth > 0) ? chartBox.clientWidth : 0; }
    function buildPanel(P){
      var V = P.V, why = V.chartWhy || {};
      P.parts = [];
      /* a chart or table left off (the page's cap, curves that cannot be
         sent, a "show" that does not fit the reference): staff are told why */
      function note(part){
        if(QRID_IS_SURVEY || typeof why[part] !== "string") return;
        var n = QRID_htmlEl("div", { margin: "4px 0", fontSize: "12px", color: "#555" }, P.holder);
        n.textContent = "\u2139 " + (part === "chart" ? "Growth chart" : "Table") + " not shown: " + why[part];
      }
      /* each part is built on its own: one that fails leaves the other */
      function add(make){
        try { var part = make(); P.parts.push(part); P.holder.appendChild(part.el); } catch(e){}
      }
      if(V.show.chart){
        if(typeof why.chart === "string") note("chart");
        else add(function(){ return QRID_growthChart(V, P.uid, chartWidth); });
      }
      if(V.show.table){
        if(typeof why.table === "string") note("table");
        else add(function(){ return QRID_growthTable(V); });
      }
    }
    /* Draw what the variants that apply show; only this visit's point is
       worked out here, and a chart moves its marker unless the sex or the
       window changed. A variant with an input this page was not sent shows
       nothing, as its check says. A part that fails is hidden on its own,
       and nothing here reaches the check. */
    function showPanels(act){
      if(!panels.length) return;
      var on = [];
      if(!act.conflict && !(act.length > 1 && !VS.grouped)) on = act;
      var typed = null;
      for(var pi = 0; pi < panels.length; pi++){
        var P = panels[pi];
        if(on.indexOf(P.V) < 0 || P.V.deferred || !P.V.growth || !P.V.growth.ref){ P.holder.hidden = true; continue; }
        var gp;
        try { gp = growthPoint(P.V); } catch(e){ gp = null; }
        if(!gp || gp.state === "withheld"){ P.holder.hidden = true; continue; }
        if(!P.parts) buildPanel(P);
        if(typed === null){
          try {
            typed = QRID_WHEN.readRef(fieldName, null);
            typed = (typed == null || typeof typed === "object" || QRID_isMissingCode(typed)) ? "" : String(typed);
          } catch(e){ typed = ""; }
        }
        var pt = null;
        try { pt = QRID_growthChartPoint(P.V, gp, typed); } catch(e){ pt = null; }
        for(var k = 0; k < P.parts.length; k++){
          try { P.parts[k].update(pt); P.parts[k].el.hidden = false; } catch(e){ P.parts[k].el.hidden = true; }
        }
        P.holder.hidden = false;
      }
    }
    /* Own guard item (see QRIDConstraintInit). __qridRecheck re-judges at save
       time: the note waits for the field to be left, the guard does not. */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} },
                __qridRecheck: function(){ check(); } };
      QRID_registerBlocker(GITEM, fieldName, "off");
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function inert(){ msg.style.display = "none"; msg.innerHTML = ""; setGuard(false); QRID_setModeState(input, "n", null); }
    /* A grey note for staff; never a block. */
    function infoNote(html){
      msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
        "font-size:13px;font-family:inherit;border:1px solid #cfcfcf;background:#f5f5f5;color:#555";
      msg.innerHTML = "&#8505; " + html;
      setGuard(false); QRID_setModeState(input, "n", null);
    }
    /* The values the rule returns (@UVRANGE "return"), written after every
       judgement of the field, whatever the verdict: a weight outside the limits
       still has its z-score. Each reference (branch group) fills its own
       fields. A group that cannot be told here (a deferral, a selector this
       page cannot read, an input on a form this page does not carry) leaves
       its fields as they are, and the save fills them. A branch conflict
       leaves every group's fields as they are, and the save keeps them too:
       the server returns nothing on a conflict. The first fill, made as the page opens, does not
       mark the form as changed. */
    var opening = true;
    function publish(act){
      if(!OWNED_ANY || QRID_returnDepth >= QRID_RETURN_DEPTH_MAX) return;
      if(act.conflict || (act.length > 1 && !VS.grouped)) return;
      /* nothing applies because the whole rule is deferred, or its selector
         cannot be read on this page */
      if(!act.length && (VS.unresolved || QRID_CONFIG.dynamicDeferred)) return;
      var byGroup = Object.create(null);
      for(var ai = 0; ai < act.length; ai++) byGroup[act[ai].group || ""] = act[ai];
      var writes = [], typed = null;
      for(var gk in OWNED){
        if(!Object.prototype.hasOwnProperty.call(OWNED, gk)) continue;
        var V = byGroup[gk], vals;
        if(!V || !V.returns) vals = {};      /* no branch of it applies, or the one that does returns nothing */
        else if(V.deferred) continue;
        else {
          if(typed === null){
            typed = QRID_WHEN.readRef(fieldName, null);
            if(QRID_isMissingCode(typed)) typed = "";
          }
          try { vals = returnValues(V, typed); } catch(e){ vals = null; }   /* fail open */
          if(vals === null) continue;
        }
        for(var oi = 0; oi < OWNED[gk].length; oi++){
          var of = OWNED[gk][oi];
          writes.push([of, Object.prototype.hasOwnProperty.call(vals, of) ? vals[of] : ""]);
        }
      }
      if(!writes.length) return;
      QRID_returnDepth++;
      try {
        for(var wi = 0; wi < writes.length; wi++) QRID_writeReturned(writes[wi][0], writes[wi][1], !opening);
      } finally { QRID_returnDepth--; }
    }
    /* What one active variant says about the typed value: { kind: "none" }
       (nothing to show), "deferral" (why), "info" (html, a grey note for
       staff), or "warn" / "bad" (html, block). */
    function judge(V, typed){
      if(V.onSave){
        /* not judged here, but the measurement is (L1). A Missing Data Code
           is not checked on save either, so it gets no note. */
        if(QRID_isMissingCode(typed)) return { kind: "none" };
        var mo = measureOnly(V, typed);
        if(mo) return { kind: "bad", html: describe(V, mo), block: blockFor(V, mo.tier, mo.reason) };
        return (V.deferredWhy && !QRID_IS_SURVEY)
          ? { kind: "info", html: (VS.grouped ? refSubject(V) + " not checked on this page: " : "Not checked on this page: ")
              + QRID_escapeHtml(V.deferredWhy.join(" ")) } : { kind: "none" };
      }
      if(V.deferred){
        return (V.deferredWhy && !QRID_IS_SURVEY)
          ? { kind: "deferral", why: V.deferredWhy, checkedOnSave: V.checkedOnSave === true } : { kind: "none" };
      }
      var r;
      try { r = verdictOf(V, typed); } catch(e){ return { kind: "none" }; }   /* fail open */
      if(r.tier === "withheld" || (r.tier === "inert" && r.why)){
        /* Staff are told why a growth check did not run; a survey respondent
           is told nothing. */
        if(QRID_IS_SURVEY) return { kind: "none" };
        return { kind: "info", html: r.tier === "withheld"
          ? "Not checked against " + QRID_escapeHtml(V.growth.ref ? V.growth.ref.title : "its growth reference") +
            " on this page: an input is on a form you cannot view here. It is checked when the record is saved."
          : "Not checked against " + QRID_escapeHtml(V.growth.ref.title) + ": " + QRID_escapeHtml(r.why) + "." };
      }
      if(r.tier !== "soft" && r.tier !== "hard") return { kind: "none" };
      var base = describe(V, r);
      /* "not a number" and "0 or below" use none of the saved values */
      if(V.snapshot && !QRID_IS_SURVEY && !measureReason(r)){
        /* A growth rule's saved values are its inputs (sex, dates, age, length),
           not a choice of limits; when a saved value also chose the branch
           (snapshotGate), the note says "based on", as @UVWINDOW does. */
        base += ' <span style="opacity:.8">(' + (V.growth ? (V.snapshotGate ? "based on " : "worked out with ") : "limits chosen from ") +
          QRID_escapeHtml(V.snapshot.join(", ")) +
          ", read when this page was opened — reload if it has changed since." +
          " This check does not block saving; it is re-checked after the save.)</span>";
      }
      return { kind: r.tier === "soft" ? "warn" : "bad", html: base, block: blockFor(V, r.tier, r.reason) };
    }
    var ICON = { warn: "&#9888; ", bad: "&#10007; ", info: "&#8505; ", deferral: "&#9888; " };
    /* With several references, a line that is not a verdict says which
       reference it is about (a verdict names its table already). */
    function refSubject(V){
      return QRID_escapeHtml(V.group === "" ? "The limits without a reference are" : 'Reference "' + V.group + '" is');
    }
    var BLOCK_RANK = { off: 0, confirm: 1, hard: 2 };
    function check(){
      var act = QRID_activeVariants(VS);
      publish(act);
      showPanels(act);
      if(!act.length){
        /* No branch applies. A rule left off for the table cap is checked on
           save only where it applies, so it has nothing to say here; any other
           deferral, and a selector this page cannot resolve, keeps its notice. */
        var capOnly = QRID_CONFIG.deferred && QRID_CONFIG.deferredOnSave === true && !QRID_CONFIG.dynamicDeferred;
        if(!capOnly && QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "n")){ setGuard(false); QRID_setModeState(input, "n", null); return; }
        inert(); return;
      }
      if(act.conflict || (act.length > 1 && !VS.grouped)){ QRID_renderConflict(msg, input, act, "n"); setGuard(false); return; }
      var typed = QRID_WHEN.readRef(fieldName, null);
      if(act.length === 1){
        var j = (act[0].deferred || !QRID_isMissingCode(typed)) ? judge(act[0], typed) : { kind: "none" };
        if(j.kind === "deferral"){
          QRID_renderDeferralNotice(msg, input, j.why, "n", j.checkedOnSave, VS.grouped ? refSubject(act[0]) : "");
          setGuard(false); QRID_setModeState(input, "n", null);
          return;
        }
        if(j.kind === "info"){ infoNote(j.html); return; }
        if(j.kind === "none"){ inert(); return; }
        styleMsg(msg, j.kind);
        QRID_setModeState(input, "n", j.kind);
        setGuard(true, j.block);
        msg.innerHTML = ICON[j.kind] + j.html;
        return;
      }
      /* Several references on one field (branch groups): every one judges the
         value, the note has a line for each that has something to say, and
         the worst decides the colour and the guard. A Missing Data Code is
         judged by none, but a reference that is not checked still says so,
         as with one reference. */
      var missing = QRID_isMissingCode(typed);
      var lines = [], seen = Object.create(null), worst = null, block = "off", deferral = false;
      for(var ai = 0; ai < act.length; ai++){
        var one = (act[ai].deferred || !missing) ? judge(act[ai], typed) : { kind: "none" };
        if(one.kind === "none") continue;
        var line = ICON[one.kind]
          + (one.kind === "deferral" ? QRID_deferralText(one.why, one.checkedOnSave, refSubject(act[ai])) : one.html);
        if(!seen[line]){ seen[line] = true; lines.push(line); }
        if(one.kind === "deferral") deferral = true;
        if(one.kind === "bad") worst = "bad";
        else if(one.kind === "warn" && worst !== "bad") worst = "warn";
        if(one.block && BLOCK_RANK[one.block] > BLOCK_RANK[block]) block = one.block;
      }
      if(!lines.length){ inert(); return; }
      if(worst === null){
        /* each line keeps its own icon; amber when a reference is not checked */
        msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;font-size:13px;font-family:inherit;" +
          "border:1px solid " + (deferral ? "#d9c48a;background:#fdf8e6;color:#7a5c00" : "#cfcfcf;background:#f5f5f5;color:#555");
        msg.innerHTML = lines.join("<br>");
        setGuard(false); QRID_setModeState(input, "n", null);
        return;
      }
      styleMsg(msg, worst);
      QRID_setModeState(input, "n", worst);
      setGuard(true, block);
      msg.innerHTML = lines.join("<br>");
    }
    /* Typing clears the note; leaving the field (or a change from a script,
       a calc or the slider) judges the value. The self-watch below also hears
       every keystroke, so it stays quiet while the person is in the field. */
    function typing(){ return typeof document !== "undefined" && document.activeElement === input; }
    if(input.addEventListener){
      input.addEventListener("input", function(){ if(msg.style.display !== "none") inert(); });
      input.addEventListener("change", function(){ check(); });
      input.addEventListener("blur", function(){ check(); });
    }
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(function(){ if(!typing()) check(); });
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(function(){ check(); });
      for(var wi = 0; wi < VS.all[gi].watch.length; wi++) VS.all[gi].watch[wi].onChange(function(){ check(); });
    }
    check();
    opening = false;
    return true;
  }
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "range", mode: { range: true, configError: configError },
      test: function(){
        if(configError) return null;   /* any variant's error (QRID_buildVariants): nothing is checked */
        var a = QRID_activeVariants(VS);
        if(!a.length || a.conflict || (a.length > 1 && !VS.grouped)) return null;
        if(QRID_isMissingCode(QRID_WHEN.readRef(f, null))) return null;
        /* every reference must pass; one that cannot judge leaves the answer open */
        var open = false;
        for(var ti = 0; ti < a.length; ti++){
          if(a[ti].deferred){
            if(a[ti].onSave && measureOnly(a[ti], QRID_WHEN.readRef(f, null))) return false;
            open = true; continue;
          }
          var t;
          try { t = verdictOf(a[ti], QRID_WHEN.readRef(f, null)).tier; } catch(e){ open = true; continue; }   /* fail open, as check() */
          if(t === "soft" || t === "hard") return false;
          if(t !== "ok") open = true;
        }
        return open ? null : true;
      } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- required validator (@UVREQUIRED) -------------------------------------
   The INVERSE emptiness rule: a BLANK field is the violation (every other mode
   is inert on blank). Optional "when" makes the requirement conditional —
   REDCap's own required flag can do neither conditionality nor a real block.
   Required mode never judges the VALUE; pair it with @UVALIDATE / @UVASSERT
   (the modes compose, each with its own guard item). A satisfied requirement
   CLEARS the notice rather than showing a green OK — green would falsely
   suggest the value itself was validated. tests/required_dom_js.cjs locks this
   contract; UniversalValidator::auditRule (type "required") is the server twin. */
function QRIDRequiredInit(QRID_CONFIG){
  function styleMsg(el){
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);   /* requirement gate */
    /* A rule the server could not resolve must not enforce here either. Branch
       machinery is shared by every mode, so an unresolved SELECTOR would
       otherwise hand control to the fallback branch and make its requiredness
       stick (H-01) — this factory previously had no notion of deferral at all. */
    var DEFERRED = !configError && !!cfg.deferred;
    if(DEFERRED) BLOCK = "off";
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             deferred: DEFERRED,
             deferredWhy: (cfg.deferredWhy && cfg.deferredWhy.length) ? cfg.deferredWhy : null,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             mode: { required: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = VS.firstBlock !== "off";

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-r")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-r", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "r");
    /* Own guard item (see QRIDConstraintInit): independent invalid/block state
       so required composes with any other mode on the same input. */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off", __qridType: QRID_CONFIG.type,
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} },
                /* re-judged at save: a "when" over a calc flipped without an
                   event must not let a blank field through (REQ-3) */
                __qridRecheck: function(){ check(); } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function clear(){ msg.style.display = "none"; QRID_setModeState(input, "r", null); setGuard(false); }
    function check(){
      /* REDCap hides the row of a field whose branching logic is false (or
         that carries @HIDDEN), leaves it out of its own required check, and
         erases the value on save. Nobody can answer a field they cannot see,
         so it is not required while hidden (REQ-1). */
      if(QRID_fieldHidden(input)){ clear(); return; }
      var act = QRID_activeVariants(VS);
      if(!act.length){
        /* no requirement in force — unless no branch could be CHOSEN at all,
           which is a rule problem and has to be said out loud (M-01). */
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "r")){ setGuard(false); QRID_setModeState(input, "r", null); return; }
        clear(); return;
      }
      if(act.length > 1){                               /* branch conflict: show, never block */
        styleMsg(msg);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The requirement was <b>NOT</b> enforced; make the conditions mutually exclusive.';
        QRID_setModeState(input, "r", null); setGuard(false); return;
      }
      var V = act[0];
      /* deferred: the server could not resolve this rule's references (or its
         branch selector), so it enforces nothing here. Say why on staff forms;
         never block. Mirrors QRIDConstraintInit. */
      if(V.deferred){
        if(V.deferredWhy && !QRID_IS_SURVEY){
          QRID_renderDeferralNotice(msg, input, V.deferredWhy, "r");
        } else {
          msg.style.display = "none"; msg.innerHTML = "";
        }
        QRID_setModeState(input, "r", null); setGuard(false); return;
      }
      var blank = QRID_isBlank(QRID_WHEN.readRef(fieldName, null));
      if(!blank){ clear(); return; }                    /* satisfied — no green OK on purpose */
      styleMsg(msg);
      QRID_setModeState(input, "r", "bad");
      setGuard(true, V.blockSave);
      msg.innerHTML = "&#10007; " + (V.message ? QRID_ruleHtml(V, "message")
        : "This field must not be left blank" + (V.when && !QRID_IS_SURVEY ? " while: " + QRID_escapeHtml(V.when) : "") + ".");
    }
    var debounced = QRID_debounced(function(){ check(); });
    if(input.addEventListener){
      input.addEventListener("input", debounced);
      input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(); });
      input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(); });
    }
    /* Watch the field ITSELF through the shared when-registry: a synthetic
       "[field]<>''" gate is never evaluated for the verdict (readRef+trim is),
       it exists purely so requestField wires the ___radio / select / hidden-
       mirror listeners this factory would otherwise have to duplicate. */
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(function(){ check(); });
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(function(){ check(); });
    }
    /* the row is shown or hidden by REDCap's doBranching, with no event */
    QRID_onBranching(function(){ check(); });
    check();
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "required", mode: { required: true, configError: configError },
      test: function(){ var a = QRID_activeVariants(VS);
        if(a.length !== 1 || QRID_fieldHidden(QRID_findAnchor(f))) return null;
        return !QRID_isBlank(QRID_WHEN.readRef(f, null)); } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* Where a dropdown's hidden options wait: one unnamed, disabled select per
   field, inside one hidden box on the page. Kept out of the page entirely,
   they missed REDCap's Multi-Language Management, which rewrites every
   [data-mlm-field][data-mlm-type=enum] element it finds (inside #center on a
   data entry form, anywhere on a survey). An option hidden before a language
   switch came back in the old language, and one hidden before MLM's first pass
   was never translated at all, because the engine binds first. A select with
   no name is never submitted, never matched by a select[name=...] lookup, and
   the box sits outside every field's row. null when the page offers no place
   to put it; the caller then takes options out of the page as before. */
function QRID_choiceParking(fieldName){
  try {
    if(!document.createElement || !document.getElementById) return null;
    var box = document.getElementById("uvalidate-choice-parking");
    if(!box){
      var host = document.getElementById("center") || document.body;
      if(!host || !host.appendChild) return null;
      box = document.createElement("div");
      box.setAttribute("id", "uvalidate-choice-parking");
      box.setAttribute("aria-hidden", "true");
      box.hidden = true;
      if(box.style) box.style.display = "none";
      host.appendChild(box);
    }
    var sel = document.createElement("select");
    sel.disabled = true;
    sel.tabIndex = -1;
    sel.setAttribute("data-uv-parking", fieldName);
    box.appendChild(sel);
    return sel;
  } catch(e){ return null; }
}
/* ---- choice filter (@UVCHOICES) --------------------------------------------
   Dynamic choice filtering: show/hide individual options of a radio, dropdown
   or checkbox field while a condition holds (REDCap's @HIDECHOICE is static).
   Each branch carries a "show" whitelist or "hide" blacklist; the hidden set
   is show ? complement over choicesAll (the field's full code list, attached
   server-side from the data dictionary) : the hide list. Rendering:
     dropdown  — hidden <option>s are physically REMOVED from the select and
                 re-inserted in their original order when unhidden (Safari
                 ignores hidden/display:none on <option>, removal is the one
                 portable way); they wait in a hidden parking select
                 (QRID_choiceParking) so a language switch still reaches them;
     radio     — the input named <field>___radio with value=code hides its
                 parent wrapper; the hidden mirror input is never touched;
     checkbox  — the input named __chk__<field>_RC_<code> hides its parent.
   A currently-SELECTED choice is NEVER hidden or cleared: it stays visible
   (dropdown: kept and marked stale, never disabled, so the browser still
   submits it), the field is flagged invalid with the message,
   and blockSave (off/confirm/hard) decides whether the save is challenged —
   silently destroying an entered value is the one thing this mode must never
   do. A value outside choicesAll is out of scope, and so is a project Missing
   Data Code, even one that is also a choice code (CHO-3): the server skips
   such a field (php/modes.json "missingCodes":"skip").
   tests/choices_dom_js.cjs locks this contract (tests/choices_fixture.json is
   the hidden-set contract); UniversalValidator::ruleFindings (type "choices")
   is the server twin. */
function QRIDChoiceFilterInit(QRID_CONFIG){
  function styleMsg(el){
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var all = (cfg.choicesAll && cfg.choicesAll.length) ? cfg.choicesAll.map(String) : null;
    var hiddenSet = Object.create(null);
    var hiddenList = [];
    if(!configError){
      if(cfg.choicesShow && cfg.choicesShow.length){
        if(!all){
          /* the complement of a whitelist is undefined without the full list —
             fail visible, never guess (the server attaches choicesAll to every
             valid rule; only a hand-built config can get here) */
          configError = 'a "show" list needs the field\'s full choice list (choicesAll) — ' +
            "the module attaches it from the data dictionary; re-save the field/rule.";
        } else {
          var showSet = Object.create(null);
          for(var s = 0; s < cfg.choicesShow.length; s++) showSet[String(cfg.choicesShow[s])] = true;
          for(var a = 0; a < all.length; a++){
            if(!showSet[all[a]]){ hiddenSet[all[a]] = true; hiddenList.push(all[a]); }
          }
        }
      } else if(cfg.choicesHide && cfg.choicesHide.length){
        for(var h = 0; h < cfg.choicesHide.length; h++){
          var hc = String(cfg.choicesHide[h]);
          if(!hiddenSet[hc]){ hiddenSet[hc] = true; hiddenList.push(hc); }
        }
      } else {
        configError = 'the choices rule carries neither a "show" nor a "hide" list.';
      }
    }
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             all: all, hiddenSet: hiddenSet, hiddenList: hiddenList,
             mode: { choices: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = VS.firstBlock !== "off";

  /* Every code any variant may hide, plus the full list when known — the set
     of choice elements this factory manages (hides AND restores). */
  var MANAGED = (function(){
    var seen = Object.create(null), out = [];
    for(var i = 0; i < VS.all.length; i++){
      var v = VS.all[i];
      var src = v.all || v.hiddenList;
      for(var j = 0; j < src.length; j++){
        if(!seen[src[j]]){ seen[src[j]] = true; out.push(src[j]); }
      }
    }
    return out;
  })();

  function attach(fieldName){
    /* Checkbox fields carry NO element named <field> — fall back to the first
       __chk__ option input so the message region and guard have a home. */
    var input = QRID_findAnchor(fieldName);
    var isCheckbox = !input;   /* only checkbox fields carry no element named <field> */
    if(!input){
      for(var mi = 0; mi < MANAGED.length && !input; mi++){
        var chkEls = document.getElementsByName ? document.getElementsByName("__chk__" + fieldName + "_RC_" + MANAGED[mi]) : [];
        if(chkEls.length) input = chkEls[0];
      }
    }
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-cf")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-cf", "1");
    var acAnchor = document.getElementById ? document.getElementById("rc-ac-input_" + fieldName) : null;
    var msg = QRID_attachMsgRegion(acAnchor || input, fieldName, "ch");
    /* A checkbox anchor is the FIRST option's input, so the region landed inside
       that option's row and vanished with it whenever the filter hid the row:
       the save was blocked with no message on screen. It belongs to the option
       container, after the last row. */
    if(isCheckbox && msg.parentNode && msg.parentNode === input.parentNode && input.parentNode.parentNode){
      var optionRow = input.parentNode, optionBox = optionRow.parentNode;
      optionRow.removeChild(msg);
      optionBox.appendChild(msg);
    }
    /* The anchor of a radio or checkbox field is a hidden mirror input, which
       @READONLY leaves enabled while it disables the options the person would
       have to click. Such a field can never be corrected here, so it must never
       hold the save (UX-003 applied to the inputs that actually matter). The
       test is the one every factory shares (QRID_fieldLocked). */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName, isCheckbox ? MANAGED : null)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                __qridRecheck: function(){ check(); },
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }

    /* ---- the three renderers (kind detected per call — late DOM tolerant) */
    var select = (input.tagName || "").toLowerCase() === "select" ? input : null;
    var selectOrig = [];   /* options retain their original parent and order */
    var parking = null;    /* where hidden options wait (QRID_choiceParking) */
    var activeHidden = Object.create(null), autocompleteInput = null;
    function inSelect(o){
      var at = o.parentNode;
      return !!at && (at === select || at.parentNode === select);
    }
    function snapshotOptions(){
      /* An option this module did not park that has left the select means its
         options were replaced (a piping refresh, another module, a script).
         The old nodes were put back by the next render, so every choice showed
         twice (CHO-5). The select's options are then the list: every entry not
         in it is dropped, and the old nodes waiting in the parking select go.
         selectOrig keeps its identity, which the expando below compares. */
      var rebuilt = false, d;
      for(d = 0; d < selectOrig.length && !rebuilt; d++){
        if(!selectOrig[d].parked && !inSelect(selectOrig[d].node)) rebuilt = true;
      }
      if(rebuilt){
        var kept = 0;
        for(d = 0; d < selectOrig.length; d++){
          var e = selectOrig[d];
          if(inSelect(e.node)){ selectOrig[kept++] = e; continue; }
          e.node.__qridSnap = null;
          if(e.parked && e.node.parentNode && e.node.parentNode.removeChild) e.node.parentNode.removeChild(e.node);
        }
        selectOrig.length = kept;
      }
      function visit(parent){
        for(var i = 0; i < parent.children.length; i++){
          var o = parent.children[i], tag = (o.tagName || "").toLowerCase();
          if(tag === "optgroup"){ visit(o); continue; }
          if(tag !== "option") continue;
          /* An expando, not a scan of selectOrig: the scan made every check()
             quadratic in the option count (a 3,000-option dropdown blocked the
             main thread for seconds per selection). */
          if(o.__qridSnap === selectOrig) continue;
          o.__qridSnap = selectOrig;
          selectOrig.push({ node: o, parent: parent, disabled: !!o.disabled, parked: false });
        }
      }
      visit(select); // also discover options populated after page initialization
    }
    function autocompleteCode(item){
      if(item && item.option && item.option.value != null) return String(item.option.value);
      /* REDCap's dropdown autocomplete carries the choice code on the item.
         Matching by label alone refused a permitted option whenever another
         choice of the same field shared its label. */
      if(item && item.code != null && String(item.code) !== "") return String(item.code);
      /* REDCap versions/widgets differ: some return codes, others option
         labels. Resolve labels against this field only, never another menu. */
      var value = item && item.value != null ? String(item.value) : String(item);
      var label = item && item.label != null ? String(item.label) : value;
      var found = null;
      for(var i = 0; i < selectOrig.length; i++){
        var o = selectOrig[i].node, code = String(o.value), text = String(o.text || o.textContent || "");
        if(value === code) return code;
        if(label === text || value === text){
          if(found !== null && found !== code) return null; // ambiguous labels cannot authorize an option
          found = code;
        }
      }
      return found;
    }
    function syncAutocomplete(changed){
      if(!select || !document.getElementById) return;
      var ac = document.getElementById("rc-ac-input_" + fieldName);
      if(!ac) return;
      var jq = typeof window !== "undefined" && window.jQuery;
      if(ac !== autocompleteInput){
        autocompleteInput = ac;
        /* Keep REDCap's select + companion adjacency intact; its widget
           initialization uses the companion next to the underlying select. */
        if(ac.parentNode && ac.parentNode.parentNode){
          ac.parentNode.parentNode.insertBefore(msg, ac.parentNode.nextSibling);
        }
        var desc = ac.getAttribute("aria-describedby") || "";
        if((" " + desc + " ").indexOf(" " + msg.id + " ") < 0)
          ac.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
        if(jq && jq.fn && jq.fn.on){
          jq(ac).on("autocompleteresponse.uvChoices", function(event, ui){
            if(!ui || !ui.content) return;
            var filtering = false;
            for(var hiddenCode in activeHidden){ filtering = true; break; }
            if(!filtering) return;
            for(var i = ui.content.length - 1; i >= 0; i--){
              var code = autocompleteCode(ui.content[i]);
              if(code === null || activeHidden[code]) ui.content.splice(i, 1);
            }
          });
          /* Some widgets update the select after their selection callback. */
          jq(ac).on("autocompleteselect.uvChoices autocompletechange.uvChoices", function(){
            setTimeout(check, 0);
          });
        }
      }
      QRID_setModeState(ac, "ch", input.__qridModeState && input.__qridModeState.ch || null);
      if(changed && jq && jq.fn && jq.fn.autocomplete){
        try { jq(ac).autocomplete("close"); } catch(e){} // late widget initialization is legitimate
      }
    }
    function renderSelect(hiddenSet){
      snapshotOptions();
      var changed = false;
      for(var k in activeHidden) if(!hiddenSet[k]) changed = true;
      for(var k2 in hiddenSet) if(!activeHidden[k2]) changed = true;
      activeHidden = hiddenSet;
      var cur = select.value == null ? "" : String(select.value);
      /* Walked BACKWARDS so each parent carries its own insertion reference:
         the nearest following option that is in place. Searching forward for it
         per restored option was quadratic when a whole list came back. */
      for(var p = 0; p < selectOrig.length; p++) selectOrig[p].parent.__qridNext = null;
      for(var i = selectOrig.length - 1; i >= 0; i--){
        var entry = selectOrig[i], o = entry.node, parent = entry.parent;
        var val = o.value == null ? "" : String(o.value);
        var isCur = (val !== "" && val === cur);
        var show = (val === "") || isCur || !hiddenSet[val];
        if(show && o.parentNode !== parent){
          if(o.parentNode && o.parentNode.removeChild) o.parentNode.removeChild(o);   /* out of the parking select */
          parent.insertBefore(o, parent.__qridNext || null);
          entry.parked = false;
          changed = true;
        } else if(!show && o.parentNode === parent){
          parent.removeChild(o);
          if(!parking || parking.parentNode == null) parking = QRID_choiceParking(fieldName);
          if(parking) parking.appendChild(o);
          entry.parked = true;   /* held here, in the parking select or out of the page */
          changed = true;
        }
        /* The kept stale answer is MARKED, never disabled. A browser leaves a
           disabled selected <option> out of the form submission, so under
           blockSave "off" or "Save anyway" the answer on screen was not the
           answer REDCap received: an edit was silently discarded and the audit
           never saw it. Disabling bought nothing anyway — the stale option is
           already a dead end, removed the moment another choice is picked.
           `disabled` is only ever restored to what REDCap itself set. */
        if(o.parentNode === parent) parent.__qridNext = o;
        var stale = !!(isCur && hiddenSet[val]);
        if(o.disabled !== entry.disabled){ o.disabled = entry.disabled; changed = true; }
        if(!!o.__qridStale !== stale){
          o.__qridStale = stale;
          if(o.setAttribute && o.removeAttribute){
            if(stale) o.setAttribute("data-uv-stale", "1"); else o.removeAttribute("data-uv-stale");
          }
          if(o.style){
            if(stale){ entry.color = o.style.color || ""; o.style.color = "#8a8a8a"; }
            else o.style.color = entry.color || "";
          }
          changed = true;
        }
      }
      /* Option insertion/removal can change native selection. Restore the
         exact answer without dispatching change or altering saved data. */
      if(String(select.value) !== cur) select.value = cur;
      syncAutocomplete(changed);
    }
    function optionWrappers(code){   /* checkbox: a radio's come from renderOptions */
      var out = [];
      /* checkbox: the hidden mirror AND (REDCap 17.x) the visible checkbox both
         sit inside the same choicevert row, so hiding either one's parent hides
         the whole option — include both so the row hides on every rendering. */
      var chk = document.getElementsByName ? document.getElementsByName("__chk__" + fieldName + "_RC_" + code) : [];
      for(var c = 0; c < chk.length; c++) out.push(chk[c]);
      var vis = document.getElementById ? document.getElementById("id-__chk__" + fieldName + "_RC_" + code) : null;
      if(vis) out.push(vis);
      return out;
    }
    function renderOptions(hiddenSet){   /* radio + checkbox: hide the wrapper row */
      var buttons = QRID_enhancedChoiceButtons(fieldName);
      /* A radio field's options in one pass, and its value read once, per
         render (CHO-1). Looking each code up by walking every radio made a
         400-option radio cost 160,803 element visits per change. */
      var radioRows = null, cur = null;
      if(!isCheckbox){
        radioRows = Object.create(null);
        var radios = document.getElementsByName ? document.getElementsByName(fieldName + "___radio") : [];
        for(var r = 0; r < radios.length; r++){
          var rc = String(radios[r].value);
          (radioRows[rc] || (radioRows[rc] = [])).push(radios[r]);
        }
        cur = QRID_whenTrim(QRID_WHEN.readRef(fieldName, null));
      }
      for(var i = 0; i < MANAGED.length; i++){
        var code = MANAGED[i];
        /* a checked/selected option is NEVER hidden from under the user. The
           checked state is read robustly (QRID_readCheckbox) because a checkbox
           option's .checked cannot be read off the hidden mirror on REDCap 17. */
        var selected = isCheckbox ? (QRID_readCheckbox(fieldName, code) === "1") : (cur === code);
        var hide = !!hiddenSet[code] && !selected;
        var els = isCheckbox ? optionWrappers(code) : (radioRows[code] || []);
        for(var j = 0; j < els.length; j++) QRID_choiceDisplay(els[j].parentNode, hide);
        var btns = buttons[code] || [];
        for(var b = 0; b < btns.length; b++) QRID_choiceDisplay(btns[b], hide);
      }
    }
    function render(hiddenSet){
      if(select) renderSelect(hiddenSet);
      else renderOptions(hiddenSet);
    }
    /* codes currently SELECTED that the active filter hides (the stale set) */
    function staleCodes(V){
      var out = [];
      if(!isCheckbox){                                     /* dropdown / radio: one scalar value */
        var v = QRID_whenTrim(QRID_WHEN.readRef(fieldName, null));
        if(v !== "" && V.hiddenSet[v] && (!V.all || QRID_inList(V.all, v)) && !QRID_isMissingCode(v)) out.push(v);
        return out;
      }
      for(var i = 0; i < V.hiddenList.length; i++){        /* checkbox: any checked hidden code */
        var code = V.hiddenList[i];
        if(QRID_WHEN.readRef(fieldName, code) === "1") out.push(code);
      }
      return out;
    }
    var NONE = Object.create(null);
    /* A radio or checkbox anchor is a type=hidden mirror, which assistive
       technology never reaches: the verdict is reflected onto the inputs a
       person can actually focus, and the message region is tied to them. */
    function reachable(){
      var out = [], els, i, k;
      if(select || !document.getElementsByName) return out;
      els = document.getElementsByName(fieldName + "___radio");
      for(i = 0; i < els.length; i++) out.push(els[i]);
      els = document.getElementsByName("__chkn__" + fieldName);
      for(i = 0; i < els.length; i++) out.push(els[i]);
      for(k = 0; isCheckbox && document.getElementById && k < MANAGED.length; k++){
        var vis = document.getElementById("id-__chk__" + fieldName + "_RC_" + MANAGED[k]);
        if(vis && out.indexOf(vis) < 0) out.push(vis);
      }
      return out;
    }
    function setState(kind){
      QRID_setModeState(input, "ch", kind);
      var els = reachable();
      for(var i = 0; i < els.length; i++){
        var el = els[i];
        if(!el.setAttribute || !el.getAttribute) continue;
        if(kind === "bad") el.setAttribute("aria-invalid", "true");
        else if(el.removeAttribute) el.removeAttribute("aria-invalid");
        var desc = el.getAttribute("aria-describedby") || "";
        if((" " + desc + " ").indexOf(" " + msg.id + " ") < 0) el.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
      }
    }
    function clear(){ msg.style.display = "none"; setGuard(false); setState(null); }
    function check(){
      try { checkValue(); } finally { syncAutocomplete(false); }
    }
    function checkValue(){
      var act = QRID_activeVariants(VS);
      if(!act.length){
        /* no filter in force — everything shown. Unless no branch could be
           CHOSEN at all, which is a rule problem, not an empty filter (M-01). */
        render(NONE);
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "ch")){ setGuard(false); setState(null); return; }
        clear(); return;
      }
      if(act.length > 1){                                  /* branch conflict: show, never filter, never block */
        render(NONE);
        styleMsg(msg);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The choice filter was <b>NOT</b> applied; make the conditions mutually exclusive.';
        setGuard(false); setState(null); return;
      }
      var V = act[0];
      render(V.hiddenSet);
      var stale = staleCodes(V);
      if(!stale.length){ clear(); return; }
      styleMsg(msg);
      setGuard(true, V.blockSave);
      setState("bad");
      msg.innerHTML = "&#10007; " + (V.message ? QRID_ruleHtml(V, "message")
        : "The selected choice is no longer available" +
          (V.when && !QRID_IS_SURVEY ? " while: " + QRID_escapeHtml(V.when) : "") +
          " — pick one of the choices shown (the current answer is kept until you do).");
    }
    var debounced = QRID_debounced(function(){ check(); });
    if(input.addEventListener){
      input.addEventListener("input", debounced);
      input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(); });
      input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(); });
    }
    /* Watch the field ITSELF through the shared when-registry (see
       QRIDRequiredInit): synthetic gates are never evaluated for the verdict,
       they exist so requestField wires the select / ___radio / __chk__
       listeners this factory would otherwise duplicate. */
    /* ONE callback object for every watcher below, so the registry keeps a
       single entry per watched field: a change runs check() once, not once per
       managed code plus once per branch. */
    var recheck = function(){ check(); };
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(recheck);
    /* Per-code elements exist only for checkbox fields; a dropdown or radio is
       fully covered by the field watcher above. */
    for(var mc = 0; isCheckbox && mc < MANAGED.length; mc++){
      var codeWatch = QRID_WHEN.gateFor("[" + fieldName + "(" + MANAGED[mc] + ")]='1'", null);
      if(codeWatch) codeWatch.onChange(recheck);
    }
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(recheck);
    }
    check();
    /* REDCap builds a survey's enhanced choice buttons when the page is
       ready, which may be after this bind: draw the filter again once the
       page has loaded. */
    if(!select && typeof window !== "undefined" && window.addEventListener && document.readyState !== "complete"){
      window.addEventListener("load", function(){ check(); });
    }
    if(select && typeof MutationObserver !== "undefined"){
      var optionObserver = new MutationObserver(function(){ check(); });
      optionObserver.observe(select, { childList: true, subtree: true });
      /* The widget may be installed after this module's DOMContentLoaded. */
      if(select.parentNode && (autocompleteInput || /(^|\s)rc-autocomplete(\s|$)/.test(select.className || ""))){
        var widgetObserver = new MutationObserver(function(){
          syncAutocomplete(false);
          if(autocompleteInput) widgetObserver.disconnect();
        });
        widgetObserver.observe(select.parentNode, { childList: true, subtree: true });
      }
    }
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users):
     test() = "no stale selection under the single active filter" */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "choices", mode: { choices: true, configError: configError },
      test: function(){ var a = QRID_activeVariants(VS);
        if(a.length !== 1) return null;
        var v = QRID_whenTrim(QRID_WHEN.readRef(f, null));
        if(v !== "" && QRID_isMissingCode(v)) return null;   /* not judged (CHO-3) */
        if(v !== "") return !a[0].hiddenSet[v];
        for(var i = 0; i < a[0].hiddenList.length; i++){
          if(QRID_WHEN.readRef(f, a[0].hiddenList[i]) === "1") return false;
        }
        return true; } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* Hide or show an element the choices filter manages. Showing gives back the
   display the element had before the filter hid it, so an option row or a
   button that REDCap or a script hid stays hidden (CHO-4). With enhanced
   choices REDCap hides the original list, which must not come back beside
   the buttons. */
function QRID_choiceDisplay(el, hide){
  if(!el || !el.style) return;
  if(hide){
    if(!el.__qridHidden){ el.__qridDisplay = el.style.display || ""; el.__qridHidden = true; }
    if(el.style.display !== "none") el.style.display = "none";   /* written only when it changes (CHO-1) */
  } else if(el.__qridHidden){
    el.style.display = el.__qridDisplay;
    el.__qridHidden = false;
  }
}
/* REDCap's survey option "Enhanced radio buttons and checkboxes" draws each
   choice again as a button, <div class="enhancedchoice"><label comps=
   "field,code,1" for="opt-field_1">, and hides the original list. A
   respondent clicks the button, so a filtered choice hides its button too
   (CHO-ENH). comps names the field first and the code last (REDCap codes
   hold no comma); a label without comps is matched by its "for", which names
   the radio (opt-<field>_<code>) or the checkbox (id-__chk__<field>_RC_<code>).
   Read on every render: the buttons may be built after the engine bound the
   field. Returns code => [div.enhancedchoice, ...]. */
function QRID_enhancedChoiceButtons(fieldName){
  var out = Object.create(null);
  if(typeof document === "undefined" || !document.querySelectorAll) return out;
  var labels;
  try { labels = document.querySelectorAll(".enhancedchoice label"); } catch(e){ return out; }
  var radioFor = "opt-" + fieldName + "_", chkFor = "id-__chk__" + fieldName + "_RC_";
  for(var i = 0; labels && i < labels.length; i++){
    var lab = labels[i], code = null;
    var parts = String((lab.getAttribute && lab.getAttribute("comps")) || "").split(",");
    if(parts.length >= 3){
      if(parts[0] === fieldName) code = parts[parts.length - 1];
    } else {
      var target = String((lab.getAttribute && lab.getAttribute("for")) || "");
      if(target.indexOf(chkFor) === 0) code = target.slice(chkFor.length);
      else if(target.indexOf(radioFor) === 0) code = target.slice(radioFor.length);
    }
    var box = lab.parentNode;
    if(code === null || code === "" || !box) continue;
    (out[code] = out[code] || []).push(box);
  }
  return out;
}
/* ES5-safe Array#indexOf membership (shared helper for the choices mode). */
function QRID_inList(list, v){

  for(var i = 0; i < list.length; i++){ if(list[i] === v) return true; }
  return false;
}
/* ---- unique validator (@UVUNIQUE) -----------------------------------------
   No-duplicates across records — the one check the browser cannot do alone.
   The server answers through the framework's JavaScript Module Object
   (module.ajax, CSRF-protected, survey-aware); the module injects the JSMO
   and its path as config.jsmoName only when a unique rule is live. The
   endpoint re-derives scope/composite from stored rules and only answers for
   fields carrying a unique rule (no existence oracle); surveys are answered
   only when the rule opted in ("surveys": true), boolean-only. Every failure
   here FAILS OPEN — a network error or missing transport never traps a save;
   the server audit is the race/backstop net. tests/unique_dom_js.cjs locks
   this contract; UniversalValidator::redcap_module_ajax is the server twin. */
function QRID_ajaxTransport(){
  var name = (typeof QRID_COMBINED_CONFIG === "object" && QRID_COMBINED_CONFIG) ? QRID_COMBINED_CONFIG.jsmoName : null;
  if(typeof name !== "string" || !name) return null;
  try {
    var obj = name.split(".").reduce(function(o, k){ return (o && o[k] !== undefined) ? o[k] : null; }, window);
    if(obj && typeof obj.ajax === "function") return obj;
  } catch(e){}
  return null;
}
/* The transport's first name, from when @UVUNIQUE was its only user. */
var QRID_uniqueTransport = QRID_ajaxTransport;
function QRIDUniqueInit(QRID_CONFIG){
  function styleMsg(el, kind){   /* true=free, false=used, "info"=checking, "warn"=could not check */
    var c = (kind === true) ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "info") ? "#c9dbf5;background:#eef3fb;color:#3a567f"
          : (kind === "warn") ? "#d9c48a;background:#fdf8e6;color:#7a5c00"
          : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             localAsts: cfg.uniqueRecordAsts || {},
             uniqueWith: (cfg.uniqueWith && cfg.uniqueWith.length) ? cfg.uniqueWith.slice() : [],
             surveys: !!cfg.uniqueSurveys,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             mode: { unique: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = VS.firstBlock !== "off";

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-q")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-q", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "q");
    QRID_followWidget(input, msg, fieldName);
    var localGates = VS.all.map(function(v){return v.localAsts[fieldName] ? QRID_WHEN.gateFor("1=1",v.localAsts[fieldName],QRID_BLANK_PASSES,true) : null;});
    var GITEM = null;
    if(ANY_BLOCK && !configError && !QRID_fieldLocked(input, fieldName)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function setPending(on){ if(GITEM) GITEM.__qridPending = !!on; }
    function inert(){ msg.style.display = "none"; QRID_setModeState(input, "q", null); setGuard(false); setPending(false); QRID_setModeState(input, "q", null); }
    var seq = 0;            /* stale-response guard: only the LATEST request may render */
    var pendingKey = null;  /* candidate key already asked, awaiting an answer */
    /* Answer cache. The direct input listeners and the when-registry self-watch
       BOTH recheck on a change; the cache turns the second pass (and a gate
       re-flip over the same values) into a synchronous render instead of a
       duplicate server request. */
    var answers = QRID_answerCache(16);
    /* The last payload that got no usable answer, and why: Save does not ask
       it again (a server that cannot answer would hold every click). */
    var failedKey = null, failedWhy = null;
    var dirty = {};         /* field name => typed since its last change/blur */
    function typing(){ for(var k in dirty){ if(Object.prototype.hasOwnProperty.call(dirty, k) && dirty[k]) return true; } return false; }
    /* No answer: say so on staff forms, as @UVEXISTS does, and never block.
       A throttled server, a failed read and no answer in time all land here
       (UNQ-3); before, the notice simply went away. */
    function unknown(why){
      if(QRID_IS_SURVEY){ inert(); return; }
      styleMsg(msg, "warn");
      QRID_setModeState(input, "q", "warn");
      setGuard(false);
      setPending(false);
      msg.innerHTML = "&#9888; Could not check whether this value is already recorded just now" +
        (why ? " (" + QRID_escapeHtml(String(why)) + ")" : "") + ".";
    }
    /* Throttled: the server answers again within the minute (a refused
       check does not count toward the limit). A rule that blocks holds the
       save until then instead of letting an unchecked value through, and
       Save asks again (UNQ-3). */
    function held(why){
      unknown(why);
      setPending(true);
      msg.innerHTML += " The save waits until it can be checked.";
    }
    function renderResp(resp, V){
      if(resp.used){
        styleMsg(msg, false);
        QRID_setModeState(input, "q", "bad");
        setGuard(true, V.blockSave);
        QRID_setModeState(input, "q", "bad");
        /* resp.field: the "also" field the value was found in (staff only). */
        msg.innerHTML = "&#10007; " + (V.message ? QRID_ruleHtml(V, "message")
            : "This value is already recorded" +
              (resp.field ? " in field <b>" + QRID_escapeHtml(String(resp.field)) + "</b>" : "") +
              (resp.record ? " (record <b>" + QRID_escapeHtml(String(resp.record)) + "</b>)" : "") + ".");
      } else {
        styleMsg(msg, true);
        QRID_setModeState(input, "q", "ok");
        setGuard(false);
        QRID_setModeState(input, "q", "ok");
        msg.innerHTML = "&#10003; Not used before.";
      }
    }
    var activeVariant = null;
    function check(saving){
      QRID_followWidget(input, msg, fieldName);
      var act = QRID_activeVariants(VS);
      var nextVariant = act.length === 1 ? act[0] : null;
      if(activeVariant !== nextVariant){ ++seq; pendingKey = null; setPending(false); activeVariant = nextVariant; }
      if(!act.length){
        /* inert — unless no branch could be CHOSEN at all (M-01). */
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "q")){ setGuard(false); QRID_setModeState(input, "q", null); return; }
        inert(); return;
      }
      if(act.length > 1){
        styleMsg(msg, false);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The value was <b>NOT</b> checked; make the conditions mutually exclusive.';
        QRID_setModeState(input, "q", null); setGuard(false); return;
      }
      var V = act[0];
      /* Surveys are opt-in per rule: a used/free answer is record-derived
         information a respondent would not otherwise see (SEC-005 posture). */
      if(QRID_IS_SURVEY && !V.surveys){ inert(); return; }
      var val = QRID_lookupTrim(QRID_WHEN.readRef(fieldName, null));
      /* A reply still on its way is for a value this field no longer holds. */
      if(val === "" || QRID_isMissingCode(val)){ ++seq; pendingKey = null; inert(); return; }
      var localGate = localGates[VS.all.indexOf(V)];
      if(localGate){
        var valid = localGate.active();
        if(localGate.unresolved()){
          inert();
          if(!QRID_IS_SURVEY) QRID_renderDeferralNotice(msg,input,["Current values cannot resolve record uniqueness. Save and reload to refresh other entries."],"q");
        } else {
          renderResp({used:!valid},V);
          if(valid) msg.innerHTML = "&#10003; No duplicate among the other saved entries in this record.";
        }
        return;
      }
      var payload = { field: fieldName, values: {}, cond: QRID_condValues(VS.all) };
      payload.values[fieldName] = val;
      /* A record not saved when the page was drawn: its id is REDCap's guess
         (auto-numbering), and a record another user saved under that id since
         is not "this record" (UNQ-6). */
      if(QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.newRecord === true) payload.newRecord = true;
      for(var wi = 0; wi < V.uniqueWith.length; wi++){
        var w = V.uniqueWith[wi];
        /* A "with" field this page does not carry is left out: the server
           reads its saved value (another form, or another survey page). */
        if(!QRID_WHEN.has(w, null)) continue;
        payload.values[w] = QRID_lookupTrim(QRID_WHEN.readRef(w, null));
      }
      var key = JSON.stringify([payload.values, payload.cond]);
      var cached = answers.get(key);
      /* Save asks again about a cached answer older than QRID_RECHECK_MS,
         "used" or "free": another record may have changed since, and a
         "free" given twenty minutes ago let a value saved meanwhile through
         (UNQ-4). The guard holds the save meanwhile. A re-ask that gets no
         usable answer keeps the old one. */
      var prior = (cached && saving === true && typeof cached.used === "boolean" && QRID_answerStale(cached)) ? cached : null;
      if(cached && !prior){
        /* A cached answer is the latest word: an older request still in
           flight must not paint over it when it lands. */
        ++seq; pendingKey = null; setPending(false);
        renderResp(cached, V); return;
      }
      if(pendingKey === key) return;                    /* already asked; the answer will render */
      if(saving === true && failedKey === key && !prior){ ++seq; pendingKey = null; unknown(failedWhy); return; }
      function keepPrior(){ answers.put(key, QRID_stampAnswer(prior)); renderResp(prior, V); }
      var t = QRID_ajaxTransport();
      if(!t){
        try { if(typeof console !== "undefined" && console.error) console.error("Universal Field Validator: no AJAX transport for the uniqueness check — rule inert."); } catch(e){}
        inert(); return;
      }
      var my = ++seq;
      pendingKey = key;
      /* pending: informative, and it holds a save click until the answer
         comes (fail open if none comes within QRID_PENDING_MS) */
      styleMsg(msg, "info");
      msg.innerHTML = "&#8230; checking whether this value is already recorded&hellip;";
      QRID_setModeState(input, "q", "info");
      setGuard(false);
      setPending(V.blockSave !== "off");
      QRID_setModeState(input, "q", null);
      setTimeout(function(){
        if(my === seq && pendingKey === key){
          ++seq; pendingKey = null; setPending(false);
          if(prior){ keepPrior(); return; }
          failedKey = key; failedWhy = "no answer in time"; unknown(failedWhy);
        }
      }, QRID_PENDING_MS);
      function render(err, resp){
        if(my !== seq) return;   /* a newer keystroke superseded this answer */
        pendingKey = null;
        setPending(false);
        /* "unknown": the server could not settle it (its read budget, a
           failed read). Not an error, and not "free" either. */
        var unsettled = !err && resp && !resp.error && resp.unknown === true;
        if(err || !resp || resp.error || unsettled || typeof resp.used === "undefined"){
          if(!unsettled){
            try { if(typeof console !== "undefined" && console.error) console.error("Universal Field Validator: uniqueness check failed — " + (err || (resp && resp.error) || "bad response")); } catch(e){}
          }
          if(prior){ keepPrior(); return; }
          if(unsettled && resp.throttled === true && V.blockSave !== "off" && !QRID_IS_SURVEY){
            failedKey = null; held(typeof resp.why === "string" ? resp.why : null); return;
          }
          failedKey = key;
          failedWhy = (resp && typeof resp.why === "string" && resp.why !== "") ? resp.why : null;
          unknown(failedWhy); return;   /* fail open; the server audit is the net */
        }
        answers.put(key, QRID_stampAnswer(resp));
        renderResp(resp, V);
      }
      try {
        var p = t.ajax("unique-check", payload);
        if(p && typeof p.then === "function"){
          p.then(function(r){ render(null, r); }, function(e){ render(e || "rejected", null); });
        } else {
          render("no promise from transport", null);
        }
      } catch(e){ render(e, null); }
    }
    /* Typing clears the verdict; change and blur ask, as @UVEXISTS does
       (UNQ-3). Asking after every pause sent one request per character, and
       a clerk entering a few records a minute ran into the server's limit of
       checks a minute. */
    function watchTyping(name, el){
      if(!el || !el.addEventListener) return;
      el.addEventListener("input", function(){
        dirty[name] = true;
        if(name === fieldName || msg.style.display !== "none"){ ++seq; pendingKey = null; inert(); }
      });
      var settleNow = function(){ dirty[name] = false; check(); };
      el.addEventListener("change", settleNow);
      el.addEventListener("blur", settleNow);
    }
    dirty[fieldName] = false;
    watchTyping(fieldName, input);
    /* Save re-judges the field first, typing or not: the click ends the
       typing, and a change no event reported (REDCap's radio "reset" link)
       must not leave a stale verdict deciding the save. */
    if(GITEM) GITEM.__qridRecheck = function(){ for(var dk in dirty) dirty[dk] = false; check(true); };
    /* watch the field itself and every composite member through the shared
       when-registry (same trick as QRIDRequiredInit — wiring only). Asked
       at once, unless someone is typing: a picked option or a flipped
       condition is a settled value, and a typed one asks on change or blur. */
    var settle = function(){ if(!typing()) check(); };
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(settle);
    for(var vi = 0; vi < VS.all.length; vi++){
      if(VS.all[vi].gate) VS.all[vi].gate.onChange(settle);
      if(localGates[vi]) localGates[vi].onChange(settle);
      for(var wj = 0; wj < VS.all[vi].uniqueWith.length; wj++){
        var wname = VS.all[vi].uniqueWith[wj];
        var ww = QRID_WHEN.gateFor("[" + wname + "]<>''", null);
        if(ww) ww.onChange(settle);
        /* typing in a text "with" field is typing too */
        if(!Object.prototype.hasOwnProperty.call(dirty, wname)){
          var wel = QRID_findAnchor(wname);
          var wtag = wel ? (wel.tagName || "").toLowerCase() : "";
          var wty = wel ? (wel.type || "").toLowerCase() : "";
          if(wtag === "textarea" || (wtag === "input" && wty !== "hidden" && wty !== "radio" && wty !== "checkbox")){
            dirty[wname] = false; watchTyping(wname, wel);
          }
        }
      }
    }

    check();
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "unique", mode: { unique: true, configError: configError } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- exists validator (@UVEXISTS) ------------------------------------------
   The value must already be saved somewhere in the project: a record ID, or a
   value of the field named in "in". Where to look is NOT on this page - the
   server strips it (ModeRegistry::clientShape) and the exists-check endpoint
   re-reads it from the stored rule. The page sends the field's value and the
   values of its "match" fields; a match field not on this page is sent blank
   and read from the saved record by the server.
   Requests go out on change/blur and once on load, never per keystroke:
   typing clears the verdict, so a half-typed value never reads "not found".
   Answers: found (green), not-found (red; blocks per blockSave), unknown
   (amber "could not check"; never blocks). Every failure is unknown.
   A rule with "return" fills fields of this form from the entry found: a
   found answer carries the values (or why none were copied: several entries
   that disagree, or one outside the user's Data Access Group, and the fields
   keep what they hold); not-found, a blank value and a rule that does not
   apply empty them; unknown leaves them. The save writes the same values
   (UniversalValidator::returnsExists).
   tests/exists_dom_js.cjs and tests/exists_return_dom_js.cjs lock this
   contract; existsCheck() in UniversalValidator.php is the server twin. */
function QRIDExistsInit(QRID_CONFIG){
  function styleMsg(el, kind){   /* "ok" | "bad" | "warn" | "info" */
    var c = (kind === "ok") ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "warn") ? "#d9c48a;background:#fdf8e6;color:#7a5c00"
          : (kind === "info") ? "#c9dbf5;background:#eef3fb;color:#3a567f"
          : "#e0b4b0;background:#fbeceb;color:#c62828";
    el.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
      "font-size:13px;font-family:inherit;border:1px solid " + c;
  }
  function makeVariant(cfg){
    var configError = cfg.configErrorOverride || "";
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             deferred: !configError && !!cfg.deferred,
             deferredWhy: (cfg.deferredWhy && cfg.deferredWhy.length) ? cfg.deferredWhy : null,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
             whereMessage: (typeof cfg.whereMessage === "string" && cfg.whereMessage !== "") ? cfg.whereMessage : "",
             i18n: (cfg.i18n && typeof cfg.i18n === "object") ? cfg.i18n : null,
             locals: (cfg.existsLocal && cfg.existsLocal.length) ? cfg.existsLocal.slice() : [],
             /* the searched field, when it is on this form (EXS-6): its value
                here is what this record holds, so it goes with the lookup */
             selfIn: (typeof cfg.existsSelfIn === "string" && cfg.existsSelfIn !== "") ? cfg.existsSelfIn : null,
             returns: (cfg.existsReturnFields && cfg.existsReturnFields.length) ? cfg.existsReturnFields.slice() : [],
             numbers: (cfg.existsReturnNumbers && cfg.existsReturnNumbers.length) ? cfg.existsReturnNumbers.slice() : [],
             surveys: cfg.existsSurveys === true,
             when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
             mode: { exists: true } };
  }

  var VS = QRID_buildVariants(QRID_CONFIG, makeVariant);
  var configError = VS.configError;
  var ANY_BLOCK = VS.firstBlock !== "off";
  function trim(v){ return QRID_lookupTrim(v); }
  /* The fields the rule fills from the entry it finds ("return"): every
     branch's, since the rule owns them all, and a branch that is not picked
     (or none) empties them, as the save does (UniversalValidator::ruleFindings,
     ownedReturns). NUMBERS: those that hold numbers, where "7.80" is "7.8";
     any other is written exactly ("007" is not "7"). */
  var OWNED = [], NUMBERS = Object.create(null);
  for(var ovi = 0; ovi < VS.all.length; ovi++){
    for(var ori = 0; ori < VS.all[ovi].returns.length; ori++){
      if(OWNED.indexOf(VS.all[ovi].returns[ori]) < 0) OWNED.push(VS.all[ovi].returns[ori]);
    }
    for(var oni = 0; oni < VS.all[ovi].numbers.length; oni++) NUMBERS[VS.all[ovi].numbers[oni]] = true;
  }
  var OWNED_ANY = OWNED.length > 0;

  function attach(fieldName){
    var input = QRID_findAnchor(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound-x")) return true;   /* per-mode bind marker */
    if(input.setAttribute) input.setAttribute("data-qrid-bound-x", "1");
    var msg = QRID_attachMsgRegion(input, fieldName, "x");
    QRID_followWidget(input, msg, fieldName);
    var GITEM = null;
    /* A rule that fills fields holds the save while its answer is on the way,
       even with "blockSave":"off": the values come with the answer. A field
       the user cannot edit (@READONLY) never blocks the save (UX-003, see
       QRID_registerBlocker), but a rule that fills fields still holds it
       while its answer is on the way, and no longer than QRID_PENDING_MS. */
    var EDITABLE = !QRID_fieldLocked(input, fieldName);
    if(!configError && ((ANY_BLOCK && EDITABLE) || OWNED_ANY)){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName), __qridLabelEl: input,
                readOnly: false, disabled: false,
                focus: function(){ try { QRID_focusTarget(input, fieldName).focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, EDITABLE ? VS.firstBlock : "off");
    }
    function setGuard(invalid, mode){ if(GITEM && EDITABLE){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function setPending(on){ if(GITEM) GITEM.__qridPending = !!on; }
    function inert(){ msg.style.display = "none"; msg.innerHTML = ""; setGuard(false); setPending(false); QRID_setModeState(input, "x", null); }
    var seq = 0;            /* stale-response guard: only the LATEST request may render */
    var pendingKey = null;  /* payload already asked, awaiting an answer */
    var answers = QRID_answerCache(16);   /* found / not-found answers only */
    /* The last payload that got no usable answer (unknown, an error, or none
       in time), and why. Save does not ask it again: a server that cannot
       answer would otherwise hold every click for another QRID_PENDING_MS. */
    var unanswered = { key: null, why: null };
    var dirty = {};         /* field name => typed since its last change/blur */
    function typing(){ for(var k in dirty){ if(Object.prototype.hasOwnProperty.call(dirty, k) && dirty[k]) return true; } return false; }
    /* No promise about later: when the reason is a blank "match" field the
       save has nothing to check either. */
    function unknown(V, why){
      if(QRID_IS_SURVEY){ inert(); return; }
      styleMsg(msg, "warn");
      QRID_setModeState(input, "x", "warn");
      setGuard(false);
      setPending(false);
      msg.innerHTML = "&#9888; Could not check this value just now" +
        (why ? " (" + QRID_escapeHtml(String(why)) + ")" : "") + ".";
    }
    /* Throttled: as @UVUNIQUE does, a rule that blocks holds the save until
       the server answers again, and Save asks again (UNQ-3). */
    function held(V, why){
      unknown(V, why);
      setPending(true);
      msg.innerHTML += " The save waits until it can be checked.";
    }
    /* Write the fields the rule fills: vals is field -> text, and a field it
       does not name keeps what it holds. quiet for the fill made as the page
       opens: it does not mark the form as changed (QRID_writeReturned). A
       survey page never fills: a rule that fills fields is not open to
       surveys, and a survey save writes nothing. Returns the dropdown and
       radio fields that were emptied because the page does not offer the
       code to copy. */
    function fill(vals, quiet){
      var lost = [];
      if(!OWNED_ANY || QRID_IS_SURVEY || QRID_returnDepth >= QRID_RETURN_DEPTH_MAX) return lost;
      QRID_returnDepth++;
      try {
        for(var fi = 0; fi < OWNED.length; fi++){
          var ff = OWNED[fi];
          if(Object.prototype.hasOwnProperty.call(vals, ff)) QRID_writeReturned(ff, vals[ff], !quiet, NUMBERS[ff] !== true, lost);
        }
      } finally { QRID_returnDepth--; }
      return lost;
    }
    function emptyOwned(quiet){
      var none = {};
      for(var ei = 0; ei < OWNED.length; ei++) none[OWNED[ei]] = "";
      fill(none, quiet);
    }
    function returnsOf(V){ return (V && V.returns) ? V.returns : []; }
    /* What a found answer copies: the picked branch's fields get the values
       the server sent (as the page shows them: a date in the field's order),
       the other fields the rule owns are emptied. A field the answer does not
       name keeps what it holds. */
    function foundValues(V, values){
      var out = {}, mine = returnsOf(V);
      for(var vi2 = 0; vi2 < OWNED.length; vi2++){
        var of2 = OWNED[vi2];
        if(mine.indexOf(of2) < 0) out[of2] = "";
        else if(Object.prototype.hasOwnProperty.call(values, of2) && typeof values[of2] === "string") out[of2] = values[of2];
      }
      return out;
    }
    /* Why a found answer copied nothing: the fields keep what they hold, and
       the save leaves them too (UniversalValidator::returnsExists). */
    function keptNote(resp){
      if(resp.fill === "conflict"){
        var n = typeof resp.count === "number" && resp.count > 1 ? String(Math.floor(resp.count)) : "Several";
        return n + " saved entries hold this value with different values to copy, so the fields this lookup fills are left as they are.";
      }
      if(resp.fill === "withheld"){
        return "It is saved outside your Data Access Group, so the fields this lookup fills are left as they are.";
      }
      return "";
    }
    function amberLine(text){ return '<div style="margin-top:4px;color:#7a5c00">&#9888; ' + QRID_escapeHtml(text) + "</div>"; }
    /* On a form the person may only read, or a locked one, nothing is filled
       (QRID_writeReturned). Whether what the lookup would copy differs from
       what the fields show, so the person can be told (EXS-2). */
    var READ_ONLY = !!(QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.formReadOnly === true);
    function differsFromShown(vals){
      for(var k in vals){
        if(!Object.prototype.hasOwnProperty.call(vals, k)) continue;
        var rel = QRID_returnElement(k);
        if(!rel || typeof rel.value !== "string") continue;
        var cur = rel.value, text = String(vals[k]);
        if(cur === text) continue;
        if(NUMBERS[k] === true && cur !== "" && text !== "" && QRID_sameNumber(cur, text)) continue;
        return true;
      }
      return false;
    }
    function renderResp(resp, V, quiet){
      if(resp.state === "found"){
        styleMsg(msg, "ok");
        QRID_setModeState(input, "x", "ok");
        setGuard(false);
        var kept = returnsOf(V).length ? keptNote(resp) : "";
        var html = "&#10003; Found" +
          (resp.record ? " (record <b>" + QRID_escapeHtml(String(resp.record)) + "</b>)" : "") + "." +
          (kept ? amberLine(kept) : "");
        msg.innerHTML = html;
        if(!kept && returnsOf(V).length && resp.values && typeof resp.values === "object" && READ_ONLY){
          if(differsFromShown(foundValues(V, resp.values))){
            msg.innerHTML = html + amberLine("The entry found now holds other values than this saved copy. This form cannot be changed here, so they are left as saved.");
          }
        } else if(!kept && returnsOf(V).length && resp.values && typeof resp.values === "object"){
          var at = seq, lost = fill(foundValues(V, resp.values), quiet);
          /* A write that set off a new judgement of this field (a "when"
             field it fills) left its own message: seq moved. */
          if(lost.length && seq === at){
            for(var lk = 0; lk < lost.length; lk++){
              html += amberLine("The value to copy into [" + lost[lk] + "] is not offered on this page, so the field is left empty.");
            }
            msg.innerHTML = html;
          }
        }
      } else if(resp.state === "not-found"){
        styleMsg(msg, "bad");
        QRID_setModeState(input, "x", "bad");
        setGuard(true, V.blockSave);
        /* filtered: saved, but in no entry that meets the rule's "where".
           The server says so to staff only; a survey gets the plain answer. */
        if(resp.filtered === true && !QRID_IS_SURVEY){
          msg.innerHTML = "&#10007; " + (V.whereMessage ? QRID_ruleHtml(V, "whereMessage")
            : "This value is saved, but not in an entry that meets this rule's conditions.");
        } else {
          msg.innerHTML = "&#10007; " + (V.message ? QRID_ruleHtml(V, "message")
            : "This value is not saved where it should be. Check it, or record it there first.");
        }
        if(READ_ONLY && returnsOf(V).length){
          var none = {};
          for(var ni = 0; ni < OWNED.length; ni++) none[OWNED[ni]] = "";
          if(differsFromShown(none)) msg.innerHTML += amberLine("This form cannot be changed here, so the fields this lookup fills are left as saved.");
        }
        emptyOwned(quiet);
      } else {
        unknown(V, resp.why);
      }
    }
    /* The values a lookup for V sends, as the page holds them now: the value
       searched, then each match field. A match field this page does not
       carry (another form, another survey page) is left out: the server
       reads its saved value. The searched field, when this page carries it,
       goes too: the record being edited holds what the page shows there, not
       what was saved (EXS-6). */
    function lookupValues(V){
      var out = {};
      out[fieldName] = trim(QRID_WHEN.readRef(fieldName, null));
      for(var li = 0; li < V.locals.length; li++){
        var lf = V.locals[li];
        if(QRID_WHEN.has(lf, null)) out[lf] = trim(QRID_WHEN.readRef(lf, null));
      }
      if(V.selfIn && QRID_WHEN.has(V.selfIn, null)) out[V.selfIn] = trim(QRID_WHEN.readRef(V.selfIn, null));
      return out;
    }
    /* One lookup: its values and the branch values sent with them. */
    function lookupKey(values, cond){ return JSON.stringify([values, cond]); }
    var activeVariant = null;
    var opening = true;
    function check(saving){
      /* Only the first judgement, made as the page opens, fills quietly. */
      var quiet = opening;
      opening = false;
      QRID_followWidget(input, msg, fieldName);
      var act = QRID_activeVariants(VS);
      var nextVariant = act.length === 1 ? act[0] : null;
      if(activeVariant !== nextVariant){ ++seq; pendingKey = null; setPending(false); activeVariant = nextVariant; }
      if(!act.length){
        /* No branch applies, or "when" is false: the fields are emptied. A
           rule this page cannot judge at all (deferred, or a selector it
           cannot read) leaves them for the save. */
        if(!VS.unresolved && !QRID_CONFIG.dynamicDeferred) emptyOwned(quiet);
        if(QRID_renderRuleDeferral(msg, input, QRID_CONFIG, "x")){ setGuard(false); setPending(false); QRID_setModeState(input, "x", null); return; }
        inert(); return;
      }
      if(act.length > 1){ QRID_renderConflict(msg, input, act, "x"); setGuard(false); setPending(false); return; }
      var V = act[0];
      if(V.deferred){
        if(V.deferredWhy && !QRID_IS_SURVEY){
          QRID_renderDeferralNotice(msg, input, V.deferredWhy, "x");
          setGuard(false); setPending(false); QRID_setModeState(input, "x", null);
          return;
        }
        inert(); return;
      }
      /* Surveys are opt-in per rule, as for @UVUNIQUE. */
      if(QRID_IS_SURVEY && !V.surveys){ inert(); return; }
      /* A branch without "return": the fields the other branches fill are
         emptied, whatever the answer. */
      if(!returnsOf(V).length) emptyOwned(quiet);
      var vals = lookupValues(V), val = vals[fieldName];
      if(val === "" || QRID_isMissingCode(val)){ ++seq; pendingKey = null; inert(); emptyOwned(quiet); return; }
      for(var li = 0; li < V.locals.length; li++){
        if(!Object.prototype.hasOwnProperty.call(vals, V.locals[li])) continue;
        var lv = vals[V.locals[li]];
        /* A match field on this page that is blank: nothing to narrow by
           yet, and the fields keep what they hold (as the save does). */
        if(lv === "" || QRID_isMissingCode(lv)){ ++seq; pendingKey = null; inert(); return; }
      }
      var payload = { field: fieldName, values: vals, cond: QRID_condValues(VS.all) };
      var key = lookupKey(vals, payload.cond);
      var cached = answers.get(key);
      /* A found answer of a rule that fills fields ages too: the entry it
         copies from may have changed since. Judging the field again after
         QRID_RECHECK_MS asks again; Save does not, it goes by what it has. */
      if(cached && cached.state === "found" && saving !== true && returnsOf(V).length && QRID_answerStale(cached)) cached = null;
      /* Save asks again about a cached "not found" older than QRID_RECHECK_MS
         when the rule can block: the value may have been saved elsewhere
         since (another tab, a colleague). The guard holds the save until the
         answer lands. A re-ask that gets no usable answer keeps the old one.
         A rule that cannot block holds the save only while a lookup is
         already on its way. */
      var prior = (cached && saving === true && cached.state === "not-found" && V.blockSave !== "off" && EDITABLE
                   && QRID_answerStale(cached)) ? cached : null;
      if(cached && !prior){
        /* A cached answer is the latest word: an older request still in
           flight must not paint over it when it lands. */
        ++seq; pendingKey = null; setPending(false);
        renderResp(cached, V, quiet); return;
      }
      if(pendingKey === key) return;
      if(saving === true && unanswered.key === key && !prior){ ++seq; pendingKey = null; unknown(V, unanswered.why); return; }
      function keepPrior(){ answers.put(key, QRID_stampAnswer(prior)); renderResp(prior, V, quiet); }
      var t = QRID_ajaxTransport();
      if(!t){
        try { if(typeof console !== "undefined" && console.error) console.error("Universal Field Validator: no AJAX transport for the lookup — rule inert."); } catch(e){}
        inert(); return;
      }
      var my = ++seq;
      pendingKey = key;
      /* pending: informative, and it holds a save click until the answer
         comes; no answer within QRID_PENDING_MS counts as "could not check" */
      styleMsg(msg, "info");
      msg.innerHTML = "&#8230; checking&hellip;";
      QRID_setModeState(input, "x", "info");
      setGuard(false);
      setPending((V.blockSave !== "off" && EDITABLE) || returnsOf(V).length > 0);
      setTimeout(function(){
        if(my === seq && pendingKey === key){
          ++seq; pendingKey = null; setPending(false);
          if(prior){ keepPrior(); return; }
          unanswered = { key: key, why: "no answer from the server" };
          unknown(V, unanswered.why);
        }
      }, QRID_PENDING_MS);
      function render(err, resp){
        if(my !== seq) return;
        /* An answer for values the page no longer holds is stale too: a
           change no event reported (a date picker, an autocomplete pick,
           @SETVALUE) reaches check() only after the silent-change poll and
           the pause, and the answer may land before. check() asks again. */
        if(lookupKey(lookupValues(V), QRID_condValues(VS.all)) !== key) return;
        pendingKey = null;
        setPending(false);
        if(err || !resp || resp.error || (resp.state !== "found" && resp.state !== "not-found" && resp.state !== "unknown")){
          try { if(typeof console !== "undefined" && console.error) console.error("Universal Field Validator: lookup failed — " + (err || (resp && resp.error) || "bad response")); } catch(e){}
          if(prior){ keepPrior(); return; }
          unanswered = { key: key, why: null };
          unknown(V, null); return;
        }
        if(resp.state === "unknown" && prior){ keepPrior(); return; }
        if(resp.state === "unknown" && resp.throttled === true && V.blockSave !== "off" && EDITABLE && !QRID_IS_SURVEY){
          unanswered = { key: null, why: null }; held(V, typeof resp.why === "string" ? resp.why : null); return;
        }
        if(resp.state !== "unknown") answers.put(key, QRID_stampAnswer(resp));

        else unanswered = { key: key, why: resp.why || null };
        renderResp(resp, V, quiet);
      }
      try {
        var p = t.ajax("exists-check", payload);
        if(p && typeof p.then === "function"){
          p.then(function(r){ render(null, r); }, function(e){ render(e || "rejected", null); });
        } else {
          render("no promise from transport", null);
        }
      } catch(e){ render(e, null); }
    }
    /* Typing clears the verdict; change and blur ask. */
    function watchTyping(name, el){
      if(!el || !el.addEventListener) return;
      el.addEventListener("input", function(){
        dirty[name] = true;
        if(name === fieldName || msg.style.display !== "none"){ ++seq; pendingKey = null; inert(); }
      });
      var settleNow = function(){ dirty[name] = false; check(); };
      el.addEventListener("change", settleNow);
      el.addEventListener("blur", settleNow);
    }
    watchTyping(fieldName, input);
    /* Save re-judges the field first, typing or not: the click ends the
       typing, and a change no event reported (REDCap's radio "reset" link)
       must not leave a stale verdict deciding the save. A value with no
       answer yet is asked now, and the guard holds the save until it lands. */
    if(GITEM) GITEM.__qridRecheck = function(){ for(var dk in dirty) dirty[dk] = false; check(true); };
    /* Anything else that changes (REDCap's date picker or autocomplete without
       a native event, a "match" field, a "when" field) reaches the shared
       when-registry. Asked after a pause, and only when nobody is typing. */
    var settle = QRID_debounced(function(){ if(!typing()) check(); });
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(settle);
    for(var vi = 0; vi < VS.all.length; vi++){
      if(VS.all[vi].gate){
        VS.all[vi].gate.onChange(settle);
        /* Typing in a text field a "when" condition reads is typing too. */
        var grefs = VS.all[vi].gate.refs ? VS.all[vi].gate.refs() : [];
        for(var gr = 0; gr < grefs.length; gr++){
          var gname = grefs[gr][0];
          if(grefs[gr][1] !== null || typeof gname !== "string" || Object.prototype.hasOwnProperty.call(dirty, gname)) continue;
          var gel = QRID_findAnchor(gname);
          var gtag = gel ? (gel.tagName || "").toLowerCase() : "";
          var gty = gel ? (gel.type || "").toLowerCase() : "";
          if(gtag === "textarea" || (gtag === "input" && gty !== "hidden" && gty !== "radio" && gty !== "checkbox")){
            dirty[gname] = false; watchTyping(gname, gel);
          }
        }
      }
      var watched = VS.all[vi].locals.slice();
      if(VS.all[vi].selfIn) watched.push(VS.all[vi].selfIn);
      for(var lj = 0; lj < watched.length; lj++){
        var lname = watched[lj];
        if(!Object.prototype.hasOwnProperty.call(dirty, lname)){ dirty[lname] = false; watchTyping(lname, QRID_findAnchor(lname)); }
        var lw = QRID_WHEN.gateFor("[" + lname + "]<>''", null);
        if(lw) lw.onChange(settle);
      }
    }
    check();
    return true;
  }
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "exists", mode: { exists: true, configError: configError } };
  });
  function boot(){
    if(configError){
      var missing = (QRID_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }
    function sweep(){ pending = pending.filter(function(f){ return !attach(f); }); if(!pending.length){ stop(); return true; } return false; }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){ tries++; if(sweep() || tries >= 20){ clearInterval(timer); stop(); } }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- pooled-field validator (factory: one instance per config/rule) ---- */
function QRIDPooledInit(QRID_MULTI_CONFIG){
  var ALPHA = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

  /* ---- one validation VARIANT: the whole mode-resolution + parser closure,
     built once per rule (legacy) or once per branch (branch rules). ---- */
  function makeVariant(cfg){

  /* ---- resolve the accepted ID formats from the config ----
     One rule = one or more ALTERNATES, each with its own pattern and its own
     check algorithm. A legacy scalar rule normalizes to exactly one alternate,
     so everything below is a single loop over ALTS rather than two code paths.
     CHECK_MODE/REGEX_ONLY become rule-level summaries: a MIXED rule is both. */
  var configError = cfg.configErrorOverride || "";
  var A0 = QRID_alternatesOf(cfg);
  configError = configError || A0.error;
  var ALTS = A0.list, HAS_ALTS = !!(cfg.alternates != null && cfg.alternates !== "");
  for(var ai = 0; ai < ALTS.length && !configError; ai++){
    var A = ALTS[ai], an = QRID_altName(A, ai);
    if(A.algorithm !== "none" && !Q.ALGORITHMS[A.algorithm]){
      configError = "Unknown algorithm \"" + A.algorithm + "\"" +
        (HAS_ALTS ? " on " + an : "") + ". Valid: " + Object.keys(Q.ALGORITHMS).join(", ") + ".";
      break;
    }
    A.check = (A.algorithm !== "none");
    if(A.pattern){
      var gA = QRID_gatePattern(A.pattern, HAS_ALTS ? an : "");
      if(gA.error){ configError = gA.error; break; }
      A.re = gA.re;
    } else { A.re = null; }
    A.regexOnly = (!A.check && !!A.re);
    if(!A.check && !A.regexOnly){
      configError = (HAS_ALTS ? an + ": algorithm" : "algorithm") + " is \"none\" and no " +
        (HAS_ALTS ? "pattern" : "idPattern") + " is set — nothing to validate. " +
        "Set the check algorithm your IDs were minted with, or (for a legacy project " +
        "without check characters) set " + (HAS_ALTS ? "pattern" : "idPattern") + " to your ID regex.";
      break;
    }
    A.source = A.source || "normalized_id";
    if(A.check && A.source !== "normalized_id" && A.source !== "digits_only" && A.source !== "sequence_only"){
      configError = 'Unknown source "' + A.source + '"' + (HAS_ALTS ? " on " + an : "") +
        " — use normalized_id, digits_only or sequence_only.";
      break;
    }
    A.scheme = A.check ? Q.makeScheme({
      algorithm: A.algorithm, source: A.source,
      placement: "append", delimiter: "-",
      normalize_rules: { strip_delimiters: A.strip || "", uppercase: true,
                         unify_unicode_dashes: true, keep_only: null },
      enabled: true
    }) : null;
    A.nCheck = A.check ? Q.ALGORITHMS[A.algorithm].nCheckChars : 0;
  }
  /* rule-level summaries, kept for the render layer and the public mode object */
  var CHECK_MODE = false, REGEX_ONLY = false, MIXED = false;
  if(!configError){
    var nChk = 0;
    for(var si = 0; si < ALTS.length; si++) if(ALTS[si].check) nChk++;
    CHECK_MODE = nChk > 0;
    REGEX_ONLY = nChk === 0;
    MIXED      = nChk > 0 && nChk < ALTS.length;
  }
  /* ---- validate the length configuration ---- */
  function isPosInt(x){ return typeof x === "number" && isFinite(x) && x > 0 && x % 1 === 0; }
  /* Two sources of truth for one fact is the bug this repo keeps re-litigating:
     with alternates, lengths are declared per alternate and the rule-level keys
     must be absent. That also makes the idMaxLen < 2 x idMinLen branch below
     unreachable on the alternates path — only the union test survives. */
  if(!configError && HAS_ALTS){
    if(cfg.idPattern) configError = "a rule with \"alternates\" must not also set a rule-level " +
      "\"pattern\" — give each alternate its own.";
    else if(cfg.idLengths != null || cfg.idMinLen !== undefined || cfg.idMaxLen !== undefined)
      configError = "a rule with \"alternates\" must not also set rule-level \"idLengths\", " +
        "\"idMinLen\" or \"idMaxLen\" — give each alternate its own \"lengths\".";
    else for(var qi = 0; qi < ALTS.length; qi++)
      if(!ALTS[qi].lengths){
        configError = QRID_altName(ALTS[qi], qi) + " needs \"lengths\" — a pooled rule splits a run " +
          "at member boundaries, so every format must say how long its IDs are.";
        break;
      }
  }
  var minLen = cfg.idMinLen === undefined ? 8 : cfg.idMinLen;
  var maxLen = cfg.idMaxLen === undefined ? 14 : cfg.idMaxLen;
  var LENS = [];
  if(!configError && HAS_ALTS){
    /* The safety proofs below are properties of the WHOLE length universe, not
       of one alternate: two alternates that are individually safe ([9] and
       [5,4]) are jointly unsafe, because 9 = 5 + 4 lets one 9-char window
       swallow a real 5 and a real 4. So the union is what gets tested. */
    var _uSeen = {};
    for(var ui = 0; ui < ALTS.length; ui++)
      for(var uj = 0; uj < ALTS[ui].lengths.length; uj++){
        var uL = ALTS[ui].lengths[uj];
        if(!_uSeen[uL]){ _uSeen[uL] = 1; LENS.push(uL); }
      }
    LENS.sort(function(a, b){ return a - b; });
    minLen = LENS[0]; maxLen = LENS[LENS.length - 1];
    if(maxLen > QRID_MAX_ID_LEN){
      configError = "ID lengths above " + QRID_MAX_ID_LEN + " characters are not supported.";
    } else {
      var swA = QRID_swallowSum(LENS);
      if(swA) configError = "the alternates produce ID lengths " + LENS.join(", ") + ", and " +
        swA.target + " = " + swA.parts.join(" + ") + " — one \"member\" could swallow " +
        swA.parts.length + " real ones and still verify, so a mis-scan would be reported as a " +
        "clean ID. Give the alternates lengths where no length is the sum of two or more others, " +
        "or split them into separate fields.";
    }
  } else if(!configError && cfg.idLengths != null){
    var lensCfg = cfg.idLengths;
    if(!(lensCfg instanceof Array) || !lensCfg.length || !lensCfg.every(isPosInt)){
      configError = "idLengths must be a list of positive whole numbers, e.g. [10] — got " +
        JSON.stringify(lensCfg) + ".";
    } else {
      /* Deduplicate (and integer-normalize) to mirror the server's pooledState
         (array_unique + intval), so the length count, the sum-swallow check, and
         the per-rule scan cap are all computed from the same |LENS| on both
         runtimes (F6 — a duplicate length otherwise shrank only the browser's
         scan cap, so a mid-length pooled value got no client verdict but was
         still audited server-side). */
      var _seenL = {};
      LENS = [];
      lensCfg.forEach(function(x){ var n = x | 0; if(!_seenL[n]){ _seenL[n] = 1; LENS.push(n); } });
      LENS.sort(function(a, b){ return a - b; });
      minLen = LENS[0]; maxLen = LENS[LENS.length - 1];
      if(LENS.length > QRID_MAX_LEN_CHOICES){
        configError = "idLengths lists " + LENS.length + " lengths — at most " +
          QRID_MAX_LEN_CHOICES + " are supported.";
      } else if(maxLen > QRID_MAX_ID_LEN){
        configError = "ID lengths above " + QRID_MAX_ID_LEN + " characters are not supported.";
      }
      /* a member length reachable by adding two OR MORE of the others would let
         one token swallow that many real members */
      if(!configError){
        var sw = QRID_swallowSum(LENS);
        if(sw) configError = "idLengths " + JSON.stringify(LENS) + " is unsafe: " +
          sw.target + " = " + sw.parts.join(" + ") + ", so one \"member\" could swallow " +
          sw.parts.length + " real ones. Split such projects into separate fields/rules.";
      }
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
    } else if(maxLen > QRID_MAX_ID_LEN){
      /* cap BEFORE the range loop below — a huge range must not even allocate */
      configError = "idMaxLen (" + maxLen + ") is above the supported maximum of " + QRID_MAX_ID_LEN + ".";
    }
    if(!configError){
      minLen = Math.max(minLen, ALTS[0].nCheck + 1);
      for(var _L = minLen; _L <= maxLen; _L++) LENS.push(_L);
    }
  }
  /* Every alternate that did not declare its own lengths inherits the rule-level
     set — which is exactly the legacy single-alternate case, so PAIRS below is
     byte-for-byte today's LENS and the DP is unchanged. */
  var PAIRS = [], RESCAN = [];
  if(!configError){
    for(var pi2 = 0; pi2 < ALTS.length; pi2++)
      if(!ALTS[pi2].lengths) ALTS[pi2].lengths = LENS;
    /* Bucketed, NOT sorted: length ascending on the outside keeps the DP's
       early `break` valid and preserves "shortest member wins ties"; declaration
       order on the inside gives precedence. A comparator would order ties
       differently on PHP 7.4, where usort is not stable, and the two runtimes
       would silently disagree.
       The winning alternate's identity never reaches a segment, so two
       alternates accepting one token produce identical output and betterThan
       needs no extra criterion. Feed alternate identity into the DP score and
       that guarantee is gone. */
    for(var li3 = 0; li3 < LENS.length; li3++){
      /* Shape ownership: the check-bearing alternates that also produce IDs of
         this length. A token any of them shapes as its own must pass that
         check, so a format-only pair here may not accept it (verifiesAs). A
         null entry is a check-bearing alternate without a pattern, which owns
         every token of its lengths. Twin of pooledState's owners (php). */
      var owners = [];
      for(var oj = 0; oj < ALTS.length; oj++)
        if(ALTS[oj].check && ALTS[oj].lengths.indexOf(LENS[li3]) >= 0) owners.push(ALTS[oj].re);
      for(var aj = 0; aj < ALTS.length; aj++)
        if(ALTS[aj].lengths.indexOf(LENS[li3]) >= 0)
          PAIRS.push({ len: LENS[li3], alt: aj, a: ALTS[aj], owners: ALTS[aj].regexOnly ? owners : [] });
    }
    for(var ri = 0; ri < PAIRS.length; ri++)
      if(PAIRS[ri].a.check && PAIRS[ri].a.re) RESCAN.push(PAIRS[ri]);
    if(!PAIRS.length){
      configError = "no candidate ID length survived — check the declared lengths.";
    } else if(PAIRS.length > QRID_MAX_LEN_CHOICES){
      /* the same bound the single-format rule has always had: this IS the
         candidate list the parser walks at every position (PER-002) */
      configError = "the rule declares " + PAIRS.length + " format/length combinations — at most " +
        QRID_MAX_LEN_CHOICES + " are supported.";
    }
  }
  if(!configError && cfg.expectedIds != null
     && (!isPosInt(cfg.expectedIds) || cfg.expectedIds > QRID_MAX_EXPECTED)){
    configError = "expectedIds must be a positive whole number up to " + QRID_MAX_EXPECTED + ".";
  }
  if(!configError && cfg.keepChars){
    var keepCfg = String(cfg.keepChars);
    if(keepCfg.length > QRID_MAX_KEEP){
      configError = "keepChars is limited to " + QRID_MAX_KEEP + " characters.";
    } else if(/[^\x20-\x7E]/.test(keepCfg)){
      configError = "keepChars must contain printable ASCII characters only " +
        "(Unicode dashes in VALUES are unified automatically before checking).";
    }
  }

  /* Mirror study_id_patterns.validate_concatenated_ids cleaning: unify unicode
     dashes, uppercase, then keep ONLY A-Z 0-9 (+ configured keepChars) — so
     spaces, commas, newlines and stray separators all disappear before splitting.
     Also automatically kept: the check algorithm's special characters (e.g. the
     "*" that iso7064_mod37_2 can emit) and any literal separator the ID regex
     itself uses (-, /, _, ...), so the printed form still matches. */
  function keepFor(A){
    var K = ALPHA + (cfg.keepChars || "");
    if(A.check){
      var CA = Q.ALGORITHMS[A.algorithm].checkAlphabet || "";
      for(var _c = 0; _c < CA.length; _c++)
        if(K.indexOf(CA.charAt(_c)) < 0) K += CA.charAt(_c);
    }
    if(A.re){
      var _lit = QRID_patternLiterals(A.pattern);
      for(var _li = 0; _li < _lit.length; _li++)
        if(K.indexOf(_lit.charAt(_li)) < 0) K += _lit.charAt(_li);
    }
    return K;
  }
  /* clean() runs ONCE over the whole pooled string, before anything is split,
     so there is one KEEP set for the field: the union over the alternates.
     That union is also a hazard — an algorithm that can emit "*" would make "*"
     survive for a sibling alternate that cannot contain it, changing the string
     recorded for that sibling. checkFragment refuses a rule whose alternates
     disagree about KEEP unless the designer declares the union in keepChars. */
  var KEEP = ALPHA + (cfg.keepChars || "");
  if(!configError){
    for(var ki = 0; ki < ALTS.length; ki++){
      var Ki = keepFor(ALTS[ki]);
      for(var kc = 0; kc < Ki.length; kc++)
        if(KEEP.indexOf(Ki.charAt(kc)) < 0) KEEP += Ki.charAt(kc);
    }
  }
  function clean(v){
    return Q.normalize(v, { strip_delimiters: "", uppercase: true, unify_unicode_dashes: true,
      keep_only: KEEP });
  }
  /* Per-rule scan cap: the full QRID_MAX_POOLED_LEN unless this rule's length
     configuration makes parsing expensive, in which case the cap shrinks so
     one parse stays inside QRID_POOLED_WORK_BUDGET char-ops (PER-002). Mirrors
     CheckCharacter::pooledScanCap (php) — keep the formula identical. */
  /* PAIRS, not LENS: the candidate list the parser walks at every position is
     now (alternate, length) pairs. For a single-format rule PAIRS.length IS
     LENS.length, so every existing rule keeps exactly the cap it has today. */
  var SCAN_CAP = configError ? QRID_MAX_POOLED_LEN : Math.min(QRID_MAX_POOLED_LEN,
    Math.max(256, Math.floor(QRID_POOLED_WORK_BUDGET / ((PAIRS.length || 1) * (maxLen || 1)))));
  /* Does THIS alternate accept the token? Check mode: the check character
     verifies (and the shape matches, when a pattern is also given) — the check
     char alone marks where one ID ends and the next begins. Regex-only
     (legacy projects, no check character): the shape IS the whole test. */
  function verifiesAs(P, t){
    if(P.a.re && !P.a.re.test(t)) return false;
    if(P.a.regexOnly){
      /* a check-bearing family shapes this token as its own: its check
         decides, never a format-only sibling of the same length */
      for(var oi = 0; oi < P.owners.length; oi++)
        if(!P.owners[oi] || P.owners[oi].test(t)) return false;
      return true;
    }
    return Q.validateIdCheck(t, P.a.scheme);
  }
  function parse(raw){
    /* over-budget input: no verdict at all (null), never a slow parse — the
       server bails identically, so the two runtimes cannot disagree here */
    if(QRID_codePointLen(raw) > SCAN_CAP) return null;
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
      for(var li = 0; li < PAIRS.length; li++){    /* length-ascending: shortest member wins ties */
        var L = PAIRS[li].len;
        if(L > N - i) break;
        if(!verifiesAs(PAIRS[li], s.substr(i, L))) continue;
        var ch = sc[i + L];
        var cand = { tok: ch.tok + 1, maxL: (L > ch.maxL ? L : ch.maxL),
                     runs: ch.runs, chars: ch.chars, startsJunk: false,
                     move: L, alt: PAIRS[li].alt };
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
        /* WHICH alternate won is already known here — carry it rather than
           re-deriving it later. A second pass that re-matched by pattern could
           credit a different, more permissive alternate than the one the parser
           actually verified through, and then report a check-verified member as
           shape-only. This records the winning pair; it is still not an input to
           betterThan, so segmentation stays deterministic. */
        segs.push({type:"id", id: s.substr(pos, m), valid: true, alt: sc[pos].alt});
        pos += m;
      } else {
        junk += s.charAt(pos);
        pos++;
      }
    }
    flushJunk();
    /* Re-scan junk runs for well-formed-but-wrong-check members so they surface
       as their own X chips instead of anonymous leftover text.
       Only CHECK-BEARING alternates that carry a pattern take part: for a
       regex-only alternate the shape IS the test, so a leftover matching its
       shape would already be a member, and stamping valid:false on it would
       report a check-character error against an ID that has no check character. */
    if(RESCAN.length){
      var out = [];
      for(var g = 0; g < segs.length; g++){
        if(segs[g].type !== "junk"){ out.push(segs[g]); continue; }
        var rest = segs[g].text, buf = "";
        while(rest.length){
          var hit = "";
          /* Only lengths the rule actually DECLARES, and only for the alternate
             that declares them. This walked the contiguous range minLen..maxLen,
             so idLengths [10,12] tested length 11 and could stamp an "invalid
             ID" chip the segmentation pass can never produce — an error message
             naming a length the rule forbids. Length ascending outer, alternate
             order inner, so the shortest hit still wins. */
          var hitAlt = -1;
          for(var li2 = 0; li2 < RESCAN.length && !hit; li2++){
            var L2 = RESCAN[li2].len;
            if(L2 > rest.length) break;                /* RESCAN is length-ascending */
            if(RESCAN[li2].a.re.test(rest.substr(0, L2))){ hit = rest.substr(0, L2); hitAlt = RESCAN[li2].alt; }
          }
          if(hit){
            if(buf){ out.push({type:"junk", text: buf}); buf = ""; }
            out.push({type:"id", id: hit, valid: false, alt: hitAlt});
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
  var BLOCK = cfg.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  /* optional "when" condition: null when absent (no behavior change) */
  var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst, QRID_BLANK_INERT, cfg.caseSensitive === true);
  return { configError: configError, clean: clean, parse: parse, scanCap: SCAN_CAP,
           alts: ALTS,
           expectedIds: (cfg.expectedIds == null ? null : cfg.expectedIds),
           blockSave: BLOCK, gate: GATE,
           when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
           mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY, mixed: MIXED,
                   alternates: ALTS.length } };
  }
  /* ---- end makeVariant ---- */

  var VS = QRID_buildVariants(QRID_MULTI_CONFIG, makeVariant);
  var configError = VS.configError;
  var IS_BRANCH = !!(QRID_MULTI_CONFIG.branches && QRID_MULTI_CONFIG.branches.length);
  var ANY_BLOCK = VS.firstBlock !== "off";
  var V0 = VS.all[0];
  var api = { clean: V0.clean, parse: V0.parse,        /* exposed for testing / power users */
              alts: V0.alts,
              mode: { check: V0.mode.check, regexOnly: V0.mode.regexOnly,
                      mixed: V0.mode.mixed, alternates: V0.mode.alternates,
                      configError: configError } };
  UV_lastPooled = api;                                 /* namespace .lastPooled */
  (QRID_MULTI_CONFIG.fields || []).forEach(function(f){
    if(IS_BRANCH){
      UV_validators[f] = { type: "pooled", branch: true,
        branches: VS.all.map(function(V){ return { when: V.when, mode: V.mode, parse: V.parse, clean: V.clean }; }),
        active: function(){ return QRID_activeVariants(VS); } };
      return;
    }
    UV_validators[f] = { type: "pooled", mode: api.mode, parse: V0.parse, clean: V0.clean };
  });

  function esc(t){ return String(t).replace(/&/g, "&amp;").replace(/</g, "&lt;"); }
  function chip(text, kind, label){
    /* Color families: hard problems (bad check character AND junk that is not
       an ID) are red; a DUPLICATE of a valid ID is a warning, not an error —
       amber. Amber #8a5500 on #fbf6e8 measures 5.7:1 and red #c62828 on
       #fbeceb 4.9:1 — WCAG 2.2 AA for normal text needs 4.5:1, and chips are
       12px (A11Y-002). Every state also has a non-color mark
       (check / circled-x / cross / question mark).
       "fmt" is a member accepted by a FORMAT-ONLY alternate in a mixed rule:
       it is not an error, but it did not have a check character to verify, and
       a green tick beside genuinely verified members would over-claim. Grey +
       a circle mark, and the same 4.5:1 floor (#4a4a4a on #f2f2f2 = 8.9:1). */
    var c = (kind === "ok") ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "fmt") ? "#cfcfcf;background:#f2f2f2;color:#4a4a4a"
          : (kind === "dup") ? "#e6d4a8;background:#fbf6e8;color:#8a5500"
          : "#e0b4b0;background:#fbeceb;color:#c62828";   /* bad + junk share red */
    var mark = (kind === "ok") ? "&#10003;&nbsp;"
             : (kind === "fmt") ? "&#9675;&nbsp;"          /* open circle = shape only */
             : (kind === "dup") ? "&#8855;&nbsp;"          /* circled x = scanned again */
             : (kind === "bad") ? "&#10007;&nbsp;" : "?&nbsp;";
    var suffix = (kind === "dup") ? "&nbsp;(again!)" : "";
    /* the alternate's name is project configuration, so it is escaped like any
       other setting that reaches innerHTML (UV-001) */
    if(label) suffix += '&nbsp;<span style="opacity:.7">' + QRID_escapeHtml(label) + "</span>";
    return '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:10px;' +
      'font-family:monospace;font-size:12px;border:1px solid ' + c + '">' + mark + text + suffix + '</span>';
  }
  function render(msg, input){
    /* which variant applies right now? (see the single factory) */
    var act = QRID_activeVariants(VS);
    if(!act.length){
      input.__qridInvalid = false;
      /* inert — unless no branch could be CHOSEN at all (M-01). */
      if(QRID_renderRuleDeferral(msg, input, QRID_MULTI_CONFIG, "check")){ QRID_setModeState(input, "check", null); return; }
      msg.style.display = "none"; QRID_setModeState(input, "check", null);
      QRID_setModeState(input, "check", null);
      return;
    }
    if(act.length > 1){
      QRID_renderConflict(msg, input, act, "check");
      return;
    }
    var V = act[0];
    input.__qridBlockMode = V.blockSave;   /* the ACTIVE variant governs blocking */
    var v = (input.value || "").trim();
    if(!v || QRID_isMissingCode(v)){
      msg.style.display = "none"; QRID_setModeState(input, "check", null); input.__qridInvalid = false;
      QRID_setModeState(input, "check", null);
      return;
    }
    if(QRID_codePointLen(v) > V.scanCap){
      msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
        "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
      msg.innerHTML = "&#10007; This field is too long to scan (over " + V.scanCap +
        " characters for this rule's ID lengths) — split the pool into smaller entries.";
      QRID_setModeState(input, "check", "bad"); input.__qridInvalid = true;
      QRID_setModeState(input, "check", "bad");
      return;
    }
    var segs = V.parse(v);
    var ids = segs.filter(function(x){ return x.type === "id"; });
    var junkSegs = segs.filter(function(x){ return x.type === "junk"; });
    var bad = ids.filter(function(x){ return !x.valid; });
    var seen = Object.create(null), dups = 0;
    ids.forEach(function(x){ if(seen[x.id]) dups++; seen[x.id] = true; });
    var expected = V.expectedIds;
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
    /* Which alternate claimed each member? Declaration order, computed after
       segmentation — reporting only, never an input to the DP score. */
    /* COR-005: member text is attacker-ish input for a plain object — a token
       reading "constructor" must not inherit prototype members and corrupt
       the lookups. Same prototype-free map the other registries use. */
    var nFmtOnly = 0;
    if(V.alts){
      ids.forEach(function(x){
        if(!x.valid) return;
        /* the alternate the PARSER verified through, carried on the segment.
           Re-matching by pattern here credited whichever alternate accepted the
           shape FIRST, so a permissive format-only sibling could take credit
           for a member that had in fact been check-verified — and the summary
           then told the reader the field carries no check character. */
        var k = (typeof x.alt === "number") ? x.alt : -1;
        if(k >= 0 && V.alts[k] && V.alts[k].regexOnly) nFmtOnly++;
      });
    }
    /* A MIXED rule can say neither "all verified" nor "no check character in
       this project" — both are false when some families carry a check character
       and others do not. Say what actually happened instead. */
    var okWord;
    if(V.mode.mixed && nFmtOnly > 0 && nFmtOnly < ids.length){
      okWord = (ids.length - nFmtOnly) + " verified &#10003;, " + nFmtOnly +
        " format-only (no check character in " + (nFmtOnly === 1 ? "that format" : "those formats") + ")";
    } else if(V.mode.regexOnly || (V.mode.mixed && nFmtOnly === ids.length && ids.length)){
      okWord = "all match the ID format &#10003; (no check character in " +
        (V.mode.mixed ? "that format" : "this project") + ")";
    } else {
      okWord = "all verified &#10003;";
    }
    var html = "<b>" + ids.length + " ID" + (ids.length === 1 ? "" : "s") + " read" +
      (ok ? " &mdash; " + okWord : " &mdash; " + esc(problems.join("; "))) + "</b><br>";
    var occ = Object.create(null);
    segs.forEach(function(x){
      if(x.type === "id"){
        occ[x.id] = (occ[x.id] || 0) + 1;
        var k = (typeof x.alt === "number") ? x.alt : -1;
        var label = (V.mode.mixed && k >= 0 && V.alts[k] && V.alts[k].label) ? V.alts[k].label : "";
        if(x.valid && occ[x.id] > 1) html += chip(esc(x.id), "dup", label);   /* repeat scans stand out */
        else html += chip(esc(x.id), x.valid ? (k >= 0 && V.alts[k] && V.alts[k].regexOnly ? "fmt" : "ok") : "bad", label);
      } else {
        html += chip(esc(x.text), "junk");
      }
    });
    msg.innerHTML = html;
    QRID_setModeState(input, "check", ok ? "ok" : "bad");
    input.__qridInvalid = !ok;
    QRID_setModeState(input, "check", ok ? "ok" : "bad");
  }
  /* ---- optional save blocking (shared QRID_registerBlocker semantics) ---- */
  function attach(fieldName){
    var input = QRID_findField(fieldName);
    if(!input) return false;
    if(input.getAttribute && input.getAttribute("data-qrid-bound")) return true;  /* idempotent */
    if(input.setAttribute) input.setAttribute("data-qrid-bound", "1");
    var msg = QRID_attachMsgRegion(input, fieldName);
    var debounced = QRID_debounced(function(){ render(msg, input); });
    input.addEventListener("input", debounced);
    input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); render(msg, input); });
    input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); render(msg, input); });
    /* a condition flip (true<->false) re-renders this field's pooled verdict */
    for(var gi = 0; gi < VS.variants.length; gi++){
      if(VS.variants[gi].gate) VS.variants[gi].gate.onChange(function(){ render(msg, input); });
    }
    /* register BEFORE the initial render: render() stamps the ACTIVE variant's
       blockSave on the input, and registration must never clobber it */
    if(ANY_BLOCK && !configError){
      /* re-judged at save, as the single field is (VAL-6) */
      input.__qridRecheck = function(){ if(debounced.cancel) debounced.cancel(); render(msg, input); };
      QRID_registerBlocker(input, fieldName, VS.firstBlock);
    }
    render(msg, input);
    return true;
  }
  function boot(){
    if(configError){
      /* Put the error on the affected field(s) when they are on this page —
         that is where the designer will look (UX-001); fields not present
         fall back to ONE page-level notice (muted on surveys). Nothing to
         validate either way, so no retry/observer is armed. */
      var missing = (QRID_MULTI_CONFIG.fields || []).filter(function(f){ return !QRID_attachErrorRegion(f, configError); });
      if(missing.length) QRID_configErrorNotice(configError);
      return;
    }
    var pending = (QRID_MULTI_CONFIG.fields || []).slice();
    var mo = null;
    function stop(){ if(mo){ mo.disconnect(); mo = null; } }   /* prevent observer leak */
    function sweep(){
      pending = pending.filter(function(f){ return !attach(f); });
      if(pending.length === 0){ stop(); return true; }
      return false;
    }
    if(sweep()) return;
    var tries = 0;
    var timer = setInterval(function(){                 /* REDCap builds the form progressively */
      tries++;
      if(sweep() || tries >= 20){ clearInterval(timer); stop(); }  /* give up -> disconnect */
    }, 500);
    if(typeof MutationObserver !== "undefined" && document.body){
      mo = new MutationObserver(function(){ sweep(); });
      mo.observe(document.body, { childList: true, subtree: true });   /* late-rendered fields */
    }
  }
  if(document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
}
/* ---- dispatcher: one validator instance per rule ---- */
(function(){
  var C = QRID_COMBINED_CONFIG;
  /* DEFAULT_KEYS: every key a rule carries to its factory (the union of the
     modes' clientKeys). "deferred" marks a rule the server had to freeze (an
     off-page ref this viewer may not read): the verdict is stale the moment
     the user types, so the client shows it as advisory and never blocks.
     MODE_OF_TYPE / FACTORY_OF_TYPE: which mode a rule type belongs to and
     which factory starts it. */
  /* @generated mode-registry BEGIN — written from php/modes.json by tests/gen_mode_registry.cjs.
     Edit php/modes.json and re-run that script; never edit this block. */
  var DEFAULT_KEYS = ["algorithm", "idPattern", "alternates", "source", "strip", "suggestFix",
                      "keepChars", "idLengths", "idMinLen", "idMaxLen", "expectedIds",
                      "blockSave", "when", "whenAst", "assert", "assertAst", "caseSensitive",
                      "exact", "message", "deferred", "deferredWhy", "snapshotFields", "i18n",
                      "uniqueWith", "uniqueScope", "uniqueSurveys", "uniqueRecordAsts",
                      "choicesShow", "choicesHide", "choicesAll", "windowFrom", "windowFromOp",
                      "windowFromOpWhy", "windowLo", "windowHi", "windowUnit",
                      "windowNotFuture", "dateType", "dateFormat", "fromType", "fromFormat",
                      "windowAnchor", "windowPeriod", "windowOffLo", "windowOffHi",
                      "windowWeekStart", "windowNotPast", "snapshotGate", "windowNotFutureOff",
                      "existsLocal", "existsSelfIn", "existsSurveys", "existsReturnFields",
                      "existsReturnNumbers", "whereMessage", "rangeSoftLo", "rangeSoftHi",
                      "rangeHardLo", "rangeHardHi", "rangeSoftBlock", "rangeHardBlock",
                      "rangeUnit", "rangeSoftText", "rangeHardText", "decimalComma",
                      "rangeComputed", "rangeReference", "rangeSexOp", "rangeMale",
                      "rangeFemale", "rangeAgeDobOp", "rangeAgeAtOp", "rangeAgeDaysOp",
                      "rangeAgeMonthsOp", "rangeByOp", "rangeMeasuredOp", "rangeLying",
                      "rangeStanding", "rangeDobType", "rangeDobFormat", "rangeAtType",
                      "rangeAtFormat", "rangeAxisComma", "rangePlausibleLo", "rangePlausibleHi",
                      "rangePlausibleText", "rangeMeasureScale", "rangeReturn",
                      "rangeReturnLimits", "rangeDecimals", "rangePercentileDecimals",
                      "rangeReturnComma", "deferredOnSave", "extendedAdvisory", "growthIdle",
                      "selectorBlocked", "rangeShow", "growthCurveKey", "growthTableKey",
                      "growthChartWhy", "growthChartSource"];
  var MODE_OF_TYPE = {
    "single": "check",
    "pooled": "check",
    "constraint": "constraint",
    "required": "required",
    "unique": "unique",
    "choices": "choices",
    "window": "window",
    "exists": "exists",
    "range": "range"
  };
  var FACTORY_OF_TYPE = {
    "single": "QRIDSingleInit",
    "pooled": "QRIDPooledInit",
    "constraint": "QRIDConstraintInit",
    "required": "QRIDRequiredInit",
    "unique": "QRIDUniqueInit",
    "choices": "QRIDChoiceFilterInit",
    "window": "QRIDWindowInit",
    "exists": "QRIDExistsInit",
    "range": "QRIDRangeInit"
  };
  var BRANCH_GROUP_OF_TYPE = {
    "range": "rangeReference"
  };
  var KNOWN_TYPES_TEXT = "\"single\", \"pooled\", \"constraint\", \"required\", \"unique\", \"choices\", \"window\", \"exists\" or \"range\"";
  /* @generated mode-registry END */
  /* The factories the registry may name. Hand-written, because a name in a
     data file must never reach an arbitrary function; tests/gen_mode_registry.cjs
     fails when the registry names a factory that is missing here. */
  var QRID_FACTORY_BY_NAME = {
    QRIDSingleInit: QRIDSingleInit,
    QRIDPooledInit: QRIDPooledInit,
    QRIDConstraintInit: QRIDConstraintInit,
    QRIDRequiredInit: QRIDRequiredInit,
    QRIDUniqueInit: QRIDUniqueInit,
    QRIDChoiceFilterInit: QRIDChoiceFilterInit,
    QRIDWindowInit: QRIDWindowInit,
    QRIDExistsInit: QRIDExistsInit,
    QRIDRangeInit: QRIDRangeInit
  };
  function own(o, k){ return Object.prototype.hasOwnProperty.call(o, k); }
  /* The validation MODE a rule's type belongs to — twin of
     ModeRegistry::modeOfType in php/ModeRegistry.php. Different modes on one
     field COMPOSE (each attaches its own validator); same-mode sharing is
     branched server-side. Duplicate detection therefore keys on (field, mode).
     Unknown types belong to the check mode. */
  function modeOfType(t){
    return (typeof t === "string" && own(MODE_OF_TYPE, t)) ? MODE_OF_TYPE[t] : "check";
  }
  /* Keys a multi-format rule carries PER ALTERNATE. They must not be inherited
     from the top-level config, because the server merges defaults() there
     (idMinLen 8, idMaxLen 14) and inheriting them made the "don't set these
     alongside alternates" guard fire on EVERY pooled alternates rule — the
     documented feature was dead in the browser while the server, which reads
     the raw rule where the keys are genuinely absent, validated normally.
     Taking the RULE's own value (or nothing) makes the client see exactly what
     the server sees, so a rule that genuinely sets them is still refused. */
  var OWN_KEYS = { idPattern: 1, idLengths: 1, idMinLen: 1, idMaxLen: 1 };
  function cfgFor(rule){
    var cfg = {}, i, k;
    /* Branch rule (php/Branching.php): defaults are filled PER BRANCH, so a
       branch and a plain rule default identically. A branch's explicit
       when:null (the else branch) must not inherit anything — only an
       UNDEFINED key falls back to the top-level config. */
    if(rule.branches && rule.branches.length){
      cfg.branches = [];
      cfg.deferred = !!rule.deferred;
      cfg.deferredWhy = rule.deferredWhy;
      cfg.deferredOnSave = rule.deferredOnSave === true;
      cfg.snapshotFields = rule.snapshotFields;
      for(i = 0; i < rule.branches.length; i++){
        var b = rule.branches[i], bc = {};
        for(var j = 0; j < DEFAULT_KEYS.length; j++){
          k = DEFAULT_KEYS[j];
          /* per-alternate key on a multi-format branch: take the branch's own
             value or nothing — never the top-level default */
          bc[k] = (b[k] !== undefined) ? b[k]
                : (OWN_KEYS[k] && b.alternates != null && b.alternates !== "") ? undefined
                : C[k];
        }
        /* The field's full code list is the same on every branch, so the
           server sends it once on the rule. */
        if(bc.choicesAll === undefined && rule.choicesAll !== undefined) bc.choicesAll = rule.choicesAll;
        cfg.branches.push(bc);
      }
      cfg.fields = rule.fields || [];
      cfg.type = rule.type;                 /* the guard asks a rule's type (QRID_PARTIAL_SAVE) */
      /* branches split by this key resolve per group (QRID_buildVariants) */
      cfg.branchGroup = (typeof rule.type === "string" && own(BRANCH_GROUP_OF_TYPE, rule.type)) ? BRANCH_GROUP_OF_TYPE[rule.type] : null;
      if(rule.configError) cfg.configErrorOverride = rule.configError;
      return cfg;
    }
    for(i = 0; i < DEFAULT_KEYS.length; i++){
      k = DEFAULT_KEYS[i];
      cfg[k] = (rule[k] !== undefined) ? rule[k]
             : (OWN_KEYS[k] && rule.alternates != null && rule.alternates !== "") ? undefined
             : C[k];
    }
    cfg.fields = rule.fields || [];
    cfg.type = rule.type;                   /* the guard asks a rule's type (QRID_PARTIAL_SAVE) */
    /* a rule the server flagged as mis-configured (e.g. a non-integer expected
       count or bad id-lengths) surfaces as a field-level config error, never as
       silent wrong validation. */
    if(rule.configError) cfg.configErrorOverride = rule.configError;
    return cfg;
  }
  var rules = (C.rules || []).slice();
  if(C.singleFields && C.singleFields.length) rules.push({ type: "single", fields: C.singleFields });
  if(C.pooledFields && C.pooledFields.length) rules.push({ type: "pooled", fields: C.pooledFields });
  /* a field may be owned by exactly ONE rule/list — duplicates would attach
     two contradictory validators to the same input. Detect them FIRST and bind
     the error box before any real validator can claim the field. Prototype-free
     map: a field literally named "constructor" must not corrupt the counts
     (COR-005). */
  /* Count per (field, MODE): a check rule and a constraint rule may share a
     field (they compose — each attaches its own validator); only two rules of
     the SAME mode on one field are a genuine duplicate. Key with a separator
     that cannot occur in a field name or mode. */
  var counts = Object.create(null);
  function dupKey(field, type){ return field + "\x1F" + modeOfType(type); }
  rules.forEach(function(rule){
    if(rule.configError) return;  /* config-error rules validate nothing and often
      carry placeholder / shared field names — keep them out of duplicate detection
      so two mis-configured rules can't collapse into one bogus "duplicate" error. */
    (rule.fields || []).forEach(function(f){ var k = dupKey(f, rule.type); counts[k] = (counts[k] || 0) + 1; });
  });
  var dupByField = Object.create(null);
  for(var dk in counts) if(counts[dk] > 1){ var fld = dk.slice(0, dk.lastIndexOf("\x1F")); dupByField[fld] = true; }
  var dupFields = [];
  for(var df in dupByField) dupFields.push(df);
  if(dupFields.length){
    QRIDSingleInit({ fields: dupFields, algorithm: "none",
      configErrorOverride: 'field(s) "' + dupFields.join('", "') +
        '" are listed in more than one rule/list of the same kind — remove the duplicates so each field has exactly one validator per kind.' });
  }
  rules.forEach(function(rule){
    var cfg = cfgFor(rule);
    /* config-error rules keep their fields as-is (their message is shown once in
       the page notice by the factory's boot); live rules drop same-mode duplicates. */
    cfg.fields = rule.configError ? (rule.fields || [])
               : (rule.fields || []).filter(function(f){ return counts[dupKey(f, rule.type)] === 1; });
    if(!cfg.fields.length) return;
    var factory = (typeof rule.type === "string" && own(FACTORY_OF_TYPE, rule.type))
      ? QRID_FACTORY_BY_NAME[FACTORY_OF_TYPE[rule.type]] : null;
    if(factory){
      factory(cfg);
    } else if(rule.type === undefined || rule.type === "" || rule.configError){
      QRIDSingleInit(cfg);
    } else {
      cfg.configErrorOverride = 'rule for fields [' + cfg.fields.join(", ") +
        '] has unknown type "' + rule.type + '" — use ' + KNOWN_TYPES_TEXT + '.';
      QRIDSingleInit(cfg);
    }
  });
})();

/* ---- single public namespace (module deviation; REDCap JS guidance strongly
   discourages global scope). EVERYTHING this module exposes is reachable
   through this one global; the legacy upstream/JavaScript-Injector globals
   (QRCheck, QRIDSingleInit, QRIDPooledInit, QRIDValidators, QRIDMulti,
   QRID_COMBINED_CONFIG, __QRIDGuard) are NOT published — this module never
   shipped with them, so there is no consumer to break. The lazy members use
   getters because validators/guard/lastPooled are populated asynchronously as
   fields attach. */
window.INSPIREUniversalValidator = {
  config: QRID_COMBINED_CONFIG,
  configShape: QRID_CONFIG_SHAPE,           /* twin of UniversalValidator::CONFIG_SHAPE (QUA-7) */
  engine: Q,
  riskyPattern: QRID_riskyPattern,          /* cross-runtime gate, locked by tests/risky_js.cjs */
  gatePattern: QRID_gatePattern,            /* the one pattern admission point, locked by tests/risky_js.cjs */
  swallowSum: QRID_swallowSum,              /* hand-mirrored php twins, locked by tests/alternates_dom_js.cjs */
  alternatesOf: QRID_alternatesOf,
  configErrorNotice: QRID_configErrorNotice, /* exercised by tests/config_notice_js.cjs */
  whenLogic: {                               /* "when" twins, locked by tests/when_js.cjs */
    parse: QRID_whenParse,
    refKey: QRID_whenRefKey, qualifiedRefs: QRID_whenQualifiedRefs,
    evaluate: QRID_whenEvaluate,
    referencedFields: QRID_whenRefs,
    /* The blank-operand polarity (CRIT-01), so the fixture can drive BOTH
       roles through the same evaluator the runtime uses. Twins of
       Logic::BLANK_PASSES / Logic::BLANK_INERT. */
    BLANK_PASSES: QRID_BLANK_PASSES,
    BLANK_INERT: QRID_BLANK_INERT,
    EXACT: QRID_WHEN_EXACT,                  /* the "exact" text mode (AST-2), twin of Logic::EXACT */
    caps: { maxLen: QRID_WHEN_MAX_LEN, maxRefs: QRID_WHEN_MAX_REFS, maxDepth: QRID_WHEN_MAX_DEPTH }
  },
  windowLogic: {                             /* @UVWINDOW twins, locked by tests/window_js.cjs */
    verdict: QRID_windowVerdict, verdictSpread: QRID_windowVerdictSpread, anchorType: QRID_anchorType,
    keywordAnchorType: QRID_keywordAnchorType, canonical: QRID_temporalCanonical, format: QRID_temporalFormat,
    family: QRID_temporalFamily, clockNow: QRID_clockNow, clockAt: QRID_clockAt,
    clockLenient: QRID_clockLenient, clockError: QRID_deviceClockError, units: QRID_WINDOW_UNITS, calendarUnits: QRID_CALENDAR_UNITS,
    periodLabel: QRID_windowPeriodLabel, spanLabel: QRID_windowSpanLabel,
    calendar: { shiftMonths: QRID_shiftMonths, shiftDays: QRID_shiftDays, isoWeekday: QRID_isoWeekday,
                periodStart: QRID_periodStart, periodShift: QRID_periodShift, periodEnd: QRID_periodEnd }
  },
  rangeLogic: {                              /* @UVRANGE twins, locked by tests/range_js.cjs */
    verdict: QRID_rangeVerdict, number: QRID_rangeNumber, plainDecimal: QRID_plainDecimal
  },
  returnWriter: {                            /* the one writer of returned values, locked by tests/growth_return_dom_js.cjs */
    write: QRID_writeReturned
  },
  lookupLogic: {                             /* @UVEXISTS/@UVUNIQUE comparison twins, locked by tests/lookup_js.cjs */
    key: QRID_lookupKey, number: QRID_numberKey, trim: QRID_lookupTrim, sameFlags: QRID_sameFlags
  },
  growthLogic: {                             /* @UVRANGE growth references, locked by tests/growth_js.cjs */
    axis: QRID_growthAxis, lms: QRID_growthLms, zRaw: QRID_growthZRaw, zText: QRID_growthZText,
    measure: QRID_growthMeasure, zScore: QRID_growthZScore, plausible: QRID_growthPlausible,
    valueAt: QRID_growthValueAt, bound: QRID_growthBound, unitsText: QRID_growthUnitsText,
    markText: QRID_growthMarkText, outputs: QRID_growthOutputs,
    output: QRID_growthOutput, outputOf: QRID_growthOutputOf, catalog: QRID_GROWTH_OUTPUTS,
    percentile: QRID_growthPercentile, percentileText: QRID_growthPercentileText, valueAtText: QRID_growthValueAtText,
    normalCdf: QRID_growthNormalCdf, normalQuantile: QRID_growthNormalQuantile,
    roundedUnits: QRID_growthRoundedUnits, computed: QRID_GROWTH_COMPUTED,
    axisText: QRID_growthAxisText, lineZ: QRID_growthLineZ, positionAdd: QRID_growthPositionAdd
  },
  chartLogic: {                              /* @UVRANGE "show", locked by tests/growth_chart_dom_js.cjs */
    geometry: QRID_CHART, step: QRID_chartStep, ticks: QRID_chartTicks, xUnit: QRID_chartXUnit, span: QRID_growthSpan,
    window: QRID_chartWindow, clip: QRID_chartClip, yRange: QRID_chartYRange, showOf: QRID_showOf, readX: QRID_growthReadX
  },
  singleInit: QRIDSingleInit,
  pooledInit: QRIDPooledInit,
  constraintInit: QRIDConstraintInit,   /* @UVASSERT — locked by tests/constraint_dom_js.cjs */
  requiredInit: QRIDRequiredInit,       /* @UVREQUIRED — locked by tests/required_dom_js.cjs */
  uniqueInit: QRIDUniqueInit,           /* @UVUNIQUE — locked by tests/unique_dom_js.cjs */
  choiceFilterInit: QRIDChoiceFilterInit, /* @UVCHOICES — locked by tests/choices_dom_js.cjs */
  windowInit: QRIDWindowInit,           /* @UVWINDOW — locked by tests/window_dom_js.cjs */
  existsInit: QRIDExistsInit,           /* @UVEXISTS — locked by tests/exists_dom_js.cjs */
  rangeInit: QRIDRangeInit,             /* @UVRANGE — locked by tests/range_dom_js.cjs */
  get validators(){ return UV_validators; },
  get guard(){ return UV_guard; },
  get lastPooled(){ return UV_lastPooled; }
};
/* The verified core published itself as a global for the legacy Injector
   contract; it is captured as .engine above — retire the alias so this module
   leaves exactly ONE global behind. */
try { delete G.QRCheck; } catch(e){ G.QRCheck = undefined; }
})();
