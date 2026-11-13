<?php
/**
 * lookup_key_php.php - the value index stores keyed hashes, not MD5 (FRM-7).
 *
 * uv_lookup_key held md5(the value's lookup key) beside the raw record id.
 * Anyone who could read the table could test guesses against it: a phone
 * number or a national id has few enough candidates to try them all. The key
 * is now the first 16 bytes of an HMAC-SHA-256 made with the module's secret
 * and the project id. This suite holds the key and the spec to that with no
 * database; tests/mysql/cases/lookup_index.php checks the rows a real server
 * stores, the clearing of version-1 keys, and forgetProject().
 *
 * Run:  php tests/lookup_key_php.php
 */

if (PHP_SAPI !== 'cli') {
    // A test or a tool, never a page: answer a web request with 404 and stop.
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/Logic.php';
require_once __DIR__ . '/../php/Scan/Schema.php';
require_once __DIR__ . '/../php/Scan/ScanDb.php';
require_once __DIR__ . '/../php/Scan/ScanStoreUnavailable.php';
require_once __DIR__ . '/../php/Scan/LookupIndex.php';

use INSPIRE\UniversalValidator\Logic;
use INSPIRE\UniversalValidator\Scan\LookupIndex;

$n = 0;
$fail = 0;
function check($label, $cond)
{
    global $n, $fail;
    $n++;
    if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
}

$S = str_repeat('ab', 32);
$T = str_repeat('cd', 32);

// ---- the key -----------------------------------------------------------------
$k = LookupIndex::keyOf($S, 7, '+237 600 000 001', null);
check('the key is 32 hex characters (16 bytes, the width of key_hash)', preg_match('/^[0-9a-f]{32}$/', $k) === 1);
check('the key is not the MD5 of the value or of its lookup key',
    $k !== md5('+237 600 000 001') && $k !== md5(Logic::lookupKey('+237 600 000 001', true, null)));
check('the key is the start of the HMAC over the lookup key, keyed with the secret and the project',
    $k === substr(hash_hmac('sha256', Logic::lookupKey('+237 600 000 001', true, null), $S . '|lookup|7'), 0, 32));
check('the same value has another key in another project', LookupIndex::keyOf($S, 8, '+237 600 000 001', null) !== $k);
check('...and under another secret', LookupIndex::keyOf($T, 7, '+237 600 000 001', null) !== $k);
check('...and the same key when asked again', LookupIndex::keyOf($S, 7, '+237 600 000 001', null) === $k);
check('letter case is folded, as the lookups compare', LookupIndex::keyOf($S, 7, 'Tk-1', null) === LookupIndex::keyOf($S, 7, 'tK-1', null));
check('a number field keys by the number', LookupIndex::keyOf($S, 7, '1.50', 'point') === LookupIndex::keyOf($S, 7, '1.5', 'point')
    && LookupIndex::keyOf($S, 7, '1.50', null) !== LookupIndex::keyOf($S, 7, '1.5', null));
check('the project id is read as a number', LookupIndex::keyOf($S, '7', 'x', null) === LookupIndex::keyOf($S, 7, 'x', null));
// 1,000 guesses of a four-digit code: none found by MD5, as every one was before
$stored = LookupIndex::keyOf($S, 7, '4821', null);
$hit = false;
for ($i = 0; $i < 10000; $i++) {
    if (md5(Logic::lookupKey(sprintf('%04d', $i), true, null)) === $stored) { $hit = true; break; }
}
check('no four-digit guess hashed with MD5 matches a stored key', !$hit);

// ---- the spec ----------------------------------------------------------------
check('the key version went up from the MD5 one', LookupIndex::KEY_VERSION >= 2);
$head = LookupIndex::specHeadOf($S);
check('a spec starts with the key version and an 8-hex id of the secret (got ' . $head . ')',
    preg_match('/^' . LookupIndex::KEY_VERSION . '\.[0-9a-f]{8}$/', $head) === 1);
check('the id is not a piece of the secret', strpos($S, substr($head, -8)) === false && strpos(hash('sha256', $S), substr($head, -8)) !== 0);
check('another secret is another spec', LookupIndex::specHeadOf($T) !== $head);
check('the spec names the number mark', LookupIndex::specOf($S, null) === $head . '|text'
    && LookupIndex::specOf($S, 'point') === $head . '|point' && LookupIndex::specOf($S, 'comma') === $head . '|comma'
    && LookupIndex::specOf($S, 'other') === $head . '|text');
check('a spec fits the column (80)', strlen(LookupIndex::specOf($S, 'point')) <= 80);
check('the version-1 spec is not one of this version', strpos('1|text', $head . '|') !== 0);

// ---- no secret: nothing asked, nothing answered ---------------------------------
final class UvKeyModule
{
    public $log = [];
    public function query($sql, $params = [])
    {
        $this->log[] = [$sql, $params];
        if (strpos($sql, 'ROW_COUNT()') !== false) return [[0]];
        return [];
    }
}
foreach ([[], ['keySecret' => ''], ['keySecret' => 'short'], ['keySecret' => 12345678901234567890123456789012], ['keySecret' => null]] as $opts) {
    $m = new UvKeyModule();
    $ix = new LookupIndex($m, $opts);
    check('with ' . json_encode($opts) . ' the index answers null and sends no statement',
        $ix->candidates(7, ['tok' => null], 'x') === null && $m->log === []);
    check('...and its cron pass does nothing',
        $ix->maintain(time() + 60) === ['built' => 0, 'dropped' => 0, 'waiting' => 0, 'left' => 0] && $m->log === []);
}

// ---- the cron's question, and forgetProject() -----------------------------------
$m = new UvKeyModule();
(new LookupIndex($m, ['keySecret' => $S]))->maintain(time() + 60);
$q = $m->log[0];
check('the cron also lists the fields keyed another way, comparing bytes, not a pattern',
    strpos($q[0], 'FROM uv_lookup_field') !== false && strpos($q[0], 'LEFT(spec, ?) <> ?') !== false
    && array_slice($q[1], -2) === [strlen($head . '|'), $head . '|']);
check('...and never sends the secret', strpos(json_encode($m->log), $S) === false);

$m = new UvKeyModule();
$ok = (new LookupIndex($m))->forgetProject(7);
$deletes = array_values(array_filter($m->log, function ($l) { return strpos($l[0], 'DELETE FROM') === 0; }));
$tables = array_map(function ($l) { return preg_match('/^DELETE FROM (\w+) WHERE project_id = \?/', $l[0], $mm) ? $mm[1] : '?'; }, $deletes);
sort($tables);
check('forgetProject() deletes the project from the four tables (got ' . json_encode($tables) . ')',
    $ok === true && $tables === ['uv_lookup_field', 'uv_lookup_key', 'uv_lookup_record', 'uv_lookup_sync']);
check('...each statement bound to that project and nothing else',
    count(array_filter($deletes, function ($l) { return $l[1] !== [7]; })) === 0);
check('...the large tables a page at a time',
    count(array_filter($deletes, function ($l) { return strpos($l[0], 'uv_lookup_key') !== false && strpos($l[0], ' LIMIT ' . LookupIndex::DELETE_ROWS) !== false; })) === 1);
$m = new UvKeyModule();
check('forgetProject() of no project sends nothing', (new LookupIndex($m))->forgetProject(0) === false
    && (new LookupIndex($m))->forgetProject(-3) === false && $m->log === []);

echo "lookup_key_php: $n checks, $fail failure(s)\n";
exit($fail ? 1 : 0);
