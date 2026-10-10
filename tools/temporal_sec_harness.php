<?php
/** End-to-end opt-in event/repeat regression tests with field-filtering reads. */
namespace ExternalModules {
    class AbstractExternalModule {
        public $logCalls = []; public $subSettings = []; public $projectSettings = [];
        public $systemSettings = []; public $projectIdReturn = null;
        public function getSubSettings($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            return $e ? $this->subSettings : [];
        }
        public function getProjectSetting($k, $pid = null) {
            $e = ($pid !== null && $pid !== '') ? $pid : $this->projectIdReturn;
            if (!$e) return null;
            return isset($this->projectSettings[$k]) ? $this->projectSettings[$k] : null;
        }
        public function getSystemSetting($k) { return $k==='log-hmac-key'?str_repeat('a',64):null; }
        public function setSystemSetting($k, $v) {}
        public function getProjectId() { return $this->projectIdReturn; }
        public function getUrl($p) { return '/x/' . $p; }
        public function log($m, $p = []) { $this->logCalls[] = [$m, $p]; return count($this->logCalls); }
        public function initializeJavascriptModuleObject() { return '<script></script>'; }
        public function getJavascriptModuleObjectName() { return 'EM.T.UV'; }
        public function getUser() {
            $u = isset($GLOBALS['__TEST_USER']) ? $GLOBALS['__TEST_USER'] : null;
            return $u === null ? null : new TestUser($u);
        }
    }
    class TestUser {
        private $n; public function __construct($n) { $this->n = $n; }
        public function getUsername() { return $this->n; }
        public function hasDesignRights() { return true; }
    }
}

namespace {
    class REDCap {
        public static $data = []; public static $dictionary = []; public static $rights = [];

        /**
         * PER-001 instrumentation: every \REDCap::getData() the module makes.
         * A save on an instrument with neither a rule nor a dependant must read
         * NOTHING, so this counter staying at 0 is the assertion.
         */
        public static $getDataCalls = 0; public static $readParams = [];

        /**
         * H-04. 'ok' returns the fixture; the other three are the distinct read
         * failures that used to be indistinguishable from an empty record.
         *   'throw'    the API raised
         *   'nonarray' the API answered something that is not an array
         *   'norecord' the read succeeded but this record is not in the result
         */
        public static $getDataMode = 'ok';

        /**
         * M-01. null models a build/project where the instrument-event mapping
         * cannot be established — the module must then NOT claim 'missing'.
         */
        public static $eventMappings = null;
        public static $repeats = [];
        public static function getRepeatingFormsEvents($pid) { return self::$repeats; }

        public static function getData($p) {
            self::$getDataCalls++; self::$readParams[]=$p;
            if (self::$getDataMode === 'throw') throw new \RuntimeException('simulated getData failure');
            if (self::$getDataMode === 'nonarray') return false;
            if (self::$getDataMode === 'norecord') return ['999' => [1 => ['record_id' => '999']]];
            $data=self::$data;
            if (isset($p['records'])) $data=array_intersect_key($data,array_fill_keys($p['records'],true));
            if (!empty($p['events'])) foreach($data as &$record){
                $keepEvents=array_fill_keys($p['events'],true);
                foreach($record as $event=>$row)if($event!=='repeat_instances'&&!isset($keepEvents[$event]))unset($record[$event]);
                if(isset($record['repeat_instances']))$record['repeat_instances']=array_intersect_key($record['repeat_instances'],$keepEvents);
            }unset($record);
            if (!empty($p['fields'])) {
                $keep=array_fill_keys($p['fields'],true);
                foreach($data as &$record)foreach($record as $event=>&$row){
                    if($event==='repeat_instances'){foreach($row as &$forms)foreach($forms as &$instances)foreach($instances as &$values)$values=array_intersect_key($values,$keep);unset($forms,$instances,$values);}
                    else $row=array_intersect_key($row,$keep);
                }unset($record,$row);
            }
            return $data;
        }
        public static function getDataDictionary($pid, $f = 'array') {
            if (!$pid) throw new \RuntimeException('needs pid');
            return self::$dictionary;
        }
        public static function getUserRights($pid = null, $u = null) { return self::$rights; }
        public static function getGroupNames($a = false, $b = null) { return ''; }
        public static function getRecordIdField() { return 'record_id'; }
        public static function getInstrumentEventMappings($pid = null) { return self::$eventMappings; }
    }
    require_once __DIR__ . '/../UniversalValidator.php';

    $n = 0; $fail = 0;
    function check($label, $cond) {
        global $n, $fail; $n++;
        if (!$cond) { $fail++; fwrite(STDERR, "FAIL: $label\n"); }
    }

    const PID = 700;
    /** Every form readable, so nothing below is ever refused for RIGHTS reasons
     *  (crossform_php.php owns that axis) — a refusal here is a RESOLUTION one. */
    $RIGHTS = ['nurse' => ['forms' => ['fa' => '1', 'fb' => '1', 'fc' => '1']]];

