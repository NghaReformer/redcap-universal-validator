/* engine.js — vendored from the qrcode_generation repo:
   integrations/redcap/redcap_combined_id_check.js

   DEVIATIONS FROM UPSTREAM (full list + rationale in js/README.md, which is the
   authoritative provenance record — keep the two in sync):
     1. Config source: parsed from the inert <script type="application/json"
        id="inspire-validator-config"> node UniversalValidator.php emits (with
        window.INSPIRE_VALIDATOR_CONFIG as a test-only fallback) instead of a
        hardcoded literal.
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
  function QRID_readConfig(){
    try {
      if (typeof document !== "undefined" && document.getElementById) {
        var el = document.getElementById("inspire-validator-config");
        if (el && el.textContent) return JSON.parse(el.textContent);
      }
    } catch(e){
      if (typeof console !== "undefined" && console.error)
        console.error("Universal Regex & Check-Character Validator: could not parse config", e);
    }
    if (typeof window !== "undefined" && window.INSPIRE_VALIDATOR_CONFIG)
      return window.INSPIRE_VALIDATOR_CONFIG;
    return { singleFields: [], pooledFields: [], rules: [], algorithm: "iso7064_mod37_36",
      idPattern: null, source: "normalized_id", strip: "-/ _|\\", suggestFix: false,
      keepChars: "", idLengths: null, idMinLen: 8, idMaxLen: 14, expectedIds: null, blockSave: "off" };
  }
  var QRID_COMBINED_CONFIG = QRID_readConfig();
  /* Survey pages face respondents who cannot act on configuration problems:
     technical config detail is muted there (UX-001) — admins still see every
     detail on data-entry forms, in the Configure dialog, and in the module log. */
  var QRID_IS_SURVEY = (QRID_COMBINED_CONFIG && QRID_COMBINED_CONFIG.context === "survey");

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
/* Conservative catastrophic-backtracking detector. Two stages, both at CONFIG
   time. Stage one rejects the EXPONENTIAL shapes: nested quantifiers AND any
   repetition of a group that can match the same text more than one way
   (alternation, optionals, or inner quantifiers) — so (a|aa)+, (a?)+ and
   ((a)|(aa))+ are refused, not only (a+)+ — collapsing inner groups layer by
   layer so nesting cannot hide the shape. Stage two (QRID_polyOverlap) rejects
   the POLYNOMIAL shapes stage one misses: two or more unbounded quantifiers over
   overlapping character classes with no mandatory separator between them
   (.*.*, [0-9]*[0-9]*, [A-Z]+[A-Z0-9]+). PCRE2 auto-possessifies those and stays
   fast, but this browser engine backtracks and would freeze the tab, so they
   must be refused before they ever run. Genuinely linear shapes — disjoint
   adjacent classes ([A-Z]+[0-9]+) and a mandatory separator (.*x.*) — pass.
   Still a heuristic, not a proof: it over-rejects, and on the server the
   match-time PCRE-error guard remains the backstop for the bounded residue.
   Keep BYTE-IDENTICAL behavior with CheckCharacter::riskyPattern (php);
   tests/risky_*.{cjs,php} assert parity. */
