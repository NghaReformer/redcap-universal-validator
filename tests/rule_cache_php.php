<?php
/**
 * rule_cache_php.php - a project with no rule reads no dictionary, and an
 * expensive parse is kept between requests without ever going stale
 * (FRM-10, FRM-4, VAL-3, AST-3).
 *
 * Part 1. With the module on and no rule, every form view, survey page and
 * save exported the whole data dictionary to learn there was nothing to do.
 * One statement now asks the database whether any annotation holds a module
 * tag. "No" is believed only on evidence; anything else reads the dictionary
 * as before.
 *
 * Part 2. php/RuleCache.php keeps what an annotation parses to, keyed on the
 * text, the options and the build, sealed with the module's secret. These
 * checks hold it to that: another text, option or build is another key; a
 * stored text that was altered, or sealed for another project, is not read;
 * a parse with an error is not kept; and the page a cached parse produces is
 * the page a fresh parse produces.
 *
 * Run:  php tests/rule_cache_php.php
 */

namespace ExternalModules {
    if (PHP_SAPI !== 'cli') {
        // A test or a tool, never a page: answer a web request with 404 and stop.
        http_response_code(404);
        exit;
    }

    class AbstractExternalModule
    {
        public $subSettings = [];
        public $logCalls = [];
        public $calls = [];
        public static $projectSettings = [];   // shared by every object: one request after another
        public static $systemSettings = [];
        public $statements = [];
        /** What the count statement answers: an array of rows, 'throw', or 'none' for no query(). */
        public $metadataAnswer = [];
        public $setThrows = false;
        private function hit($m) { $this->calls[$m] = isset($this->calls[$m]) ? $this->calls[$m] + 1 : 1; }
        public function getSubSettings($key, $pid = null) { $this->hit('getSubSettings'); return $this->subSettings; }
        public function getProjectSetting($key, $pid = null)
        {
            $this->hit('getProjectSetting');
            return isset(self::$projectSettings[$pid][$key]) ? self::$projectSettings[$pid][$key] : null;
        }
        public function setProjectSetting($key, $value, $pid = null)
        {
            $this->hit('setProjectSetting');
            if ($this->setThrows) throw new \RuntimeException('settings are read-only');
            self::$projectSettings[$pid][$key] = $value;
        }
        public function getSystemSetting($key) { return isset(self::$systemSettings[$key]) ? self::$systemSettings[$key] : null; }
        public function setSystemSetting($key, $value) { $this->hit('setSystemSetting'); self::$systemSettings[$key] = $value; }
        public function getProjectId() { return 7; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($message, $parameters = []) { $this->logCalls[] = [$message, $parameters]; return 1; }
        public function getUser() { return null; }
        public function query($sql, $params = [])
        {
            $this->hit('query');
            $this->statements[] = [$sql, $params];
            if (strpos($sql, 'redcap_metadata') !== false) {
                if ($this->metadataAnswer === 'throw') throw new \RuntimeException('lock wait timeout');
                return $this->metadataAnswer;
            }
            return [];
        }
        public function initializeJavascriptModuleObject() { return ''; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.T.UV'; }
    }
}

namespace {
    class REDCap
    {
        public static $dd = [];
        public static $ddCalls = 0;
        public static function getDataDictionary($pid = null, $fmt = 'array') { self::$ddCalls++; return self::$dd; }
        public static function getData() { return []; }
        public static function getEventNames() { return []; }
        public static function isLongitudinal() { return false; }
        public static function getInstrumentEventMappings() { return []; }
        public static function getRepeatingFormsEvents() { return []; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getUserRights() { return []; }
    }

    require __DIR__ . '/../UniversalValidator.php';

    use INSPIRE\UniversalValidator\RuleCache;
    use INSPIRE\UniversalValidator\AnnotationRules;
    use ExternalModules\AbstractExternalModule as Stub;

