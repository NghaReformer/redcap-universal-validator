<?php
/**
 * window_module_php.php — @UVWINDOW through the module (2.2.0).
 *
 * window_php.php pins the verdict; this file pins the REDCap glue around it:
 *   - the field and dictionary hooks: a window needs a date field, a "from"
 *     field of the same kind, and a unit the field can hold; the types and
 *     display formats travel on the rule,
 *   - the page fold of the "from" date: a field of this page stays live, an
 *     entitled off-page value is baked in as its saved Y-M-D (a snapshot, so
 *     the rule never blocks), and a value the viewer may not read is never
 *     shipped at all (survey, no rights to its form),
 *   - the server clock reaches the page only when a rule reads it,
 *   - the post-save audit logs window-early / window-late / future, checks
 *     nothing against a blank "from" date, and re-checks a window when only
 *     the form holding its "from" date is saved (reverse dependency),
 *   - "from" of today, now or a written date, periods and notPast (2.7.0):
 *     the dictionary checks, the week start, the time zone refusals, the
 *     saved values the page compares with (windowSaved), and the audit and
 *     the scan judging each value against the day it was saved, read from a
 *     mock of REDCap's log (ValueStamps); an unknown day is not checked.
 *
 * Run:  php tests/window_module_php.php
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

    require_once __DIR__ . '/../UniversalValidator.php';

    /** A mock of REDCap's log for ValueStamps: rows [id, pid, pk, event_id, ts, data_values, object_type, event]. */
    final class WinLog implements \INSPIRE\UniversalValidator\Scan\ScanDb
    {
        public $rows = [];
        public function select($sql, array $params = [])
        {
            if (strpos($sql, 'FROM redcap_projects') !== false) return [['redcap_log_event4']];
            preg_match('/LIMIT (\d+)$/', $sql, $m);
            $before = isset($params[2]) ? (int) $params[2] : PHP_INT_MAX;
            $out = [];
            foreach ($this->rows as $r) {
                if ($r[1] !== $params[0] || $r[2] !== $params[1] || $r[0] >= $before) continue;
                if ($r[6] !== 'redcap_data' || !in_array($r[7], ['INSERT', 'UPDATE'], true)) continue;
                $out[] = [(string) $r[0], $r[4], $r[3], $r[5], $r[2]];
            }
            usort($out, function ($a, $b) { return (int) $b[0] - (int) $a[0]; });
            return array_slice($out, 0, (int) $m[1]);
        }
        public function exec($sql, array $params = []) {}
        public function affected() { return 0; }
        public function begin() {}
        public function commit() {}
        public function rollback() {}
    }
    require_once __DIR__ . '/../php/Scan/ArrayScanStore.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    function f($form, $ann = '', $validation = '', $type = 'text') {
        return ['field_type' => $type, 'form_name' => $form, 'field_annotation' => $ann,
                'text_validation_type_or_show_slider_number' => $validation];
    }
    // baseline_form holds the anchor; visit_form the windows.
    $DICT = [
        'record_id'     => f('baseline_form'),
        'visit_date_bl' => f('baseline_form', '', 'date_dmy'),
        'dose_at'       => f('baseline_form', '', 'datetime_ymd'),
        'visit_date_2'  => f('visit_form', '@UVWINDOW={"from":"[visit_date_bl]","window":[21,35],"blockSave":"hard"}', 'date_dmy'),
        'collected'     => f('visit_form', '@UVWINDOW={"notFuture":true}', 'date_ymd'),
        'v_start'       => f('visit_form', '', 'date_ymd'),
        'v_end'         => f('visit_form', '@UVWINDOW={"from":"[v_start]","window":[0,7]}', 'date_mdy'),
        'bad_novalid'   => f('visit_form', '@UVWINDOW={"notFuture":true}', ''),
        'bad_number'    => f('visit_form', '@UVWINDOW={"notFuture":true}', 'number'),
        'bad_hours'     => f('visit_form', '@UVWINDOW={"from":"[v_start]","window":[0,2],"unit":"hours"}', 'date_ymd'),
        'bad_nofield'   => f('visit_form', '@UVWINDOW={"from":"[nope]","window":[0,2]}', 'date_ymd'),
        'bad_self'      => f('visit_form', '@UVWINDOW={"from":"[bad_self]","window":[0,2]}', 'date_ymd'),
        'bad_notdate'   => f('visit_form', '@UVWINDOW={"from":"[record_id]","window":[0,2]}', 'date_ymd'),
        'bad_family'    => f('visit_form', '@UVWINDOW={"from":"[dose_at]","window":[0,2]}', 'date_ymd'),
        'bad_notes'     => f('visit_form', '@UVWINDOW={"notFuture":true}', '', 'notes'),
    ];
    $DATA = ['2' => [351 => [
        'record_id' => '2', 'visit_date_bl' => '2026-03-01', 'dose_at' => '',
        'visit_date_2' => '2026-03-15', 'collected' => '2026-10-10',
        'v_start' => '2026-01-01', 'v_end' => '2026-01-05',
    ]]];
    $FULL = ['nurse' => ['forms' => ['baseline_form' => '1', 'visit_form' => '1']]];
    $PART = ['nurse' => ['forms' => ['baseline_form' => '0', 'visit_form' => '1']]];
    $CLOCK = ['today' => '2026-10-09', 'now' => '2026-10-09 14:30:00'];

    function mod($dict, $data, $rights, $user) {
        global $CLOCK;
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->projectSettings = ['log-values' => 'raw'];
        $m->projectIdReturn = 149;
        \REDCap::$dictionary = $dict;
        \REDCap::$data = $data;
        \REDCap::$rights = $rights;
        // Pin the server clock: the test must not depend on the day it runs.
        $rp = new \ReflectionProperty(\INSPIRE\UniversalValidator\UniversalValidator::class, 'clockOverride');
        $rp->setAccessible(true);
        $rp->setValue($m, $CLOCK);
        return $m;
    }
    function page($m, $ctx, $rec, $form) {
        ob_start();
        if ($ctx === 'survey') $m->redcap_survey_page_top(149, $rec, $form, 351, null, 'hash', null, 1);
        else $m->redcap_data_entry_form_top(149, $rec, $form, 351, null, 1);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleFor($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function findings($m, $type = 'invalid-id-saved') {
        return array_values(array_map(function ($c) { return $c[1]; },
            array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })));
    }

    // ---- 1) the hooks: what a window needs from the dictionary -----------------
    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $err = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('no validation: refused, named', strpos($err('bad_novalid'), 'needs a date field') !== false);
    check('number validation: refused, names it', strpos($err('bad_number'), '(it has "number")') !== false);
    check('hours on a date field: refused', strpos($err('bad_hours'), '"unit" "hours" needs a datetime field') !== false);
    check('unknown "from" field: refused', strpos($err('bad_nofield'), '"[nope]", which is not a field') !== false);
    check('"from" itself: refused', strpos($err('bad_self'), 'names this field itself') !== false);
    check('"from" not a date: refused', strpos($err('bad_notdate'), 'is not a date field') !== false);
    check('date counted from a datetime: refused', strpos($err('bad_family'), 'holds dates with a time and this field holds dates') !== false);
    check('notes field: refused by eligibility', strpos($err('bad_notes'), 'does not support "notes" fields') !== false);
    check('every refusal names @UVWINDOW', strpos($err('bad_family'), '@UVWINDOW on "bad_family"') === 0);
    // With event and instance references on, [event-name][f] and [f][current-instance]
    // are this same entry too; [f][previous-instance] is another entry.
    $SELF = $DICT;
    $SELF['self_ev']   = f('visit_form', '@UVWINDOW={"from":"[event-name][self_ev]","window":[0,2]}', 'date_ymd');
    $SELF['self_in']   = f('visit_form', '@UVWINDOW={"from":"[self_in][current-instance]","window":[0,2]}', 'date_ymd');
    $SELF['self_prev'] = f('visit_form', '@UVWINDOW={"from":"[self_prev][previous-instance]","window":[0,2]}', 'date_ymd');
    $ms = mod($SELF, $DATA, $FULL, 'nurse');
    $ms->projectSettings['enable-event-instance-refs'] = true;
    $ps = page($ms, 'form', '2', 'visit_form');
    $serr = function ($field) use ($ps) { $r = ruleFor($ps, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('"from" [event-name] of itself: refused', strpos($serr('self_ev'), 'names this field itself') !== false);
    check('"from" itself at [current-instance]: refused', strpos($serr('self_in'), 'names this field itself') !== false);
    check('"from" itself at [previous-instance]: another entry, not refused as itself', strpos($serr('self_prev'), 'names this field itself') === false);

    // A default filled in another zone that runs ahead of the rule's clock
    // would always read as in the future.
    $Z = $DICT;
    $Z['nf_utc']  = f('visit_form', '@NOW-UTC @UVWINDOW={"notFuture":true}', 'datetime_ymd');
    $Z['nf_tday'] = f('visit_form', '@TODAY-UTC @UVWINDOW={"notFuture":true}', 'date_ymd');
    $Z['nf_srv']  = f('visit_form', '@NOW-SERVER @UVWINDOW={"notFuture":true}', 'datetime_ymd');
    $Z['nf_now']  = f('visit_form', '@NOW @UVWINDOW={"notFuture":true}', 'datetime_ymd');
    $Z['win_utc'] = f('visit_form', '@NOW-UTC @UVWINDOW={"from":"[v_start]","window":[0,7]}', 'date_ymd');
    $zoneErr = function ($tz, $field) use ($Z, $DATA, $FULL) {
        $mz = mod($Z, $DATA, $FULL, 'nurse');
        if ($tz !== null) $mz->projectSettings['window-timezone'] = $tz;
        $r = ruleFor(page($mz, 'form', '2', 'visit_form'), $field);
        return $r && isset($r['configError']) ? $r['configError'] : '';
    };
    check('@NOW-UTC with a clock behind UTC: refused, names both zones',
        strpos($zoneErr('America/New_York', 'nf_utc'), 'filled by @NOW-UTC: that value is UTC time') !== false
        && strpos($zoneErr('America/New_York', 'nf_utc'), '(America/New_York)') !== false);
    check('@TODAY-UTC with a clock behind UTC: refused', strpos($zoneErr('America/New_York', 'nf_tday'), '@TODAY-UTC') !== false);
    check('@NOW-UTC with a clock never behind UTC: allowed', $zoneErr('Africa/Lagos', 'nf_utc') === '');
    check('@NOW-UTC with a clock behind UTC for part of the year: refused',
        strpos($zoneErr('Atlantic/Azores', 'nf_utc'), 'runs ahead') !== false);
    check('@NOW-UTC with a clock level with or ahead of UTC all year: allowed', $zoneErr('Europe/London', 'nf_utc') === '');
    check('@NOW (the computer\'s time) is not judged here', $zoneErr('America/New_York', 'nf_now') === '');
    check('a window without notFuture is not refused for it', $zoneErr('America/New_York', 'win_utc') === '');
    $mz = mod($Z, $DATA, $FULL, 'nurse');
    $mz->projectSettings['window-timezone'] = 'America/New_York';
    check('...and gets no note about it', empty(ruleFor(page($mz, 'form', '2', 'visit_form'), 'win_utc')['windowNotFutureOff']));
    $tzWas = date_default_timezone_get();
    date_default_timezone_set('Europe/Paris');
    check('@NOW-SERVER ahead of the rule\'s clock: refused', strpos($zoneErr('UTC', 'nf_srv'), 'Europe/Paris time') !== false);
    check('@NOW-SERVER on the server\'s own clock: allowed', $zoneErr(null, 'nf_srv') === '');
    date_default_timezone_set($tzWas);
    check('the refusal names the time zone setting first', strpos($zoneErr('America/New_York', 'nf_utc'),
        'Set the project setting Time zone for @UVWINDOW "notFuture" to UTC, or fill the field with @NOW if the computers '
        . 'entering data are set to America/New_York time.') !== false);
    // A rule with a window keeps it: only "notFuture" is dropped, with a note.
    $Z['win_nf_utc'] = f('visit_form', '@TODAY-UTC @UVWINDOW={"from":"[v_start]","window":[0,7],"notFuture":true}', 'date_ymd');
    $mz = mod($Z, $DATA, $FULL, 'nurse');
    $mz->projectSettings['window-timezone'] = 'America/New_York';
    $r = ruleFor(page($mz, 'form', '2', 'visit_form'), 'win_nf_utc');
    check('@TODAY-UTC on a rule with a window: the window stays, notFuture is dropped with a note',
        $r && empty($r['configError']) && empty($r['windowNotFuture']) && ($r['windowLo'] ?? null) === 0
        && strpos(implode(' ', $r['windowNotFutureOff'] ?? []), 'filled by @TODAY-UTC') !== false);
    // ...on each branch of a branched rule too.
    $Z['win_nf_br'] = f('visit_form', '@TODAY-UTC @UVWINDOW={"from":"[v_start]","window":[0,7],"notFuture":true,"when":"[v_start]<>\'\'"} '
        . '@UVWINDOW={"from":"[v_start]","window":[0,14],"notFuture":true}', 'date_ymd');
    $mz = mod($Z, $DATA, $FULL, 'nurse');
    $mz->projectSettings['window-timezone'] = 'America/New_York';
    $r = ruleFor(page($mz, 'form', '2', 'visit_form'), 'win_nf_br');
    $notes = array_map(function ($b) { return [empty($b['windowNotFuture']), strpos(implode(' ', $b['windowNotFutureOff'] ?? []), 'filled by @TODAY-UTC') !== false]; },
        array_values($r['branches'] ?? []));
    check('@TODAY-UTC on a branched rule: every branch drops notFuture with the note', $notes === [[true, true], [true, true]]);

    // A branch selector read from another form, when the page was built: every
    // branch, the else branch too, may be the wrong one, so none blocks.
    $G = $DICT;
    $G['gate_br'] = f('visit_form', '@UVWINDOW={"notFuture":true,"blockSave":"hard","when":"[visit_date_bl]<>\'\'"} '
        . '@UVWINDOW={"notFuture":true,"blockSave":"hard"}', 'date_ymd');
    $r = ruleFor(page(mod($G, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form'), 'gate_br');
    $gates = array_map(function ($b) { return [!empty($b['snapshotGate']), $b['blockSave'] ?? null]; }, array_values($r['branches'] ?? []));
    check('a selector from another form: every branch, the else too, is marked and does not block (got ' . json_encode($gates) . ')',
        count($gates) === 2 && $gates[0][0] && $gates[1][0]);

    // ---- 2) the fold: live, snapshot, and nothing shipped when not entitled ---
    $r = ruleFor($p, 'visit_date_2');
    check('types and formats travel on the rule', $r && $r['dateType'] === 'date' && $r['dateFormat'] === 'dmy'
        && $r['fromType'] === 'date' && $r['fromFormat'] === 'dmy');
    check('entitled off-page "from": its saved Y-M-D is baked in', $r && $r['windowFromOp'] === ['lit', '2026-03-01']);
    check('...as a snapshot, so the rule cannot block', $r && $r['snapshotFields'] === ['visit_date_bl'] && empty($r['deferred']));
    check('...a "from" date read from another form is not a stale condition', $r && empty($r['snapshotGate']));
    $G = $DICT;
    $G['gate_nf'] = f('visit_form', '@UVWINDOW={"notFuture":true,"blockSave":"hard","when":"[visit_date_bl]<>\'\'"}', 'date_ymd');
    $rg = ruleFor(page(mod($G, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form'), 'gate_nf');
    check('a "when" read from another form: marked as a stale condition, so notFuture does not block either',
        $rg && !empty($rg['snapshotGate']) && $rg['snapshotFields'] === ['visit_date_bl']);
    $r = ruleFor($p, 'v_end');
    check('"from" on this page: a live ref', $r && $r['windowFromOp'] === ['ref', 'v_start', null] && empty($r['snapshotFields']));
    check('the server clock reaches the page', isset($p['cfg']['clock']) && $p['cfg']['clock'] === $CLOCK);
    check('the page lists its day- and month-first date fields', ($p['cfg']['dateFormats'] ?? null)
        === ['visit_date_2' => ['date', 'dmy'], 'v_end' => ['date', 'mdy']]);
    // The real clock carries what the page needs across a daylight-saving change.
    $UV = '\INSPIRE\UniversalValidator\UniversalValidator';
    $st = $UV::clockStamp(new \DateTimeImmutable('2026-10-20 12:00:00.250', new \DateTimeZone('Europe/London')));
    check('clockStamp: wall-clock day and time', $st['today'] === '2026-10-20' && $st['now'] === '2026-10-20 12:00:00');
    check('clockStamp: the moment in UTC milliseconds', $st['utc'] === gmmktime(11, 0, 0, 10, 20, 2026) * 1000 + 250);
    check('clockStamp: the next change and the offsets around it', $st['offset'] === 3600
        && $st['next'] === gmmktime(1, 0, 0, 10, 25, 2026) * 1000 && $st['offsetAfter'] === 0);
    $st = $UV::clockStamp(new \DateTimeImmutable('2026-10-20 12:00:00', new \DateTimeZone('UTC')));
    check('clockStamp: a zone with no change has none', $st['offset'] === 0 && $st['next'] === null && $st['offsetAfter'] === null);
    $st = $UV::clockStamp(new \DateTimeImmutable('2026-10-20 12:00:00+02:00'));
    check('clockStamp: a fixed offset has no change', $st['offset'] === 7200 && $st['next'] === null);
    $mz = mod($DICT, $DATA, $FULL, 'nurse');
    $rp = new \ReflectionProperty($UV, 'clockOverride'); $rp->setAccessible(true); $rp->setValue($mz, null);
    $mz->projectSettings['window-timezone'] = 'Europe/London';
    $pz = page($mz, 'form', '2', 'visit_form');
    check('the page clock carries its UTC moment and zone offsets', isset($pz['cfg']['clock'])
        && is_int($pz['cfg']['clock']['utc']) && is_int($pz['cfg']['clock']['offset'])
        && array_key_exists('next', $pz['cfg']['clock']) && array_key_exists('offsetAfter', $pz['cfg']['clock']));

    $p = page(mod($DICT, $DATA, $PART, 'nurse'), 'form', '2', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('no rights to the "from" form: withheld, nothing shipped', $r && ($r['windowFromOp'] ?? null) === ['withheld']);
    check('no rights: the rule stays live for notFuture', $r && empty($r['deferred']));
    check('no rights: the saved date is nowhere in the page', strpos($p['html'], '2026-03-01') === false);
    check('no rights: no reason that would name the form', empty($r['deferredWhy']));

    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'survey', '2', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('survey: withheld, nothing shipped', $r && ($r['windowFromOp'] ?? null) === ['withheld'] && empty($r['deferred']));
    check('survey: the saved date is nowhere in the page', strpos($p['html'], '2026-03-01') === false);
    check('survey: a "from" on the same page stays live', ruleFor($p, 'v_end')['windowFromOp'] === ['ref', 'v_start', null]);

    $p = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '', 'visit_form');
    $r = ruleFor($p, 'visit_date_2');
    check('new record: the "from" date is blank, not unknown', $r && $r['windowFromOp'] === ['lit', ''] && empty($r['deferred']));

    $NOWIN = ['record_id' => f('baseline_form'), 'a' => f('visit_form', '@UVASSERT="[a]<>\'x\'"')];
    $p = page(mod($NOWIN, [], $FULL, 'nurse'), 'form', '2', 'visit_form');
    check('no window rule: no clock on the page', is_array($p['cfg']) && !array_key_exists('clock', $p['cfg']));

    // ---- 3) the post-save audit ---------------------------------------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = [];
    foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: early visit logged', isset($by['visit_date_2']) && $by['visit_date_2']['type'] === 'window'
        && $by['visit_date_2']['reason'] === 'window-early' && $by['visit_date_2']['value'] === '2026-03-15');
    check('audit: tomorrow (server clock) is future', isset($by['collected']) && $by['collected']['reason'] === 'future');
    check('audit: a date inside its window is not logged', !isset($by['v_end']));
    check('audit: a misconfigured window logs nothing as data', !isset($by['bad_hours']) && !isset($by['bad_family']));

    $late = $DATA; $late['2'][351]['visit_date_2'] = '2026-04-06';
    $m = mod($DICT, $late, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: late visit logged', isset($by['visit_date_2']) && $by['visit_date_2']['reason'] === 'window-late');

    $blank = $DATA; $blank['2'][351]['visit_date_bl'] = '';
    $m = mod($DICT, $blank, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
    check('audit: a blank "from" date checks nothing', !isset($by['visit_date_2']));

    $junk = $DATA; $junk['2'][351]['v_end'] = '2026-02-30';
    $m = mod($DICT, $junk, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $u = findings($m, 'uvalidate-unconfigurable');
    check('audit: an impossible saved date is reported, not passed',
        (bool) array_filter($u, function ($e) { return $e['fields'] === 'v_end' && strpos($e['why'], 'not a date this rule can read') !== false; }));
    check('audit: ...and never logged as a violation', !array_filter(findings($m), function ($e) { return $e['field'] === 'v_end'; }));

    // An unreadable "from" date stops the window part only: "notFuture" does not
    // need it, so a future date is still a finding.
    $NF = $DICT;
    $NF['nf_end'] = f('visit_form', '@UVWINDOW={"from":"[v_start]","window":[0,7],"notFuture":true}', 'date_ymd');
    $nfData = $DATA; $nfData['2'][351]['v_start'] = '2026-02-30'; $nfData['2'][351]['nf_end'] = '2026-10-10';
    $m = mod($NF, $nfData, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    $nf = array_values(array_filter(findings($m), function ($e) { return $e['field'] === 'nf_end'; }));
    check('audit: an unreadable "from" date still lets notFuture flag a future date', count($nf) === 1 && $nf[0]['reason'] === 'future');
    $nfData['2'][351]['nf_end'] = '2026-01-05';
    $m = mod($NF, $nfData, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    check('audit: ...a past date is reported as not checked, not passed',
        !array_filter(findings($m), function ($e) { return $e['field'] === 'nf_end'; })
        && (bool) array_filter(findings($m, 'uvalidate-unconfigurable'), function ($e) {
            return $e['fields'] === 'nf_end' && strpos($e['why'], 'the window was not checked (the date is not in the future)') !== false; }));
    $nfData['2'][351]['nf_end'] = '2026-02-31';
    $m = mod($NF, $nfData, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    check('audit: ...an unreadable value too is skipped, without claiming it is not in the future',
        (bool) array_filter(findings($m, 'uvalidate-unconfigurable'), function ($e) {
            return $e['fields'] === 'nf_end' && strpos($e['why'], 'field skipped') !== false; }));

    // A date and time gets the page's 120-second margin after saving too.
    $DT = $DICT;
    $DT['dt_nf'] = f('visit_form', '@UVWINDOW={"notFuture":true}', 'datetime_ymd');
    $dtData = $DATA; $dtData['2'][351]['dt_nf'] = '2026-10-09 14:32';
    $m = mod($DT, $dtData, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    check('audit: a date and time within 120 s of now is not future',
        !array_filter(findings($m), function ($e) { return $e['field'] === 'dt_nf'; }));
    $dtData['2'][351]['dt_nf'] = '2026-10-09 14:33';
    $m = mod($DT, $dtData, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
    check('audit: ...past it, it is', (bool) array_filter(findings($m), function ($e) { return $e['field'] === 'dt_nf' && $e['reason'] === 'future'; }));

    // ---- 4) reverse dependency: saving only the "from" form re-checks the window ---
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $m->redcap_save_record(149, '2', 'baseline_form', 351, null, null, null, 1);
    $dep = array_values(array_filter(findings($m), function ($e) { return $e['field'] === 'visit_date_2'; }));
    check('reverse dependency: the window on visit_form is re-checked', count($dep) === 1 && $dep[0]['reason'] === 'window-early');
    check('reverse dependency: reported where the field lives', $dep && $dep[0]['instrument'] === 'visit_form');
    check('reverse dependency: an unrelated window is not re-audited',
        !array_filter(findings($m), function ($e) { return $e['field'] === 'collected'; }));

    // ---- 5) the scan: same verdicts, and one "today" per run --------------------
    $m = mod($DICT, $DATA, $FULL, 'nurse');
    $res = $m->scanProject(149);
    $by = []; foreach ($res['violations'] as $v) $by[$v['field']] = $v['reason'];
    check('scan: early visit and future date found', ($by['visit_date_2'] ?? null) === 'window-early' && ($by['collected'] ?? null) === 'future');
    check('scan: misconfigured windows are reported as rule problems, not data',
        !isset($by['bad_hours']) && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('bad_hours', $u['fields'], true); }));

    $plan = new \ReflectionMethod($m, 'scanPlan'); $plan->setAccessible(true);
    $record = new \ReflectionMethod($m, 'scanRecord'); $record->setAccessible(true);
    // A durable scan may start only for a reader entitled to every form it
    // reads; the "from" field's form is one of them.
    $own = $plan->invoke($m, 149, [])['ownership'];
    check('scan entitlement: the "from" field is read and owned by its form',
        ($own['visit_date_bl'] ?? null) === 'baseline_form' && ($own['visit_date_2'] ?? null) === 'visit_form');
    // Every scan request judges "today" by the clock when it runs. A durable run
    // used to pin the day it started, and then a record re-scanned on a later
    // day with that day's date was reported as "Date in the future".
    $p = $plan->invoke($m, 149, []);
    check('scan plan: no pinned clock', !array_key_exists('clock', $p));
    $found = [];
    $sink = new \INSPIRE\UniversalValidator\CallbackFindingSink(function (array $v) use (&$found) { $found[$v['field']] = $v['reason']; });
    $seen = []; $unconf = [];
    $record->invokeArgs($m, [$p, 149, '2', $DATA['2'], $sink, &$seen, &$unconf]);
    check('scan record: judged by the current clock', ($found['collected'] ?? null) === 'future');
    $later = mod($DICT, $DATA, $FULL, 'nurse');
    $rp = new \ReflectionProperty($later, 'clockOverride'); $rp->setAccessible(true);
    $rp->setValue($later, ['today' => '2026-10-10', 'now' => '2026-10-10 09:00:00']);
    $found = []; $seen = []; $unconf = [];
    $record->invokeArgs($later, [$plan->invoke($later, 149, []), 149, '2', $DATA['2'], $sink, &$seen, &$unconf]);
    check('scan record on the next day: that date is no longer future', !isset($found['collected']));

    // The project's time zone, when set, decides today and now.
    $clockOf = function ($zone) use ($DICT, $DATA, $FULL) {
        $z = mod($DICT, $DATA, $FULL, 'nurse');
        $rp = new \ReflectionProperty($z, 'clockOverride'); $rp->setAccessible(true);
        $rp->setValue($z, null);
        if ($zone !== null) $z->projectSettings['window-timezone'] = $zone;
        $sc = new \ReflectionMethod($z, 'serverClock'); $sc->setAccessible(true);
        return $sc->invoke($z, 149);
    };
    $kiri = (new \DateTimeImmutable('now', new \DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d');
    $here = (new \DateTimeImmutable('now'))->format('Y-m-d');
    check('window-timezone: today is the date in that zone', $clockOf('Pacific/Kiritimati')['today'] === $kiri);
    check('window-timezone blank: the server zone', $clockOf(null)['today'] === $here);
    check('window-timezone unknown: the server zone, never an error', $clockOf('Mars/Olympus')['today'] === $here);
    check('window-timezone: an offset is not a zone name', $clockOf('+14:00')['today'] === $here);
    $vs = mod($DICT, $DATA, $FULL, 'nurse');
    $msg = $vs->validateSettings(['window-timezone' => 'Mars/Olympus']);
    check('window-timezone: an unknown name is refused when saved', is_string($msg) && strpos($msg, 'Mars/Olympus') !== false);
    check('window-timezone: a zone name saves', $vs->validateSettings(['window-timezone' => 'Africa/Douala']) === null);
    check('window-timezone: blank saves', $vs->validateSettings(['window-timezone' => '']) === null);
    // The report: the issue label and the staff detail line come from the registry.
    $dims = \INSPIRE\UniversalValidator\ScanDimensions::build(149, $DICT, [
        ['type' => 'window', 'fields' => ['visit_date_2'], 'windowFrom' => '[visit_date_bl]', 'windowLo' => 21, 'windowHi' => 35, 'windowUnit' => 'days'],
        ['type' => 'window', 'fields' => ['test_date'], 'windowFrom' => '[dob]', 'windowLo' => 0, 'windowUnit' => 'days'],
    ]);
    check('report detail: the window that was missed',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-early'], $dims->rule(1))
        === 'The window opens 21 days from [visit_date_bl].');
    check('report detail: the closing bound',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-late'], $dims->rule(1))
        === 'The window closes 35 days from [visit_date_bl].');
    check('report detail: an open end has no sentence, not half of one',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'window-late'], $dims->rule(2)) === '');
    check('report detail: future has none',
        \INSPIRE\UniversalValidator\MessageCatalog::detail(['type' => 'window', 'reason' => 'future'], $dims->rule(1)) === '');
    check('report wording: catalog, not fallback',
        \INSPIRE\UniversalValidator\MessageCatalog::explain(['type' => 'window', 'reason' => 'future', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The date is in the future.', 'source' => 'catalog']);


    // ---- 7) today, now, written dates, periods and notPast ------------------------
    $P9 = $DICT + [
        'w_today'     => f('visit_form', '@UVWINDOW={"from":"today","window":[-30,0]}', 'date_ymd'),
        'w_past'      => f('visit_form', '@UVWINDOW={"notPast":true}', 'date_ymd'),
        'w_month'     => f('visit_form', '@UVWINDOW={"period":"month","offset":-1}', 'date_ymd'),
        'w_week'      => f('visit_form', '@UVWINDOW={"period":"week"}', 'date_ymd'),
        'w_week_mon'  => f('visit_form', '@UVWINDOW={"period":"week","weekStart":"monday"}', 'date_ymd'),
        'w_lit'       => f('visit_form', '@UVWINDOW={"from":"2026-01-01","window":[0,null]}', 'date_ymd'),
        'w_now'       => f('visit_form', '@UVWINDOW={"from":"now","window":[-2,0],"unit":"hours"}', 'datetime_ymd'),
        'w_of_field'  => f('visit_form', '@UVWINDOW={"from":"[v_start]","period":"month"}', 'date_ymd'),
        'w_lit_dt_p'  => f('visit_form', '@UVWINDOW={"from":"2026-01-01 08:00","period":"month"}', 'date_ymd'),
        'w_today_dtp' => f('visit_form', '@UVWINDOW={"period":"week"}', 'datetime_ymd'),
        'bad_now_d'   => f('visit_form', '@UVWINDOW={"from":"now","window":[-1,0]}', 'date_ymd'),
        'bad_today_dt'=> f('visit_form', '@UVWINDOW={"from":"today","window":[-1,0]}', 'datetime_ymd'),
        'bad_lit_fam' => f('visit_form', '@UVWINDOW={"from":"2026-01-01 08:00","window":[0,1]}', 'date_ymd'),
    ];
    $D9 = $DATA;
    $D9['2'][351] += ['w_today' => '2026-09-01', 'w_past' => '2026-10-01', 'w_month' => '2026-09-15', 'w_week' => '',
        'w_week_mon' => '', 'w_lit' => '2025-12-31', 'w_now' => '2026-10-09 12:27', 'w_of_field' => '2026-01-31',
        'w_lit_dt_p' => '', 'w_today_dtp' => ''];
    $p = page(mod($P9, $D9, $FULL, 'nurse'), 'form', '2', 'visit_form');
    $e9 = function ($field) use ($p) { $r = ruleFor($p, $field); return $r && isset($r['configError']) ? $r['configError'] : ''; };
    check('"from": "now" on a date field: refused', strpos($e9('bad_now_d'), '"from": "now" is a date and time') !== false);
    check('"from": "today" on a datetime field: refused', strpos($e9('bad_today_dt'), '"from": "today" is a date') !== false);
    check('a written date of another kind: refused', strpos($e9('bad_lit_fam'), 'is a date with a time and this field holds dates without a time') !== false);
    foreach (['w_today', 'w_past', 'w_month', 'w_week', 'w_lit', 'w_now', 'w_of_field', 'w_lit_dt_p', 'w_today_dtp'] as $okf) {
        check($okf . ': configures', ruleFor($p, $okf) !== null && $e9($okf) === '');
    }
    $r = ruleFor($p, 'w_today');
    check('"from": "today" travels as windowAnchor, never as a field', ($r['windowAnchor'] ?? null) === 'today' && !isset($r['windowFrom']));
    $r = ruleFor($p, 'w_month');
    check('a period with no "from" is the period holding today', ($r['windowAnchor'] ?? null) === 'today'
        && ($r['windowPeriod'] ?? null) === 'month' && ($r['windowOffLo'] ?? null) === -1 && ($r['windowOffHi'] ?? null) === -1);
    check('a week starts on Monday by default', (ruleFor($p, 'w_week')['windowWeekStart'] ?? null) === 'monday');
    $m9 = mod($P9, $D9, $FULL, 'nurse');
    $m9->projectSettings['window-week-start'] = 'sunday';
    $p9 = page($m9, 'form', '2', 'visit_form');
    check('the project setting moves the week start', (ruleFor($p9, 'w_week')['windowWeekStart'] ?? null) === 'sunday');
    check('...a rule\'s own weekStart wins', (ruleFor($p9, 'w_week_mon')['windowWeekStart'] ?? null) === 'monday');
    // The saved values of the fields a today-relative part judges.
    $ws = isset($p['cfg']['windowSaved']) ? $p['cfg']['windowSaved'] : null;
    check('windowSaved: the saved value of each today-relative field', is_array($ws) && ($ws['w_today'] ?? null) === '2026-09-01'
        && ($ws['w_past'] ?? null) === '2026-10-01' && ($ws['w_month'] ?? null) === '2026-09-15' && array_key_exists('w_now', $ws));
    check('windowSaved: a window counted from a field or a written date is not listed',
        !array_key_exists('w_lit', $ws ?? []) && !array_key_exists('w_of_field', $ws ?? []) && !array_key_exists('visit_date_2', $ws ?? []));
    check('windowSaved: notFuture alone is not listed', !array_key_exists('collected', $ws ?? []));
    $pn = page(mod($P9, $D9, $FULL, 'nurse'), 'form', null, 'visit_form');
    check('windowSaved: a record not saved yet sends an empty object', strpos($pn['raw'] ?? $pn['html'], '"windowSaved":{}') !== false);
    $pw = page(mod($DICT, $DATA, $FULL, 'nurse'), 'form', '2', 'visit_form');
    check('windowSaved: no today-relative rule, no windowSaved', is_array($pw['cfg']) && !array_key_exists('windowSaved', $pw['cfg']));

    // Time zones: notPast reads as in the past where the filling zone runs behind.
    $Z9 = $P9;
    $Z9['np_utc']   = f('visit_form', '@NOW-UTC @UVWINDOW={"notPast":true}', 'datetime_ymd');
    $Z9['td_utc']   = f('visit_form', '@TODAY-UTC @UVWINDOW={"from":"today","window":[-7,0]}', 'date_ymd');
    $Z9['np_win']   = f('visit_form', '@TODAY-UTC @UVWINDOW={"from":"[v_start]","window":[0,7],"notPast":true}', 'date_ymd');
    $z9 = function ($tz, $field) use ($Z9, $D9, $FULL) {
        $mz = mod($Z9, $D9, $FULL, 'nurse');
        if ($tz !== null) $mz->projectSettings['window-timezone'] = $tz;
        return ruleFor(page($mz, 'form', '2', 'visit_form'), $field);
    };
    $r = $z9('Africa/Lagos', 'np_utc');
    check('@NOW-UTC with notPast and a clock ahead of UTC: refused', strpos($r['configError'] ?? '', '"notPast" cannot be judged on a field filled by @NOW-UTC') !== false
        && strpos($r['configError'] ?? '', 'runs behind') !== false);
    check('@NOW-UTC with notPast and a clock behind UTC: allowed', empty($z9('America/New_York', 'np_utc')['configError']));
    check('@TODAY-UTC with "from": "today" in another zone: refused', strpos($z9('America/New_York', 'td_utc')['configError'] ?? '', '"from": "today" cannot be judged') !== false);
    $r = $z9('Africa/Lagos', 'np_win');
    check('@TODAY-UTC with a window from a field and notPast: the window stays, notPast is dropped with a note',
        $r && empty($r['configError']) && empty($r['windowNotPast']) && ($r['windowLo'] ?? null) === 0
        && strpos(implode(' ', $r['windowNotFutureOff'] ?? []), '"notPast" cannot be judged') !== false);

    // The audit and the scan judge each value against the day it was saved.
    $stamps = function ($m, array $rows) {
        $db = new WinLog();
        foreach ($rows as $i => $row) $db->rows[] = array_merge([$i + 1, 149, '2', 351], $row);
        $vs = \INSPIRE\UniversalValidator\ValueStamps::forProject($db, 149, new \DateTimeZone('UTC'), new \DateTimeZone('UTC'));
        $rp = new \ReflectionProperty($m, 'valueStampsOverride'); $rp->setAccessible(true); $rp->setValue($m, $vs);
        return $m;
    };
    $audit = function ($m) {
        $m->redcap_save_record(149, '2', 'visit_form', 351, null, null, null, 1);
        $by = []; foreach (findings($m) as $e) $by[$e['field']] = $e;
        $un = []; foreach (findings($m, 'uvalidate-unconfigurable') as $e) $un[$e['fields']][] = $e['why'];
        return [$by, $un];
    };
    $row = function ($ts, $dv, $ev = 'UPDATE') { return [$ts, $dv, 'redcap_data', $ev]; };
    // w_today 2026-09-01: inside [-30,0] of a save on 2026-09-02, 38 days before today.
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20260902100000', "w_today = '2026-09-01'")]));
    check('audit: a value saved on 2026-09-02 is judged against that day, not today', !isset($by['w_today']) && !isset($un['w_today']));
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20261009090000', "w_today = '2026-09-01'")]));
    check('audit: saved today, 38 days back is early', ($by['w_today']['reason'] ?? null) === 'window-early'
        && ($by['w_today']['as_of'] ?? null) === '2026-10-09');
    // The log shows another value: this save wrote this one, so it was saved now.
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20260902100000', "w_today = '2026-08-30'")]));
    check('audit: a value the log does not show yet was saved now', ($by['w_today']['reason'] ?? null) === 'window-early');
    // The log holds the record but not the field: a first entry this save wrote.
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20260902100000', "wt = '1'")]));
    check('audit: a field the log never shows for the record was saved now', ($by['w_today']['reason'] ?? null) === 'window-early');
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), []));
    check('audit: a record with no log yet was saved now', ($by['w_today']['reason'] ?? null) === 'window-early');
    // notPast and a period, against their save day.
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20260930080000', "w_past = '2026-10-01',\nw_month = '2026-09-15'")]));
    check('audit: notPast judged on the day it was saved', !isset($by['w_past']));
    check('audit: last month judged on the day it was saved (September 30: August)', ($by['w_month']['reason'] ?? null) === 'window-late');
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20261002080000', "w_past = '2026-10-01',\nw_month = '2026-09-15'")]));
    check('audit: saved the day after, notPast finds it', ($by['w_past']['reason'] ?? null) === 'past' && ($by['w_past']['as_of'] ?? null) === '2026-10-02');
    check('audit: last month on October 2 is September', !isset($by['w_month']));
    // notFuture moves to the save day: a date that was in the future when saved.
    $DN = $D9; $DN['2'][351]['collected'] = '2026-10-05';
    list($by, $un) = $audit($stamps(mod($P9, $DN, $FULL, 'nurse'), [$row('20261001080000', "collected = '2026-10-05'")]));
    check('audit: notFuture on the save day finds a date that was in the future then', ($by['collected']['reason'] ?? null) === 'future');
    list($by, $un) = $audit($stamps(mod($P9, $DN, $FULL, 'nurse'), [$row('20261001080000', "wt = '1'")]));
    check('audit: notFuture with no save day falls back to today, never a false finding', !isset($by['collected'])
        && !isset($un['collected']));
    // "now": a span of 120 s either side of the save moment.
    list($by, $un) = $audit($stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20261009143000', "w_now = '2026-10-09 12:27'")]));
    check('audit: now [-2,0] hours, 2 h 3 min before the save is early', ($by['w_now']['reason'] ?? null) === 'window-early'
        && ($by['w_now']['as_of'] ?? null) === '2026-10-09 14:30');
    $DW = $D9; $DW['2'][351]['w_now'] = '2026-10-09 12:29';
    list($by, $un) = $audit($stamps(mod($P9, $DW, $FULL, 'nurse'), [$row('20261009143000', "w_now = '2026-10-09 12:29'")]));
    check('audit: ...2 h 1 min before is inside the margin', !isset($by['w_now']));
    // The scan: no save in progress, so a value the log does not show is not checked.
    $ms = $stamps(mod($P9, $D9, $FULL, 'nurse'), [$row('20260902100000', "w_today = '2026-08-30'"), $row('20261009090000', "w_past = '2026-10-01'")]);
    $res = $ms->scanProject(149);
    $sv = []; foreach ($res['violations'] as $v) $sv[$v['field']] = $v;
    check('scan: a value the log shows another value for is not checked', !isset($sv['w_today'])
        && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('w_today', $u['fields'], true)
            && strpos($u['why'], 'the project log shows another value for it') !== false; }));
    check('scan: a value whose save day is not known is not checked, and the report says why', !isset($sv['w_month'])
        && (bool) array_filter($res['unconfigurable'], function ($u) { return in_array('w_month', $u['fields'], true)
            && strpos($u['why'], 'the day this value was saved is not known (the project log does not show when it was saved)') !== false; }));
    check('scan: the day judged against travels with the finding', ($sv['w_past']['reason'] ?? null) === 'past' && ($sv['w_past']['asOf'] ?? null) === '2026-10-09');
    // The report names that day.
    $dims = \INSPIRE\UniversalValidator\ScanDimensions::build(149, $P9, [
        ['type' => 'window', 'fields' => ['w_today'], 'windowAnchor' => 'today', 'windowLo' => -30, 'windowHi' => 0, 'windowUnit' => 'days'],
        ['type' => 'window', 'fields' => ['w_month'], 'windowAnchor' => 'today', 'windowPeriod' => 'month', 'windowOffLo' => -1, 'windowOffHi' => -1],
        ['type' => 'window', 'fields' => ['w_of_field'], 'windowFrom' => '[v_start]', 'windowPeriod' => 'month'],
    ]);
    $MC = \INSPIRE\UniversalValidator\MessageCatalog::class;
    check('report detail: a window from today, with the day it was judged against',
        $MC::detail(['type' => 'window', 'reason' => 'window-early', 'asOf' => '2026-10-09'], $dims->rule(1))
        === 'The window opens -30 days from today. Judged against 2026-10-09, when this value was saved.');
    check('report detail: a period with an offset',
        $MC::detail(['type' => 'window', 'reason' => 'window-late'], $dims->rule(2)) === 'The rule allows the months -1 to -1 from the one holding today.');
    check('report detail: the period of a field', $MC::detail(['type' => 'window', 'reason' => 'window-early'], $dims->rule(3))
        === 'The rule allows the month holding [v_start].');
    check('report detail: past names the day only', $MC::detail(['type' => 'window', 'reason' => 'past', 'asOf' => '2026-10-02'], $dims->rule(1))
        === 'Judged against 2026-10-02, when this value was saved.');
    check('report wording: past', $MC::explain(['type' => 'window', 'reason' => 'past', 'rule' => 1], $dims->rule(1))
        === ['text' => 'The date is in the past.', 'source' => 'catalog']);
    check('report issue label: past', \INSPIRE\UniversalValidator\ModeRegistry::issueLabel('window', 'past') === 'Date in the past');

    fwrite(STDOUT, "window_module_php: $n checks, $fail failure(s)\n");
    exit($fail ? 1 : 0);
}
