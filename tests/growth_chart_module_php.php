<?php
/**
 * growth_chart_module_php.php — @UVRANGE "show" through the module: the
 * growth chart and the page table, this visit only.
 *
 * growth_curve_php.php pins the curves themselves; this file pins:
 *   - the grammar: "show" "chart" (lines, bands, y, span, open, labels) and
 *     "show" "table" (rows, step, span, columns, open, labels), every
 *     default filled in, numbers as canonical text, labels as language maps,
 *   - what is refused when the tag is parsed, and what is not: a span
 *     outside the reference, or a table of reference values over 100 rows
 *     over it, leaves that part off the page with the reason (the dictionary
 *     hook) and the rule stands,
 *   - the page: config.growthCurves keyed per reference, scale, span, lines
 *     and band, both sexes for a sex typed on the page and one for a saved
 *     sex, none for a saved sex that is blank, no curves for a chart of
 *     z-scores, the rows of a table of reference values once in
 *     config.growthCurveTables, the source title, decimals resolved for a
 *     table that needs them, nothing for a rule the page does not judge,
 *     a branch per reference with its own curves,
 *   - the "pageCharts" cap (index.json, default 4): one past it is left off
 *     with the reason and the check itself does not change; a cap out of
 *     range keeps the default,
 *   - the payload size for 7 lines and both sexes,
 *   - the live fixture docs/testbed/uvrange_chart_test_fields.csv, loaded as
 *     the data dictionary: the rule errors, the sets of curves, the cap and
 *     the values section K of docs/testbed/RANGE_LIVE_ACCEPTANCE.md expects.
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
    $p = parsed(tag('{"chart":{"lines":{"z":[2.5e0,-25e-1,"0"]}}}'));
    check('a line written with an exponent reads as a limit does (got ' . json_encode($p['rangeShow']['chart']['lines'] ?? $p) . ')',
        ($p['rangeShow']['chart']['lines'] ?? null) === ['kind' => 'z', 'values' => ['-2.5', '0', '2.5']]);
    $p = parsed('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE
        . ',"soft":[-2.005,2.005],"hard":[-7,7],"show":{"chart":true,"table":{"rows":"curve","step":365.25}}}');
    check('without "lines", every limit is a line, whatever its value; the table lists the ones a column can name (got '
        . json_encode($p['rangeShow'] ?? $p) . ')',
        ($p['rangeShow']['chart']['lines']['values'] ?? null) === ['-7', '-2.005', '0', '2.005', '7']
        && ($p['rangeShow']['table']['columns'] ?? null) === ['age', 'valueAtZ:0']);
    $p = parsed('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"show":{"chart":true}}');
    check('a rule that only shows needs no limits, and its chart draws the median (got ' . json_encode($p) . ')',
        !isset($p['error']) && ($p['rangeShow']['chart']['lines']['values'] ?? null) === ['0']);
    $p = parsed(tag('{"table":{"columns":["age","valueAtZ:2.50"],"labels":{"valueAtZ:2.5":{"en":"+2.5 SD","fr":"+2,5 ET"}}}}'));
    check('a column\'s label may name it as the catalog writes it; it is kept under the column\'s own spelling (got '
        . json_encode([$p['rangeShow']['table']['labels'] ?? $p, $p['i18n'] ?? null]) . ')',
        ($p['rangeShow']['table']['labels'] ?? null) === ['valueAtZ:2.50' => '+2.5 SD']
        && ($p['i18n']['show.table.labels.valueAtZ:2.50']['fr'] ?? null) === '+2,5 ET' && !isset($p['i18n']['show.table.labels.valueAtZ:2.5']));
    $p = parsed(tag('{"chart":{"labels":{"x":"Age ({unit})","months":{"en":"months","fr":"mois"},"years":"years","weeks":"wk","days":"d","male":"boy","female":"girl"}}}'));
    check('the unit and sex words are labels too', !isset($p['error']) && ($p['rangeShow']['chart']['labels']['male'] ?? null) === 'boy'
        && ($p['i18n']['show.chart.labels.months']['fr'] ?? null) === 'mois');
    $p = parsed(tag('{"table":{"rows":"curve","step":18.2625,"span":[0,1826.25]}}'));
    check('a table of reference values is counted up to its span\'s end, not at it (the page counts again)', !isset($p['error']));
    // returnLimits and decimals shape a table's columns as they shape returned values
    check('"returnLimits", "decimals" and "percentileDecimals" apply to a table\'s columns',
        errOf(tag('{"table":{"columns":["upper","percentile"]}}', 'who-wfa', ',"returnLimits":"hard","decimals":2,"percentileDecimals":2')) === '');
    check('a chart of measurements by length', errOf(tag('{"chart":{"span":[50,100]}}', 'who-wfh')) === '');

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
        'an unknown label'            => [tag('{"chart":{"labels":{"legend":"x"}}}'), 'unknown "show" "chart" label "legend" — valid: summary, title, x, y, point, lines, band, days, weeks, months, years, male, female.'],
        'a chart as a list'           => [tag('{"chart":[]}'), '"show" "chart" must be true or an object of its settings'],
        'a table as a list'           => [tag('{"table":[]}'), '"show" "table" must be true or an object of its settings'],
        'labels as an empty list'     => [tag('{"chart":{"labels":[]}}'), '"show" "chart" "labels" must be an object of label name to text'],
        'a label with a line separator' => [tag('{"chart":{"labels":{"title":"a\u2028b"}}}'), '"show" "chart" label "title" must be a text of 1 to 200 characters on one line'],
        'a label with a direction override' => [tag('{"table":{"labels":{"caption":"a\u202eb"}}}'), '"show" "table" label "caption" must be a text of 1 to 200'],
        'a column labelled twice, two ways' => [tag('{"table":{"columns":["valueAtZ:2"],"labels":{"valueAtZ:2":"a","valueAtZ:2.0":"b"}}}'), '"show" "table" labels "valueAtZ:2" twice, written two ways'],
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
        'a curve table too long'      => [tag('{"table":{"rows":"curve","step":1,"span":[0,365]}}'), 'has more than 100 rows over its "span" [0, 365] — take a larger "step"'],
        'over the chart\'s span'      => [tag('{"chart":{"span":[0,730]},"table":{"rows":"curve","step":1}}'), 'has more than 100 rows over the chart\'s "span" [0, 730]'],
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
    ];
    unset($refused['a column the limits cannot make']);
    foreach ($refused as $label => $c) {
        $e = errOf($c[0], isset($c[2]) ? $c[2] : []);
        check('refused: ' . $label . ' (got ' . json_encode($e) . ')', $e !== '' && strpos($e, $c[1]) !== false);
    }
    $e = errOf('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"hard":[-5,5],"show":{"table":{"columns":["upper"]}}}');
    check('refused: a column made from a limit the rule lacks (got ' . json_encode($e) . ')',
        strpos($e, '"show" "table" column "upper" is made from the high "soft" limit, which this rule does not have — add one, or write "returnLimits":"hard".') !== false);
    $e = errOf('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"soft":[-2,null],"hard":[-5,null],"show":{"table":{"columns":["upper"]}}}');
    check('... and the other tier is named only when it has that limit (got ' . json_encode($e) . ')',
        strpos($e, 'is made from the high "soft" limit, which this rule does not have — add one.') !== false);
    $e = errOf('@UVRANGE={"reference":"who-wfa","sex":"[sex]","male":"1","female":"2",' . $AGE . ',"soft":[-2,null],"hard":[-5,null],"return":{"upper":"[w_hi]"}}');
    check('... for a returned bound too (got ' . json_encode($e) . ')',
        strpos($e, '"return" "upper" is made from the high "soft" limit, which this rule does not have — add one.') !== false);
    check('a span past the reference is not refused when the tag is read', errOf(tag('{"chart":{"span":[0,2000]}}')) === ''
        && errOf(tag('{"chart":{"span":[0,2000]}}'), ['referenceDir' => null]) === '');
    // a catalog that cannot be read refuses the rule that shows, and only it
    $badCat = tempnam(sys_get_temp_dir(), 'uvcat');
    file_put_contents($badCat, '{"format":');
    \INSPIRE\UniversalValidator\GrowthOutputs::useFile($badCat);
    $e = errOf(tag('{"chart":true}'));
    $plain = errOf('@UVRANGE={"soft":[12,17.5],"hard":[3,25]}');
    \INSPIRE\UniversalValidator\GrowthOutputs::useFile(null);
    unlink($badCat);
    check('a catalog that cannot be read: "show" is refused, not thrown (got ' . json_encode($e) . ')',
        strpos($e, '"show" cannot be checked: ') !== false && $plain === '');

    // ---- 2) the dictionary hook ---------------------------------------------------------
    // What does not fit the reference leaves that part off with the reason;
    // the rule and its check stand (the reference can change under it).
    $p = page(dict(['weight' => tag('{"chart":{"span":[0,2000]},"table":true}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('a span past the reference: the chart is left off with the reason (got ' . json_encode($r['growthChartWhy'] ?? $r) . ')',
        empty($r['configError']) && ($r['growthChartWhy'] ?? null) === ['chart' => 'its "span" [0, 2000] lies outside "who-wfa", which covers 0 to under 1826.25 days.']
        && !isset($r['growthCurveKey']) && !isset($p['cfg']['growthCurves']) && ($r['rangeSoftLo'] ?? null) === '-2');
    $p = page(dict(['weight' => tag('{"table":{"rows":"curve","step":10}}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('... and a table of reference values over 100 rows (got ' . json_encode($r['growthChartWhy'] ?? $r) . ')',
        empty($r['configError']) && !isset($r['growthTableKey']) && ($r['growthChartWhy']['table'] ?? null)
            === '"step" 10 gives more than 100 rows over "who-wfa" (0 to under 1826.25 days) — take a larger "step", or a shorter "span".');

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
    check('percentiles 1 to 99 reach z -2 and 2 at every row: no extent sent', !array_key_exists('extent', $set['male'] ?? []));
    $pm = page(dict(['weight' => tag('{"chart":{"lines":{"z":[0]}}}')]), $SAVED);
    $sm = $pm['cfg']['growthCurves'][ruleFor($pm, 'weight')['growthCurveKey'] ?? ''] ?? [];
    check('the median alone: the reference at z -2 and 2 travels as the extent, for the y axis (got ' . json_encode(array_keys($sm['male'] ?? [])) . ')',
        count($sm['male']['lines'] ?? []) === 1 && count($sm['male']['extent'] ?? []) === 2
        && is_array($sm['male']['extent'][0]) && is_array($sm['male']['extent'][1]));
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
    $CURVE = '{"table":{"rows":"curve","step":365.25,"columns":["age","valueAtZ:0","range"]}}';
    $p = page(dict(['weight' => tag($CURVE), 'weight_b' => tag($CURVE)]), $SAVED);
    $r = ruleFor($p, 'weight');
    $tk = $r['growthTableKey'] ?? null;
    $rows = $p['cfg']['growthCurveTables'][$tk] ?? null;
    check('a table of reference values: its rows, once, for the sex the page can read (got ' . json_encode($rows) . ')',
        is_string($tk) && isset($rows['male']) && !isset($rows['female']) && count($rows['male']) === 5
        && $rows['male'][1][0] === '365.25' && $rows['male'][0][1] === '3.3'
        && strpos($rows['male'][0][2], ' to ') !== false && !isset($p['cfg']['growthCurves']));
    check('... shared by a second rule with the same table', (ruleFor($p, 'weight_b')['growthTableKey'] ?? null) === $tk
        && count($p['cfg']['growthCurveTables']) === 1);
    check('decimals resolved from the field for a table that needs them', ($r['rangeDecimals'] ?? null) === 1);
    $p = page(dict(['weight' => tag('{"table":true}')]), $SAVED);
    $r = ruleFor($p, 'weight');
    check('a table of this visit: computed on the page, the percentile\'s decimals resolved, no decimals it does not use',
        !isset($r['growthTableKey']) && ($r['rangePercentileDecimals'] ?? null) === 1 && !array_key_exists('rangeDecimals', $r));
    // a sex saved blank on another form: nothing to draw, and no slot of the cap taken
    $blank = ['2' => [351 => ['record_id' => '2', 'sex' => '', 'dob' => '2025-01-10']]];
    $p = page(dict(['weight' => tag($SEVEN . '', 'who-wfa'), 'weight_b' => tag($CURVE)]), $blank);
    $r = ruleFor($p, 'weight');
    $NOSEX = 'the sex saved for this record is blank or neither the rule\'s male nor its female code, so there is nothing to draw.';
    check('a saved sex that is blank: the chart and the table say why, nothing is built (got ' . json_encode([$r['growthChartWhy'] ?? null, ruleFor($p, 'weight_b')['growthChartWhy'] ?? null]) . ')',
        ($r['growthChartWhy']['chart'] ?? null) === $NOSEX && (ruleFor($p, 'weight_b')['growthChartWhy']['table'] ?? null) === $NOSEX
        && !isset($p['cfg']['growthCurves']) && !isset($p['cfg']['growthCurveTables']));
    // a branch per reference: each draws its own curves
    $twoTags = tag('{"chart":{"lines":{"z":[-2,0,2]}}}', 'who-wfa', ',"when":"[height]=\'1\'"') . "\n"
        . tag('{"chart":{"lines":{"percentiles":[3,50,97]}}}', 'who-wfa', ',"when":"[height]=\'2\'"');
    $p = page(dict(['weight' => $twoTags], 'anthro_form'), $SAVED);
    $r = ruleFor($p, 'weight');
    $bk = [];
    foreach ((isset($r['branches']) ? $r['branches'] : []) as $b) $bk[] = $b['growthCurveKey'] ?? null;
    check('two tags on one field: one branch each, each with its own curves (got ' . json_encode($bk) . ')',
        count($bk) === 2 && $bk[0] !== $bk[1] && isset($p['cfg']['growthCurves'][$bk[0]], $p['cfg']['growthCurves'][$bk[1]]));
    // a survey sees only its own page: the inputs live on it
    $dSurvey = dict(['weight' => tag($SEVEN)], 'anthro_form');
    $dSurvey['dob'] = f('anthro_form', '', 'date_ymd');
    $p = page($dSurvey, $SAVED, 'survey');
    check('a survey gets the chart too (closed on the page)', isset(ruleFor($p, 'weight')['growthCurveKey'])
        && ($p['cfg']['context'] ?? null) === 'survey');
    // a condition the page reads keeps the chart: the page shows it while the condition holds
    $p = page(dict(['weight' => tag($SEVEN, 'who-wfa', ',"when":"[height]=\'99\'"')]), $SAVED);
    check('a rule with a condition still gets its chart', isset(ruleFor($p, 'weight')['growthCurveKey']));
    // an input on a form this user cannot see is withheld: no chart, no table
    $dObs = dict(['weight' => tag($SEVEN)]);
    $dObs['dob'] = f('secret_form', '', 'date_ymd');
    $m = mod($dObs, $SAVED);
    \REDCap::$rights = ['nurse' => ['forms' => ['enrol_form' => '1', 'anthro_form' => '1', 'secret_form' => '0']]];
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'anthro_form', 351, null, 1);
    $html = ob_get_clean();
    preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
    $wc = json_decode($mm[1] ?? 'null', true);
    $wr = ruleFor(['cfg' => $wc], 'weight');
    check('an input withheld from the page: no table, no chart', $wr !== null && ($wr['rangeAgeDobOp'] ?? null) === ['withheld']
        && !isset($wr['growthCurveKey']) && !isset($wc['growthCurves']));

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
        && $left[0][1] === 'this form already shows 4 growth charts or tables of reference values, the most one page may show (4). The check itself is not affected.');
    check('... and its check is unchanged', isset($left[0]) && empty($left[0][2]['deferred']) && empty($left[0][2]['configError'])
        && ($left[0][2]['rangeSoftLo'] ?? null) === '-2');
    $shared = ['a' => tag('{"chart":true}'), 'b' => tag('{"chart":true}'), 'c' => tag('{"chart":{"y":"z"}}'),
               'd' => tag('{"chart":{"span":[0,365]}}'), 'e' => tag('{"chart":{"span":[0,730]}}'), 'g' => tag('{"table":{"rows":"curve","step":365.25}}')];
    $p = page(dict($shared), $SAVED);
    check('charts that draw the same curves share one copy, a chart of z-scores and a table of this visit cost nothing, a table of reference values counts',
        count($p['cfg']['growthCurves'] ?? []) === 3 && ruleFor($p, 'a')['growthCurveKey'] === ruleFor($p, 'b')['growthCurveKey']
        && isset(ruleFor($p, 'g')['growthTableKey']) && !isset(ruleFor($p, 'g')['growthChartWhy']));
    $dir = tmpdir();
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 1, 'references' => new \stdClass()]));
    $p = page(dict(['w1' => tag('{"chart":true}'), 'w2' => tag('{"table":{"rows":"curve","step":365.25}}')]), $SAVED, 'form', ['reference-data-dir' => $dir]);
    check('the extra folder\'s "pageCharts" sets the cap (got ' . json_encode(ruleFor($p, 'w2')['growthChartWhy'] ?? null) . ')',
        isset(ruleFor($p, 'w1')['growthCurveKey']) && !isset(ruleFor($p, 'w2')['growthTableKey'])
        && (ruleFor($p, 'w2')['growthChartWhy']['table'] ?? '') === 'this form already shows 1 growth chart or table of reference values, the most one page may show (1). The check itself is not affected.');
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 0, 'references' => new \stdClass()]));
    $p = page(dict(['w1' => tag('{"chart":true}')]), $SAVED, 'form', ['reference-data-dir' => $dir]);
    check('"pageCharts" 0: no chart on any page, and the note says so (got ' . json_encode(ruleFor($p, 'w1')['growthChartWhy'] ?? null) . ')',
        !isset(ruleFor($p, 'w1')['growthCurveKey'])
        && (ruleFor($p, 'w1')['growthChartWhy']['chart'] ?? null) === 'this server shows no growth charts or tables of reference values on a page ("pageCharts" is 0). The check itself is not affected.');
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 51, 'references' => new \stdClass()]));
    GrowthReference::reset();
    $cat = GrowthReference::catalog($dir);
    check('a "pageCharts" past 50 is a problem of the folder, and the default applies; the references stay (got ' . json_encode($cat['problems']) . ')',
        in_array('the reference folder in the module settings: "pageCharts" must be a whole number from 0 to 50, so the default (4) applies.', $cat['problems'], true)
        && $cat['pageCharts'] === 4 && $cat['broken'] === null && isset($cat['references']['who-wfa']));
    GrowthReference::reset();
    rmtree($dir);
    // the bundled catalog says 4
    check('the bundled index.json leaves the default cap', GrowthReference::catalog(null)['pageCharts'] === GrowthReference::PAGE_CHARTS
        && GrowthReference::PAGE_CHARTS === 4);

    // ---- 5) the live fixture: section K of docs/testbed/RANGE_LIVE_ACCEPTANCE.md expects these ----
    $fx = fopen(__DIR__ . '/../docs/testbed/uvrange_chart_test_fields.csv', 'r');
    fgetcsv($fx, 0, ',', '"', '\\');
    $live = ['record_id' => f('uv_growth_chart')];
    while (($c = fgetcsv($fx, 0, ',', '"', '\\')) !== false) {
        if (count($c) < 18) continue;
        $live[$c[0]] = f($c[1], $c[17], $c[7], $c[3], $c[5]);
    }
    fclose($fx);
    $m = mod($live, ['2' => [351 => ['record_id' => '2']]]);
    \REDCap::$rights = ['nurse' => ['forms' => ['uv_growth_chart' => '1']]];
    ob_start();
    $m->redcap_data_entry_form_top(149, '2', 'uv_growth_chart', 351, null, 1);
    $html = ob_get_clean();
    preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
    $lp = ['cfg' => json_decode($mm[1] ?? 'null', true)];
    $err = function ($f) use ($lp) { $r = ruleFor($lp, $f); return (string) ($r['configError'] ?? ''); };
    check('live fixture: the six good rules are taken (K1)', $err('ugc_weight') === '' && $err('ugc_weight_z') === '' && $err('ugc_height') === ''
        && $err('ugc_head') === '' && $err('ugc_arm') === '' && $err('ugc_span') === '');
    check('live fixture: the four bad rules are refused for their own reason (K1)',
        strpos($err('ugc_bad_noref'), '"show" only applies with a "reference"') !== false
        && strpos($err('ugc_bad_bands'), '"bands" shades the "soft" range') !== false
        && strpos($err('ugc_bad_list'), '"show" "chart" must be true or an object of its settings') !== false
        && strpos($err('ugc_bad_rows'), 'has more than 100 rows over its "span" [0, 365]') !== false);
    check('live fixture: three sets of curves and one table of reference values fill the cap of 4; the z-score chart costs nothing (K2)',
        count($lp['cfg']['growthCurves'] ?? []) === 3 && count($lp['cfg']['growthCurveTables'] ?? []) === 1
        && isset(ruleFor($lp, 'ugc_weight')['growthCurveKey'], ruleFor($lp, 'ugc_height')['growthCurveKey'],
            ruleFor($lp, 'ugc_height')['growthTableKey'], ruleFor($lp, 'ugc_head')['growthCurveKey'])
        && !isset(ruleFor($lp, 'ugc_weight_z')['growthCurveKey']));
    check('live fixture: the fifth set is left off with the cap note, the span past the reference with its own (K7, K8)',
        (ruleFor($lp, 'ugc_arm')['growthChartWhy']['chart'] ?? null)
            === 'this form already shows 4 growth charts or tables of reference values, the most one page may show (4). The check itself is not affected.'
        && (ruleFor($lp, 'ugc_span')['growthChartWhy']['chart'] ?? null)
            === 'its "span" [0, 2000] lies outside "who-wfa", which covers 0 to under 1826.25 days.'
        && (ruleFor($lp, 'ugc_arm')['rangeSoftLo'] ?? null) === '-2' && (ruleFor($lp, 'ugc_span')['rangeSoftLo'] ?? null) === '-2');
    $hs = $lp['cfg']['growthCurves'][ruleFor($lp, 'ugc_head')['growthCurveKey'] ?? ''] ?? [];
    check('live fixture: the head chart draws the median alone, with the extent for its y axis, both sexes (K6, K12)',
        ($hs['values'] ?? null) === ['0'] && count($hs['male']['extent'] ?? []) === 2 && count($hs['female']['extent'] ?? []) === 2);
    $lt = $lp['cfg']['growthCurveTables'][ruleFor($lp, 'ugc_height')['growthTableKey']] ?? [];
    check('live fixture: the length table at each birthday, both sexes (K5) (got ' . json_encode($lt['male'][1] ?? null) . ')',
        count($lt['male'] ?? []) === 5 && ($lt['male'][1] ?? null) === ['365.25', '71.3', '75.7', '80.2']
        && ($lt['female'][1] ?? null) === ['365.25', '69.2', '74.0', '78.8']);
    $boy = function ($id, $y) {
        $e = GrowthReference::entry($id, null);
        $t = GrowthReference::table($e);
        return GrowthReference::output($e, $t, 'male', 365, $y, 'percentile', null, 1);
    };
    check('live fixture: the percentiles section K prints for the boy of 365 days',
        $boy('who-wfa', 9.6) === '48.3' && $boy('who-lhfa', 75.7) === '49.3' && $boy('who-hcfa', 46) === '48.0' && $boy('who-acfa', 15) === '62.3');

    // ---- 6) the pages tests/growth_chart_dom_js.cjs draws, and what it must show ----------
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
        // two branches: the first draws weight3's curves (one shared set), the second its own
        'weight4' => tag('{"chart":{"span":[0,365]}}', 'who-wfa', ',"when":"[height]=\'1\'"') . "\n"
            . tag('{"chart":{"lines":{"percentiles":[3,50,97]},"span":[0,365]}}', 'who-wfa', ',"when":"[height]=\'2\'"'),
    ], 'anthro_form');
    $LIVE['dob'] = f('anthro_form', '', 'date_ymd');
    $LIVE['weight2'] = f('anthro_form', $LIVE['weight2']['field_annotation'], 'number_2dp_comma_decimal');
    $pages = ['form' => page($LIVE, [])['cfg'], 'survey' => page($LIVE, [], 'survey')['cfg']];
    $dir = tmpdir();
    file_put_contents($dir . '/index.json', json_encode(['format' => 'uv-references-1', 'pageCharts' => 0, 'references' => new \stdClass()]));
    $pages['capped'] = page($LIVE, [], 'form', ['reference-data-dir' => $dir])['cfg'];
    // a weight typed in grams: the curves come in grams ("measureScale" undone)
    $GRAMS = dict(['weight' => tag('{"chart":{"lines":{"percentiles":[3,50,97]}}}', 'who-wfa', ',"measureScale":0.001')], 'anthro_form');
    $GRAMS['dob'] = f('anthro_form', '', 'date_ymd');
    $pages['scaled'] = page($GRAMS, [])['cfg'];
    // the median alone: the y axis spans the reference at z -2 to 2 (the extent)
    $MEDIAN = dict(['weight' => tag('{"chart":{"lines":{"z":[0]}}}')], 'anthro_form');
    $MEDIAN['dob'] = f('anthro_form', '', 'date_ymd');
    $pages['median'] = page($MEDIAN, [])['cfg'];
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

    // RNG-5: a chart with a fixed span and one that zooms can draw the same
    // curves under one key; the curves are built for the deeper zoom, whichever
    // chart comes first.
    $both = page(dict(['fx' => tag('{"chart":{"span":[0,1826.25]}}'), 'au' => tag('{"chart":true}')]), $SAVED);
    $auto = page(dict(['au' => tag('{"chart":true}')]), $SAVED);
    $flat = page(dict(['fx' => tag('{"chart":{"span":[0,1826.25]}}')]), $SAVED);
    $kBoth = ruleFor($both, 'au')['growthCurveKey'] ?? null;
    check('RNG-5: the two charts share one key', $kBoth !== null && (ruleFor($both, 'fx')['growthCurveKey'] ?? null) === $kBoth);
    $setOf = function ($p, $f) { $k = ruleFor($p, $f)['growthCurveKey'] ?? ''; return $p['cfg']['growthCurves'][$k]['male'] ?? null; };
    check('RNG-5: the shared curves are the ones a zoomable chart alone gets',
        $setOf($both, 'au') !== null && $setOf($both, 'au') === $setOf($auto, 'au'));
    check('RNG-5: ...which differ from those a fixed span alone gets', $setOf($flat, 'fx') !== null && $setOf($flat, 'fx') !== $setOf($auto, 'au'));

    fwrite(STDOUT, "growth_chart_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