    $n = 0;
    $fail = 0;
    function check($label, $cond)
    {
        global $n, $fail;
        $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    $row = function ($name, $form, $ann = '') {
        return ['field_name' => $name, 'form_name' => $form, 'field_type' => 'text', 'field_label' => $name,
                'select_choices_or_calculations' => '', 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => '', 'identifier' => '', 'section_header' => '',
                'branching_logic' => '', 'required_field' => ''];
    };
    $plain = ['record_id' => $row('record_id', 'f1')];
    for ($i = 0; $i < 40; $i++) $plain["f$i"] = $row("f$i", 'f1');
    $tagged = $plain;
    $tagged['f1']['field_annotation'] = '@UVALIDATE';

    $HOOKS = ['redcap_data_entry_form_top' => [7, '1', 'f1', 1, null, 1],
              'redcap_survey_page_top'     => [7, '1', 'f1', 1, null, 'HASH', 5, 1],
              'redcap_save_record'         => [7, '1', 'f1', 1, null, null, null, 1]];
    /** One request: a new module object, one hook, what it printed. */
    $request = function ($hook, $dd, $answer, $subs = [], $prepare = null) use ($HOOKS) {
        \REDCap::$dd = $dd;
        \REDCap::$ddCalls = 0;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->metadataAnswer = $answer;
        $m->subSettings = $subs;
        if ($prepare) $prepare($m);
        ob_start();
        $m->$hook(...$HOOKS[$hook]);
        $out = ob_get_clean();
        return [$m, $out, \REDCap::$ddCalls];
    };
    $NONE = [['41', '0']];
    $SOME = [['41', '1']];

    // ============ Part 1: a project with no rule reads no dictionary ============
    foreach (array_keys($HOOKS) as $hook) {
        list($m, $out, $dds) = $request($hook, $plain, $NONE);
        check("FRM-10: $hook on a project with no rule reads no dictionary (got $dds)", $dds === 0);
        check("FRM-10: $hook on a project with no rule prints nothing and logs nothing", $out === '' && $m->logCalls === []);
        check("FRM-10: $hook asks the database once", ($m->calls['query'] ?? 0) === 1);
    }
    list($m) = $request('redcap_data_entry_form_top', $plain, $NONE);
    $st = $m->statements[0];
    check('FRM-10: the statement reads redcap_metadata of this project with bound values only',
        strpos($st[0], 'FROM redcap_metadata WHERE project_id = ?') !== false && $st[1] === ['%@uv%', 7]
        && substr_count($st[0], '?') === 2 && strpos($st[0], 'LOWER(misc) LIKE ?') !== false);
    // an associative row, as mysqli hands one back, reads the same
    list($m, $out, $dds) = $request('redcap_data_entry_form_top', $plain, [['COUNT(*)' => '41', 'tagged' => '0']]);
    check('FRM-10: a row with named columns is read by position', $dds === 0);

    // a tag is there: the dictionary is read and the rule is on the page
    foreach (['redcap_data_entry_form_top', 'redcap_survey_page_top'] as $hook) {
        list($m, $out, $dds) = $request($hook, $tagged, $SOME);
        check("FRM-10: $hook with a tagged field reads the dictionary and puts the rule on the page",
            $dds >= 1 && strpos($out, 'f1') !== false && strlen($out) > 200);
    }

    // FAIL DIRECTION: anything but one row saying "fields, none tagged" reads the dictionary.
    $unsure = [
        'no row'                => [],
        'a statement that throws' => 'throw',
        'no field counted'      => [['0', '0']],
        'a null sum'            => [['41', null]],
        'a count that is not a number' => [['x', '0']],
        'two rows'              => [['41', '0'], ['41', '0']],
        'a row of another shape' => [['41']],
        'a row of three columns' => [['41', '0', '0']],
        'false'                 => false,
        'a result that cannot be walked' => 'what',
    ];
    foreach ($unsure as $what => $answer) {
        list($m, $out, $dds) = $request('redcap_data_entry_form_top', $tagged, $answer);
        check("FRM-10: $what is not \"no rules\": the dictionary is read and the rule is on the page (got $dds)",
            $dds >= 1 && strlen($out) > 200);
    }
    // the database says none but a dictionary read in this request is already in hand: it is used
    list($m, $out, $dds) = $request('redcap_data_entry_form_top', $tagged, $NONE, [], function ($m) {
        $p = new \ReflectionProperty($m, 'ddCache');
        $p->setAccessible(true);
        $p->setValue($m, [7 => \REDCap::$dd]);
    });
    check('FRM-10: a dictionary already read answers before the database does', strlen($out) > 200);

    // settings rows
    list($m, $out, $dds) = $request('redcap_data_entry_form_top', $plain, $NONE, [['fields' => [null], 'fields-csv' => '  ', 'rule-type' => 'single']]);
    check('FRM-10: a settings row that names no field is no rule: no dictionary read', $dds === 0 && $out === '');
    list($m, $out, $dds) = $request('redcap_data_entry_form_top', $plain, $NONE, [['fields' => ['f2'], 'rule-type' => 'single']]);
    check('FRM-10: a settings row that names a field reads the dictionary and is on the page', $dds >= 1 && strpos($out, 'f2') !== false);
    list($m, $out, $dds) = $request('redcap_data_entry_form_top', $plain, $NONE, [['fields-csv' => 'no_such_field', 'rule-type' => 'single']]);
    check('FRM-10: a settings row that names an unknown field still reads the dictionary (its error must show)', $dds >= 1);

    // ============ Part 2: the parse cache ============
    $SECRET = str_repeat('ab', 32);
    $sig = RuleCache::signature();
    check('RC: the signature is a sha256 and stable in a process', preg_match('/^[0-9a-f]{64}$/', $sig) === 1 && RuleCache::signature() === $sig);
    $k = RuleCache::key($sig, ['qualified' => false], '@UVALIDATE');
    check('RC: another annotation is another key', RuleCache::key($sig, ['qualified' => false], '@UVALIDATE ') !== $k);
    check('RC: another option is another key', RuleCache::key($sig, ['qualified' => true], '@UVALIDATE') !== $k);
    check('RC: another build is another key', RuleCache::key(str_repeat('0', 64), ['qualified' => false], '@UVALIDATE') !== $k);
    check('RC: the order of the options does not matter', RuleCache::key($sig, ['a' => 1, 'b' => 2], 'x') === RuleCache::key($sig, ['b' => 2, 'a' => 1], 'x'));

    $frags = AnnotationRules::parseAllTags('@UVALIDATE', ['qualified' => false]);
    check('RC: the plain tag parses to a list with no error', is_array($frags) && RuleCache::storable($frags));
    check('RC: a parse with an error is not storable', !RuleCache::storable([['error' => 'x', '_tag' => '@UVALIDATE']]));
    check('RC: ...nor one beside a good fragment', !RuleCache::storable([['type' => 'single'], ['error' => 'x']]));
    check('RC: null and an empty list are not storable', !RuleCache::storable(null) && !RuleCache::storable([]));
    check('RC: a value JSON does not give back identical is not storable',
        !RuleCache::storable([['a' => new \stdClass()]]) && !RuleCache::storable([['a' => "\xff"]]) && !RuleCache::storable([['a' => NAN]]));
    check('RC: floats, nested lists and empty arrays are storable when identical',
        RuleCache::storable([['a' => 1.5, 'b' => [1, 2, ['c' => []]], 'd' => 1.0, 'e' => '1']]));

    $sealed = RuleCache::seal([$k => $frags], $SECRET, 7);
    check('RC: seal and open give the entries back identical', RuleCache::open($sealed, $SECRET, 7) === [$k => $frags]);
    check('RC: sealed for another project is not opened', RuleCache::open($sealed, $SECRET, 8) === []);
    check('RC: sealed with another secret is not opened', RuleCache::open($sealed, str_repeat('cd', 32), 7) === []);
    $box = json_decode($sealed, true);
    $evil = $box;
    $evil['body'] = json_encode([$k => [['type' => 'single', 'pattern' => '(a+)+$']]]);
    check('RC: a body altered after sealing is not opened', RuleCache::open(json_encode($evil), $SECRET, 7) === []);
    $evil = $box;
    $evil['v'] = RuleCache::SHAPE + 1;
    check('RC: another shape is not opened', RuleCache::open(json_encode($evil), $SECRET, 7) === []);
    foreach ([null, '', 'x', '[]', '{"v":1}', 7, ['v' => 1]] as $junk) {
        check('RC: ' . json_encode($junk) . ' opens to nothing', RuleCache::open($junk, $SECRET, 7) === []);
    }
    check('RC: no secret, nothing sealed and nothing opened',
        RuleCache::seal([$k => $frags], '', 7) === null && RuleCache::seal([$k => $frags], null, 7) === null && RuleCache::open($sealed, '', 7) === []);
    $many = [];
    for ($i = 0; $i < RuleCache::MAX_ENTRIES + 20; $i++) $many[hash('sha256', "k$i")] = $frags;
    check('RC: at most MAX_ENTRIES are sealed', count(RuleCache::open(RuleCache::seal($many, $SECRET, 7), $SECRET, 7)) === RuleCache::MAX_ENTRIES);
    $fat = [];
    for ($i = 0; $i < 8; $i++) $fat[hash('sha256', "f$i")] = [['type' => 'single', 'pad' => str_repeat('x', 10000)]];
    $sealedFat = RuleCache::seal($fat, $SECRET, 7);
    check('RC: at most MAX_BYTES are sealed (got ' . strlen($sealedFat) . ')',
        strlen($sealedFat) < 65535 && count(RuleCache::open($sealedFat, $SECRET, 7)) === 4);
    check('RC: a key that is not a sha256 is not sealed', RuleCache::open(RuleCache::seal(['abc' => $frags], $SECRET, 7), $SECRET, 7) === []);
    check('RC: an entry with an error is not sealed', RuleCache::open(RuleCache::seal([$k => [['error' => 'x']]], $SECRET, 7), $SECRET, 7) === []);

    // ---- through the module ----
    $keepAll = function ($m) {
        $p = new \ReflectionProperty($m, 'parseCacheMinMs');
        $p->setAccessible(true);
        $p->setValue($m, 0.0);
    };
    $stored = function () { return Stub::$projectSettings[7][RuleCache::SETTING] ?? null; };
    $reset = function ($secret) { Stub::$projectSettings = []; Stub::$systemSettings = $secret === null ? [] : ['log-hmac-key' => $secret]; };

    // no secret: parsed, nothing kept, and a page view makes no system setting
    $reset(null);
    list($m, $fresh) = $request('redcap_data_entry_form_top', $tagged, $SOME, [], $keepAll);
    check('RC: without the secret nothing is stored and none is made by a page view',
        strlen($fresh) > 200 && $stored() === null && !isset($m->calls['setProjectSetting']) && !isset($m->calls['setSystemSetting']));

    // the default: an ordinary parse is too cheap to keep
    $reset($SECRET);
    list($m, $out) = $request('redcap_data_entry_form_top', $tagged, $SOME);
    check('RC: an ordinary annotation is not stored (cheaper than MIN_MS)', $out === $fresh && $stored() === null && !isset($m->calls['setProjectSetting']));

    // kept, then read by the next request
    list($m, $out1) = $request('redcap_data_entry_form_top', $tagged, $SOME, [], $keepAll);
    $entries = RuleCache::open($stored(), $SECRET, 7);
    check('RC: a parse over the threshold is stored once, under the key of its text', $out1 === $fresh
        && ($m->calls['setProjectSetting'] ?? 0) === 1 && array_keys($entries) === [$k] && $entries[$k] === $frags);
    list($m, $out2) = $request('redcap_data_entry_form_top', $tagged, $SOME, [], $keepAll);
    check('RC: the next request prints the same page and writes nothing', $out2 === $fresh && !isset($m->calls['setProjectSetting']));
    foreach (['redcap_survey_page_top', 'redcap_save_record'] as $hook) {
        list($m, $o) = $request($hook, $tagged, $SOME, [], $keepAll);
        check("RC: $hook reads the stored parse and writes nothing", !isset($m->calls['setProjectSetting']));
    }

    // THE STORED PARSE IS WHAT IS USED: an entry sealed with the secret under this
    // annotation's key decides the page. (Only the module can seal one.)
    $other = AnnotationRules::parseAllTags('@UVALIDATE={"algorithm":"none","pattern":"^[A-Z]{3}$"}', ['qualified' => false]);
    check('RC: the other annotation parses clean', RuleCache::storable($other) && $other !== $frags);
    Stub::$projectSettings[7][RuleCache::SETTING] = RuleCache::seal([$k => $other], $SECRET, 7);
    list($m, $swapped) = $request('redcap_data_entry_form_top', $tagged, $SOME);
    check('RC: a sealed entry is read (the page shows what it holds)', $swapped !== $fresh && strpos($swapped, '[A-Z]{3}') !== false);
    // ...and the same text with a wrong seal is ignored: the annotation is parsed
    $box = json_decode(Stub::$projectSettings[7][RuleCache::SETTING], true);
    $box['mac'] = str_repeat('0', 64);
    Stub::$projectSettings[7][RuleCache::SETTING] = json_encode($box);
    list($m, $o) = $request('redcap_data_entry_form_top', $tagged, $SOME);
    check('RC: an entry whose seal is wrong is not read: the annotation is parsed', $o === $fresh);
    Stub::$projectSettings[7][RuleCache::SETTING] = RuleCache::seal([$k => $other], str_repeat('cd', 32), 7);
    list($m, $o) = $request('redcap_data_entry_form_top', $tagged, $SOME);
    check('RC: an entry sealed with another secret is not read', $o === $fresh);
    Stub::$projectSettings[8][RuleCache::SETTING] = RuleCache::seal([$k => $other], $SECRET, 8);
    Stub::$projectSettings[7][RuleCache::SETTING] = Stub::$projectSettings[8][RuleCache::SETTING];
    list($m, $o) = $request('redcap_data_entry_form_top', $tagged, $SOME);
    check('RC: an entry copied from another project is not read', $o === $fresh);

    // AN EDITED ANNOTATION IS NEVER ANSWERED FROM THE OLD ENTRY
    $reset($SECRET);
    $request('redcap_data_entry_form_top', $tagged, $SOME, [], $keepAll);
    $edited = $tagged;
    $edited['f1']['field_annotation'] = '@UVALIDATE={"algorithm":"none","pattern":"^[A-Z]{3}$"}';
    list($m, $freshEdited) = $request('redcap_data_entry_form_top', $edited, $SOME, [], function ($m) use ($keepAll) {
        $keepAll($m);
        Stub::$systemSettings = [];                  // no secret: no cache at all
    });
    Stub::$systemSettings = ['log-hmac-key' => $SECRET];
    list($m, $o) = $request('redcap_data_entry_form_top', $edited, $SOME, [], $keepAll);
    $k2 = RuleCache::key($sig, ['qualified' => false], $edited['f1']['field_annotation']);
    check('RC: an edited annotation shows the edited rule, as a request with no cache does', $o === $freshEdited && strpos($o, '[A-Z]{3}') !== false);
    check('RC: ...and the build of every field drops the entry of the old text',
        array_keys(RuleCache::open($stored(), $SECRET, 7)) === [$k2]);
    // the option the parse is read with
    Stub::$projectSettings[7]['enable-event-instance-refs'] = true;
    list($m, $o) = $request('redcap_data_entry_form_top', $edited, $SOME, [], $keepAll);
    $k3 = RuleCache::key($sig, ['qualified' => true], $edited['f1']['field_annotation']);
    check('RC: a changed option is another entry, and the old one is dropped', array_keys(RuleCache::open($stored(), $SECRET, 7)) === [$k3]);
    unset(Stub::$projectSettings[7]['enable-event-instance-refs']);

    // a tag removed from the dictionary: no rule, whatever the store still holds
    $request('redcap_data_entry_form_top', $edited, $SOME, [], $keepAll);
    check('RC: (the entry is there)', count(RuleCache::open($stored(), $SECRET, 7)) === 1);
    list($m, $o) = $request('redcap_data_entry_form_top', $plain, $SOME, [], $keepAll);
    check('RC: with the tag gone from the dictionary the page has no rule, and the store is not even read',
        $o === '' && !isset($m->calls['setProjectSetting']));
    list($m, $o) = $request('redcap_data_entry_form_top', $tagged, $SOME, [], $keepAll);
    check('RC: the next build that parses a tag drops the entry nothing asked for',
        array_keys(RuleCache::open($stored(), $SECRET, 7)) === [$k]);

    // a parse that ends in an error is asked again every time
    $reset($SECRET);
    $broken = $tagged;
    $broken['f1']['field_annotation'] = '@UVALIDATE={"algorithm":';
    $brokenFrags = AnnotationRules::parseAllTags($broken['f1']['field_annotation'], ['qualified' => false]);
    check('RC: the broken annotation parses to an error', is_array($brokenFrags) && isset($brokenFrags[0]['error']));
    list($m, $o) = $request('redcap_data_entry_form_top', $broken, $SOME, [], $keepAll);
    check('RC: a parse with an error is not stored', $stored() === null && !isset($m->calls['setProjectSetting']));

    // a store that cannot be written changes nothing on the page
    $reset($SECRET);
    list($m, $o) = $request('redcap_data_entry_form_top', $tagged, $SOME, [], function ($m) use ($keepAll) { $keepAll($m); $m->setThrows = true; });
    check('RC: a cache that cannot be written leaves the page as it is', $o === $fresh && $stored() === null);

    // a build for some fields (a live lookup) drops nothing
    $reset($SECRET);
    $two = $tagged;
    $two['f2']['field_annotation'] = '@UVALIDATE={"algorithm":"none","pattern":"^[0-9]{4}$"}';
    $request('redcap_data_entry_form_top', $two, $SOME, [], $keepAll);
    check('RC: both annotations are stored', count(RuleCache::open($stored(), $SECRET, 7)) === 2);
    \REDCap::$dd = $two;
    $m = new \INSPIRE\UniversalValidator\UniversalValidator();
    $m->metadataAnswer = $SOME;
    $keepAll($m);
    $get = new \ReflectionMethod($m, 'getRules');
    $get->setAccessible(true);
    $scoped = $get->invoke($m, 7, ['f2']);
    check('RC: a build for one field reads its rule and drops no other entry',
        count($scoped) === 1 && count(RuleCache::open($stored(), $SECRET, 7)) === 2 && !isset($m->calls['setProjectSetting']));

    // the rules a cached build gives are the rules a fresh build gives
    $full = function ($withCache) use ($two, $SOME, $keepAll, $SECRET) {
        Stub::$systemSettings = $withCache ? ['log-hmac-key' => $SECRET] : [];
        \REDCap::$dd = $two;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->metadataAnswer = $SOME;
        $keepAll($m);
        $get = new \ReflectionMethod($m, 'getRules');
        $get->setAccessible(true);
        return $get->invoke($m, 7);
    };
    check('RC: the rule list from the cache is identical to a fresh one', $full(true) === $full(false) && count($full(true)) === 2);

    echo "rule_cache_php: $n checks, $fail failure(s)\n";
    exit($fail ? 1 : 0);
}
