<?php
/** temporal_perf harness: REDCap/EM mocks copied from tests/temporal_integration_php.php. */
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
        public static $getDataCalls = 0; public static $readParams = [];
        public static $getDataMode = 'ok';
        public static $eventMappings = null;
        public static $repeats = [];
        public static function getRepeatingFormsEvents($pid) { return self::$repeats; }
        public static function getData($p) {
            self::$getDataCalls++; self::$readParams[]=$p;
            if (self::$getDataMode === 'throw') throw new \RuntimeException('simulated getData failure');
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

    const PID = 700;
    /**
     * $forms: field=>form; $ann: field=>annotation.
     * $events: id=>[name, arm, forms[], repeats(list of forms | 'WHOLE')].
     */
    function perf_mod(array $forms, array $ann, array $events, array $data, array $settings = []) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $d = [];
        foreach ($forms as $f => $form) $d[$f] = ['field_type'=>'text','form_name'=>$form,'field_annotation'=>$ann[$f] ?? ''];
        $allForms = array_values(array_unique(array_values($forms)));
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = []; $m->projectSettings = ['log-values'=>'', 'enable-event-instance-refs'=>true] + $settings;
        $m->projectIdReturn = PID;
        \REDCap::$dictionary = $d; \REDCap::$rights = ['nurse'=>['forms'=>array_fill_keys($allForms,'1')]];
        \REDCap::$data = $data; \REDCap::$getDataCalls = 0; \REDCap::$readParams = []; \REDCap::$getDataMode = 'ok';
        $rep = []; $map = []; $info = []; $ef = [];
        foreach ($events as $id => $e) {
            [$name, $arm, $efs, $reps] = $e;
            $info[$id] = ['unique_event_name'=>$name, 'arm_num'=>$arm];
            $ef[$id] = $efs;
            foreach ($efs as $f) $map[] = ['event_id'=>$id, 'form'=>$f];
            if ($reps === 'WHOLE') $rep[$id] = ['' => ''];
            else foreach ($reps as $f) $rep[$id][$f] = '';
        }
        \REDCap::$repeats = $rep; \REDCap::$eventMappings = $map;
        $GLOBALS['Proj'] = (object)['project_id'=>PID, 'longitudinal'=>true, 'eventInfo'=>$info, 'eventsForms'=>$ef];
        return $m;
    }
    function perf_render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(PID, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        // strpos, not a regex: a multi-megabyte payload exceeds PCRE's backtrack limit.
        $open = 'id="inspire-validator-config">'; $a = strpos($html, $open); $raw = '';
        if ($a !== false) { $a += strlen($open); $raw = substr($html, $a, strpos($html, '</script>', $a) - $a); }
        return ['html'=>$html, 'raw'=>$raw, 'cfg'=>json_decode($raw === '' ? 'null' : $raw, true)];
    }
    function perf_logs($m, $type) { return array_values(array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })); }
    /** [result, ms, peak MB above baseline] */
    function perf_measure(callable $f) {
        gc_collect_cycles(); if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
        $base = memory_get_usage(); $t = hrtime(true);
        $r = $f();
        $ms = (hrtime(true) - $t) / 1e6;
        return [$r, round($ms, 1), round((memory_get_peak_usage() - $base) / 1048576, 1)];
    }
    function perf_row($label, $n, $ms, $mb, $extra = '') { printf("%-46s n=%-6s %9.1f ms %7.1f MB  %s\n", $label, $n, $ms, $mb, $extra); }
    /** One record: event 1 (fa, fb repeating), event 2 (fa repeating). */
    function perf_std_events() {
        return [1=>['baseline_arm_1',1,['fa','fb'],['fa','fb']], 2=>['followup_arm_1',1,['fa'],['fa']]];
    }
    function perf_tag(array $rule, $tag = 'UVASSERT') { return '@'.$tag.'='.json_encode($rule); }
}