    /** A text-field dictionary: field => form, plus optional annotations. */
    function dict(array $formOf, array $ann = []) {
        $d = [];
        foreach ($formOf as $f => $form) {
            $d[$f] = ['field_type' => 'text', 'form_name' => $form,
                      'field_annotation' => isset($ann[$f]) ? $ann[$f] : ''];
        }
        return $d;
    }
    /** The cross-form constraint under test, hard-blocking so a false verdict would bite. */
    function tag($expr) {
        return '@UVASSERT={"assert":"' . $expr . '","message":"cross-form","blockSave":"hard"}';
    }
    /** The three-field layout most sections use: host on fa, referenced on fb. */
    function crossDict($hostForm = 'fa', $refForm = 'fb') {
        return dict(['record_id' => 'fa', 'a_val' => $hostForm, 'b_open' => $refForm],
                    ['a_val' => tag('[a_val]=[b_open]')]);
    }

    function mkMod($dict, $rights, $data, $subs = [], $user = 'nurse') {
        $GLOBALS['__TEST_USER'] = $user;
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = $subs; $m->projectSettings = ['log-values' => ''];
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $dict; \REDCap::$rights = $rights; \REDCap::$data = $data;
        \REDCap::$getDataCalls = 0; \REDCap::$readParams=[]; \REDCap::$getDataMode = 'ok'; \REDCap::$eventMappings = null;
        return $m;
    }
    function render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'raw' => isset($mm[1]) ? $mm[1] : '',
                'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
    function ruleOf($p, $f) {
        foreach ((isset($p['cfg']['rules']) ? $p['cfg']['rules'] : []) as $r) {
            if (in_array($f, isset($r['fields']) ? $r['fields'] : [], true)) return $r;
        }
        return null;
    }
    function assertAst($p, $f) {
        $r = ruleOf($p, $f);
        return json_encode(($r && isset($r['assertAst'])) ? $r['assertAst'] : null);
    }
    /** The 'deferredWhy' strings the page carries, flattened for matching. */
    function why($p, $f) {
        $r = ruleOf($p, $f);
        return ($r && isset($r['deferredWhy']) && is_array($r['deferredWhy'])) ? $r['deferredWhy'] : [];
    }
    function logsOf($m, $type) {
        return array_values(array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; }));
    }
    function invalid($m) { return logsOf($m, 'invalid-id-saved'); }
    function unconf($m)  { return logsOf($m, 'uvalidate-unconfigurable'); }
    /**
     * How many entries of $list carry $needle. $list is either a list of arrays
     * (log params, scan rows) read at $key, or a plain list of strings (the
     * deferredWhy notices), in which case $key is ignored.
     */
    function saying(array $list, $key, $needle) {
        return count(array_filter($list, function ($e) use ($key, $needle) {
            $s = is_array($e) ? (isset($e[$key]) ? (string) $e[$key] : '') : (string) $e;
            return $s !== '' && strpos($s, $needle) !== false;
        }));
    }


    function temporal($expression, $references=null, $type='UVASSERT') {
        $rule=$type==='UVUNIQUE'?['scope'=>'record']:['assert'=>$expression];
        if($references!==null)$rule['references']=$references;
        $dd=dict(['record_id'=>'fa','a_val'=>'fa','b_open'=>'fb','key_a'=>'fa','key_b'=>'fb','unrelated'=>'fc'],['a_val'=>'@'.$type.'='.json_encode($rule)]);
        $data=[1=>[1=>['record_id'=>'1'],2=>['record_id'=>'1'],'repeat_instances'=>[
            1=>['fa'=>[1=>['a_val'=>'10','key_a'=>'A','fa_complete'=>'2'],3=>['a_val'=>'12','key_a'=>'B','fa_complete'=>'2']],
                'fb'=>[2=>['b_open'=>'11','key_b'=>'A','fb_complete'=>'2'],4=>['b_open'=>'9','key_b'=>'B','fb_complete'=>'2']]],
            2=>['fa'=>[1=>['a_val'=>'8','key_a'=>'A','fa_complete'=>'2']]]]]];
        $m=mkMod($dd,$GLOBALS['RIGHTS'],$data);$m->projectSettings['enable-event-instance-refs']=true;
        REDCap::$repeats=[1=>['fa'=>'','fb'=>''],2=>['fa'=>'']];
        REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['event_id'=>1,'form'=>'fb'],['event_id'=>2,'form'=>'fa']];
        $GLOBALS['Proj']=(object)['project_id'=>PID,'longitudinal'=>true,
            'eventInfo'=>[1=>['unique_event_name'=>'baseline_arm_1','arm_num'=>1],2=>['unique_event_name'=>'followup_arm_1','arm_num'=>1]],
            'eventsForms'=>[1=>['fa','fb'],2=>['fa']]];
        return $m;
    }
    // ---- temporal_sec additions (harness copied from tests/temporal_integration_php.php) ----
    function survey_render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_survey_page_top(PID, $rec, $form, $evt, null, 'hash', 'resp', $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return ['html' => $html, 'raw' => isset($mm[1]) ? $mm[1] : '',
                'cfg' => json_decode(isset($mm[1]) ? $mm[1] : 'null', true)];
    }
}
