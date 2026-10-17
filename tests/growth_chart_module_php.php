<?php
/**
 * growth_chart_module_php.php — @UVRANGE "show" through the module: the
 * growth chart and the page table, this visit only.
 *
 * growth_curve_php.php pins the curves themselves; this file pins:
 *   - the grammar: "show" "chart" (lines, bands, y, span, open, labels) and
 *     "show" "table" (rows, step, span, columns, open, labels), every
 *     default filled in, numbers as canonical text, labels as language maps,
 *   - what is refused, when the tag is parsed and with the reference in hand
 *     (a span outside it, a table of reference values over 100 rows),
 *   - the page: config.growthCurves keyed per reference, scale, span and
 *     lines, both sexes for a sex typed on the page and one for a saved sex,
 *     no curves for a chart of z-scores, the rows of a table of reference
 *     values, the source title, decimals resolved for a table that needs
 *     them, nothing for a rule the page does not judge,
 *   - the "pageCharts" cap (index.json, default 4): one past it is left off
 *     with the reason and the check itself does not change,
 *   - the payload size for 7 lines and both sexes.
 *
 * Run:  php tests/growth_chart_module_php.php
 */

namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = [];
        public $subSettings = [];
        public $projectSettings = [];
        public $systemSettings = [];
        public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return isset($this->systemSettings[$k]) ? $this->systemSettings[$k] : null; }
        public function setSystemSetting($k, $v) { $this->systemSettings[$k] = $v; }
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'ExternalModules.TEST.UniversalValidator'; }
        public function getUser() {
            $u = isset($GLOBALS['__TEST_USER']) ? $GLOBALS['__TEST_USER'] : null;
            return $u === null ? null : new TestUser($u);
        }
    }
    class TestUser {
        private $n;
        public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
        public function getRights($pid = null) {
            $all = \REDCap::$rights;
            return isset($all[$this->n]) ? $all[$this->n] : null;
        }
    }
}

