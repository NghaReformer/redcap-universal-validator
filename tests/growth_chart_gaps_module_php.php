<?php
/**
 * growth_chart_gaps_module_php.php — @UVRANGE "show" on the paths section K
 * of docs/testbed/RANGE_LIVE_ACCEPTANCE.md does not cover, through the
 * module, with the live fixture docs/testbed/uvrange_chart_gaps_fields.csv
 * loaded as the data dictionary of a project laid out as pid 149 is
 * (longitudinal, uv_chart_gaps on events 351 and 380, uv_chart_saved on 351,
 * uv_chart_repeat repeating on 380). The REDCap stub answers as REDCap 17.0.6
 * does: the map of what repeats is on the \Project object only.
 *
 * It pins what steps K18 to K36 expect from the server:
 *   - the rule errors (none on the good rules; the event-qualified rule
 *     without the project setting),
 *   - labels as language maps: the defaults, and the other languages under
 *     i18n for the chart and the table,
 *   - a sex and a date of birth saved on another form: one sex's curves and
 *     reference values on the page, against both on a page that types them;
 *     a sex saved blank leaves the chart and the table off with the reason,
 *   - two branches switching reference by age, each with its own curves,
 *   - a comma-decimal field, a chart of z-scores with percentile lines,
 *     "span" "auto" and "open" false,
 *   - a charted field on another event and on a repeating instrument, its
 *     saved inputs read in the event of the page, and an input read from
 *     another event with event and instance references on,
 * and writes the pages tests/growth_chart_gaps_dom_js.cjs draws
 * (growth_chart_gaps_fixture.json, refreshed with --write).
 *
 * Run:  php tests/growth_chart_gaps_module_php.php
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
    /** pid 149's events, as far as these forms go */
    const E1 = 351;      // event_1_arm_1
    const V1 = 380;      // visit_1_arm_1
    const EVENTS = [E1 => 'event_1_arm_1', V1 => 'visit_1_arm_1'];
    const FORMS = [E1 => ['uv_chart_gaps', 'uv_chart_saved'], V1 => ['uv_chart_gaps', 'uv_chart_repeat']];
    const REPEATS = [V1 => ['uv_chart_repeat' => '']];

    class REDCap {
        public static $data = [];
        public static $dictionary = [];
        public static $rights = [];
        public static function getData($p) {
            $data = self::$data;
            if (isset($p['records'])) $data = array_intersect_key($data, array_fill_keys($p['records'], true));
            if (!empty($p['events'])) {
                $keep = array_fill_keys($p['events'], true);
                foreach ($data as &$record) {
                    foreach ($record as $event => $row) {
                        if ($event !== 'repeat_instances' && !isset($keep[$event])) unset($record[$event]);
                    }
                    if (isset($record['repeat_instances'])) $record['repeat_instances'] = array_intersect_key($record['repeat_instances'], $keep);
                }
                unset($record);
            }
            return $data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $user = null) { return self::$rights; }
        public static function getGroupNames($u = false, $g = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function isLongitudinal() { return true; }
        public static function getEventNames($unique = false, $armNum = false, $eventId = null) {
            if ($eventId !== null) return isset(EVENTS[$eventId]) ? EVENTS[$eventId] : false;
            return EVENTS;
        }
        public static function getInstrumentEventMappings($pid = null) {
            $rows = [];
            foreach (FORMS as $e => $forms) foreach ($forms as $form) {
                $rows[] = ['arm_num' => 1, 'unique_event_name' => EVENTS[$e], 'form' => $form];
            }
            return $rows;
        }
        // REDCap 17.0.6 offers no static getRepeatingFormsEvents: the map is on \Project
    }
    class Project {
        public static $codes = '';
        public $project;
        public $project_id;
        public $longitudinal = true;
        public $eventInfo = [];
        public $eventsForms = FORMS;
        public function __construct($pid) {
            $this->project_id = $pid;
            $this->project = ['missing_data_codes' => self::$codes];
            foreach (EVENTS as $id => $name) $this->eventInfo[$id] = ['unique_event_name' => $name, 'arm_num' => 1];
        }
        public function getRepeatingFormsEvents() { return REPEATS; }
        public function getUniqueEventNames() { return EVENTS; }
        public function isRepeatingEvent($id) { return false; }
    }

    require_once __DIR__ . '/../UniversalValidator.php';

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

    // ---- the live fixture as the data dictionary -------------------------------------
    $fx = fopen(__DIR__ . '/../docs/testbed/uvrange_chart_gaps_fields.csv', 'r');
    $head = fgetcsv($fx, 0, ',', '"', '\\');
    $DICT = ['record_id' => f('uv_chart_gaps')];
    $rows = 0;
    while (($c = fgetcsv($fx, 0, ',', '"', '\\')) !== false) {
        if (count($c) < 18) continue;
        $DICT[$c[0]] = f($c[1], $c[17], $c[7], $c[3], $c[5]);
        $rows++;
    }
    fclose($fx);
    check('the fixture: 14 fields on three instruments, 18 columns', $rows === 14 && count($head) === 18
        && count(array_unique(array_column($DICT, 'form_name'))) === 3);

    $GLOBALS['__TEST_USER'] = 'nurse';
    /** The page of $form for $record at $event (instance $inst), with project settings $settings. */
    function page($form, $event, $record, array $data, $inst = 1, array $settings = [], $ctx = 'form') {
        global $DICT;
        GrowthReference::reset();
        unset($GLOBALS['Proj']);
        $GLOBALS['Proj'] = new \Project(149);
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = array_merge(['log-values' => 'raw'], $settings);
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $DICT;
        \REDCap::$data = $data;
        \REDCap::$rights = ['nurse' => ['forms' => ['uv_chart_gaps' => '1', 'uv_chart_saved' => '1', 'uv_chart_repeat' => '1']]];
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $record, $form, $event, null, 'hash', null, $inst);
        else $m->redcap_data_entry_form_top(149, $record, $form, $event, null, $inst);
        $html = ob_get_clean();
        $GLOBALS['lastHtml'] = $html;
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function ruleFor($cfg, $f) {
        foreach (isset($cfg['rules']) ? $cfg['rules'] : [] as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function err($cfg, $f) { $r = ruleFor($cfg, $f); return (string) (isset($r['configError']) ? $r['configError'] : ''); }
    function setOf($cfg, $r) {
        $k = isset($r['growthCurveKey']) ? $r['growthCurveKey'] : null;
        return ($k !== null && isset($cfg['growthCurves'][$k])) ? $cfg['growthCurves'][$k] : null;
    }
    function sexesOf($set) { return $set === null ? null : array_values(array_intersect(['male', 'female'], array_keys($set))); }

    /** XE-1: a boy on event 351 (born 10 Jan 2025, weighed 10 Jan 2026), a girl on 380. */
    $A351 = ['record_id' => 'XE-1', 'ucg_sex' => '1', 'ucg_dob' => '2025-01-10', 'ucg_visit' => '2026-01-10'];
    $A380 = ['ucg_sex' => '2', 'ucg_dob' => '2025-01-10', 'ucg_visit' => '2026-01-10'];
    $XE1 = ['XE-1' => [E1 => $A351, V1 => $A380, 'repeat_instances' => [V1 => ['uv_chart_repeat' => [
        1 => ['ucr_visit' => '2026-01-10', 'ucr_weight' => '9.6'], 2 => ['ucr_visit' => '2026-07-10', 'ucr_weight' => '8.0']]]]]];
    $NOSEX = 'the sex saved for this record is blank or neither the rule\'s male nor its female code, so there is nothing to draw.';

    // ---- K18 to K28: uv_chart_gaps, every input typed on the page ----------------------
    $A = page('uv_chart_gaps', E1, 'XE-9', []);
    $htmlA = $GLOBALS['lastHtml'];
    foreach (['ucg_mlm', 'ucg_comma', 'ucg_zpct', 'ucg_branch'] as $f) check('uv_chart_gaps: "' . $f . '" is taken (got ' . json_encode(err($A, $f)) . ')', err($A, $f) === '');
    $mlm = ruleFor($A, 'ucg_mlm');
    $LANG_CHART = ['summary' => ['Growth chart', 'Courbe de croissance'], 'title' => ['Weight-for-age', 'Poids pour l\'âge'],
        'x' => ['Age ({unit})', 'Âge ({unit})'], 'y' => ['Weight (kg)', 'Poids (kg)'], 'point' => ['This visit', 'Cette visite'],
        'band' => ['Usual range', 'Zone habituelle'], 'lines' => ['Percentiles', 'Centiles'], 'months' => ['months', 'mois'],
        'years' => ['years', 'ans'], 'male' => ['boy', 'garçon'], 'female' => ['girl', 'fille']];
    $LANG_TABLE = ['summary' => ['This visit', 'Cette visite'], 'caption' => ['Weight-for-age', 'Poids pour l\'âge'],
        'age' => ['Age (days)', 'Âge (jours)'], 'value' => ['Weight (kg)', 'Poids (kg)'], 'z' => ['Z-score', 'Score z'],
        'percentile' => ['Percentile', 'Centile']];
    $okLang = true;
    foreach ($LANG_CHART as $k => $t) {
        $okLang = $okLang && ($mlm['rangeShow']['chart']['labels'][$k] ?? null) === $t[0]
            && ($mlm['i18n']['show.chart.labels.' . $k] ?? null) === ['en' => $t[0], 'fr' => $t[1]];
    }
    foreach ($LANG_TABLE as $k => $t) {
        $okLang = $okLang && ($mlm['rangeShow']['table']['labels'][$k] ?? null) === $t[0]
            && ($mlm['i18n']['show.table.labels.' . $k] ?? null) === ['fr' => $t[1]];
    }
    check('K27: each label keeps its English text, and its other languages travel under i18n ("en" named, "default" not) (got '
        . json_encode($mlm['i18n'] ?? null, JSON_UNESCAPED_UNICODE) . ')', $okLang && count($mlm['i18n']) === 17);
    check('K27: the "this visit" table keeps its default columns', ($mlm['rangeShow']['table']['columns'] ?? null) === ['age', 'value', 'z', 'percentile']);
    $sets = $A['growthCurves'] ?? [];
    $both = true;
    foreach ($sets as $s) $both = $both && sexesOf($s) === ['male', 'female'];
    $br = ruleFor($A, 'ucg_branch');
    $bk = [];
    foreach ($br['branches'] ?? [] as $b) $bk[] = [$b['rangeReference'] ?? null, $b['growthCurveKey'] ?? null, $b['when'] ?? null];
    check('K27: four sets of curves, both sexes each, the z-score chart none (got ' . json_encode(array_keys($sets)) . ')',
        count($sets) === 4 && $both && !isset(ruleFor($A, 'ucg_zpct')['growthCurveKey']) && !isset($A['growthCurveTables'])
        && isset($mlm['growthCurveKey'], ruleFor($A, 'ucg_comma')['growthCurveKey']));
    check('K27: two branches, one per reference, each with its own curves (got ' . json_encode($bk) . ')',
        count($bk) === 2 && $bk[0][0] === 'who-wfa' && $bk[1][0] === 'who2007-wfa' && $bk[0][1] !== $bk[1][1]
        && isset($sets[$bk[0][1]], $sets[$bk[1][1]]) && $sets[$bk[1][1]]['span'] === [60, 121] && $sets[$bk[0][1]]['values'] === ['-6', '-3', '0', '3', '5']);
    // Every row of both sexes may be read: each reference is named by hash,
    // and its rows script, which the browser caches, loads before the engine (PRF-7).
    $rowsJs = function ($id) { return GrowthReference::rowsPath(GrowthReference::entry($id)['sha256']); };
    $html = $htmlA;
    check('K27: both references travel, named by hash, their rows in their own scripts',
        ($A['growth']['who-wfa']['rowsFrom'] ?? null) === GrowthReference::entry('who-wfa')['sha256']
        && ($A['growth']['who2007-wfa']['rowsFrom'] ?? null) === GrowthReference::entry('who2007-wfa')['sha256']
        && !isset($A['growth']['who-wfa']['male']) && !isset($A['growth']['who2007-wfa']['female'])
        && strpos($html, '<script src="/x/' . $rowsJs('who-wfa') . '"></script>') !== false
        && strpos($html, '<script src="/x/' . $rowsJs('who2007-wfa') . '"></script>') !== false
        && strpos($html, $rowsJs('who2007-wfa')) < strpos($html, '/x/js/engine.'));
    $cm = ruleFor($A, 'ucg_comma');
    check('K24: the comma field is read with a comma, and its chart and table travel', ($cm['decimalComma'] ?? null) === true
        && ($cm['rangeShow']['table']['columns'] ?? null) === ['age', 'value', 'z', 'percentile'] && ($cm['rangePercentileDecimals'] ?? null) === 1);
    $zp = ruleFor($A, 'ucg_zpct');
    check('K26: a chart of z-scores with percentile lines, "span" "auto" read as no span, closed (got ' . json_encode($zp['rangeShow'] ?? null) . ')',
        ($zp['rangeShow'] ?? null) === ['chart' => ['lines' => ['kind' => 'percentiles', 'values' => ['3', '15', '50', '85', '97']],
            'bands' => false, 'y' => 'z', 'span' => null, 'open' => false, 'labels' => []]]
        && ($zp['growthChartSource'] ?? null) === 'WHO Child Growth Standards (2006)');
    $A380page = page('uv_chart_gaps', V1, 'XE-1', $XE1);
    $same = function ($a, $b) { unset($a['eventId']); unset($b['eventId']); return $a === $b; };
    check('K28: on event 380 the form gets the same rules and curves', array_keys($A380page['growthCurves'] ?? []) === array_keys($sets)
        && err($A380page, 'ucg_mlm') === '' && err($A380page, 'ucg_branch') === '');

    // ---- K29 to K32: uv_chart_saved, the sex and the date of birth saved on uv_chart_gaps ----
    $B = page('uv_chart_saved', E1, 'XE-1', $XE1);
    $w = ruleFor($B, 'ucs_weight');
    $all = ruleFor($B, 'ucs_all');
    check('K29: both rules are taken', err($B, 'ucs_weight') === '' && err($B, 'ucs_all') === '');
    check('K29: the saved sex and date of birth travel as values, the date of the measurement is read on the page (got '
        . json_encode([$w['rangeSexOp'] ?? null, $w['rangeAgeDobOp'] ?? null, $w['rangeAgeAtOp'] ?? null, $w['snapshotFields'] ?? null]) . ')',
        ($w['rangeSexOp'] ?? null) === ['lit', '1'] && ($w['rangeAgeDobOp'] ?? null) === ['lit', '2025-01-10']
        && ($w['rangeAgeAtOp'] ?? null) === ['ref', 'ucs_visit', null] && ($w['snapshotFields'] ?? null) === ['ucg_sex', 'ucg_dob']);
    check('K29: ucs_all reads every input saved', ($all['rangeAgeAtOp'] ?? null) === ['lit', '2026-01-10']
        && ($all['snapshotFields'] ?? null) === ['ucg_sex', 'ucg_dob', 'ucg_visit']);
    check('K29: the boy\'s curves only, for each chart (got ' . json_encode([sexesOf(setOf($B, $w)), sexesOf(setOf($B, $all))]) . ')',
        sexesOf(setOf($B, $w)) === ['male'] && sexesOf(setOf($B, $all)) === ['male'] && count($B['growthCurves']) === 2);
    $tk = $w['growthTableKey'] ?? null;
    $bt = $B['growthCurveTables'][$tk] ?? [];
    check('K29: the boy\'s reference values only, a row at each birthday (got ' . json_encode($bt['male'] ?? null) . ')',
        is_string($tk) && array_keys($bt) === ['male'] && count($bt['male']) === 5 && ($bt['male'][1] ?? null) === ['365.25', '7.8', '9.6', '11.8']);
    check('K29: the boy\'s rows may all be read, so the page names the rows script instead of carrying them',
        ($B['growth']['who-wfa']['rowsFrom'] ?? null) === GrowthReference::entry('who-wfa')['sha256'] && !isset($B['growth']['who-wfa']['male']));
    $B2 = page('uv_chart_saved', E1, 'XE-2', ['XE-2' => [E1 => ['record_id' => 'XE-2']]]);
    $w2 = ruleFor($B2, 'ucs_weight');
    check('K31: a sex saved blank: the chart and the table are left off with the reason, and nothing is built (got '
        . json_encode([$w2['growthChartWhy'] ?? null, ruleFor($B2, 'ucs_all')['growthChartWhy'] ?? null]) . ')',
        ($w2['growthChartWhy'] ?? null) === ['chart' => $NOSEX, 'table' => $NOSEX]
        && (ruleFor($B2, 'ucs_all')['growthChartWhy'] ?? null) === ['chart' => $NOSEX]
        && !isset($B2['growthCurves']) && !isset($B2['growthCurveTables']) && err($B2, 'ucs_weight') === '');
    $B3 = page('uv_chart_saved', E1, 'XE-3', ['XE-3' => [E1 => ['record_id' => 'XE-3', 'ucg_sex' => '2', 'ucg_dob' => '2025-01-10', 'ucg_visit' => '2026-01-10']]]);
    check('K32: a girl saved: her curves and values only', sexesOf(setOf($B3, ruleFor($B3, 'ucs_weight'))) === ['female']
        && array_keys($B3['growthCurveTables'][ruleFor($B3, 'ucs_weight')['growthTableKey']] ?? []) === ['female']);

    // ---- K33 to K35: uv_chart_repeat on event 380, a repeating instrument ---------------------
    $C1 = page('uv_chart_repeat', V1, 'XE-1', $XE1, 1, ['enable-event-instance-refs' => true]);
    $cw = ruleFor($C1, 'ucr_weight');
    $cx = ruleFor($C1, 'ucr_xev');
    check('K33: ucr_weight is taken and reads the sex saved in this event (a girl on 380) (got '
        . json_encode([err($C1, 'ucr_weight'), $cw['rangeSexOp'] ?? null]) . ')',
        err($C1, 'ucr_weight') === '' && ($cw['rangeSexOp'] ?? null) === ['lit', '2'] && ($cw['rangeAgeAtOp'] ?? null) === ['ref', 'ucr_visit', null]
        && sexesOf(setOf($C1, $cw)) === ['female']);
    check('K35: ucr_xev reads event_1_arm_1 (a boy there), never blocks, and draws his curves (got '
        . json_encode([err($C1, 'ucr_xev'), $cx['rangeSexOp'] ?? null, $cx['extendedAdvisory'] ?? null]) . ')',
        err($C1, 'ucr_xev') === '' && ($cx['rangeSexOp'] ?? null) === ['lit', '1'] && ($cx['extendedAdvisory'] ?? null) === true
        && sexesOf(setOf($C1, $cx)) === ['male']);
    $C2 = page('uv_chart_repeat', V1, 'XE-1', $XE1, 2, ['enable-event-instance-refs' => true]);
    check('K34: instance 2 is built as instance 1', ($C2['growthCurves'] ?? null) === ($C1['growthCurves'] ?? null)
        && err($C2, 'ucr_weight') === '' && (ruleFor($C2, 'ucr_weight')['rangeSexOp'] ?? null) === ['lit', '2']);
    $C0 = page('uv_chart_repeat', V1, 'XE-1', $XE1, 1);
    check('K33: without event and instance references, ucr_xev shows its configuration error and ucr_weight still draws (got '
        . json_encode(err($C0, 'ucr_xev')) . ')', strpos(err($C0, 'ucr_xev'), 'event and instance references') !== false
        && err($C0, 'ucr_weight') === '' && sexesOf(setOf($C0, ruleFor($C0, 'ucr_weight'))) === ['female']);

    // ---- the visits the steps type, worked out by GrowthReference -----------------------
    // tests/growth_chart_gaps_dom_js.cjs compares each tooltip, table and note
    // with these, so the numbers on the run sheet are the server's.
    $days = function ($ymd) { return (int) floor(strtotime($ymd . ' 00:00:00 UTC') / 86400); };
    $visit = function ($ref, $sex, array $in, $typed, $comma = false) {
        $e = GrowthReference::entry($ref, null);
        $t = GrowthReference::table($e);
        $x = GrowthReference::axisValue($e, $in)['x'];
        $y = GrowthReference::measure($typed, $comma, null)['y'];
        return ['x' => $x, 'z' => GrowthReference::zScore($e, $t, $sex, $x, $y)['z'], 'pct' => GrowthReference::percentile($e, $t, $sex, $x, $y, 1)];
    };
    $dates = function ($dob, $at) use ($days) { return ['dobDays' => $days($dob), 'atDays' => $days($at)]; };
    $visits = [
        'boy365_9.6'      => $visit('who-wfa', 'male', $dates('2025-01-10', '2026-01-10'), '9.6'),
        'boy365_7.0'      => $visit('who-wfa', 'male', $dates('2025-01-10', '2026-01-10'), '7.0'),
        'boy365_7,0'      => $visit('who-wfa', 'male', $dates('2025-01-10', '2026-01-10'), '7,0', true),
        'boy912_9.6'      => $visit('who-wfa', 'male', $dates('2025-01-10', '2027-07-10'), '9.6'),
        'girl365_9.6'     => $visit('who-wfa', 'female', $dates('2025-01-10', '2026-01-10'), '9.6'),
        'girl546_8.0'     => $visit('who-wfa', 'female', $dates('2025-01-10', '2026-07-10'), '8.0'),
        'boy1826d_18.0'   => $visit('who-wfa', 'male', ['days' => '1826'], '18.0'),
        'boy1827d_18.0'   => $visit('who2007-wfa', 'male', ['days' => '1827'], '18.0'),
        'boy2000d_18.0'   => $visit('who2007-wfa', 'male', ['days' => '2000'], '18.0'),
    ];
    check('the visits: 365 and 546 days, and 1826 to 2000 days read in months on WHO 2007 (got ' . json_encode($visits) . ')',
        $visits['boy365_9.6']['x'] === 365.0 && $visits['girl546_8.0']['x'] === 546.0 && $visits['boy365_9.6']['z'] === '-0.04'
        && $visits['boy365_9.6']['pct'] === '48.3' && $visits['boy365_7,0'] === $visits['boy365_7.0']
        && abs($visits['boy2000d_18.0']['x'] - 2000 / 30.4375) < 1e-9);

    // ---- the pages tests/growth_chart_gaps_dom_js.cjs draws -------------------------------
    // Each page's reference rows are a slice of the rows the page that types
    // every input carries (both sexes, every row), so they are written once.
    $pages = ['gaps' => $A, 'saved' => $B, 'savedBlank' => $B2, 'repeat' => $C1, 'repeatOff' => $C0];
    // A page named by hash carries no rows (the browser suite loads its rows
    // script); the pool holds the whole rows of each reference a page carries inline.
    $pool = [];
    foreach ($pages as $pg) {
        foreach (isset($pg['growth']) ? $pg['growth'] : [] as $ref => $g) {
            if (isset($pool[$ref]) || (empty($g['male']) && empty($g['female']))) continue;
            $e = GrowthReference::entry($ref);
            $pool[$ref] = GrowthReference::pageCopy($e, GrowthReference::table($e));
        }
    }
    $sliced = true;
    foreach ($pages as $k => $pg) {
        foreach (isset($pg['growth']) ? $pg['growth'] : [] as $ref => $g) {
            foreach (['male', 'female'] as $sex) {
                if (!isset($g[$sex])) continue;
                $rows = $g[$sex];
                if (!$rows) continue;  // an empty list stays as it is
                $from = 0;
                if ($rows) {
                    $from = array_search($rows[0], $pool[$ref][$sex], true);
                    if ($from === false || array_slice($pool[$ref][$sex], $from, count($rows)) !== $rows) { $sliced = false; continue; }
                }
                $pages[$k]['growth'][$ref][$sex] = ['slice' => [$from, count($rows)]];
            }
        }
    }
    check('each page\'s reference rows are a slice of the rows the typed page carries', $sliced);
    $enc = function ($v) { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); };
    $lines = ['{"note":' . $enc('Generated by php tests/growth_chart_gaps_module_php.php --write; do not edit.') . ','];
    $first = true;
    foreach ($pages as $k => $pg) { $lines[] = ($first ? '"pages":{' : '') . $enc($k) . ':' . $enc($pg) . ','; $first = false; }
    $lines[count($lines) - 1] = rtrim($lines[count($lines) - 1], ',') . '},';
    $lines[] = '"visits":' . $enc($visits) . ',';
    $lines[] = '"growth":' . $enc($pool) . '}';
    $fixture = implode("\n", $lines) . "\n";
    check('the fixture is JSON', is_array(json_decode($fixture, true)));
    $fxFile = __DIR__ . '/growth_chart_gaps_fixture.json';
    if (in_array('--write', $argv, true)) {
        file_put_contents($fxFile, $fixture);
        fwrite(STDOUT, "wrote tests/growth_chart_gaps_fixture.json (" . strlen($fixture) . " bytes)\n");
    } else {
        check('tests/growth_chart_gaps_fixture.json is current (run php tests/growth_chart_gaps_module_php.php --write and commit it)',
            is_file($fxFile) && file_get_contents($fxFile) === $fixture);
    }

    fwrite(STDOUT, "growth_chart_gaps_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