function QRID_riskyPattern(src){
  if(Array.from(String(src)).length > 512) return true;   /* absurd pattern source */
  var s = String(src).replace(/\\./g, "");                 /* ignore escaped chars */
  s = s.replace(/\(\?(<?[=!]|:)/g, "(");                   /* (?: (?= (?! (?<= (?<! -> plain ( */
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
  return false;
}
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
    var unbounded = false, mandatory = true;           /* a bare atom must match once */
    if(i < n){
      var q = s.charAt(i);
      if(q === "*"){ unbounded = true; mandatory = false; i++; }
      else if(q === "+"){ unbounded = true; mandatory = true; i++; }
      else if(q === "?"){ unbounded = false; mandatory = false; i++; }
      else if(q === "{"){
        var qc = s.indexOf("}", i + 1), mm;
        if(qc !== -1 && (mm = /^([0-9]*)(,?)([0-9]*)$/.exec(s.slice(i + 1, qc)))){
          var lo = mm[1] === "" ? 0 : parseInt(mm[1], 10);
          unbounded = (mm[2] === ",") && (mm[3] === "");   /* {n,} unbounded; {n} / {n,m} bounded */
          mandatory = lo >= 1;
          i = qc + 1;
        }
      }
    }
    toks.push({ cls: cls, unbounded: unbounded, mandatory: mandatory });
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
var QRID_MAX_SINGLE_LEN = 512;    /* one ID field: refuse to validate absurd input */
var QRID_MAX_POOLED_LEN = 4096;   /* pooled field: cap total scanned length        */
/* Rule-config work caps — mirror php/CheckCharacter.php MAX_* constants (the
   server rejects the same limits at settings-save time and treats them as
   unconfigurable at audit time); keep the values in sync. */
var QRID_MAX_ID_LEN      = 64;    /* longest single ID/member the parser considers */
var QRID_MAX_LEN_CHOICES = 32;    /* most candidate lengths a pooled rule may declare */
var QRID_MAX_EXPECTED    = 9999;
var QRID_MAX_KEEP        = 64;
/* One pooled parse costs about (scanned length) x |LENS| x (member length)
   character operations; this budget bounds that product for EVERY legal
   config, so an expensive rule shrinks its scan cap instead of freezing the
   tab. Default rules keep the full QRID_MAX_POOLED_LEN. */
var QRID_POOLED_WORK_BUDGET = 2000000;
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
/* Best-effort visible label for a field (REDCap data-entry rows put it in a
   .labelrc cell); falls back to the internal field name. Used so save-block
   dialogs and announcements speak the language of the FORM, not the data
   dictionary (A11Y-001). */
function QRID_fieldLabel(input, fieldName){
  try {
    var t = input.getAttribute && input.getAttribute("aria-label");
    if (t && t.replace(/\s+/g, "").length) return t.replace(/\s+/g, " ").trim().slice(0, 80);
    if (input.labels && input.labels.length && input.labels[0].textContent) {
      t = input.labels[0].textContent.replace(/\s+/g, " ").trim();
      if (t) return t.slice(0, 80);
    }
    var row = input.closest ? input.closest("tr") : null;
    if (row && row.querySelector) {
      var cell = row.querySelector("td.labelrc, .labelrc");
      if (cell && cell.textContent) {
        t = cell.textContent.replace(/\s+/g, " ").trim();
        if (t) return t.slice(0, 80);
      }
    }
  } catch(e){}
  return fieldName;
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

function QRID_whenErr(msg){ return { ok: false, error: msg }; }

/* Parse one condition -> {ok:true, ast} | {ok:false, error}. Error strings are
   subject-less predicates so callers can prefix 'the "when" condition '. */
function QRID_whenParse(expr){
  if(typeof expr !== "string") return QRID_whenErr("must be a non-empty condition string.");
  var s = expr.replace(/[\t\r\n]/g, " ").replace(/^ +| +$/g, "");
  if(s === "") return QRID_whenErr("must be a non-empty condition string.");
  if(/[^\x20-\x7E]/.test(s)) return QRID_whenErr("must contain printable ASCII only.");
  if(s.length > QRID_WHEN_MAX_LEN) return QRID_whenErr("is limited to " + QRID_WHEN_MAX_LEN + " characters.");
  var lex = QRID_whenLex(s);
  if(lex.error) return QRID_whenErr(lex.error);
  var st = { t: lex.tokens, p: 0 };
  var r = QRID_whenOr(st, 0);
  if(r.error) return QRID_whenErr(r.error);
  if(st.p < st.t.length){
    return QRID_whenErr('has unexpected content after a complete condition — combine comparisons with "and"/"or".');
  }
  return { ok: true, ast: r.node };
}
function QRID_whenLex(s){
  var tokens = [];
  var n = s.length;
  var refs = 0;
  var i = 0;
  while(i < n){
    var ch = s.charAt(i);
    if(ch === " "){ i++; continue; }
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
  return { node: ["cmp", op, lhs.node, rhs.node] };
}
/* operand := ref | string | number */
function QRID_whenOperandTok(st){
  var t = QRID_whenPeek(st);
  if(t === null){
    return { error: "ends where a [field] or quoted value was expected." };
  }
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
function QRID_whenEvaluateWith(ast, resolve){
  switch(ast[0]){
    case "const":
      return !!ast[1];
    case "or":
      for(var i = 0; i < ast[1].length; i++){ if(QRID_whenEvaluateWith(ast[1][i], resolve)) return true; }
      return false;
    case "and":
      for(var j = 0; j < ast[1].length; j++){ if(!QRID_whenEvaluateWith(ast[1][j], resolve)) return false; }
      return true;
    case "not":
      return !QRID_whenEvaluateWith(ast[1], resolve);
    case "cmp":
      return QRID_whenCompare(ast[1],
        QRID_whenOperandVal(ast[2], resolve),
        QRID_whenOperandVal(ast[3], resolve));
  }
  return false; /* unreachable for QRID_whenParse-produced ASTs */
}
function QRID_whenOperandVal(op, resolve){
  return op[0] === "lit" ? op[1] : resolve(op[1], op[2]);
}
/* Evaluate against a value map (field => string, or field => {code:'0'|'1'}
   for checkboxes). Missing fields resolve to '' (checkbox refs to '0'). */
function QRID_whenEvaluate(ast, values){
  return QRID_whenEvaluateWith(ast, function(f, code){
    var v = (values && Object.prototype.hasOwnProperty.call(values, f)) ? values[f] : null;
    if(code !== null){
      if(v !== null && typeof v === "object"){
        return (v[code] !== undefined && String(v[code]) === "1") ? "1" : "0";
      }
      return "0";
    }
    if(v === null || v === undefined || typeof v === "object") return "";
    return String(v);
  });
}
function QRID_whenTrim(v){ return String(v).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, ""); }
/* Numeric compare (floats) iff BOTH trimmed sides match QRID_WHEN_NUM_RE,
   else exact case-sensitive string compare (ASCII-identical to PHP strcmp). */
function QRID_whenCompare(op, a, b){
  a = QRID_whenTrim(a); b = QRID_whenTrim(b);
  if(QRID_WHEN_NUM_RE.test(a) && QRID_WHEN_NUM_RE.test(b)){
    var fa = parseFloat(a), fb = parseFloat(b);
    switch(op){
      case "=":  return fa === fb;
      case "<>": return fa !== fb;
      case ">":  return fa > fb;
      case "<":  return fa < fb;
      case ">=": return fa >= fb;
      case "<=": return fa <= fb;
    }
    return false;
  }
  switch(op){
    case "=":  return a === b;
    case "<>": return a !== b;
    case ">":  return a > b;
    case "<":  return a < b;
    case ">=": return a >= b;
    case "<=": return a <= b;
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
  function fire(f){
    var cbs = hooks[f];
    if(!cbs) return;
    for(var i = 0; i < cbs.length; i++){
      try { cbs[i](); } catch(e){}
    }
  }
  /* Bind the recheck trigger to every element carrying this target name.
     Returns true once at least one element is hooked (or was already). */
  function tryBind(t){
    var els = document.getElementsByName ? document.getElementsByName(t.name) : [];
    var bound = false;
    for(var i = 0; i < els.length; i++){
      var el = els[i];
      if(el.__qridWhenBound){ bound = true; continue; }
      if(!el.addEventListener) continue;
      el.__qridWhenBound = true;
      bound = true;
      var handler = function(){ fire(t.field); };
      for(var k = 0; k < t.events.length; k++) el.addEventListener(t.events[k], handler);
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
  function sweep(){
    var remaining = [];
    for(var i = 0; i < targets.length; i++){
      var t = targets[i];
      if(tryBind(t)) continue;
      t.tries = (t.tries || 0) + 1;
      if(t.tries < 20) remaining.push(t);
    }
    targets = remaining;
    if(!targets.length) stopSweep();
    return !targets.length;
  }
  function queueTarget(name, field, events){
    if(queued[name]) return;
    queued[name] = true;
    targets.push({ name: name, field: field, events: events, tries: 0 });
  }
  function requestField(f, code){
    if(code !== null){
      // classic REDCap: __chk__…_RC_code is the real checkbox and fires events.
      queueTarget("__chk__" + f + "_RC_" + code, f, ["change", "click"]);
      // REDCap 17.x: that element is a hidden mirror (no events); the VISIBLE
      // checkbox shares the name __chkn__<field> — bind there so a live toggle
      // still re-evaluates the gate (LIVE-FOUND, see QRID_readCheckbox).
      queueTarget("__chkn__" + f, f, ["change", "click"]);
    } else {
      queueTarget(f, f, ["input", "change"]);
      queueTarget(f + "___radio", f, ["change", "click"]);
    }
    if(typeof document === "undefined" || !document.getElementsByName) return;
    if(sweep()) return;
    if(timer === null && typeof setInterval === "function"){
      timer = setInterval(function(){ sweep(); }, 500);
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
  function gateFor(exprRaw, astRaw){
    var ast;
    if(astRaw && typeof astRaw === "object" && astRaw.length){
      ast = astRaw;
    } else {
      if(exprRaw === undefined || exprRaw === null || exprRaw === "") return null;
      var r = QRID_whenParse(typeof exprRaw === "string" ? exprRaw : String(exprRaw));
      if(!r.ok){
        try {
          if(typeof console !== "undefined" && console.error){
            console.error('Universal Regex & Check-Character Validator: the "when" condition ' + r.error + ' — rule disabled: ' + exprRaw);
          }
        } catch(e){}
        return { active: function(){ return false; }, onChange: function(){} };
      }
      ast = r.ast;
    }
    var refs = QRID_whenRefs(ast);
    return {
      active: function(){
        try { return QRID_whenEvaluateWith(ast, readRef); }
        catch(e){ return false; } /* fail open: never trap a save on a gate bug */
      },
      onChange: function(cb){
        for(var i = 0; i < refs.length; i++){
          var f = refs[i][0];
          if(!hooks[f]) hooks[f] = [];
          hooks[f].push(cb);
          requestField(f, refs[i][1]);
        }
      }
    };
  }
  return { gateFor: gateFor, readRef: readRef };
})();
/* ---- optional save blocking (shared by both factories) ---------------------
   blockSave: "off" (warn-only) | "confirm" (Save anyway? dialog) | "hard"
   (refuse the BROWSER save until fixed — it cannot stop API/import writes;
   the server audit is the net for those). Guards both real form submits and
   REDCap's save buttons (which submit programmatically and never fire a
   submit event). Native alert/confirm are used deliberately: they are modal
   and announced by assistive tech without custom focus management. */
function QRID_registerBlocker(input, fieldName, blockMode){
  /* A field the user cannot edit must never trap the save: readonly/disabled
     inputs (e.g. @READONLY) keep their message but are exempt from blocking
     (UX-003) — the server audit still sees the value. */
  if(input.readOnly || input.disabled) return;
  input.__qridBlockMode = blockMode;
  input.__qridFieldName = fieldName;
  input.__qridFieldLabel = QRID_fieldLabel(input, fieldName);
  UV_guard.items.push(input);
  if(UV_guard.armed) return;
  UV_guard.armed = true;
  function guard(e){
    /* the click guard runs before the programmatic submit it triggers; a
       user who already chose "Save anyway" must not be asked twice */
    if(UV_guard.passUntil && new Date().getTime() < UV_guard.passUntil) return;
    /* Branch rules overwrite __qridBlockMode per check with the ACTIVE
       branch's mode, which may be "off" — such a field never holds the save.
       (Pre-0.9.0 configs never register "off" items, so this filter is a
       no-op for them.) */
    var bad = UV_guard.items.filter(function(el){ return el.__qridInvalid && el.__qridBlockMode !== "off"; });
    if(!bad.length) return;
    var names = bad.map(function(el){ return el.__qridFieldLabel || el.__qridFieldName; }).join(", ");
    var hard = bad.some(function(el){ return el.__qridBlockMode === "hard"; });
    if(hard){
      e.preventDefault();
      if(e.stopImmediatePropagation) e.stopImmediatePropagation();
      window.alert("Cannot save yet — please fix the flagged field(s): " + names);
      try { bad[0].focus(); } catch(_f){}
      return;
    }
    if(window.confirm("Validation FAILED for: " + names + "\n\nSave anyway?")){
      UV_guard.passUntil = new Date().getTime() + 2000;
    } else {
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
/* Wire one message region under one input: polite live region + programmatic
   error relationship, so dynamic verdicts are exposed to assistive technology
   (WCAG 2.2 SC 4.1.3 / F103 — A11Y-001). */
function QRID_attachMsgRegion(input, fieldName){
  var msg = document.createElement("div");
  msg.style.display = "none";
  msg.id = QRID_msgId(fieldName);
  if(msg.setAttribute){
    msg.setAttribute("role", "status");
    msg.setAttribute("aria-live", "polite");
    msg.setAttribute("aria-atomic", "true");
  }
  input.parentNode.insertBefore(msg, input.nextSibling);
  if(input.setAttribute && input.getAttribute){
    var desc = input.getAttribute("aria-describedby") || "";
    input.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
  }
  return msg;
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
   (caller falls back to the page-level notice). */
function QRID_attachErrorRegion(fieldName, configError){
  var input = QRID_findField(fieldName);
  if(!input) return false;
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
  input.parentNode.insertBefore(msg, input.nextSibling);
  if(input.setAttribute && input.getAttribute){
    var desc = input.getAttribute("aria-describedby") || "";
    input.setAttribute("aria-describedby", desc ? desc + " " + msg.id : msg.id);
  }
  msg.innerHTML = QRID_IS_SURVEY
    ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
    : "&#9888; ID-check configuration error: " + QRID_escapeHtml(configError);
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
    box.innerHTML = "<b>&#9888; Universal Regex & Check-Character Validator — configuration error(s):</b>";
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
  if(cfg.branches && cfg.branches.length){
    for(var i = 0; i < cfg.branches.length; i++){
      var v = makeVariant(cfg.branches[i]);
      if(v.when === null) elseVariant = v;
      else variants.push(v);
    }
  } else {
    variants = [makeVariant(cfg)];
  }
  var all = elseVariant ? variants.concat([elseVariant]) : variants;
  var configError = "";
  for(var c = 0; c < all.length; c++){
    if(all[c].configError){ configError = all[c].configError; break; }
  }
  var firstBlock = "off";
  for(var b = 0; b < all.length; b++){
    if(all[b].blockSave !== "off"){ firstBlock = all[b].blockSave; break; }
  }
  return { variants: variants, elseVariant: elseVariant, all: all,
           configError: configError, firstBlock: firstBlock };
}
/* The variants whose gate is true right now; the else variant only when no
   conditional one is active. A gate-less variant (legacy rule without "when")
   is always active. */
function QRID_activeVariants(vs){
  var act = [];
  for(var i = 0; i < vs.variants.length; i++){
    var g = vs.variants[i].gate;
    if(!g || g.active()) act.push(vs.variants[i]);
  }
  if(!act.length && vs.elseVariant) act = [vs.elseVariant];
  return act;
}
/* More than one branch condition is true at once: a configuration problem,
   not a data problem — show it (generic on surveys), validate nothing, and
   NEVER hold the save (the server audit logs the same conflict). */
function QRID_renderConflict(msg, input, act){
  msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
    "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
  msg.innerHTML = QRID_IS_SURVEY
    ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
    : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
      QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
      '". The value was <b>NOT</b> validated; make the conditions mutually exclusive.';
  input.style.outline = ""; input.__qridInvalid = false;
  QRID_setInvalidState(input, null);
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

  /* ---- resolve the validation mode from the config ---- */
  var configError = cfg.configErrorOverride || "";
  var CHECK_MODE = false, REGEX_ONLY = false, fullRe = null;
  var algoName = cfg.algorithm;
  if(!configError && algoName !== "none" && !Q.ALGORITHMS[algoName]){
    configError = "Unknown algorithm \"" + algoName + "\". Valid: " +
      Object.keys(Q.ALGORITHMS).join(", ") + ".";
  }
  if(!configError && cfg.idPattern){
    var rawPatS = String(cfg.idPattern);
    if(/\\[AZ]/.test(rawPatS)){
      /* JS would silently treat \A / \Z as literal letters — a Python-only trap */
      configError = "idPattern uses Python-only \\A or \\Z anchors; patterns are JavaScript " +
        "regex — use ^ and $ instead (anchors are optional anyway).";
    } else if(/[^\x20-\x7E]/.test(rawPatS)){
      /* the browser (UTF-16) and server (PCRE /u) only provably agree on ASCII */
      configError = "idPattern must contain printable ASCII only — the browser and server " +
        "regex engines are only guaranteed to agree on that subset.";
    } else if(QRID_riskyPattern(rawPatS)){
      configError = "idPattern looks catastrophically backtracking (nested quantifiers, a " +
        "repeated ambiguous group, or overlapping unbounded quantifiers — e.g. (a+)+, (a|aa)+, " +
        "or .*.* / [0-9]*[0-9]*). Rewrite it so no ambiguous group repeats and no two unbounded " +
        "quantifiers overlap (use bounded {min,max} counts or a disjoint class between them).";
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
    var srcS = cfg.source || "normalized_id";
    if(srcS !== "normalized_id" && srcS !== "digits_only" && srcS !== "sequence_only"){
      configError = 'Unknown source "' + srcS + '" — use normalized_id, digits_only or sequence_only.';
    }
  }
  var scheme = CHECK_MODE ? Q.makeScheme({
    /* explicit fallbacks: an absent key must not override makeScheme's
       defaults with undefined (Object.assign copies undefined values) */
    algorithm: algoName, source: cfg.source || "normalized_id",
    placement: "append", delimiter: "-",
    normalize_rules: { strip_delimiters: cfg.strip || "", uppercase: true,
                       unify_unicode_dashes: true, keep_only: null },
    enabled: true
  }) : null;
  var nCheck = CHECK_MODE ? Q.ALGORITHMS[algoName].nCheckChars : 0;
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
  var atoms = fullRe ? tokenizePattern(cfg.idPattern) : null;
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
    if(!cfg.suggestFix || cfg.source !== "normalized_id") return "";
    try {
      var norm = Q.normalize(raw, scheme.normalize_rules);
      if(norm.length <= nCheck) return "";
      var expected = Q.ALGORITHMS[algoName].compute(norm.slice(0, -nCheck));
      return ' If everything before the last ' + (nCheck > 1 ? nCheck + ' characters' : 'character') +
        ' is correct, the ID should end in <b style="font-family:monospace">' + QRID_escapeHtml(expected) + "</b>." +
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
            /* rem is built from the configured pattern (atom .desc, which may
               echo a raw [class] body) — escape before it reaches innerHTML. */
            var rem = QRID_escapeHtml(remainingText(w.atomIdx, w.needed));
            if(!isFinal) return { ok: "info",
              html: "&#8230; format OK so far &mdash; remaining: <b>" + rem + "</b>." };
            return { ok: false,
              html: "&#10007; FORMAT error &mdash; the ID is incomplete. Still remaining: <b>" + rem + "</b>." };
          }
          if(w.state === "mismatch"){
            return { ok: false, html: "&#10007; FORMAT error at character " + (w.pos + 1) +
              ": expected " + QRID_escapeHtml(w.expected) + ", got <b style=\"font-family:monospace\">" +
              QRID_escapeHtml(w.got) + "</b>." +
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

  var BLOCK = cfg.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  /* optional "when" condition: null when absent (no behavior change) */
  var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);
  return { configError: configError, verdict: verdict, gate: GATE, blockSave: BLOCK,
           when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
           mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY, guidance: !!atoms } };
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
           never hold the save (the submit guard reads only __qridInvalid). */
        msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false;
        QRID_setInvalidState(input, null);
        return;
      }
      if(act.length > 1){
        QRID_renderConflict(msg, input, act);
        return;
      }
      var V = act[0];
      input.__qridBlockMode = V.blockSave;   /* the ACTIVE variant governs blocking */
      var v = (input.value || "").trim();
      if(!v){
        msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false;
        QRID_setInvalidState(input, null);
        return;
      }
      if(v.length > QRID_MAX_SINGLE_LEN){
        styleMsg(msg, false);
        msg.innerHTML = "&#10007; This value is too long for an ID field (over " +
          QRID_MAX_SINGLE_LEN + " characters) — validation skipped.";
        input.style.outline = "2px solid #c62828"; input.__qridInvalid = true;
        QRID_setInvalidState(input, true);
        return;
      }
      var r = V.verdict(v, !!isFinal);
      styleMsg(msg, r.ok);
      input.style.outline = (r.ok === true) ? "2px solid #2e9e44"
                          : (r.ok === "info") ? "2px solid #0067c0" : "2px solid #c62828";
      input.__qridInvalid = (r.ok !== true);          /* "info" (still typing) also blocks a save */
      QRID_setInvalidState(input, r.ok === false);    /* "info" is not announced as an error yet */
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
    if(ANY_BLOCK && !configError) QRID_registerBlocker(input, fieldName, VS.firstBlock);
    check(true);
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    if(IS_BRANCH){
      UV_validators[f] = { type: "single", branch: true,
        branches: VS.all.map(function(V){ return { when: V.when, mode: V.mode, test: V.verdict }; }),
        active: function(){ return QRID_activeVariants(VS); } };
      return;
    }
    var V0 = VS.all[0];
    UV_validators[f] = { type: "single",
      mode: { check: V0.mode.check, regexOnly: V0.mode.regexOnly, configError: configError,
              guidance: V0.mode.guidance, blockSave: V0.blockSave },
      test: function(v, isFinal){ return configError ? null : V0.verdict(v, isFinal !== false); } };
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
    var assertGate = configError ? null : QRID_WHEN.gateFor(cfg.assert, cfg.assertAst);
    if(!configError && !assertGate){
      configError = "@UVASSERT has no condition to check — set an \"assert\" such as [end_date]>=[start_date].";
    }
    var BLOCK = cfg.blockSave || "off";
    if(!configError && BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
      configError = 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
    }
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);   /* applicability */
    return { configError: configError, assertGate: assertGate, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
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
    var msg = QRID_attachMsgRegion(input, fieldName);
    /* Own guard item — a WRAPPER, not the input element. A check rule (or a
       later required/unique rule) may sit on the SAME input; each mode keeps an
       INDEPENDENT invalid/block state so they compose instead of stomping one
       shared flag. Readonly/disabled fields keep their message but never trap
       the save (the anchor's state is copied onto the wrapper). */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !input.readOnly && !input.disabled){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName),
                readOnly: false, disabled: false,
                focus: function(){ try { input.focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function inert(){ msg.style.display = "none"; input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){ inert(); return; }              /* no applicability gate true -> inert */
      if(act.length > 1){                               /* branch conflict: show, validate nothing, never block */
        styleMsg(msg, false);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The value was <b>NOT</b> validated; make the conditions mutually exclusive.';
        input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); return;
      }
      var V = act[0];
      var val = QRID_WHEN.readRef(fieldName, null);
      if(val === null || String(val).trim() === ""){ inert(); return; }   /* empty field is inert */
      var ok = true;
      try { ok = V.assertGate.active(); } catch(e){ ok = true; }          /* fail open: a gate bug never traps a save */
      styleMsg(msg, ok);
      input.style.outline = ok ? "2px solid #2e9e44" : "2px solid #c62828";
      setGuard(!ok, V.blockSave);
      QRID_setInvalidState(input, !ok);
      msg.innerHTML = ok
        ? "&#10003; OK."
        : "&#10007; " + (V.message ? QRID_escapeHtml(V.message)
            : (QRID_IS_SURVEY ? "This entry is not valid together with the other answers."
                              : "This value fails its validation rule for this field."));
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
      test: function(){ var a = QRID_activeVariants(VS); return (a.length === 1) ? a[0].assertGate.active() : null; } };
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
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);   /* requirement gate */
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
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
    var msg = QRID_attachMsgRegion(input, fieldName);
    /* Own guard item (see QRIDConstraintInit): independent invalid/block state
       so required composes with any other mode on the same input. */
    var GITEM = null;
    if(ANY_BLOCK && !configError && !input.readOnly && !input.disabled){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName),
                readOnly: false, disabled: false,
                focus: function(){ try { input.focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function clear(){ msg.style.display = "none"; input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){ clear(); return; }              /* no requirement in force */
      if(act.length > 1){                               /* branch conflict: show, never block */
        styleMsg(msg);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The requirement was <b>NOT</b> enforced; make the conditions mutually exclusive.';
        input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); return;
      }
      var V = act[0];
      var blank = String(QRID_WHEN.readRef(fieldName, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "") === "";
      if(!blank){ clear(); return; }                    /* satisfied — no green OK on purpose */
      styleMsg(msg);
      input.style.outline = "2px solid #c62828";
      setGuard(true, V.blockSave);
      QRID_setInvalidState(input, true);
      msg.innerHTML = "&#10007; " + (V.message ? QRID_escapeHtml(V.message)
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
    check();
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users) */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "required", mode: { required: true, configError: configError },
      test: function(){ var a = QRID_activeVariants(VS);
        if(a.length !== 1) return null;
        return String(QRID_WHEN.readRef(f, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "") !== ""; } };
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
/* ---- choice filter (@UVCHOICES) --------------------------------------------
   Dynamic choice filtering: show/hide individual options of a radio, dropdown
   or checkbox field while a condition holds (REDCap's @HIDECHOICE is static).
   Each branch carries a "show" whitelist or "hide" blacklist; the hidden set
   is show ? complement over choicesAll (the field's full code list, attached
   server-side from the data dictionary) : the hide list. Rendering:
     dropdown  — hidden <option>s are physically REMOVED and re-inserted in
                 their original order when unhidden (Safari ignores hidden/
                 display:none on <option>, removal is the one portable way);
     radio     — the input named <field>___radio with value=code hides its
                 parent wrapper; the hidden mirror input is never touched;
     checkbox  — the input named __chk__<field>_RC_<code> hides its parent.
   A currently-SELECTED choice is NEVER hidden or cleared: it stays visible
   (dropdown: kept + disabled), the field is flagged invalid with the message,
   and blockSave (off/confirm/hard) decides whether the save is challenged —
   silently destroying an entered value is the one thing this mode must never
   do. A value outside choicesAll (e.g. a missing-data code) is out of scope.
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
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
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
    var msg = QRID_attachMsgRegion(input, fieldName);
    var GITEM = null;
    if(ANY_BLOCK && !configError && !input.readOnly && !input.disabled){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName),
                readOnly: false, disabled: false,
                focus: function(){ try { input.focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }

    /* ---- the three renderers (kind detected per call — late DOM tolerant) */
    var select = (input.tagName || "").toLowerCase() === "select" ? input : null;
    var selectOrig = null;   /* original <option> order, snapshotted once */
    function snapshotOptions(){
      if(selectOrig !== null) return;
      selectOrig = [];
      for(var i = 0; i < select.children.length; i++){
        var o = select.children[i];
        if((o.tagName || "").toLowerCase() === "option") selectOrig.push(o);
      }
    }
    function renderSelect(hiddenSet){
      snapshotOptions();
      var cur = select.value == null ? "" : String(select.value);
      for(var i = 0; i < selectOrig.length; i++){
        var o = selectOrig[i];
        var val = o.value == null ? "" : String(o.value);
        var isCur = (val !== "" && val === cur);
        /* the blank placeholder and the CURRENT selection are never removed */
        var show = (val === "") || isCur || !hiddenSet[val];
        if(show && o.parentNode !== select){
          var ref = null;   /* next original option still attached keeps order */
          for(var j = i + 1; j < selectOrig.length && !ref; j++){
            if(selectOrig[j].parentNode === select) ref = selectOrig[j];
          }
          select.insertBefore(o, ref);
        } else if(!show && o.parentNode === select){
          select.removeChild(o);
        }
        /* a kept-but-hidden current selection is visibly dead-ended */
        o.disabled = !!(isCur && hiddenSet[val]);
      }
    }
    function optionWrappers(code){
      var out = [];
      var els = document.getElementsByName ? document.getElementsByName(fieldName + "___radio") : [];
      for(var i = 0; i < els.length; i++){
        if(String(els[i].value) === code) out.push(els[i]);
      }
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
      for(var i = 0; i < MANAGED.length; i++){
        var code = MANAGED[i];
        /* a checked/selected option is NEVER hidden from under the user. The
           checked state is read robustly (QRID_readCheckbox) because a checkbox
           option's .checked cannot be read off the hidden mirror on REDCap 17. */
        var selected = isCheckbox
          ? (QRID_readCheckbox(fieldName, code) === "1")
          : (String(QRID_WHEN.readRef(fieldName, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "") === code);
        var hide = !!hiddenSet[code] && !selected;
        var els = optionWrappers(code);
        for(var j = 0; j < els.length; j++){
          var box = els[j].parentNode;
          if(box && box.style) box.style.display = hide ? "none" : "";
        }
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
        var v = String(QRID_WHEN.readRef(fieldName, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "");
        if(v !== "" && V.hiddenSet[v] && (!V.all || QRID_inList(V.all, v))) out.push(v);
        return out;
      }
      for(var i = 0; i < V.hiddenList.length; i++){        /* checkbox: any checked hidden code */
        var code = V.hiddenList[i];
        if(QRID_WHEN.readRef(fieldName, code) === "1") out.push(code);
      }
      return out;
    }
    var NONE = Object.create(null);
    function clear(){ msg.style.display = "none"; input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){ render(NONE); clear(); return; }   /* no filter in force — everything shown */
      if(act.length > 1){                                  /* branch conflict: show, never filter, never block */
        render(NONE);
        styleMsg(msg);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The choice filter was <b>NOT</b> applied; make the conditions mutually exclusive.';
        input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); return;
      }
      var V = act[0];
      render(V.hiddenSet);
      var stale = staleCodes(V);
      if(!stale.length){ clear(); return; }
      styleMsg(msg);
      input.style.outline = "2px solid #c62828";
      setGuard(true, V.blockSave);
      QRID_setInvalidState(input, true);
      msg.innerHTML = "&#10007; " + (V.message ? QRID_escapeHtml(V.message)
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
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(function(){ check(); });
    for(var mc = 0; mc < MANAGED.length; mc++){
      var codeWatch = QRID_WHEN.gateFor("[" + fieldName + "(" + MANAGED[mc] + ")]='1'", null);
      if(codeWatch) codeWatch.onChange(function(){ check(); });
    }
    for(var gi = 0; gi < VS.all.length; gi++){
      if(VS.all[gi].gate) VS.all[gi].gate.onChange(function(){ check(); });
    }
    check();
    return true;
  }
  /* per-field registry (namespace .validators — testing / power users):
     test() = "no stale selection under the single active filter" */
  (QRID_CONFIG.fields || []).forEach(function(f){
    UV_validators[f] = { type: "choices", mode: { choices: true, configError: configError },
      test: function(){ var a = QRID_activeVariants(VS);
        if(a.length !== 1) return null;
        var v = String(QRID_WHEN.readRef(f, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "");
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
function QRID_uniqueTransport(){
  var name = (typeof QRID_COMBINED_CONFIG === "object" && QRID_COMBINED_CONFIG) ? QRID_COMBINED_CONFIG.jsmoName : null;
  if(typeof name !== "string" || !name) return null;
  try {
    var obj = name.split(".").reduce(function(o, k){ return (o && o[k] !== undefined) ? o[k] : null; }, window);
    if(obj && typeof obj.ajax === "function") return obj;
  } catch(e){}
  return null;
}
function QRIDUniqueInit(QRID_CONFIG){
  function styleMsg(el, kind){   /* true=free, false=used, "info"=checking */
    var c = (kind === true) ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
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
    var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);
    return { configError: configError, gate: GATE, blockSave: BLOCK,
             message: (typeof cfg.message === "string" && cfg.message !== "") ? cfg.message : "",
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
    var msg = QRID_attachMsgRegion(input, fieldName);
    var GITEM = null;
    if(ANY_BLOCK && !configError && !input.readOnly && !input.disabled){
      GITEM = { __qridInvalid: false, __qridBlockMode: "off",
                __qridFieldName: fieldName, __qridFieldLabel: QRID_fieldLabel(input, fieldName),
                readOnly: false, disabled: false,
                focus: function(){ try { input.focus(); } catch(e){} } };
      QRID_registerBlocker(GITEM, fieldName, VS.firstBlock);
    }
    function setGuard(invalid, mode){ if(GITEM){ GITEM.__qridInvalid = invalid; GITEM.__qridBlockMode = invalid ? (mode || "off") : "off"; } }
    function inert(){ msg.style.display = "none"; input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); }
    var seq = 0;            /* stale-response guard: only the LATEST request may render */
    var pendingKey = null;  /* candidate key already asked, awaiting an answer */
    var lastResp = null;    /* {key, resp} — one-deep answer cache. The direct input
      listeners and the when-registry self-watch BOTH recheck on a change; the
      cache turns the second pass (and a gate re-flip over the same value) into
      a synchronous render instead of a duplicate server request. */
    function renderResp(resp, V){
      if(resp.used){
        styleMsg(msg, false);
        input.style.outline = "2px solid #c62828";
        setGuard(true, V.blockSave);
        QRID_setInvalidState(input, true);
        msg.innerHTML = "&#10007; " + (V.message ? QRID_escapeHtml(V.message)
            : "This value is already recorded" +
              (resp.record ? " (record <b>" + QRID_escapeHtml(String(resp.record)) + "</b>)" : "") + ".");
      } else {
        styleMsg(msg, true);
        input.style.outline = "2px solid #2e9e44";
        setGuard(false);
        QRID_setInvalidState(input, false);
        msg.innerHTML = "&#10003; Not used before.";
      }
    }
    function check(){
      var act = QRID_activeVariants(VS);
      if(!act.length){ inert(); return; }
      if(act.length > 1){
        styleMsg(msg, false);
        msg.innerHTML = QRID_IS_SURVEY
          ? "&#9888; Automatic checking of this field is unavailable (a configuration issue has been logged for the study team)."
          : '&#9888; Validation conflict: more than one "when" condition is true for this field right now — "' +
            QRID_escapeHtml(act[0].when) + '" and "' + QRID_escapeHtml(act[1].when) +
            '". The value was <b>NOT</b> checked; make the conditions mutually exclusive.';
        input.style.outline = ""; setGuard(false); QRID_setInvalidState(input, null); return;
      }
      var V = act[0];
      /* Surveys are opt-in per rule: a used/free answer is record-derived
         information a respondent would not otherwise see (SEC-005 posture). */
      if(QRID_IS_SURVEY && !V.surveys){ inert(); return; }
      var val = String(QRID_WHEN.readRef(fieldName, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "");
      if(val === ""){ inert(); return; }
      var payload = { field: fieldName, values: {} };
      payload.values[fieldName] = val;
      for(var wi = 0; wi < V.uniqueWith.length; wi++){
        var w = V.uniqueWith[wi];
        payload.values[w] = String(QRID_WHEN.readRef(w, null)).replace(/^[ \t\r\n]+|[ \t\r\n]+$/g, "");
      }
      var key = JSON.stringify(payload.values);
      if(lastResp && lastResp.key === key){ renderResp(lastResp.resp, V); return; }  /* cached answer */
      if(pendingKey === key) return;                    /* already asked; the answer will render */
      var t = QRID_uniqueTransport();
      if(!t){
        try { if(typeof console !== "undefined" && console.error) console.error("Universal Regex & Check-Character Validator: no AJAX transport for the uniqueness check — rule inert."); } catch(e){}
        inert(); return;
      }
      var my = ++seq;
      pendingKey = key;
      /* pending: informative, never blocking (fail open if no answer comes) */
      styleMsg(msg, "info");
      msg.innerHTML = "&#8230; checking whether this value is already recorded&hellip;";
      input.style.outline = "2px solid #0067c0";
      setGuard(false);
      QRID_setInvalidState(input, null);
      function render(err, resp){
        if(my !== seq) return;   /* a newer keystroke superseded this answer */
        pendingKey = null;
        if(err || !resp || resp.error || typeof resp.used === "undefined"){
          try { if(typeof console !== "undefined" && console.error) console.error("Universal Regex & Check-Character Validator: uniqueness check failed — " + (err || (resp && resp.error) || "bad response")); } catch(e){}
          inert(); return;       /* fail open; the server audit is the net */
        }
        lastResp = { key: key, resp: resp };
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
    var debounced = QRID_debounced(function(){ check(); });
    if(input.addEventListener){
      input.addEventListener("input", debounced);
      input.addEventListener("change", function(){ if(debounced.cancel) debounced.cancel(); check(); });
      input.addEventListener("blur", function(){ if(debounced.cancel) debounced.cancel(); check(); });
    }
    /* watch the field itself and every composite member through the shared
       when-registry (same trick as QRIDRequiredInit — wiring only) */
    var selfWatch = QRID_WHEN.gateFor("[" + fieldName + "]<>''", null);
    if(selfWatch) selfWatch.onChange(function(){ check(); });
    for(var vi = 0; vi < VS.all.length; vi++){
      if(VS.all[vi].gate) VS.all[vi].gate.onChange(function(){ check(); });
      for(var wj = 0; wj < VS.all[vi].uniqueWith.length; wj++){
        var ww = QRID_WHEN.gateFor("[" + VS.all[vi].uniqueWith[wj] + "]<>''", null);
        if(ww) ww.onChange(function(){ check(); });
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
/* ---- pooled-field validator (factory: one instance per config/rule) ---- */
function QRIDPooledInit(QRID_MULTI_CONFIG){
  var ALPHA = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

  /* ---- one validation VARIANT: the whole mode-resolution + parser closure,
     built once per rule (legacy) or once per branch (branch rules). ---- */
  function makeVariant(cfg){

  /* ---- resolve the validation mode from the config ---- */
  var configError = cfg.configErrorOverride || "";
  var CHECK_MODE = false, REGEX_ONLY = false, fullRe = null;
  var algoName = cfg.algorithm;
  if(!configError && algoName !== "none" && !Q.ALGORITHMS[algoName]){
    configError = "Unknown algorithm \"" + algoName + "\". Valid: " +
      Object.keys(Q.ALGORITHMS).join(", ") + ".";
  }
  if(!configError && cfg.idPattern){
    var rawPatP = String(cfg.idPattern);
    if(/\\[AZ]/.test(rawPatP)){
      /* JS would silently treat \A / \Z as literal letters — a Python-only trap */
      configError = "idPattern uses Python-only \\A or \\Z anchors; patterns are JavaScript " +
        "regex — use ^ and $ instead (anchors are optional anyway).";
    } else if(/[^\x20-\x7E]/.test(rawPatP)){
      /* the browser (UTF-16) and server (PCRE /u) only provably agree on ASCII */
      configError = "idPattern must contain printable ASCII only — the browser and server " +
        "regex engines are only guaranteed to agree on that subset.";
    } else if(QRID_riskyPattern(rawPatP)){
      configError = "idPattern looks catastrophically backtracking (nested quantifiers, a " +
        "repeated ambiguous group, or overlapping unbounded quantifiers — e.g. (a+)+, (a|aa)+, " +
        "or .*.* / [0-9]*[0-9]*). Rewrite it so no ambiguous group repeats and no two unbounded " +
        "quantifiers overlap (use bounded {min,max} counts or a disjoint class between them).";
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
  var srcP = cfg.source || "normalized_id";
  if(!configError && CHECK_MODE &&
     srcP !== "normalized_id" && srcP !== "digits_only" && srcP !== "sequence_only"){
    configError = 'Unknown source "' + srcP + '" — use normalized_id, digits_only or sequence_only.';
  }
  var scheme = CHECK_MODE ? Q.makeScheme({
    algorithm: algoName, source: srcP,
    placement: "append", delimiter: "-",
    normalize_rules: { strip_delimiters: cfg.strip || "", uppercase: true,
                       unify_unicode_dashes: true, keep_only: null },
    enabled: true
  }) : null;
  var nCheck = CHECK_MODE ? Q.ALGORITHMS[algoName].nCheckChars : 0;
  /* ---- validate the length configuration ---- */
  function isPosInt(x){ return typeof x === "number" && isFinite(x) && x > 0 && x % 1 === 0; }
  var minLen = cfg.idMinLen === undefined ? 8 : cfg.idMinLen;
  var maxLen = cfg.idMaxLen === undefined ? 14 : cfg.idMaxLen;
  var LENS = [];
  if(!configError && cfg.idLengths != null){
    var lensCfg = cfg.idLengths;
    if(!(lensCfg instanceof Array) || !lensCfg.length || !lensCfg.every(isPosInt)){
      configError = "idLengths must be a list of positive whole numbers, e.g. [10] — got " +
        JSON.stringify(lensCfg) + ".";
    } else {
      LENS = lensCfg.slice().sort(function(a, b){ return a - b; });
      minLen = LENS[0]; maxLen = LENS[LENS.length - 1];
      if(LENS.length > QRID_MAX_LEN_CHOICES){
        configError = "idLengths lists " + LENS.length + " lengths — at most " +
          QRID_MAX_LEN_CHOICES + " are supported.";
      } else if(maxLen > QRID_MAX_ID_LEN){
        configError = "ID lengths above " + QRID_MAX_ID_LEN + " characters are not supported.";
      }
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
    } else if(maxLen > QRID_MAX_ID_LEN){
      /* cap BEFORE the range loop below — a huge range must not even allocate */
      configError = "idMaxLen (" + maxLen + ") is above the supported maximum of " + QRID_MAX_ID_LEN + ".";
    }
    if(!configError){
      minLen = Math.max(minLen, nCheck + 1);
      for(var _L = minLen; _L <= maxLen; _L++) LENS.push(_L);
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
  var KEEP = ALPHA + (cfg.keepChars || "");
  if(CHECK_MODE){
    var CA = Q.ALGORITHMS[algoName].checkAlphabet || "";
    for(var _c = 0; _c < CA.length; _c++)
      if(KEEP.indexOf(CA.charAt(_c)) < 0) KEEP += CA.charAt(_c);
  }
  if(fullRe){
    var _pat = String(cfg.idPattern), _meta = "\\^$.|?*+()[]{}";
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
  /* Per-rule scan cap: the full QRID_MAX_POOLED_LEN unless this rule's length
     configuration makes parsing expensive, in which case the cap shrinks so
     one parse stays inside QRID_POOLED_WORK_BUDGET char-ops (PER-002). Mirrors
     CheckCharacter::pooledScanCap (php) — keep the formula identical. */
  var SCAN_CAP = configError ? QRID_MAX_POOLED_LEN : Math.min(QRID_MAX_POOLED_LEN,
    Math.max(256, Math.floor(QRID_POOLED_WORK_BUDGET / ((LENS.length || 1) * (maxLen || 1)))));
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
    /* over-budget input: no verdict at all (null), never a slow parse — the
       server bails identically, so the two runtimes cannot disagree here */
    if(Array.from(String(raw)).length > SCAN_CAP) return null;
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
  var BLOCK = cfg.blockSave || "off";
  if(BLOCK !== "off" && BLOCK !== "confirm" && BLOCK !== "hard"){
    configError = configError || 'blockSave must be "off", "confirm" or "hard" — got "' + BLOCK + '".';
  }
  /* optional "when" condition: null when absent (no behavior change) */
  var GATE = configError ? null : QRID_WHEN.gateFor(cfg.when, cfg.whenAst);
  return { configError: configError, clean: clean, parse: parse, scanCap: SCAN_CAP,
           expectedIds: (cfg.expectedIds == null ? null : cfg.expectedIds),
           blockSave: BLOCK, gate: GATE,
           when: (typeof cfg.when === "string" && cfg.when !== "") ? cfg.when : null,
           mode: { check: CHECK_MODE, regexOnly: REGEX_ONLY } };
  }
  /* ---- end makeVariant ---- */

  var VS = QRID_buildVariants(QRID_MULTI_CONFIG, makeVariant);
  var configError = VS.configError;
  var IS_BRANCH = !!(QRID_MULTI_CONFIG.branches && QRID_MULTI_CONFIG.branches.length);
  var ANY_BLOCK = VS.firstBlock !== "off";
  var V0 = VS.all[0];
  var api = { clean: V0.clean, parse: V0.parse,        /* exposed for testing / power users */
              mode: { check: V0.mode.check, regexOnly: V0.mode.regexOnly, configError: configError } };
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
  function chip(text, kind){
    /* Color families: hard problems (bad check character AND junk that is not
       an ID) are red; a DUPLICATE of a valid ID is a warning, not an error —
       amber. Amber #8a5500 on #fbf6e8 measures 5.7:1 and red #c62828 on
       #fbeceb 4.9:1 — WCAG 2.2 AA for normal text needs 4.5:1, and chips are
       12px (A11Y-002). Every state also has a non-color mark
       (check / circled-x / cross / question mark). */
    var c = (kind === "ok") ? "#bcd9bd;background:#eef7ef;color:#2e7d32"
          : (kind === "dup") ? "#e6d4a8;background:#fbf6e8;color:#8a5500"
          : "#e0b4b0;background:#fbeceb;color:#c62828";   /* bad + junk share red */
    var mark = (kind === "ok") ? "&#10003;&nbsp;"
             : (kind === "dup") ? "&#8855;&nbsp;"          /* circled x = scanned again */
             : (kind === "bad") ? "&#10007;&nbsp;" : "?&nbsp;";
    var suffix = (kind === "dup") ? "&nbsp;(again!)" : "";
    return '<span style="display:inline-block;margin:2px 4px 2px 0;padding:2px 8px;border-radius:10px;' +
      'font-family:monospace;font-size:12px;border:1px solid ' + c + '">' + mark + text + suffix + '</span>';
  }
  function render(msg, input){
    /* which variant applies right now? (see the single factory) */
    var act = QRID_activeVariants(VS);
    if(!act.length){
      msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false;
      QRID_setInvalidState(input, null);
      return;
    }
    if(act.length > 1){
      QRID_renderConflict(msg, input, act);
      return;
    }
    var V = act[0];
    input.__qridBlockMode = V.blockSave;   /* the ACTIVE variant governs blocking */
    var v = (input.value || "").trim();
    if(!v){
      msg.style.display = "none"; input.style.outline = ""; input.__qridInvalid = false;
      QRID_setInvalidState(input, null);
      return;
    }
    if(v.length > V.scanCap){
      msg.style.cssText = "display:block;margin:4px 0;padding:6px 10px;border-radius:4px;" +
        "font-size:13px;font-family:inherit;border:1px solid #e0b4b0;background:#fbeceb;color:#c62828";
      msg.innerHTML = "&#10007; This field is too long to scan (over " + V.scanCap +
        " characters for this rule's ID lengths) — split the pool into smaller entries.";
      input.style.outline = "2px solid #c62828"; input.__qridInvalid = true;
      QRID_setInvalidState(input, true);
      return;
    }
    var segs = V.parse(v);
    var ids = segs.filter(function(x){ return x.type === "id"; });
    var junkSegs = segs.filter(function(x){ return x.type === "junk"; });
    var bad = ids.filter(function(x){ return !x.valid; });
    var seen = {}, dups = 0;
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
    var okWord = V.mode.regexOnly ? "all match the ID format &#10003; (no check character in this project)"
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
    QRID_setInvalidState(input, !ok);
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
    if(ANY_BLOCK && !configError) QRID_registerBlocker(input, fieldName, VS.firstBlock);
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
  var DEFAULT_KEYS = ["algorithm", "idPattern", "source", "strip", "suggestFix",
                      "keepChars", "idLengths", "idMinLen", "idMaxLen", "expectedIds",
                      "blockSave", "when", "whenAst",
                      /* constraint mode (@UVASSERT) */
                      "assert", "assertAst", "message",
                      /* unique mode (@UVUNIQUE) */
                      "uniqueWith", "uniqueScope", "uniqueSurveys",
                      /* choices mode (@UVCHOICES) */
                      "choicesShow", "choicesHide", "choicesAll"];
  /* The validation MODE a rule's type belongs to — twin of
     Branching::modeOfType in php/Branching.php. Different modes on one field
     COMPOSE (each attaches its own validator); same-mode sharing is branched
     server-side. Duplicate detection therefore keys on (field, mode). */
  function modeOfType(t){
    return (t === "constraint" || t === "required" || t === "unique" || t === "choices") ? t : "check";
  }
  function cfgFor(rule){
    var cfg = {}, i, k;
    /* Branch rule (php/Branching.php): defaults are filled PER BRANCH, so a
       branch and a plain rule default identically. A branch's explicit
       when:null (the else branch) must not inherit anything — only an
       UNDEFINED key falls back to the top-level config. */
    if(rule.branches && rule.branches.length){
      cfg.branches = [];
      for(i = 0; i < rule.branches.length; i++){
        var b = rule.branches[i], bc = {};
        for(var j = 0; j < DEFAULT_KEYS.length; j++){
          k = DEFAULT_KEYS[j];
          bc[k] = (b[k] !== undefined) ? b[k] : C[k];
        }
        cfg.branches.push(bc);
      }
      cfg.fields = rule.fields || [];
      if(rule.configError) cfg.configErrorOverride = rule.configError;
      return cfg;
    }
    for(i = 0; i < DEFAULT_KEYS.length; i++){
      k = DEFAULT_KEYS[i];
      cfg[k] = (rule[k] !== undefined) ? rule[k] : C[k];
    }
    cfg.fields = rule.fields || [];
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
    if(rule.type === "pooled"){
      QRIDPooledInit(cfg);
    } else if(rule.type === "constraint"){
      QRIDConstraintInit(cfg);
    } else if(rule.type === "required"){
      QRIDRequiredInit(cfg);
    } else if(rule.type === "unique"){
      QRIDUniqueInit(cfg);
    } else if(rule.type === "choices"){
      QRIDChoiceFilterInit(cfg);
    } else if(rule.type === "single" || rule.type === undefined || rule.type === "" || rule.configError){
      QRIDSingleInit(cfg);
    } else {
      cfg.configErrorOverride = 'rule for fields [' + cfg.fields.join(", ") +
        '] has unknown type "' + rule.type + '" — use "single", "pooled", "constraint", "required", "unique" or "choices".';
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
  engine: Q,
  riskyPattern: QRID_riskyPattern,          /* cross-runtime gate, locked by tests/risky_js.cjs */
  configErrorNotice: QRID_configErrorNotice, /* exercised by tests/config_notice_js.cjs */
  whenLogic: {                               /* "when" twins, locked by tests/when_js.cjs */
    parse: QRID_whenParse,
    evaluate: QRID_whenEvaluate,
    referencedFields: QRID_whenRefs,
    caps: { maxLen: QRID_WHEN_MAX_LEN, maxRefs: QRID_WHEN_MAX_REFS, maxDepth: QRID_WHEN_MAX_DEPTH }
  },
  singleInit: QRIDSingleInit,
  pooledInit: QRIDPooledInit,
  constraintInit: QRIDConstraintInit,   /* @UVASSERT — locked by tests/constraint_dom_js.cjs */
  requiredInit: QRIDRequiredInit,       /* @UVREQUIRED — locked by tests/required_dom_js.cjs */
  uniqueInit: QRIDUniqueInit,           /* @UVUNIQUE — locked by tests/unique_dom_js.cjs */
  choiceFilterInit: QRIDChoiceFilterInit, /* @UVCHOICES — locked by tests/choices_dom_js.cjs */
  get validators(){ return UV_validators; },
  get guard(){ return UV_guard; },
  get lastPooled(){ return UV_lastPooled; }
};
/* The verified core published itself as a global for the legacy Injector
   contract; it is captured as .engine above — retire the alias so this module
   leaves exactly ONE global behind. */
try { delete G.QRCheck; } catch(e){ G.QRCheck = undefined; }
})();
