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

    /* ---- temporal_sem helpers (pattern from tests/temporal_integration_php.php) ---- */
    const SEM_PID = 700;
    function sem_module(array $dict, array $data, array $repeats, array $mappings, array $eventInfo, array $eventsForms) {
        $GLOBALS['__TEST_USER'] = 'nurse';
        $m = new \INSPIRE\UniversalValidator\UniversalValidator();
        $m->subSettings = []; $m->projectSettings = ['log-values' => '', 'enable-event-instance-refs' => true];
        $m->projectIdReturn = SEM_PID;
        $forms = []; foreach ($dict as $meta) $forms[$meta['form_name']] = '1';
        \REDCap::$dictionary = $dict; \REDCap::$rights = ['nurse' => ['forms' => $forms]]; \REDCap::$data = $data;
        \REDCap::$getDataCalls = 0; \REDCap::$readParams = []; \REDCap::$getDataMode = 'ok';
        \REDCap::$repeats = $repeats; \REDCap::$eventMappings = $mappings;
        $GLOBALS['Proj'] = (object)['project_id'=>SEM_PID,'longitudinal'=>true,'eventInfo'=>$eventInfo,'eventsForms'=>$eventsForms];
        return $m;
    }
    function sem_render($m, $form, $rec = '1', $evt = 1, $inst = 1) {
        ob_start();
        $m->redcap_data_entry_form_top(SEM_PID, $rec, $form, $evt, null, $inst);
        $html = ob_get_clean();
        preg_match('#application/json" id="inspire-validator-config">(.*?)</script>#s', $html, $mm);
        return json_decode(isset($mm[1]) ? $mm[1] : 'null', true);
    }
    function sem_rule_of($cfg, $f) {
        foreach (($cfg['rules'] ?? []) as $r) if (in_array($f, $r['fields'] ?? [], true)) return $r;
        return null;
    }
    function sem_logs($m, $type) { return array_values(array_filter($m->logCalls, function ($c) use ($type) { return $c[0] === $type; })); }
    /** field => [form, type?, validation?, annotation?, choices?] */
    function sem_dict(array $spec) {
        $d = [];
        foreach ($spec as $f => $s) $d[$f] = ['field_type'=>$s[1] ?? 'text','form_name'=>$s[0],'field_annotation'=>$s[3] ?? '',
            'text_validation_type_or_show_slider_number'=>$s[2] ?? '','select_choices_or_calculations'=>$s[4] ?? ''];
        return $d;
    }
}
