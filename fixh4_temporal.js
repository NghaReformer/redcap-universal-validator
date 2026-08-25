const fs = require("fs");
const p = "tools/temporal_pilot_repro.php";
let s = fs.readFileSync(p, "utf8");
const DECL = `$ret = new INSPIRE\UniversalValidator\Scan\ScanRetention($db);`;
if (s.indexOf(DECL) < 0) { console.error("decl missing"); process.exit(1); }
// One construction, before the first use. It was declared for scenario 4 and
// scenario 3 now needs it too, because the store's own purge is gone (M15) and
// ScanRetention is the only purge there is.
s = s.split(DECL).join("");
const REQ = `require_once __DIR__ . '/../php/Scan/ScanRetention.php';`;
if (s.indexOf(REQ) < 0) { console.error("require missing"); process.exit(1); }
s = s.replace(REQ, "");
s = s.replace(`require_once __DIR__ . '/../php/Scan/Hmac.php';`,
  `require_once __DIR__ . '/../php/Scan/Hmac.php';
require_once __DIR__ . '/../php/Scan/ScanRetention.php';`);
const SC3 = "=== SCENARIO 3:";
const at = s.indexOf(SC3);
if (at < 0) { console.error("scenario 3 missing"); process.exit(1); }
const lineStart = s.lastIndexOf("\n", at) + 1;
s = s.slice(0, lineStart)
  + "// ScanRetention is the ONLY purge now: the store's own purgeRuns removed the\n"
  + "// run, its records and its aggregates but not its findings, and the two had\n"
  + "// already drifted on what their second argument meant.\n"
  + "$ret = new INSPIRE\UniversalValidator\Scan\ScanRetention($db);\n"
  + s.slice(lineStart);
fs.writeFileSync(p, s);
console.log("retention hoisted");