namespace {
    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static function getData($p) { return self::$data; }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
    }
    class Project {
        public static $codes = '';
        public $project;
        public function __construct($pid) { $this->project = ['missing_data_codes' => self::$codes]; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    use INSPIRE\UniversalValidator\AnnotationRules;
    use INSPIRE\UniversalValidator\GrowthReference;

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text', $choices = '') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation,
                'select_choices_or_calculations' => $choices];
    }
    $AGE = '"age":{"dob":"[dob]","at":"[visit_date]"}';
    function tag($show, $ref = 'who-wfa', $more = '') {
        global $AGE;
        $in = $ref === 'who-wfh' ? '"by":"[height]"' : $AGE;
        return '@UVRANGE={"reference":"' . $ref . '","sex":"[sex]","male":"1","female":"2",' . $in
            . ',"soft":[-2,2],"hard":[-5,5]' . $more . ($show === null ? '' : ',"show":' . $show) . '}';
    }
    $SEXES = '1, Male | 2, Female';
    function dict(array $measured, $sexForm = 'enrol_form') {
        global $SEXES;
        $d = [
            'record_id'  => f('enrol_form'),
            'sex'        => f($sexForm, '', '', 'radio', $SEXES),
            'dob'        => f('enrol_form', '', 'date_ymd'),
            'visit_date' => f('anthro_form', '', 'date_ymd'),
            'height'     => f('anthro_form', '', 'number_1dp'),
        ];
        foreach ($measured as $name => $ann) $d[$name] = f('anthro_form', $ann, 'number_1dp');
        return $d;
    }
    $SAVED = ['2' => [351 => ['record_id' => '2', 'sex' => '1', 'dob' => '2025-01-10']]];
    $FULL = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1']]];

    function mod($dict, $data, $sys = []) {
        global $FULL;
        $GLOBALS['__TEST_USER'] = 'nurse';
        GrowthReference::reset();
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->systemSettings = $sys;
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $FULL;
        return $m;
    }
    function page($dict, $data, $ctx = 'form', $sys = []) {
        $m = mod($dict, $data, $sys);
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, '2', 'anthro_form', 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, '2', 'anthro_form', 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true), 'bytes' => isset($mm[1]) ? strlen($mm[1]) : 0];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function parsed($tag, $opts = []) {
        $f = AnnotationRules::parseAllTags($tag, $opts);
        return isset($f[0]) ? $f[0] : null;
    }
    function errOf($tag, $opts = []) {
        $f = parsed($tag, $opts);
        return isset($f['error']) ? $f['error'] : '';
    }
    function tmpdir() {
        $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uv_chart_' . bin2hex(random_bytes(6));
        mkdir($d);
        return $d;
    }
    function rmtree($d) {
        foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $x) is_dir($x) ? rmtree($x) : unlink($x);
        rmdir($d);
    }

    // ---- 1) the grammar --------------------------------------------------------------
    $p = parsed(tag('{"chart":true}'));
    check('a chart with its defaults (got ' . json_encode($p['rangeShow'] ?? $p) . ')', isset($p['rangeShow'])
        && $p['rangeShow'] === ['chart' => ['lines' => ['kind' => 'z', 'values' => ['-5', '-2', '0', '2', '5']], 'bands' => false,
            'y' => 'value', 'span' => null, 'open' => null, 'labels' => []]]);
    $p = parsed(tag('{"chart":{"lines":{"percentiles":[97,3,50.0,15,85]},"bands":true,"y":"value","span":[0,730.50],"open":false,'
        . '"labels":{"title":"Weight","x":"Age","point":"Today"}}}'));
    check('lines sorted and written canonically, the span as typed, the labels kept (got ' . json_encode($p['rangeShow'] ?? $p) . ')',
        isset($p['rangeShow']['chart']) && $p['rangeShow']['chart']['lines'] === ['kind' => 'percentiles', 'values' => ['3', '15', '50', '85', '97']]
        && $p['rangeShow']['chart']['bands'] === true && $p['rangeShow']['chart']['span'] === ['0', '730.5']
        && $p['rangeShow']['chart']['open'] === false && $p['rangeShow']['chart']['labels'] === ['title' => 'Weight', 'x' => 'Age', 'point' => 'Today']);
    $p = parsed(tag('{"chart":{"lines":{"z":[2.5,-2.50,0]},"y":"z"}}'));
    check('z-score lines and a chart of z-scores', ($p['rangeShow']['chart']['lines'] ?? null) === ['kind' => 'z', 'values' => ['-2.5', '0', '2.5']]
        && $p['rangeShow']['chart']['y'] === 'z');
    $p = parsed(tag('{"table":true}'));
    check('a table of this visit with its defaults (got ' . json_encode($p['rangeShow'] ?? $p) . ')',
        ($p['rangeShow'] ?? null) === ['table' => ['rows' => 'history', 'step' => null, 'span' => null,
            'columns' => ['age', 'value', 'z', 'percentile'], 'open' => null, 'labels' => []]]);
    $p = parsed(tag('{"chart":{"lines":{"percentiles":[3,50,97]},"span":[0,730]},"table":{"rows":"curve","step":30.0}}'));
    check('a table of reference values takes the chart\'s lines and span (got ' . json_encode($p['rangeShow']['table'] ?? $p) . ')',
        ($p['rangeShow']['table'] ?? null) === ['rows' => 'curve', 'step' => '30', 'span' => ['0', '730'],
            'columns' => ['age', 'valueAtPercentile:3', 'valueAtPercentile:50', 'valueAtPercentile:97'], 'open' => null, 'labels' => []]);
    $p = parsed(tag('{"table":{"rows":"curve","step":365.25}}'));
    check('without a chart, its default lines (got ' . json_encode($p['rangeShow']['table']['columns'] ?? $p) . ')',
        ($p['rangeShow']['table']['columns'] ?? null) === ['age', 'valueAtZ:-5', 'valueAtZ:-2', 'valueAtZ:0', 'valueAtZ:2', 'valueAtZ:5']
        && $p['rangeShow']['table']['span'] === null);
    $p = parsed(tag('{"table":{"columns":["age","z","lower","range","valueAtZ:2"],"labels":{"caption":"This visit","z":"Z"}}}'));
    check('columns and their labels', ($p['rangeShow']['table']['columns'] ?? null) === ['age', 'z', 'lower', 'range', 'valueAtZ:2']
        && $p['rangeShow']['table']['labels'] === ['caption' => 'This visit', 'z' => 'Z']);
    $p = parsed(tag('{"chart":{"labels":{"title":{"en":"Weight","fr":"Poids"},"y":"kg"}},"table":{"labels":{"z":{"en":"z","fr":"score z"}}}}'));
    check('a label as a language map: the default kept, the other languages under i18n (got ' . json_encode([$p['rangeShow'] ?? null, $p['i18n'] ?? null]) . ')',
        ($p['rangeShow']['chart']['labels']['title'] ?? null) === 'Weight' && ($p['rangeShow']['chart']['labels']['y'] ?? null) === 'kg'
        && ($p['i18n']['show.chart.labels.title']['fr'] ?? null) === 'Poids'
        && ($p['i18n']['show.table.labels.z']['fr'] ?? null) === 'score z');
    // returnLimits and decimals shape a table's columns as they shape returned values
    check('"returnLimits", "decimals" and "percentileDecimals" apply to a table\'s columns',
        errOf(tag('{"table":{"columns":["upper","percentile"]}}', 'who-wfa', ',"returnLimits":"hard","decimals":2,"percentileDecimals":2')) === '');
    check('a chart of measurements by length', errOf(tag('{"chart":{"span":[50,100]}}', 'who-wfh')) === '');

    $SPAN_OUT = ['referenceDir' => null];
    $refused = [
        'show not an object'          => [tag('true'), '"show" must say what the page shows'],
        'show a list'                 => [tag('["chart"]'), '"show" must say what the page shows'],
        'an unknown part'             => [tag('{"graph":true}'), 'unknown "show" option(s): graph — valid: chart, table.'],
        'a chart as text'             => [tag('{"chart":"yes"}'), '"show" "chart" must be true or an object of its settings'],
        'an unknown chart option'     => [tag('{"chart":{"colour":"red"}}'), 'unknown "show" "chart" option(s): colour — valid: lines, bands, y, span, open, labels.'],
        'lines of another kind'       => [tag('{"chart":{"lines":{"sd":[1]}}}'), '"show" "chart" "lines" must be {"z":[...]} or {"percentiles":[...]}'],
        'lines of two kinds'          => [tag('{"chart":{"lines":{"z":[1],"percentiles":[50]}}}'), '"show" "chart" "lines" must be'],
        'lines empty'                 => [tag('{"chart":{"lines":{"z":[]}}}'), '"show" "chart" "lines" "z" must be a list of numbers'],
        'more than 9 lines'           => [tag('{"chart":{"lines":{"percentiles":[1,3,5,10,25,50,75,90,95,97]}}}'), 'has more than 9 lines'],
        'a percentile of 100'         => [tag('{"chart":{"lines":{"percentiles":[3,100]}}}'), '"show" "chart" "lines" "percentiles" takes a number from 0.1 to 99.9 with at most 2 decimals for each line — got 100.'],
        'a percentile of 0'           => [tag('{"chart":{"lines":{"percentiles":[0]}}}'), 'takes a number from 0.1 to 99.9'],
        'a z-score past 6'            => [tag('{"chart":{"lines":{"z":[-7]}}}'), '"show" "chart" "lines" "z" takes a number from -6 to 6 with at most 2 decimals for each line — got -7.'],
        'a line as a word'            => [tag('{"chart":{"lines":{"z":["two"]}}}'), 'for each line — got "two".'],
        'a line twice'                => [tag('{"chart":{"lines":{"z":[2,2.0]}}}'), '"show" "chart" "lines" "z" names 2 twice.'],
        'bands as text'               => [tag('{"chart":{"bands":"yes"}}'), '"show" "chart" "bands" must be true or false (unquoted).'],
        'bands without soft limits'   => ['@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5],"show":{"chart":{"bands":true}}}',
                                          '"show" "chart" "bands" shades the "soft" range, and this rule has no "soft" limits.'],
        'y of another kind'           => [tag('{"chart":{"y":"percentile"}}'), '"show" "chart" "y" must be "value"'],
        'span upside down'            => [tag('{"chart":{"span":[730,0]}}'), '"show" "chart" "span" must be "auto" or [low, high]'],
        'span of one number'          => [tag('{"chart":{"span":[0]}}'), '"show" "chart" "span" must be "auto" or [low, high]'],
        'span of words'               => [tag('{"chart":{"span":["a","b"]}}'), '"show" "chart" "span" must be "auto" or [low, high]'],
        'open as text'                => [tag('{"chart":{"open":"no"}}'), '"show" "chart" "open" must be true or false (unquoted).'],
        'labels as a list'            => [tag('{"chart":{"labels":["x"]}}'), '"show" "chart" "labels" must be an object of label name to text'],
        'an unknown label'            => [tag('{"chart":{"labels":{"legend":"x"}}}'), 'unknown "show" "chart" label "legend" — valid: summary, title, x, y, point, lines, band.'],
        'a blank label'               => [tag('{"chart":{"labels":{"title":" "}}}'), '"show" "chart" label "title" must be a text of 1 to 200 characters on one line'],
        'a label of two lines'        => [tag('{"chart":{"labels":{"title":"a\nb"}}}'), '"show" "chart" label "title" must be a text of 1 to 200'],
        'a label too long'            => [tag('{"chart":{"labels":{"title":"' . str_repeat('w', 201) . '"}}}'), 'must be a text of 1 to 200'],
        'a blank label in a language' => [tag('{"chart":{"labels":{"title":{"en":"Weight","fr":""}}}}'), '"show" "chart" "labels" "title" text for "fr" must be a non-empty string.'],
        'a label of two lines in a language' => [tag('{"chart":{"labels":{"title":{"en":"Weight","fr":"a\tb"}}}}'), '"show" "chart" label "title" must be a text of 1 to 200'],
        'a step as a word'            => [tag('{"table":{"rows":"curve","step":"often"}}'), '"show" "table" "step" must be a number above 0, such as 30 — got "often".'],
        'a table as a number'         => [tag('{"table":1}'), '"show" "table" must be true or an object of its settings'],
        'an unknown table option'     => [tag('{"table":{"sort":"age"}}'), 'unknown "show" "table" option(s): sort — valid: rows, step, span, columns, open, labels.'],
        'rows of another kind'        => [tag('{"table":{"rows":"all"}}'), '"show" "table" "rows" must be "history"'],
        'a curve table without step'  => [tag('{"table":{"rows":'  . '"curve"}}'), '"rows":"curve" needs "step"'],
        'a step of 0'                 => [tag('{"table":{"rows":"curve","step":0}}'), '"show" "table" "step" must be a number above 0, such as 30 — got 0.'],
        'a step on this visit'        => [tag('{"table":{"step":30}}'), 'only applies with "rows":"curve"'],
        'a span on this visit'        => [tag('{"table":{"span":[0,30]}}'), '"show" "table" "span" only applies with "rows":"curve"'],
        'a curve table too long'      => [tag('{"table":{"rows":"curve","step":1,"span":[0,365]}}'), 'has more than 100 rows over its "span"'],
        'columns not a list'          => [tag('{"table":{"columns":"z"}}'), '"show" "table" "columns" must be a list such as ["age","value","z","percentile"]'],
        'an unknown column'           => [tag('{"table":{"columns":["bmi"]}}'), 'unknown "show" "table" "columns" entry "bmi" — valid: z, lower, upper, range, percentile, valueAtZ:<z-score>, valueAtPercentile:<percentile>, age, value.'],
        'a column param out of range' => [tag('{"table":{"columns":["valueAtZ:9"]}}'), '"show" "table" "columns" "valueAtZ" takes the z-score after a colon'],
        'a column twice'              => [tag('{"table":{"columns":["valueAtZ:2","valueAtZ:2.0"]}}'), '"show" "table" "columns" "valueAtZ:2" and "valueAtZ:2.0" are the same column — list it once.'],
        'more than 16 columns'        => [tag('{"table":{"columns":["z","lower","upper","range","percentile","age","value","valueAtZ:1","valueAtZ:2","valueAtZ:3","valueAtZ:4","valueAtZ:5","valueAtZ:6","valueAtZ:-1","valueAtZ:-2","valueAtZ:-3","valueAtZ:-4"]}}'),
                                          '"show" "table" "columns" is limited to 16 columns.'],
        'a measurement column on a curve table' => [tag('{"table":{"rows":"curve","step":365.25,"columns":["age","z"]}}'), '"show" "table" column "z" needs a measurement, and a table of reference values'],
        'a column the limits cannot make' => [tag('{"table":{"columns":["upper"]}}', 'who-wfa', ',"returnLimits":"soft"'), ''],
        'a label for a column not shown' => [tag('{"table":{"labels":{"lower":"Low"}}}'), 'unknown "show" "table" label "lower" — valid: summary, caption, age, value, z, percentile.'],
        'show without a reference'    => ['@UVRANGE={"hard":[1,30],"show":{"chart":true}}', '"show" only applies with a "reference"'],
        'decimals with neither'       => [tag(null, 'who-wfa', ',"decimals":2'), '"decimals" only applies with "return" or a "show" "table"'],
        'percentileDecimals, no percentile column' => [tag('{"table":{"columns":["age","z"]}}', 'who-wfa', ',"percentileDecimals":2'), '"percentileDecimals"'],
        'a span past the reference'   => [tag('{"chart":{"span":[0,2000]}}'), '"show" "chart" "span" [0, 2000] lies outside "who-wfa", which covers 0 to under 1826.25 days.', $SPAN_OUT],
        'a table span past it'        => [tag('{"table":{"rows":"curve","step":100,"span":[-10,700]}}'), '"show" "table" "span" [-10, 700] lies outside "who-wfa"', $SPAN_OUT],
        'a curve table over the whole reference' => [tag('{"table":{"rows":"curve","step":10}}'), 'has more than 100 rows over "who-wfa" (0 to under 1826.25 days)', $SPAN_OUT],
    ];
    unset($refused['a column the limits cannot make']);
    foreach ($refused as $label => $c) {
        $e = errOf($c[0], isset($c[2]) ? $c[2] : []);
        check('refused: ' . $label . ' (got ' . json_encode($e) . ')', $e !== '' && strpos($e, $c[1]) !== false);
    }
    $e = errOf('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5],"show":{"table":{"columns":["upper"]}}}');
    check('refused: a column made from a limit the rule lacks (got ' . json_encode($e) . ')',
        strpos($e, '"show" "table" column "upper" is made from the high "soft" limit, which this rule does not have — add one, or write "returnLimits":"hard".') !== false);
    check('a span past the reference is only refused with the reference in hand', errOf(tag('{"chart":{"span":[0,2000]}}')) === '');

    // ---- 2) the dictionary hook ---------------------------------------------------------
    $p = page(dict(['weight' => tag('{"chart":{"span":[0,2000]}}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('the field hook refuses a span past the reference (got ' . json_encode($r['configError'] ?? null) . ')',
        isset($r['configError']) && strpos($r['configError'], 'lies outside "who-wfa"') !== false);
    $p = page(dict(['weight' => tag('{"table":{"rows":"curve","step":10}}')]), $SAVED);
    check('... and a table of reference values over 100 rows', strpos((string) (ruleFor($p, 'weight')['configError'] ?? ''), 'has more than 100 rows') !== false);

    // ---- 3) the page --------------------------------------------------------------------
    $SEVEN = '{"chart":{"lines":{"percentiles":[1,3,15,50,85,97,99]},"bands":true}}';
    $p = page(dict(['weight' => tag($SEVEN)]), $SAVED);
    $r = ruleFor($p, 'weight');
    $key = 'who-wfa|1|0,1826.25|percentiles:1,3,15,50,85,97,99|band:-2,2';
    check('the rule names its curves (got ' . json_encode($r['growthCurveKey'] ?? null) . ')', ($r['growthCurveKey'] ?? null) === $key
        && isset($p['cfg']['growthCurves'][$key]));
    $set = $p['cfg']['growthCurves'][$key] ?? [];
    check('the curve set: its lines, the band\'s two curves after them, the span and the row scale',
        ($set['kind'] ?? null) === 'percentiles' && $set['values'] === ['1', '3', '15', '50', '85', '97', '99'] && $set['band'] === [7, 8]
        && $set['span'] === [0, 1826.25] && $set['xScale'] === 1);
    check('a saved sex: one sex\'s curves (male)', isset($set['male']['lines']) && count($set['male']['lines']) === 9 && !isset($set['female']));
    check('the source title travels for the caption', ($r['growthChartSource'] ?? null) === 'WHO Child Growth Standards (2006)');
    check('the rule\'s "show" travels as parsed', ($r['rangeShow']['chart']['bands'] ?? null) === true && !isset($r['growthChartWhy']));
    $p = page(dict(['weight' => tag($SEVEN)], 'anthro_form'), $SAVED);
    $set = $p['cfg']['growthCurves'][$key] ?? [];
    check('a sex typed on the page: both sexes\' curves', isset($set['male']['lines'], $set['female']['lines']));
    $size = strlen(json_encode($set));
    check('7 lines and the band, both sexes, under 40 KB (' . $size . ' bytes)', $size < 40000);
    $p = page(dict(['weight' => tag('{"chart":{"y":"z"}}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('a chart of z-scores needs no curves', !isset($r['growthCurveKey']) && !isset($p['cfg']['growthCurves'])
        && ($r['growthChartSource'] ?? null) === 'WHO Child Growth Standards (2006)');
    $p = page(dict(['weight' => tag('{"table":{"rows":"curve","step":365.25,"columns":["age","valueAtZ:0","range"]}}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('a table of reference values: its rows for the sex the page can read (got ' . json_encode($r['growthTableRows'] ?? null) . ')',
        isset($r['growthTableRows']['male']) && !isset($r['growthTableRows']['female']) && count($r['growthTableRows']['male']) === 5
        && $r['growthTableRows']['male'][1][0] === '365.25' && $r['growthTableRows']['male'][0][1] === '3.3'
        && strpos($r['growthTableRows']['male'][0][2], ' to ') !== false && !isset($p['cfg']['growthCurves']));
    check('decimals resolved from the field for a table that needs them', ($r['rangeDecimals'] ?? null) === 1);
    $p = page(dict(['weight' => tag('{"table":true}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('a table of this visit: computed on the page, the percentile\'s decimals resolved',
        !isset($r['growthTableRows']) && ($r['rangePercentileDecimals'] ?? null) === 1 && ($r['rangeDecimals'] ?? null) === 1);
    // a survey sees only its own page: the inputs live on it
    $dSurvey = dict(['weight' => tag($SEVEN)], 'anthro_form');
    $dSurvey['dob'] = f('anthro_form', '', 'date_ymd');
    $p = page($dSurvey, $SAVED, 'survey');
    check('a survey gets the chart too (closed on the page)', isset(ruleFor($p, 'weight')['growthCurveKey'])
        && ($p['cfg']['context'] ?? null) === 'survey');
    // a rule the page does not judge draws nothing
    $p = page(dict(['weight' => tag($SEVEN, 'who-wfa', ',"when":"[height]=\'99\'"')]), $SAVED);
    check('a rule with a condition still gets its chart', isset(ruleFor($p, 'weight')['growthCurveKey']));
    $dObs = dict(['weight' => tag($SEVEN)]);
    $dObs['dob'] = f('secret_form', '', 'date_ymd');
    $p = page($dObs, $SAVED, 'form', []);
    \REDCap::$rights = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1', 'secret_form' => '0']]];
    $m = mod($dObs, $SAVED);
    \REDCap::$rights = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1', 'secret_form' => '0']]];
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'anthro_form', 351, null, 1);
    $html = ob_get_clean();
    preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
    $wc = json_decode($mm[1] ?? 'null', true);
    $wr = ruleFor(['cfg' => $wc], 'weight');
    check('an input withheld from the page: no table, no chart', $wr !== null && !isset($wr['growthCurveKey']) && !isset($wc['growthCurves']));

    // ---- 4) the cap -------------------------------------------------------------------
    $five = [];
    foreach ([[0, 365], [0, 730], [0, 1095], [0, 1460], [0, 1826]] as $i => $s) {
        $five['w' . $i] = tag('{"chart":{"span":[' . $s[0] . ',' . $s[1] . ']}}');
    }
    $p = page(dict($five), $SAVED);
    $keys = [];
    $left = [];
    foreach (array_keys($five) as $f) {
        $r = ruleFor($p, $f);
        if (isset($r['growthCurveKey'])) $keys[] = $f;
        if (isset($r['growthChartWhy']['chart'])) $left[] = [$f, $r['growthChartWhy']['chart'], $r];
    }
    check('pageCharts 4 by default: four charts drawn, the fifth left off (got ' . json_encode([$keys, array_column($left, 0)]) . ')',
        count($keys) === 4 && count($left) === 1 && $left[0][0] === 'w4' && count($p['cfg']['growthCurves']) === 4);
    check('... with the reason (got ' . json_encode($left[0][1] ?? null) . ')', isset($left[0])
        && $left[0][1] === 'this form already shows 4 growth charts or tables of reference values, the most one page may show (4), so this chart is not shown. The check itself is not affected.');
    check('... and its check is unchanged', isset($left[0]) && empty($left[0][2]['deferred']) && empty($left[0][2]['configError'])
        && ($left[0][2]['rangeSoftLo'] ?? null) === '-2');
    $shared = ['a' => tag('{"chart":true}'), 'b' => tag('{"chart":true}'), 'c' => tag('{"chart":{"y":"z"}}'),
               'd' => tag('{"chart":{"span":[0,365]}}'), 'e' => tag('{"chart":{"span":[0,730]}}'), 'g' => tag('{"table":{"rows":"curve","step":365.25}}')];
    $p = page(dict($shared), $SAVED);
    check('charts that draw the same curves share one copy, a chart of z-scores and a table of this visit cost nothing, a table of reference values counts',
        count($p['cfg']['growthCurves'] ?? []) === 3 && ruleFor($p, 'a')['growthCurveKey'] === ruleFor($p, 'b')['growthCurveKey']
        && isset(ruleFor($p, 'g')['growthTableRows']) && !isset(ruleFor($p, 'g')['growthChartWhy']));
    $dir = tmpdir();
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 1, 'references' => new \stdClass()]));
    $p = page(dict(['w1' => tag('{"chart":true}'), 'w2' => tag('{"table":{"rows":"curve","step":365.25}}')]), $SAVED, 'form', ['reference-data-dir' => $dir]);
    check('the extra folder\'s "pageCharts" sets the cap (got ' . json_encode(ruleFor($p, 'w2')['growthChartWhy'] ?? null) . ')',
        isset(ruleFor($p, 'w1')['growthCurveKey']) && !isset(ruleFor($p, 'w2')['growthTableRows'])
        && strpos(ruleFor($p, 'w2')['growthChartWhy']['table'] ?? '', 'shows 1 growth chart or table of reference values, the most one page may show (1), so this table is not shown') !== false);
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 0, 'references' => new \stdClass()]));
    $p = page(dict(['w1' => tag('{"chart":true}')]), $SAVED, 'form', ['reference-data-dir' => $dir]);
    check('"pageCharts" 0: no chart on any page', !isset(ruleFor($p, 'w1')['growthCurveKey']) && isset(ruleFor($p, 'w1')['growthChartWhy']['chart']));
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 51, 'references' => new \stdClass()]));
    GrowthReference::reset();
    $cat = GrowthReference::catalog($dir);
    check('a "pageCharts" past 50 is a problem of the folder', (function () use ($cat) {
        foreach ($cat['problems'] as $pr) if (strpos($pr, 'pageCharts') !== false) return true;
        return false;
    })());
    GrowthReference::reset();
    rmtree($dir);
    // the bundled catalog says 4
    check('the bundled index.json leaves the default cap', GrowthReference::catalog(null)['pageCharts'] === GrowthReference::PAGE_CHARTS
        && GrowthReference::PAGE_CHARTS === 4);

    // ---- 5) the pages tests/growth_chart_dom_js.cjs draws, and what it must show ----------
    // Built here by the module, so the browser suite draws the curves and the
    // rows the server sends, and compares this visit's numbers with the ones
    // GrowthReference computes for the same typed values (parity).
    $LIVE = dict([
        'weight'  => tag('{"chart":{"lines":{"percentiles":[3,15,50,85,97]},"bands":true,'
            . '"labels":{"title":{"en":"Weight for age","fr":"Poids pour age"},"summary":"Weight chart"}},'
            . '"table":{"rows":"curve","step":365.25,"labels":{"caption":"<b>WHO</b> values","summary":{"en":"Values","fr":"Valeurs"}}}}'),
        'weight2' => tag('{"chart":{"lines":{"z":[-2,0,2]},"y":"z","span":[0,365],"open":false},'
            . '"table":{"columns":["age","value","z","percentile","lower","upper","range","valueAtZ:2","valueAtPercentile:97"],'
            . '"labels":{"z":{"en":"z","fr":"score z"}}}}', 'who-wfa', ',"decimals":2'),
        'weight3' => tag('{"chart":{"span":[0,365]}}', 'who-wfa', ',"when":"[height]=\'1\'"'),
    ], 'anthro_form');
    $LIVE['dob'] = f('anthro_form', '', 'date_ymd');
    $LIVE['weight2'] = f('anthro_form', $LIVE['weight2']['field_annotation'], 'number_2dp_comma_decimal');
    $pages = ['form' => page($LIVE, [])['cfg'], 'survey' => page($LIVE, [], 'survey')['cfg']];
    $dir = tmpdir();
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 0, 'references' => new \stdClass()]));
    $pages['capped'] = page($LIVE, [], 'form', ['reference-data-dir' => $dir])['cfg'];
    GrowthReference::reset();
    rmtree($dir);
    $wfa = GrowthReference::entry('who-wfa');
    $wfaT = GrowthReference::table($wfa);
    $days = function ($ymd) { return (int) floor(strtotime($ymd . ' 00:00:00 UTC') / 86400); };
    $visits = [];
    $r2 = ruleFor(['cfg' => $pages['form']], 'weight2');
    foreach ([['1', '2025-01-10', '2025-04-20', '6,40'], ['2', '2025-01-10', '2025-04-20', '4,1'], ['1', '2025-01-10', '2025-07-01', ''],
              ['1', '2025-01-10', '2025-03-01', '25'], ['2', '2024-02-29', '2024-03-01', '3,25']] as $v) {
        list($sex, $dob, $at, $typed) = $v;
        $x = GrowthReference::axisValue($wfa, ['dobDays' => $days($dob), 'atDays' => $days($at)])['x'];
        $sx = $sex === '1' ? 'male' : 'female';
        $m = GrowthReference::measure($typed, true, null);
        $y = $m['state'] === 'ok' ? $m['y'] : null;
        $o = GrowthReference::outputs($wfa, $wfaT, $sx, $x, $y, ['lo' => '-2', 'hi' => '2'], 2, true, null, null);
        $cells = [];
        foreach ($r2['rangeShow']['table']['columns'] as $col) {
            $pp = \INSPIRE\UniversalValidator\GrowthOutputs::parse($col, '"columns"', 'column');
            $spec = \INSPIRE\UniversalValidator\GrowthOutputs::spec($pp['of']);
            if (array_key_exists($pp['of'], $o)) $t = $o[$pp['of']];
            else {
                $places = $spec['places'] === 'z' ? 2 : ($spec['places'] === 'percentileDecimals' ? $r2['rangePercentileDecimals'] : $r2['rangeDecimals']);
                $t = GrowthReference::output($wfa, $wfaT, $sx, $x, $y, $pp['of'], $pp['param'], $places, null, $y === null ? null : $typed);
            }
            $cells[] = $t === null ? '' : (($spec['written'] === 'number' && $pp['of'] !== 'value') ? GrowthReference::markText($t, true) : $t);
        }
        $z = $y === null ? null : GrowthReference::zScore($wfa, $wfaT, $sx, $x, $y)['z'];
        $visits[] = ['field' => 'weight2', 'sex' => $sex, 'dob' => $dob, 'at' => $at, 'typed' => $typed, 'x' => $x,
                     'z' => $z, 'pct' => $y === null ? null : GrowthReference::percentile($wfa, $wfaT, $sx, $x, $y, 1), 'cells' => $cells];
    }
    check('the browser suite\'s visits are worked out (got ' . json_encode($visits[0]['cells']) . ')',
        count($visits) === 5 && $visits[0]['cells'][1] === '6,40' && $visits[2]['cells'][2] === '' && $visits[2]['cells'][4] !== '');
    // The reference table the three pages carry is the same: it is written
    // once, and the browser suite puts it back on each page.
    $shared = $pages['form']['growth'];
    foreach ($pages as $k => $pg) {
        check('page "' . $k . '" carries the same table', $pg['growth'] === $shared);
        unset($pages[$k]['growth']);
    }
    $enc = function ($v) { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
    $lines = ['{"note":' . $enc('Generated by php tests/growth_chart_module_php.php --write; do not edit.') . ','];
    foreach ($pages as $k => $pg) $lines[] = ($k === 'form' ? '"pages":{' : '') . $enc($k) . ':' . $enc($pg) . ',';
    $lines[count($lines) - 1] = rtrim($lines[count($lines) - 1], ',') . '},';
    $lines[] = '"visits":' . $enc($visits) . ',';
    $lines[] = '"growth":' . $enc($shared) . '}';
    $fixture = implode("\n", $lines) . "\n";
    check('the fixture is JSON', is_array(json_decode($fixture, true)));
    $fxFile = __DIR__ . '/growth_chart_fixture.json';
    if (in_array('--write', $argv, true)) {
        file_put_contents($fxFile, $fixture);
        fwrite(STDOUT, "wrote tests/growth_chart_fixture.json (" . strlen($fixture) . " bytes)\n");
    } else {
        check('tests/growth_chart_fixture.json is current (run php tests/growth_chart_module_php.php --write and commit it)',
            is_file($fxFile) && file_get_contents($fxFile) === $fixture);
    }

    fwrite(STDOUT, "growth_chart_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
