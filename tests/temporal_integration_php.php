<?php
/** End-to-end opt-in event/repeat regression tests with field-filtering reads. */
namespace ExternalModules {
    class AbstractExternalModule {
        /** The legacy one-request scan (tests/support/scan_facade.php, QUA-3). */
        public function scanProject(...$a) { return \INSPIRE\UniversalValidator\scan_project($this, ...$a); }
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
    require_once __DIR__ . '/support/scan_facade.php';

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
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    $p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('qualified rule renders without configuration error', $r&&!isset($r['configError'])&&!isset($r['deferred']));
    check('current operand remains live',strpos(assertAst($p,'a_val'),'["ref","a_val",null]')!==false);
    check('source snapshot is exact instance 2',strpos(assertAst($p,'a_val'),'["lit","11"]')!==false);
    check('cross-event rule advisory',($r['blockSave']??null)==='off');
    REDCap::$getDataCalls=0;$m->redcap_save_record(PID,'1','fc',1,null);
    check('unrelated save reads nothing',REDCap::$getDataCalls===0);
    $m->redcap_save_record(PID,'1','fb',1,null);
    check('source save audits dependent hosts',count(invalid($m))===1);
    $res=$m->scanProject(PID);
    check('scan sees exactly same violation',count($res['violations'])===1);
    check('scan has no unresolved qualified rules',!$res['unconfigurable']);
    $golden=['browser'=>$r,'audit'=>invalid($m),'scan'=>$res['violations'],'reads'=>REDCap::$readParams];
    $ctx=$m->durableScanContext(PID,['generation'=>null]);
    check('durable plan reads source plus existence marker',isset($ctx['plan']['readSet']['b_open'],$ctx['plan']['readSet']['fb_complete']));
    check('durable fingerprint includes shape',$ctx['structure']['dialect']===1);
    $golden['identities']=$ctx['plan']['ruleIds'];
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');$m->projectSettings['enable-event-instance-refs']=false;
    check('disabled syntax is configuration error',!empty(ruleOf(render($m,'fa'),'a_val')['configError']));
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');REDCap::$rights['nurse']['forms']['fb']='0';
    $p=render($m,'fa');check('protected source defers live comparison',!empty(ruleOf($p,'a_val')['deferred']));
    check('protected snapshot removed',strpos($p['raw'],'["lit","11"]')===false);
    // Wargame S1: what the page gets for a rule over an instrument the viewer may
    // not read is the same whatever that instrument holds. An empty collection
    // used to ship live, and a match told "absent" (none), "unauthorized" (one)
    // and "ambiguous" (two or more) apart.
    $denied=function($expr,$refs,callable $data,$survey=false){
        $m=temporal($expr,$refs);REDCap::$rights['nurse']['forms']['fb']='0';$data();
        if(!$survey)return json_encode(ruleOf(render($m,'fa'),'a_val'));
        $fold=new ReflectionMethod($m,'foldTemporalRules');$fold->setAccessible(true);
        return json_encode($fold->invoke($m,[['type'=>'constraint','fields'=>['a_val'],'assert'=>$expr,'references'=>$refs]],PID,'1','fa',1,1,'survey'));
    };
    $count=['n'=>['field'=>'b_open','event'=>'baseline_arm_1','aggregate'=>'count']];
    $full=$denied('[a_val]>{n}',$count,function(){});
    $empty=$denied('[a_val]>{n}',$count,function(){unset(REDCap::$data[1]['repeat_instances'][1]['fb']);});
    check('S1: a protected count is refused alike with entries and without (got '.$empty.')',$full===$empty&&strpos($full,'unavailable: unauthorized.')!==false&&strpos($full,'assertAst')===false);
    $match=['m'=>['field'=>'b_open','event'=>'baseline_arm_1','match'=>['key_b'=>'[key_a]']]];
    $keyed=function($key){return function()use($key){
        REDCap::$data[1]['repeat_instances'][1]['fb'][6]=['b_open'=>'1','key_b'=>'AA','fb_complete'=>'2'];
        REDCap::$data[1]['repeat_instances'][1]['fb'][8]=['b_open'=>'2','key_b'=>'AA','fb_complete'=>'2'];
        REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']=$key;};};
    $shapes=array_map(function($k)use($denied,$match,$keyed){return $denied('[a_val]<{m}',$match,$keyed($k));},['Z','A','AA']);
    check('S1: no, one and two protected matches give the page the same rule (got '.json_encode($shapes).')',count(array_unique($shapes))===1&&strpos($shapes[0],'unavailable: unauthorized.')!==false);
    $shapes=array_map(function($k)use($denied,$keyed){return $denied('[a_val]>={c}',['c'=>['field'=>'b_open','event'=>'baseline_arm_1','aggregate'=>'count','match'=>['key_b'=>'[key_a]']]],$keyed($k));},['Z','A']);
    check('S1: ... and a protected count of matches too',count(array_unique($shapes))===1);
    // On a survey every other entry is protected, the instrument's own included.
    $others=['o'=>['field'=>'a_val','aggregate'=>'count','excludeCurrent'=>true,'match'=>['key_a'=>'[key_a]']]];
    $one=$denied('[a_val]>={o}',$others,function(){REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']='SECRET-KEY-42';},true);
    $none=$denied('[a_val]>={o}',$others,function(){REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']='SECRET-KEY-42';
        unset(REDCap::$data[1]['repeat_instances'][1]['fa'][3],REDCap::$data[1]['repeat_instances'][2]);},true);
    check('S1: a survey gets the same rule whether other entries exist or not, and never the saved key (got '.$none.')',
        $one===$none&&strpos($none,'SECRET-KEY-42')===false);
    $self=['s'=>['field'=>'a_val','match'=>['key_a'=>'[key_a]']]];
    $alone=$denied('[a_val]>={s}',$self,function(){REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']='K';},true);
    $shared=$denied('[a_val]>={s}',$self,function(){REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']='K';REDCap::$data[1]['repeat_instances'][1]['fa'][3]['key_a']='K';},true);
    check("S1: a survey match that finds only this entry looks like one that finds two (got ".$alone." | ".$shared.")",$alone===$shared);
    $gone=function(){unset(REDCap::$data[1]['repeat_instances'][1]['fb'][2]);};
    $q='[a_val]<[baseline_arm_1][b_open][2]';
    check('S1: a protected entry that is missing looks like one that is there',$denied($q,null,function(){})===$denied($q,null,$gone));
    $last=['x'=>['field'=>'b_open','event'=>'baseline_arm_1','instance'=>'last-instance']];
    $missing=$denied('{x}>5',$last,function(){unset(REDCap::$data[1]['repeat_instances'][1]['fb']);});
    check('S1: with nothing live, a protected entry that is missing gives no reason of its own (got '.$missing.')',
        strpos($missing,'unresolved')!==false&&strpos($missing,'absent')===false);
    $mixed=function(callable $data)use($denied){return $denied('[a_val]>0',null,function()use($data){
        REDCap::$dictionary['a_val']['field_annotation']='@UVASSERT={"when":"[baseline_arm_1][b_open][2]>0","assert":"[a_val]>{n}","references":{"n":{"field":"b_open","event":"baseline_arm_1","aggregate":"count"}}}';$data();});};
    check('S1: an unresolved protected "when" adds nothing to a refused "assert"',$mixed(function(){})===$mixed($gone));
    $elapsed=function(callable $data)use($denied){return $denied('[a_val]>0',null,function()use($data){
        foreach(['a_val','b_open'] as $f)REDCap::$dictionary[$f]['text_validation_type_or_show_slider_number']='date_ymd';
        REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='2026-01-01';REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2026-01-05';
        REDCap::$dictionary['a_val']['field_annotation']='@UVASSERT={"assert":"{d}>=0","references":{"d":{"field":"a_val","type":"date","elapsedFrom":"[baseline_arm_1][b_open][2]","unit":"days"}}}';$data();});};
    $there=$elapsed(function(){});
    check('S1: an elapsed time from a protected date is refused alike whether the date is there or not (got '.$there.')',
        $there===$elapsed($gone)&&strpos($there,'unavailable: unauthorized.')!==false);
    $m=temporal('[a_val]<{matched}',['matched'=>['field'=>'b_open','event'=>'baseline_arm_1','match'=>['key_b'=>'[key_a]']]]);
    $p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('matched rule not deferred',empty($r['deferred'])&&empty($r['configError']));
    $eval=function($r,$values){return \INSPIRE\UniversalValidator\TemporalLogic::evaluate(($r['uniqueRecordAsts']['a_val']??$r['assertAst']),function($f)use($values){return $values[$f]??'';});};
    check('matched saved key validates',$eval($r,['a_val'=>'10','key_a'=>'A'])===true);
    check('changed key invalidates match',$eval($r,['a_val'=>'10','key_a'=>'B'])===null);
    $m=temporal('[a_val]<{average}',['average'=>['field'=>'a_val','aggregate'=>'average']]);$r=ruleOf(render($m,'fa'),'a_val');
    check('collection retains live self',$eval($r,['a_val'=>'14'])===false);
    check('collection updates with current value',$eval($r,['a_val'=>'8'])===true);
    $m=temporal('',null,'UVUNIQUE');$p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('record uniqueness configured',empty($r['configError'])&&empty($r['deferred']));
    check('repeat uniqueness matches other instance',$eval($r,['a_val'=>'12'])===false);
    check('repeat uniqueness excludes self',$eval($r,['a_val'=>'10'])===true);
    check('repeat uniqueness preserves leading zeros',$eval($r,['a_val'=>'012'])===true);
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='10';
    $res=$m->scanProject(PID);check('record duplicates both contexts reported',count($res['violations'])===2);
    // The page turns a D-M-Y date typed as 07-01-2026 into 2026-01-07 before it
    // compares (read.logicValue), so the other entries go as saved, Y-M-D.
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['text_validation_type_or_show_slider_number']='date_dmy';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2026-01-05';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='2026-01-07';
    REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='2026-01-09';
    $r=ruleOf(render($m,'fa'),'a_val');
    $lits=json_encode($r['uniqueRecordAsts']['a_val']??null);
    check('D-M-Y record uniqueness: the other entries go as saved, never as the field shows them',
        strpos($lits,'"2026-01-07"')!==false&&strpos($lits,'"2026-01-09"')!==false&&strpos($lits,'07-01-2026')===false);
    check('D-M-Y record uniqueness: a typed duplicate is caught',$eval($r,['a_val'=>'2026-01-07'])===false);
    check('D-M-Y record uniqueness: a duplicate in another event is caught',$eval($r,['a_val'=>'2026-01-09'])===false);
    check('D-M-Y record uniqueness: a free date passes',$eval($r,['a_val'=>'2026-01-08'])===true);
    check('D-M-Y record uniqueness: this entry\'s own saved date passes',$eval($r,['a_val'=>'2026-01-05'])===true);
    $res=$m->scanProject(PID);check('D-M-Y record uniqueness: the scan finds no duplicate',!$res['violations']);

    // A field an extended @UVUNIQUE rule only searches ("also", refKeys role
    // "lookup") does not re-check the rule when its form is saved: that save
    // cannot change whether the rule's own value is a duplicate of another
    // record's, and each re-check is a whole-project read.
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['field_annotation']='@UVUNIQUE={"when":"[followup_arm_1][key_a][1]=\'A\'","also":["b_open"]}';
    $r=ruleOf(render($m,'fa'),'a_val');
    check('extended unique with "also": configured',$r&&empty($r['configError']));
    REDCap::$getDataCalls=0;$m->redcap_save_record(PID,'1','fb',1,2);
    check('extended unique with "also": saving the "also" field\'s form reads nothing',REDCap::$getDataCalls===0);
    $m->redcap_save_record(PID,'1','fa',1,1);
    check('extended unique with "also": saving its own form still checks it',REDCap::$getDataCalls>0);

    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    $m->projectSettings['qualified-audit-max-contexts']=1;$m->redcap_save_record(PID,'1','fb',1,null);
    check('audit truncation explicit',saying(array_column(unconf($m),1),'why','context limit')===1);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');REDCap::$getDataMode='throw';
    $m->redcap_save_record(PID,'1','fb',1,null);
    check('failed audit read incomplete',saying(array_column(unconf($m),1),'why','incomplete')===1);
    check('failed audit read never violation',!invalid($m));
    // Wargame P4: the saved entry is judged first, and another entry only where the
    // save can change its verdict. Eight entries on fa, every one violating.
    $many=function($ann){
        $m=temporal('[a_val]>0');REDCap::$dictionary['a_val']['field_annotation']=$ann;
        $rows=[];for($k=1;$k<=8;$k++)$rows[$k]=['a_val'=>(string)(20-$k),'key_a'=>'A','fa_complete'=>'2'];
        REDCap::$data[1]['repeat_instances'][1]['fa']=$rows;unset(REDCap::$data[1]['repeat_instances'][2]);
        $m->projectSettings['qualified-audit-max-contexts']=5;
        return $m;
    };
    $at=function($m){$s=[];foreach(invalid($m) as $c)$s[]=(int)$c[1]['instance'];sort($s);return $s;};
    $own=tag('[a_val]<[baseline_arm_1][b_open][2]');
    $m=$many($own);$m->redcap_save_record(PID,'1','fa',1,null,null,null,8);
    check('P4: saving entry 8 of 8 judges entry 8, past a limit of 5, and only it (got '.json_encode([$at($m),count(unconf($m))]).')',$at($m)===[8]&&!unconf($m));
    $m->logCalls=[];$m->redcap_save_record(PID,'1','fa',1,null,null,null,3);$m->redcap_save_record(PID,'1','fa',1,null,null,null,3);
    check('P4: two saves of entry 3 log two rows, as a rule without event references does (got '.json_encode($at($m)).')',$at($m)===[3,3]);
    $m=$many($own);$m->redcap_save_record(PID,'1','fb',1,null,null,null,2);
    check('P4: saving the entry they all read re-checks them, up to the limit, and says where it stopped (got '.json_encode([$at($m),array_column(array_column(unconf($m),1),'instance')]).')',
        $at($m)===[1,2,3,4,5]&&array_column(array_column(unconf($m),1),'instance')===['6']);
    $m=$many(tag('[a_val]>[a_val][previous-instance]'));$m->redcap_save_record(PID,'1','fa',1,null,null,null,3);
    check('P4: a rule that reads other entries of the saved form re-checks them (got '.json_encode($at($m)).')',in_array(4,$at($m),true)&&in_array(3,$at($m),true));
    // Wargame C8: instance 1 has no previous instance, so the rule does not apply there. That
    // is the record, not the configuration: the audit writes no notice for it, the scan still
    // lists where it could not judge.
    $m=$many(tag('[a_val]>[a_val][previous-instance]'));$m->redcap_save_record(PID,'1','fa',1,null,null,null,1);
    $why=array_map(function($c){return $c[1]['why'];},unconf($m));
    check('C8: saving instance 1 writes no notice for the instance before it (got '.json_encode($why).')',!preg_grep('/unavailable/',$why));
    $res=$m->scanProject(PID);
    check('C8: ... and the scan still says instance 1 was not judged (got '.json_encode(array_column($res['unconfigurable'],'why')).')',
        (bool)preg_grep('/instance 1: absent/',array_column($res['unconfigurable'],'why')));
    // Wargame P5: three records that each spend the evaluation budget are three unfinished
    // records, each named, not two notes shared by rule and position.
    $m=$many('@UVASSERT='.json_encode(['assert'=>'[a_val]<={x}','references'=>['x'=>['field'=>'a_val','aggregate'=>'average','excludeCurrent'=>true]]]));
    $rows=[];for($k=1;$k<=60;$k++)$rows[$k]=['a_val'=>str_repeat('7',4000),'key_a'=>'A','fa_complete'=>'2'];
    REDCap::$data[1]['repeat_instances'][1]['fa']=$rows;REDCap::$data[2]=REDCap::$data[3]=REDCap::$data[1];
    $res=$m->scanProject(PID);
    $spent=array_column(array_filter($res['unconfigurable'],function($u){return strpos($u['why'],'budget exhausted')!==false;}),'record');sort($spent);
    check('P5: each of three records names its spent budget (got '.json_encode([$spent,$res['incomplete'],$res['status']]).')',
        $spent===['1','2','3']&&count(preg_grep('/^record [123] was not fully checked/',$res['incomplete']))===3&&$res['status']==='incomplete');
    unset(REDCap::$data[2],REDCap::$data[3]);
    // Wargame P6: the page charges each member by its size in the page JSON, where < is
    // six bytes. 2,000 members of 640 "<" are 7.7 MB there and spend the budget; the same
    // members of plain text are 1.3 MB and reach the page.
    $page=function($v)use($many){
        $m=$many(tag("[a_val][any-instance]<>'x'"));
        $rows=[];for($k=1;$k<=2000;$k++)$rows[$k]=['a_val'=>$v.$k,'key_a'=>'A','fa_complete'=>'2'];
        REDCap::$data[1]['repeat_instances'][1]['fa']=$rows;
        ob_start();$m->redcap_data_entry_form_top(PID,'1','fa',1,null,1);$html=ob_get_clean();
        // strpos, not render()'s regex: a payload over 1 MB passes PCRE's backtrack limit.
        $open='id="inspire-validator-config">';$a=strpos($html,$open)+strlen($open);$raw=substr($html,$a,strpos($html,'</script>',$a)-$a);
        $rule=ruleOf(['cfg'=>json_decode($raw,true)],'a_val');
        return [!empty($rule['deferred'])&&in_array('Extended validation unavailable: limit.',$rule['deferredWhy']??[],true),strlen($raw)];
    };
    [$hex,$hexBytes]=$page(str_repeat('<',640));[$plain,$plainBytes]=$page(str_repeat('a',640));
    check('P6: members escaped on the page spend the budget there (got '.json_encode([$hex,$hexBytes]).')',$hex&&$hexBytes<100000);
    check('P6: ... and the same members in plain text are sent (got '.json_encode([$plain,$plainBytes]).')',!$plain&&$plainBytes>1280000);
    $m=$many('@UVASSERT='.json_encode(['assert'=>'[a_val]>={mx}','references'=>['mx'=>['field'=>'a_val','aggregate'=>'maximum','excludeCurrent'=>true]],'blockSave'=>'hard']));
    $m->redcap_save_record(PID,'1','fa',1,null,null,null,3);
    check('P4: ... a binding over the saved form too (got '.json_encode($at($m)).')',in_array(3,$at($m),true)&&in_array(2,$at($m),true));
    $m=$many('@UVUNIQUE={"scope":"record"}');foreach(REDCap::$data[1]['repeat_instances'][1]['fa'] as &$e)$e['a_val']='7';unset($e);
    $m->redcap_save_record(PID,'1','fa',1,null,null,null,3);
    check('P4: ... and record uniqueness (got '.json_encode($at($m)).')',in_array(3,$at($m),true)&&count($at($m))>1);
    $m=$many($own);
    REDCap::$dictionary['key_a']['field_annotation']='@UVASSERT='.json_encode(['assert'=>"[key_a]<>[baseline_arm_1][key_b][2]",'message'=>'key','blockSave'=>'hard']);
    REDCap::$dictionary['a_val']['field_annotation']=tag('[a_val]>[a_val][previous-instance]');
    $m->projectSettings['qualified-audit-max-contexts']=2;$m->redcap_save_record(PID,'1','fa',1,null,null,null,5);
    $fields=[];foreach(invalid($m) as $c)$fields[]=$c[1]['field'].'@'.$c[1]['instance'];sort($fields);
    check('P4: every rule\'s saved entry comes before any other entry (got '.json_encode($fields).')',$fields===['a_val@5','key_a@5']);
    $m=temporal('[a_val]<{matched}',['matched'=>['field'=>'b_open','event'=>'baseline_arm_1','match'=>['key_b'=>'[key_a]']]]);
    REDCap::$data[1]['repeat_instances'][1]['fb'][4]['key_b']='A';
    $res=$m->scanProject(PID);check('ambiguous/absent matches not certified',!$res['violations']&&count($res['unconfigurable'])>=2);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');unset($GLOBALS['Proj']);
    check('missing metadata defers',!empty(ruleOf(render($m,'fa'),'a_val')['deferred']));
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    $fold=new ReflectionMethod($m,'foldTemporalRules');$fold->setAccessible(true);
    $rule=['type'=>'constraint','fields'=>['a_val'],'assert'=>'[a_val]<[baseline_arm_1][b_open][2]'];
    $survey=$fold->invoke($m,[$rule],PID,'1','fa',1,1,'survey');
    check('survey cannot carry protected operand',!empty($survey[0]['deferred'])&&strpos(json_encode($survey),'["lit","11"]')===false);
    $rule['assert']='[baseline_arm_1][b_open][2]>0';$survey=$fold->invoke($m,[$rule],PID,'1','fa',1,1,'survey');
    check('survey server Boolean only',$survey[0]['assertAst']===['const',true]&&strpos(json_encode($survey),'["lit","11"]')===false);
    $prepared=new ReflectionMethod($m,'temporalPrepared');$prepared->setAccessible(true);
    $shape=\INSPIRE\UniversalValidator\TemporalMetadata::load(PID,REDCap::$dictionary);
    $context=['event'=>1,'instrument'=>'fa','instance'=>1,'values'=>['a_val'=>'10','key_a'=>'A']];
    foreach(['single','pooled','required','choices','unique'] as $type){
        $rule=['type'=>$type,'fields'=>['a_val'],'when'=>'[baseline_arm_1][b_open][2]>10'];
        $pr=$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context);
        check($type.' saved extended condition',$pr['rule']['when']==='1=1'&&!$pr['problems']);
    }
    $rule=['type'=>'required','fields'=>['a_val'],'branches'=>[
        ['when'=>'[b_open][previous-instance]>0'],['when'=>null]]];
    $pr=$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context);
    check('missing branch cannot choose fallback',!!$pr['problems']);
    // Wargame C5: an unresolved operand leaves the condition unknown only where
    // and/or has not decided it. [b_open][previous-instance] from instance 1 has
    // no entry; [key_a] is 'A' here.
    $kleene=function($key,$cond,$browser=false)use($m,$prepared,$shape,$context){
        $rule=$key==='when'?['type'=>'required','fields'=>['a_val'],'when'=>$cond]:['type'=>'constraint','fields'=>['a_val'],'assert'=>$cond];
        return $browser?$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context,true,function(){return true;})
                       :$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context);
    };
    $pr=$kleene('when',"[key_a]='A' or [b_open][previous-instance]>0");
    check('C5: true or unknown is true (got '.json_encode([$pr['rule']['when']??null,$pr['problems']]).')',($pr['rule']['when']??null)==='1=1'&&!$pr['problems']);
    $pr=$kleene('when',"[key_a]='B' and [b_open][previous-instance]>0");
    check('C5: false and unknown is false',($pr['rule']['when']??null)==='1=0'&&!$pr['problems']);
    $pr=$kleene('when',"[key_a]='B' or [b_open][previous-instance]>0");
    check('C5: false or unknown stays unknown, with its reason (got '.json_encode($pr['problems']).')',$pr['problems']===['absent','unresolved']);
    $pr=$kleene('assert',"[key_a]='A' or [b_open][previous-instance]>0");
    check('C5: an assert decided around the unknown passes',($pr['rule']['assert']??null)==='1=1'&&!$pr['problems']);
    $pr=$kleene('when',"[key_a]='A' or [b_open][previous-instance]>0",true);
    $ast=$pr['rule']['whenAst']??null;
    $page=function($key)use($ast){return \INSPIRE\UniversalValidator\TemporalLogic::evaluate($ast,function($f)use($key){return $f==='key_a'?$key:'';},false);};
    check('C5: the page gets the condition, live part and all (got '.json_encode([$ast,$pr['problems']]).')',!$pr['problems']&&empty($pr['rule']['deferred'])&&($ast[0]??null)==='temporal');
    check('C5: ... and decides it where the live value does, unknown elsewhere',$page('A')===true&&$page('B')===null);
    check('C5: ... marked as reading a snapshot, so it never blocks there',$pr['stale']===true&&($pr['rule']['blockSave']??null)==='off');
    $pr=$kleene('when',"[baseline_arm_1][b_open][2]>0 or [b_open][previous-instance]>0",true);
    check('C5: decided from saved values alone, the page gets the answer',($pr['rule']['whenAst']??null)===['const',true]&&!$pr['problems']);
    $pr=$kleene('when',"[b_open][previous-instance]>0 or [baseline_arm_1][b_open][2]>99",true);
    check('C5: undecidable with nothing live to read: deferred, with its reason',!empty($pr['rule']['deferred'])&&in_array('absent',$pr['problems'],true));
    $pr=$kleene('when',"[key_a]='A' or [key_a][999999999999999999999]='A'");
    check('C5: an invalid reference defers even a decided condition (got '.json_encode($pr['problems']).')',in_array('invalid',$pr['problems'],true));
    $rule=['type'=>'required','fields'=>['a_val'],'branches'=>[
        ['when'=>"[key_a]='A' or [b_open][previous-instance]>0"],['when'=>null]]];
    $pr=$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context);
    check('C5: a branch decided around the unknown is chosen (got '.json_encode([$pr['problems'],$pr['branch']??null]).')',!$pr['problems']&&($pr['branch']??null)===0);
    $rule=['type'=>'unique','fields'=>['a_val'],'branches'=>[
        ['type'=>'unique','uniqueScope'=>'record','when'=>'[baseline_arm_1][b_open][2]>0'],
        ['type'=>'unique','uniqueScope'=>'record','when'=>null]]];
    $pr=$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context,true,function(){return true;});
    check('record unique branches preserve mode',$pr['rule']['type']==='unique'&&!$pr['problems']);
    check('record unique branch retains condition',$pr['rule']['branches'][0]['whenAst'][0]==='temporal');
    $off=$m->validateSettings(['enable-event-instance-refs'=>false,'rules'=>[1],'rule-type'=>['constraint'],'fields-csv'=>['a_val'],'assert'=>['[b_open][2]>0']]);
    check('disabling preserves authored rules',$off===null);
    // QUA-4: turning event/instance references on runs the preflight over the
    // project's tags (temporalActivationProblems); keeping them on does not.
    $on=['enable-event-instance-refs'=>true,'rules'=>[]];
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');$m->projectSettings['enable-event-instance-refs']=false;
    $warned=[];set_error_handler(function($no,$msg)use(&$warned){$warned[]=$msg;return true;});
    $pre=$m->validateSettings($on);restore_error_handler();
    check('QUA-4: turning references on with sound tags passes the preflight, and the untagged fields raise no warning (got '.json_encode([$pre,$warned]).')',$pre===null&&!$warned);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');$m->projectSettings['enable-event-instance-refs']=false;
    REDCap::$dictionary['a_val']['field_annotation']='@UVASSERT='.json_encode(['assert'=>'[a_val]<[baseline_arm_1][nosuch][2]']);
    $pre=$m->validateSettings($on);
    check('QUA-4: ...a tag that reads a field the project lacks stops it, naming the field (got '.json_encode($pre).')',
        is_string($pre)&&strpos($pre,'a_val: ')!==false&&strpos($pre,'nosuch')!==false);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    REDCap::$dictionary['a_val']['field_annotation']='@UVASSERT='.json_encode(['assert'=>'[a_val]<[baseline_arm_1][nosuch][2]']);
    check('QUA-4: ...a save that keeps them on does not run it again',$m->validateSettings($on)===null);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');$m->projectSettings['enable-event-instance-refs']=false;
    unset($GLOBALS['Proj']);REDCap::$eventMappings=null;
    $pre=$m->validateSettings($on);
    check('QUA-4: ...and events it cannot read stop it with that reason (got '.json_encode($pre).')',
        is_string($pre)&&strpos($pre,'could not read')!==false);
    $m=temporal('[a_val]>0');$m->projectSettings['enable-event-instance-refs']=false;$before=render($m,'fa')['raw'];
    $m->projectSettings['enable-event-instance-refs']=true;$after=render($m,'fa')['raw'];
    check('enabled legacy-only browser payload identical',$before===$after);

    // Wargame C1-B/C: on a D-M-Y field the page gets the saved Y-M-D values and the
    // field's display format, so it converts what it shows before it compares
    // (tests/deferral_dom_js.cjs boots the engine on these shapes).
    $dmy=function($m,array $fields){foreach($fields as $f)REDCap::$dictionary[$f]['text_validation_type_or_show_slider_number']='date_dmy';return $m;};
    $m=$dmy(temporal('',null,'UVUNIQUE'),['a_val']);
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2024-01-05';REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='2024-01-07';
    REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='2024-01-09';
    $p=render($m,'fa','1',1,3);$u=json_encode(ruleOf($p,'a_val')['uniqueRecordAsts']['a_val']??null);
    check('C1-B: record uniqueness on a D-M-Y field sends the other entries as saved, with the format (got '.json_encode([$p['cfg']['dateFormats']??null,$u]).')',
        ($p['cfg']['dateFormats']['a_val']??null)===['date','dmy']&&strpos($u,'"2024-01-05"')!==false&&strpos($u,'"2024-01-09"')!==false&&strpos($u,'"2024-01-07"')===false);
    check('C1-B: ... and the scan finds no duplicate there',!$m->scanProject(PID)['violations']);
    $m=$dmy(temporal('[a_val]>={t}',['t'=>['field'=>'b_open','match'=>['key_b'=>'[key_a]']]]),['key_a','key_b']);
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['key_a']='2024-01-05';REDCap::$data[1]['repeat_instances'][1]['fb'][2]['key_b']='2024-01-05';
    $p=render($m,'fa','1',1,1);$a=assertAst($p,'a_val');
    check('C1-C: a match keyed on a D-M-Y field guards the saved key, with the format (got '.json_encode([$p['cfg']['dateFormats']??null,$a]).')',
        ($p['cfg']['dateFormats']['key_a']??null)===['date','dmy']&&strpos($a,'["guard",[["key_a","2024-01-05"]],["lit","11"]]')!==false);
    check('C1-C: ... and the scan reports the same violation',count($m->scanProject(PID)['violations'])===1);

    $m=temporal('',null,'UVUNIQUE');
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='10';
    $ctx=$m->durableScanContext(PID,['generation'=>null]);
    $d=$m->durableEvaluateRecord($ctx['plan'],PID,'1',REDCap::$data[1],1,str_repeat('k',32),$ctx['plan']['ruleIds']);
    check('durable repeat duplicates stored as findings',count($d['findings'])===2);
    check('repeat duplicates bypass distinct-record finalizer',!$d['candidates']);
    $prep=new ReflectionMethod($m,'temporalPrepared');$prep->setAccessible(true);
    $shape=\INSPIRE\UniversalValidator\TemporalMetadata::load(PID,REDCap::$dictionary);
    $rule=['type'=>'unique','fields'=>['a_val','key_a'],'uniqueScope'=>'record'];
    $pr=$prep->invoke($m,$rule,$shape,REDCap::$data[1],['event'=>1,'instrument'=>'fa','instance'=>3,'values'=>REDCap::$data[1]['repeat_instances'][1]['fa'][3]]);
    check('multi-field uniqueness isolates results',$pr['rule']['uniqueRecordResults']===['a_val'=>false,'key_a'=>true]);
    $pr=$prep->invoke($m,$rule,$shape,REDCap::$data[1],['event'=>1,'instrument'=>'fa','instance'=>3,'values'=>REDCap::$data[1]['repeat_instances'][1]['fa'][3]],true,function(){return true;});
    check('record uniqueness on the page reads the other entries, so it is off the page (got '.json_encode([$pr['offPage']??null,isset($pr['rule']['uniqueRecordAsts'])]).')',
        ($pr['offPage']??null)===true&&isset($pr['rule']['uniqueRecordAsts']));
    // Record scope compares as @UVEXISTS does: letter case ignored unless the
    // rule is caseSensitive, numbers by value in a field that holds them.
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='ab-1';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='AB-1';
    $res=$m->scanProject(PID);
    check('record uniqueness ignores letter case by default',count($res['violations'])===2);
    $ast=json_encode(ruleOf(render($m,'fa','1',1,3),'a_val')['uniqueRecordAsts']['a_val']??null);
    check('...and the page compares with the folding operator',strpos($ast,'"same:fold:text"')!==false&&strpos($ast,'"ab-1"')!==false);
    // A saved Missing Data Code is no value: "unk" typed elsewhere is no duplicate of it.
    $m=temporal('',null,'UVUNIQUE');
    $mc=new ReflectionProperty(\INSPIRE\UniversalValidator\UniversalValidator::class,'missingCodesMemo');$mc->setAccessible(true);
    $mc->setValue($m,[PID=>['UNK'=>true]]);
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='UNK';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='unk';
    $res=$m->scanProject(PID);
    check('record uniqueness: "unk" is no duplicate of a saved code UNK',count($res['violations'])===0);
    $ast=json_encode(ruleOf(render($m,'fa','1',1,3),'a_val')['uniqueRecordAsts']['a_val']??null);
    check('...and the page is not sent the code as a value to match',strpos($ast,'"UNK"')===false);
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='UNK';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='unk';
    $res=$m->scanProject(PID);
    check('...control, no codes set up: "unk" and "UNK" are duplicates',count($res['violations'])===2);
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['field_annotation']='@UVUNIQUE={"scope":"record","caseSensitive":true}';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='ab-1';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='AB-1';
    $res=$m->scanProject(PID);
    check('record uniqueness with caseSensitive keeps "AB-1" and "ab-1" apart',count($res['violations'])===0);
    $ast=json_encode(ruleOf(render($m,'fa','1',1,3),'a_val')['uniqueRecordAsts']['a_val']??null);
    check('...and the page compares exactly',strpos($ast,'"same:exact:text"')!==false);
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['text_validation_type_or_show_slider_number']='integer';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='007';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='7';
    $res=$m->scanProject(PID);
    check('record uniqueness on an integer field compares numbers by value',count($res['violations'])===2);
    $ast=json_encode(ruleOf(render($m,'fa','1',1,3),'a_val')['uniqueRecordAsts']['a_val']??null);
    check('...and the page compares numbers too',strpos($ast,'"same:fold:point"')!==false);
    // Two branches of one field, one folding and one exact: each keeps its own
    // index of the record's entries, read once per record.
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['field_annotation']='@UVUNIQUE={"scope":"record","when":"[key_a]=\'B\'","caseSensitive":true} '
        .'@UVUNIQUE={"scope":"record","when":"[key_a]=\'A\'"}';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]=['a_val'=>'AB','key_a'=>'B','fa_complete'=>'2'];
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]=['a_val'=>'ab','key_a'=>'A','fa_complete'=>'2'];
    unset(REDCap::$data[1]['repeat_instances'][2]);
    $res=$m->scanProject(PID);
    $v=array_map(function($x){return $x['instance'];},array_filter($res['violations'],function($x){return $x['field']==='a_val';}));
    check('record uniqueness: the folding branch and the exact branch do not share an index',array_values($v)===[3]);
    // ---- adversarial review 2026-09-20 ----------------------------------------
    // One saved value that is not valid UTF-8 used to make json_encode() fail and
    // the page then carried NO rule at all, in silence.
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    REDCap::$dictionary['key_a']['field_annotation']='@UVREQUIRED';
    REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']="caf\xe9";
    $p=render($m,'fa');
    check('invalid UTF-8 snapshot: the page still carries its configuration',is_array($p['cfg'])&&count($p['cfg']['rules'])===2);
    check('invalid UTF-8 snapshot: only the affected rule is given up, visibly',!empty(ruleOf($p,'a_val')['deferred'])&&(ruleOf($p,'a_val')['blockSave']??null)==='off');
    check('invalid UTF-8 snapshot: the unrelated rule stays live',empty(ruleOf($p,'key_a')['deferred'])&&ruleOf($p,'key_a')['type']==='required');
    check('invalid UTF-8 snapshot: the bad bytes never reach the page',strpos($p['raw'],"caf")===false);
    check('invalid UTF-8 snapshot: a staff page says why the rule is not checked',strpos(json_encode(ruleOf($p,'a_val')['deferredWhy']??null),'not valid UTF-8')!==false);
    // A survey withholds such a value before it is encoded; the stub step runs
    // after the survey pass all the same, so it says nothing of why there
    // either (round-4 review 4).
    $enc=new \ReflectionMethod(\INSPIRE\UniversalValidator\UniversalValidator::class,'encodableRules');
    $enc->setAccessible(true);
    $bad=[['type'=>'constraint','fields'=>['a_val'],'assert'=>"[a_val]='caf\xe9'"]];
    $fl=JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE;
    $st=$enc->invoke(null,$bad,$fl,false)[0];
    $ss=$enc->invoke(null,$bad,$fl,true)[0];
    check('the encoding stub: a staff page is told why',!empty($st['deferred'])&&isset($st['deferredWhy']));
    check('the encoding stub on a survey: given up, saying nothing of why',!empty($ss['deferred'])&&$ss['blockSave']==='off'
        &&$ss['fields']===['a_val']&&!array_key_exists('deferredWhy',$ss));

    // The rule's own caseSensitive flag governs its "when"; record scope must not force it on.
    $m=temporal('',null,'UVUNIQUE');
    REDCap::$dictionary['a_val']['field_annotation']='@UVUNIQUE={"scope":"record","when":"[key_a]=\'a\'"}';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='10';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['key_a']='A';
    $r=ruleOf(render($m,'fa'),'a_val');
    check('record uniqueness leaves the gate case-insensitive on the page',empty($r['caseSensitive'])&&($r['whenAst'][0]??null)==='temporal');
    $res=$m->scanProject(PID);
    check('and the server agrees: both duplicate entries reported',count($res['violations'])===2);

    // Scale: the per-context rescan spent the whole budget at about 220 entries.
    $m=temporal('',null,'UVUNIQUE');
    $rows=[];for($i=1;$i<=1500;$i++)$rows[$i]=['a_val'=>'v'.($i%750),'key_a'=>'k','fa_complete'=>'2'];
    REDCap::$data[1]['repeat_instances'][1]['fa']=$rows;unset(REDCap::$data[1]['repeat_instances'][2]);
    $res=$m->scanProject(PID);
    check('1,500 repeat entries: every duplicate found',count($res['violations'])===1500);
    check('1,500 repeat entries: nothing left unchecked',!$res['unconfigurable']);
    $p=render($m,'fa','1',1,7);$ast=ruleOf($p,'a_val')['uniqueRecordAsts']['a_val'][1]??null;
    check('the page carries one clause per distinct tuple, not per entry',is_array($ast)&&$ast[0]==='and'&&count($ast[1])===750);
    $m=temporal('[a_val]<={mean}',['mean'=>['field'=>'a_val','aggregate'=>'average']]);
    $rows=[];for($i=1;$i<=1500;$i++)$rows[$i]=['a_val'=>(string)($i%7),'key_a'=>'k','fa_complete'=>'2'];
    REDCap::$data[1]['repeat_instances'][1]['fa']=$rows;unset(REDCap::$data[1]['repeat_instances'][2]);
    $res=$m->scanProject(PID);
    // mean of (i mod 7) over 1..1500 is 4497/1500 = 2.998: values 3..6 exceed it.
    $over=0;for($i=1;$i<=1500;$i++)if($i%7>=3)$over++;
    check('1,500 host contexts of one aggregate: exact and complete',count($res['violations'])===$over&&!$res['unconfigurable']);

    // A date not entered yet is a saved blank: inert, never an "unresolved" finding per record.
    $m=temporal('{end}>={start}',['end'=>['field'=>'a_val','type'=>'date'],'start'=>['field'=>'b_open','event'=>'baseline_arm_1','instance'=>2,'type'=>'date']]);
    foreach(['a_val','b_open'] as $f)REDCap::$dictionary[$f]['text_validation_type_or_show_slider_number']='date_ymd';
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2026-01-05';REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='';
    REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='2026-01-01';REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='';
    $res=$m->scanProject(PID);
    check('blank typed dates are inert, not unresolved',!$res['violations']&&!$res['unconfigurable']);
    REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='2026-01-03';
    $res=$m->scanProject(PID);
    check('entered typed dates are judged',count($res['violations'])===1&&!$res['unconfigurable']);
    REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='2026-02-30';
    $res=$m->scanProject(PID);
    check('an impossible date is still unresolved, never a verdict',!$res['violations']&&count($res['unconfigurable'])>=1);

    // A truncated audit names EVERY rule it did not reach.
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    REDCap::$dictionary['key_a']['field_annotation']='@UVASSERT={"assert":"[key_a]<>[baseline_arm_1][key_b][2]"}';
    $m->projectSettings['qualified-audit-max-contexts']=1;$m->redcap_save_record(PID,'1','fb',1,null);
    $named=[];foreach(unconf($m) as $c)if(strpos($c[1]['why']??'','context limit')!==false)$named[$c[1]['fields']??'']=true;
    check('audit truncation names both rules',count($named)===2);

    // The hook may deliver the event id as a string; one event must stay one event.
    $m=temporal('{n}>0',['n'=>['field'=>'a_val','events'=>['event-name','baseline_arm_1'],'aggregate'=>'count']]);
    $r=ruleOf(render($m,'fa','1','1',1),'a_val');
    check('a string event id does not double a collection',($r['assertAst'][1][2][0]??null)==='aggregate'&&count($r['assertAst'][1][2][2])===2);

    // Configuration gaps that used to validate clean.
    $bad=\INSPIRE\UniversalValidator\TemporalRules::validate(['assert'=>'{e}<5','references'=>['e'=>['field'=>'a_val','type'=>'date','elapsedFrom'=>'[a_val][any-instance]','unit'=>'days']]]);
    check('elapsedFrom rejects a collection selector',(bool)array_filter($bad,function($e){return strpos($e,'one scalar reference')!==false;}));
    $m=temporal('{n}>=0',['n'=>['field'=>'a_val','events'=>'arm','arm'=>7,'aggregate'=>'count']]);
    $shape=\INSPIRE\UniversalValidator\TemporalMetadata::load(PID,REDCap::$dictionary);
    $bad=\INSPIRE\UniversalValidator\TemporalRules::validateProject(['assert'=>'{n}>=0','references'=>['n'=>['field'=>'a_val','events'=>'arm','arm'=>7,'aggregate'=>'count']]],$shape);
    check('an arm that collects nothing is a configuration error',(bool)array_filter($bad,function($e){return strpos($e,'arm 7')!==false;}));
    $res=$m->scanProject(PID);
    check('and never a confident count of zero',!$res['violations']&&count($res['unconfigurable'])>=1);
    // Wargame C3: a reference no host context can resolve is refused when the rule is
    // configured, instead of being deferred and logged on every save.
    $c3=new \INSPIRE\UniversalValidator\ProjectShape([
        1=>['name'=>'baseline_arm_1','arm'=>1,'order'=>1,'forms'=>['neg','xs','visit'],'repeats'=>['xs'],'eventRepeats'=>false],
        2=>['name'=>'followup_arm_1','arm'=>1,'order'=>2,'forms'=>['neg','visit'],'repeats'=>['visit'],'eventRepeats'=>false],
    ],['n1'=>['form'=>'neg'],'xe_code'=>['form'=>'neg'],'xs_id'=>['form'=>'xs'],'xs_host'=>['form'=>'xs'],'v'=>['form'=>'visit']]);
    $c3Errors=function(array $rule,array $fields)use($c3){return \INSPIRE\UniversalValidator\TemporalRules::validateProject($rule+['fields'=>$fields],$c3);};
    check('C3: a relative instance on a page that never repeats is refused',
        $c3Errors(['assert'=>'[n1]=[xe_code][previous-instance]'],['n1'])===['Relative instance selector previous-instance needs a repeating source: the instrument of this rule does not repeat in any event.']);
    check('C3: ... and one whose target never repeats',
        $c3Errors(['assert'=>'[xs_host]=[xe_code][previous-instance]'],['xs_host'])===['Relative instance selector previous-instance targets a non-repeating instrument.']);
    check('C3: a repeating form named without an instance is refused',
        $c3Errors(['assert'=>'[n1]=[baseline_arm_1][xs_id]'],['n1'])===['Reference to [xs_id] in baseline_arm_1 is ambiguous: its instrument repeats there, so it needs an instance selector.']);
    check('C3: ... as is a plain binding to it',
        $c3Errors(['assert'=>'[n1]={x}','references'=>['x'=>['field'=>'xs_id','event'=>'baseline_arm_1']]],['n1'])===['Reference to [xs_id] in baseline_arm_1 is ambiguous: its instrument repeats there, so it needs an instance selector.']);
    check('C3: the same reference from its own repeating entry is fine',$c3Errors(['assert'=>'[xs_host]=[baseline_arm_1][xs_id]'],['xs_host'])===[]);
    check('C3: a relative instance from a form that repeats in one event is fine',$c3Errors(['assert'=>'[v]=[v][previous-instance]'],['v'])===[]);
    check('C3: a count over the repeating form needs no instance',
        $c3Errors(['assert'=>'{x}>0','references'=>['x'=>['field'=>'xs_id','event'=>'baseline_arm_1','aggregate'=>'count']]],['n1'])===[]);
    check('C3: a withheld "from" with notFuture is left to the page',
        $c3Errors(['windowFrom'=>'[baseline_arm_1][xs_id]','windowNotFuture'=>true],['n1'])===[]);
    // Wargame C7: the "from" date of an elapsed binding is read with the binding's type.
    $c7=new \INSPIRE\UniversalValidator\ProjectShape([1=>['name'=>'base_arm_1','arm'=>1,'order'=>0,'forms'=>['visit'],'repeats'=>[],'eventRepeats'=>false]],
        ['consent_date'=>['form'=>'visit','type'=>'text','validation'=>'date_ymd'],'consent_dt'=>['form'=>'visit','type'=>'text','validation'=>'datetime_dmy'],
         'result_time'=>['form'=>'visit','type'=>'text','validation'=>'datetime_ymd'],'note'=>['form'=>'visit','type'=>'text','validation'=>'']]);
    $c7Errors=function($from)use($c7){return \INSPIRE\UniversalValidator\TemporalRules::validateProject(['assert'=>'{h}<=48','references'=>['h'=>['field'=>'result_time','type'=>'datetime','elapsedFrom'=>$from,'unit'=>'hours']]],$c7);};
    check('C7: a date "from" for a datetime binding is refused',$c7Errors('[consent_date]')===['Binding h elapsedFrom field must have the same date type as the binding (datetime).']);
    check('C7: ... as is a "from" that is not a date',$c7Errors('[base_arm_1][note]')===['Binding h elapsedFrom field must have the same date type as the binding (datetime).']);
    check('C7: a datetime "from" in another order is fine',$c7Errors('[consent_dt]')===[]);
    check('C3: ... and refused without it, when nothing of the rule could be checked',
        $c3Errors(['windowFrom'=>'[baseline_arm_1][xs_id]'],['n1'])===['Reference to [xs_id] in baseline_arm_1 is ambiguous: its instrument repeats there, so it needs an instance selector.']);

    // @UVWINDOW counted from a date in another event (2.2.0): the "from" operand
    // goes through the same resolver, entitlement and snapshot rules as a condition.
    $win=function($ann='@UVWINDOW={"from":"[baseline_arm_1][b_open][2]","window":[21,35],"blockSave":"hard"}'){
        $m=temporal('[a_val]>0');
        REDCap::$dictionary['a_val']['field_annotation']=$ann;
        foreach(['a_val','b_open'] as $f)REDCap::$dictionary[$f]['text_validation_type_or_show_slider_number']='date_ymd';
        REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='2026-01-01';
        REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2026-01-05';   // 4 days: early
        REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='2026-01-25';   // 24 days: inside
        REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='2026-03-01';   // 59 days: late
        return $m;
    };
    $m=$win();$p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('window from another event: no configuration error, not deferred',$r&&empty($r['configError'])&&empty($r['deferred']));
    check('window from another event: the saved anchor is a snapshot literal',($r['windowFromOp']??null)===['lit','2026-01-01']);
    // The page turns the window part advisory (snapshotFields); "notFuture" reads the
    // live field, so the rule keeps its blockSave for it.
    check('window from another event: the window part is advisory in the browser, notFuture keeps the block',
        ($r['blockSave']??null)==='hard'&&!empty($r['snapshotFields']));
    $res=$m->scanProject(PID);
    $reasons=array_column($res['violations'],'reason');sort($reasons);
    check('window from another event: the scan finds the early and the late entry',$reasons===['window-early','window-late']&&!$res['unconfigurable']);
    $m=$win();$m->redcap_save_record(PID,'1','fb',1,null);
    check('window from another event: saving the anchor form re-audits every host entry',count(invalid($m))===2);
    $m=$win();$m->projectSettings['enable-event-instance-refs']=false;
    check('window from another event needs the feature',strpos(ruleOf(render($m,'fa'),'a_val')['configError']??'','event and instance references')!==false);
    $m=$win();REDCap::$rights['nurse']['forms']['fb']='0';$p=render($m,'fa');
    check('window anchor on a form the viewer cannot read: withheld, nothing shipped',(ruleOf($p,'a_val')['windowFromOp']??null)===['withheld']&&empty(ruleOf($p,'a_val')['deferred'])&&strpos($p['raw'],'2026-01-01')===false);
    $m=$win();$fold=new ReflectionMethod($m,'foldTemporalRules');$fold->setAccessible(true);
    $rule=['type'=>'window','fields'=>['a_val'],'windowFrom'=>'[baseline_arm_1][b_open][2]','windowLo'=>21,'windowHi'=>35,
           'windowUnit'=>'days','dateType'=>'date','dateFormat'=>'ymd','fromType'=>'date','fromFormat'=>'ymd'];
    $survey=$fold->invoke($m,[$rule],PID,'1','fa',1,1,'survey');
    check('window anchor on a survey: withheld, nothing shipped',($survey[0]['windowFromOp']??null)===['withheld']&&empty($survey[0]['deferred'])&&strpos(json_encode($survey),'2026-01-01')===false);
    // Wargame S1: a protected anchor that cannot be read is withheld as a readable one is, with no reason.
    $m=$win();REDCap::$rights['nurse']['forms']['fb']='0';REDCap::$data[1]['repeat_instances'][1]['fb']='not rows';$r=ruleOf(render($m,'fa'),'a_val');
    check('S1: an unreadable protected anchor is withheld like any other, no reason given (got '.json_encode($r).')',
        ($r['windowFromOp']??null)===['withheld']&&empty($r['deferred'])&&!isset($r['windowFromOpWhy']));
    $m=$win();REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='';
    $res=$m->scanProject(PID);
    check('window from a blank anchor in another event: inert, not unresolved',!$res['violations']&&!$res['unconfigurable']);
    $m=$win();unset(REDCap::$data[1]['repeat_instances'][1]['fb'][2]);
    $res=$m->scanProject(PID);
    check('window from an anchor entry that does not exist: never a verdict',!$res['violations']);
    check('...and not unresolved: the window waits for the entry, as for a blank date',!$res['unconfigurable']);
    $m=$win();unset(REDCap::$data[1]['repeat_instances'][1]['fb'][2]);$m->redcap_save_record(PID,'1','fa',1,1);
    check('...nor on save',!invalid($m)&&!unconf($m));
    $m=$win();unset(REDCap::$data[1]['repeat_instances'][1]['fb'][2]);$r=ruleOf(render($m,'fa'),'a_val');
    check('...and the page gets a blank anchor, not a deferral',$r&&empty($r['deferred'])&&($r['windowFromOp']??null)===['lit','']);
    // "notFuture" does not need the "from" date. A "from" that cannot be resolved
    // ([baseline_arm_1][b_open] names a repeating form with no instance) leaves the
    // rule live for "notFuture", on the page and after saving.
    $amb='@UVWINDOW={"from":"[baseline_arm_1][b_open]","window":[21,35],"notFuture":true,"blockSave":"hard"}';
    $m=$win($amb);$r=ruleOf(render($m,'fa'),'a_val');
    check('unresolved "from" with notFuture: the rule stays live, the "from" withheld with its reason',
        $r&&empty($r['deferred'])&&($r['windowFromOp']??null)===['withheld']&&!empty($r['windowFromOpWhy'])&&($r['blockSave']??null)==='hard');
    $m=$win($amb);REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2099-01-01';
    $res=$m->scanProject(PID);
    check('unresolved "from" with notFuture: the scan still finds a future date',array_column($res['violations'],'reason')===['future']);
    check('...and says the window was not checked for the other entries',count($res['unconfigurable'])>=1
        &&strpos(json_encode($res['unconfigurable']),'window was not checked')!==false);
    $m=$win($amb);REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2099-01-01';$m->redcap_save_record(PID,'1','fa',1,1);
    check('unresolved "from" with notFuture: the audit flags the future date',array_map(function($c){return $c[1]['reason'];},invalid($m))===['future']);
    $m=$win($amb);$res=$m->scanProject(PID);
    check('...and the scan gives the reason it could not be resolved',strpos(json_encode($res['unconfigurable']),'could not be resolved (Extended reference unavailable')!==false);
    $m=$win($amb);REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2026-02-31';$res=$m->scanProject(PID);
    check('...an unreadable value is never called "not in the future"',
        strpos(json_encode($res['unconfigurable']),'and the saved date is not a date this rule can read')!==false);
    // Without notFuture nothing of the rule could be checked: a "from" that can never
    // resolve is a configuration error (wargame C3).
    $m=$win(str_replace(',"notFuture":true','',$amb));$r=ruleOf(render($m,'fa'),'a_val');
    check('unresolved "from" without notFuture: a configuration error naming the fix (got '.json_encode($r['configError']??null).')',
        $r&&strpos((string)($r['configError']??''),'Reference to [b_open] in baseline_arm_1 is ambiguous')!==false);
    // A plain "from" on another repeating form: the same, on the page path.
    $m=$win('@UVWINDOW={"from":"[b_open]","window":[21,35],"notFuture":true,"blockSave":"hard"}');$r=ruleOf(render($m,'fa'),'a_val');
    check('plain "from" on another repeating form, with notFuture: withheld with its reason, rule live',
        $r&&empty($r['deferred'])&&($r['windowFromOp']??null)===['withheld']
        &&strpos(json_encode($r['windowFromOpWhy']??[]),'different repeating instrument')!==false);
    $m=$win('@UVWINDOW={"from":"[b_open]","window":[21,35],"notFuture":true,"blockSave":"hard"}');
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='2099-01-01';$m->redcap_save_record(PID,'1','fa',1,1);
    check('plain "from" on another repeating form: the audit flags a future date',array_map(function($c){return $c[1]['reason'];},invalid($m))===['future']);
    // A plain "from" on a form no event of the field collects: always blank, so refused.
    $m=$win('@UVWINDOW={"from":"[b_open]","window":[21,35]}');
    REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['event_id'=>2,'form'=>'fb']];
    $r=ruleOf(render($m,'fa'),'a_val');
    check('plain "from" on a form in none of the field\'s events: refused, with the event syntax',
        $r&&strpos((string)($r['configError']??''),'which no event of this field collects')!==false
        &&strpos((string)$r['configError'],'[event_name][b_open]')!==false);
    $m=$win('@UVWINDOW={"from":"[b_open]","window":[21,35]}');
    REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['unique_event_name'=>'followup_arm_1','form'=>'fb']];
    $r=ruleOf(render($m,'fa'),'a_val');
    check('...a mapping keyed two ways proves nothing: not refused',
        $r&&strpos((string)($r['configError']??''),'which no event of this field collects')===false);
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][b_open][2]","window":[21,35]}');
    REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['event_id'=>2,'form'=>'fb']];
    $r=ruleOf(render($m,'fa'),'a_val');
    check('...an event-qualified "from" is not refused for it',
        $r&&strpos((string)($r['configError']??''),'which no event of this field collects')===false);
    $m=$win('@UVWINDOW={"from":"[b_open][2]","window":[21,35]}');
    REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['event_id'=>2,'form'=>'fb']];
    $r=ruleOf(render($m,'fa'),'a_val');
    check('...an instance with no event reads this event too: refused',
        $r&&strpos((string)($r['configError']??''),'which no event of this field collects')!==false);
    foreach(['[event-name][b_open]','[b_open][current-instance]'] as $sameEv){
        $m=$win('@UVWINDOW={"from":"'.$sameEv.'","window":[21,35]}');
        REDCap::$eventMappings=[['event_id'=>1,'form'=>'fa'],['event_id'=>2,'form'=>'fb']];
        $r=ruleOf(render($m,'fa'),'a_val');
        check('...'.$sameEv.' reads this event too: refused',
            $r&&strpos((string)($r['configError']??''),'which no event of this field collects')!==false);
    }
    // A "when" that reads another event is a stale gate: then the whole rule is advisory.
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][b_open][2]","window":[21,35],"notFuture":true,"blockSave":"hard","when":"[baseline_arm_1][b_open][2]<>\'\'"}');
    $r=ruleOf(render($m,'fa'),'a_val');
    check('window with a "when" from another event: advisory in the browser',$r&&($r['blockSave']??null)==='off');
    // A branch chosen by a value from another event may be the wrong branch, so no
    // branch blocks, not even the one whose "notFuture" reads only the live field.
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][b_open][2]","window":[21,35],"blockSave":"hard","when":"[baseline_arm_1][key_b][2]=\'A\'"} @UVWINDOW={"notFuture":true,"blockSave":"hard"}');
    $r=ruleOf(render($m,'fa'),'a_val');
    $bs=array_map(function($b){return [$b['blockSave']??null,!empty($b['snapshotGate'])];},array_values($r['branches']??[]));
    check('branches chosen by a value from another event: none blocks',$bs===[['off',true],['off',true]]);
    check('...and the rule says it was read when the page was built',!empty($r['snapshotFields']));
    // The same selector on a form this viewer may not read: no branch blocks either.
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][b_open][2]","window":[21,35],"blockSave":"hard","when":"[baseline_arm_1][key_b][2]=\'A\'"} @UVWINDOW={"notFuture":true,"blockSave":"hard"}');
    REDCap::$rights['nurse']['forms']['fb']='0';
    $r=ruleOf(render($m,'fa'),'a_val');
    REDCap::$rights['nurse']['forms']['fb']='1';
    $bs=array_map(function($b){return [$b['blockSave']??null,!empty($b['snapshotGate'])];},array_values($r['branches']??[]));
    check('branches chosen by a value this viewer may not read: none blocks',$bs===[['off',true],['off',true]]);
    // A selector and a "from" both on this page: each branch blocks as it says,
    // and the rule as a whole is not marked as read when the page was built.
    $m=$win('@UVWINDOW={"from":"[a_from][current-instance]","window":[21,35],"blockSave":"hard","when":"[key_a][current-instance]=\'A\'"} @UVWINDOW={"from":"[a_from][current-instance]","window":[0,14],"blockSave":"hard"}');
    REDCap::$dictionary['a_from']=['field_type'=>'text','form_name'=>'fa','field_annotation'=>'','text_validation_type_or_show_slider_number'=>'date_ymd'];
    $r=ruleOf(render($m,'fa'),'a_val');
    $bs=array_map(function($b){return [$b['blockSave']??null,!empty($b['snapshotGate']),!empty($b['snapshotFields'])];},array_values($r['branches']??[]));
    check('branches with a live selector and a live "from": each blocks as it says (got '.json_encode($bs).')',
        $r&&empty($r['configError'])&&$bs===[['hard',false,false],['hard',false,false]]&&empty($r['snapshotFields']));
    // A set of entries is read when the page is built, even one holding only this entry.
    $m=$win('@UVWINDOW={"notFuture":true,"blockSave":"hard","when":"[a_val][any-instance]<>\'\'"}');
    $r=ruleOf(render($m,'fa','1',2,1),'a_val');
    check('a "when" over a set of entries holding only this one: advisory in the browser',
        $r&&empty($r['configError'])&&($r['blockSave']??null)==='off'&&!empty($r['snapshotFields']));
    // A count of entries in another event is read when the page is built, even a count of none.
    $m=$win('@UVWINDOW={"notFuture":true,"blockSave":"hard","when":"{n}=0","references":{"n":{"field":"b_open","events":["event-name","baseline_arm_1"],"aggregate":"count"}}}');
    unset(REDCap::$data[1]['repeat_instances'][1]['fb']);
    $r=ruleOf(render($m,'fa'),'a_val');
    check('a "when" counting no entries in another event: advisory in the browser',$r&&empty($r['configError'])&&($r['blockSave']??null)==='off');
    // A "from" that is the host field itself on one of its entries: that entry is
    // never judged against its own value; the other entries are.
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][a_val][1]","window":[21,35]}');
    $res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=($v['event_id']??'?').'/'.($v['instance']??'?').':'.$v['reason'];sort($hit);
    check('window from its own entry: that entry has no verdict, the others do',$hit===['1/3:window-early','2/1:window-late']&&!$res['unconfigurable']);
    $m=$win('@UVWINDOW={"from":"[baseline_arm_1][a_val][1]","window":[21,35]}');$m->redcap_save_record(PID,'1','fa',1,1);
    $hit=[];foreach(invalid($m) as $c)$hit[]=$c[1]['event_id'].'/'.$c[1]['instance'].':'.$c[1]['reason'];sort($hit);
    check('window from its own entry: saving it re-audits the others, never itself',$hit===['1/3:window-early','2/1:window-late']&&!unconf($m));
    $r=ruleOf(render($m,'fa'),'a_val');
    check('window from its own entry: the page names the field itself (the browser reads no anchor)',($r['windowFromOp']??null)===['ref','a_val',null]);

    // @UVRANGE against a growth reference with the sex and the date of birth in
    // another event (2.6.0): the inputs are operands like a @UVWINDOW "from"
    // date, so they take the same resolver, entitlement and snapshot path; a
    // qualified "when" on a plain range rule does too. Neither blocks in the
    // browser.
    $growth=function($ann=null){
        $m=temporal('[a_val]>0');
        REDCap::$dictionary['a_val']['field_annotation']=$ann??'@UVRANGE={"reference":"who-wfa","sex":"[baseline_arm_1][key_b][2]",'
            .'"male":"A","female":"B","age":{"dob":"[baseline_arm_1][b_open][2]","at":"[visit]"},"soft":[-2,2],"hard":[-5,5]}';
        REDCap::$dictionary['a_val']['text_validation_type_or_show_slider_number']='number_1dp';
        REDCap::$dictionary['b_open']['text_validation_type_or_show_slider_number']='date_ymd';
        REDCap::$dictionary['visit']=['field_type'=>'text','form_name'=>'fa','field_annotation'=>'','text_validation_type_or_show_slider_number'=>'date_ymd'];
        REDCap::$data[1]['repeat_instances'][1]['fb'][2]['b_open']='2025-01-10';   // key_b 'A': a boy
        foreach([[1,1,'6.6'],[1,3,'9.6'],[2,1,'4.0']] as list($e,$i,$w)){          // z -3.40, -0.04, -6.59
            REDCap::$data[1]['repeat_instances'][$e]['fa'][$i]['visit']='2026-01-10';
            REDCap::$data[1]['repeat_instances'][$e]['fa'][$i]['a_val']=$w;
        }
        return $m;
    };
    $m=$growth();$p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('growth inputs in another event: no configuration error, not deferred (got '.json_encode([$r['configError']??null,$r['deferredWhy']??null]).')',
        $r&&empty($r['configError'])&&empty($r['deferred']));
    check('growth inputs in another event: saved values as literals, the live date as a ref',($r['rangeSexOp']??null)===['lit','A']
        &&($r['rangeAgeDobOp']??null)===['lit','2025-01-10']&&($r['rangeAgeAtOp']??null)===['ref','visit',null]);
    check('growth inputs in another event: advisory in the browser',($r['blockSave']??null)==='off'&&($r['extendedAdvisory']??null)===true&&!empty($r['snapshotFields']));
    check('growth inputs in another event: the page carries the table',isset($p['cfg']['growth']['who-wfa']));
    $res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'];sort($hit);
    check('growth inputs in another event: the scan judges every entry (got '.json_encode([$hit,$res['unconfigurable']]).')',
        $hit===['1/1:soft-low','2/1:hard-low']&&!$res['unconfigurable']);
    $m=$growth();$m->redcap_save_record(PID,'1','fb',1,null);
    check('growth inputs in another event: saving their form re-audits every host entry',count(invalid($m))===2);
    $m=$growth();REDCap::$data[1]['repeat_instances'][1]['fb'][2]['key_b']='B';$res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'];sort($hit);
    check('growth inputs in another event: a girl is judged on the female rows (6.6 kg: z -2.56)',$hit===['1/1:soft-low','2/1:hard-low']);
    REDCap::$data[1]['repeat_instances'][1]['fa'][1]['a_val']='7.5';$res=$m->scanProject(PID);
    check('... and 7.5 kg is usual for her (z -1.46)',count($res['violations'])===1);
    $m=$growth();unset(REDCap::$data[1]['repeat_instances'][1]['fb'][2]);$res=$m->scanProject(PID);
    check('growth inputs in an entry that does not exist: blank, nothing checked, no problem',!$res['violations']&&!$res['unconfigurable']);
    $m=$growth();REDCap::$rights['nurse']['forms']['fb']='0';$p=render($m,'fa');$r=ruleOf($p,'a_val');
    check('growth inputs on a form the viewer cannot read: withheld, nothing shipped',($r['rangeSexOp']??null)===['withheld']
        &&($r['rangeAgeDobOp']??null)===['withheld']&&strpos($p['raw'],'2025-01-10')===false);
    $m=$growth();$m->projectSettings['enable-event-instance-refs']=false;
    check('growth inputs in another event need the feature',strpos(ruleOf(render($m,'fa'),'a_val')['configError']??'','event and instance references')!==false);
    $m=$growth('@UVRANGE={"hard":[0,10],"when":"[baseline_arm_1][key_b][2]=\'A\'"}');
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='12';
    $r=ruleOf(render($m,'fa'),'a_val');
    // @UVRANGE reads extendedAdvisory, not blockSave (the page-wide default is "off" for every rule)
    check('range with a qualified "when": accepted, advisory in the browser',$r&&empty($r['configError'])&&($r['blockSave']??null)==='off'&&($r['extendedAdvisory']??null)===true);
    $res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'];sort($hit);
    check('range with a qualified "when": the scan judges by it (got '.json_encode($hit).')',$hit===['1/3:hard-high']&&!$res['unconfigurable']);
    REDCap::$data[1]['repeat_instances'][1]['fb'][2]['key_b']='B';$res=$m->scanProject(PID);
    check('... and a false "when" checks nothing',!$res['violations']);
    // Two references on one field (branch groups) on the extended path: each group picks its
    // own branch. Here the fixed-limit group's "when" is false, so only who-wfa judges, and its
    // findings still name it, the rule having two groups.
    $wfa='@UVRANGE={"reference":"who-wfa","sex":"[baseline_arm_1][key_b][2]","male":"A","female":"B",'
        .'"age":{"dob":"[baseline_arm_1][b_open][2]","at":"[visit]"},"soft":[-2,2],"hard":[-5,5]';
    $m=$growth($wfa.'} @UVRANGE={"hard":[5,40],"when":"[visit]<>\'2026-01-10\'"}');$res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'].':'.$v['locus'];sort($hit);
    check('two references, extended: each group picks its branch, findings name the reference (got '.json_encode([$hit,$res['unconfigurable']]).')',
        $hit===['1/1:soft-low:who-wfa','2/1:hard-low:who-wfa']&&!$res['unconfigurable']);
    // with a second group, so the choice is made per group
    $m=$growth($wfa.',"when":"[visit]=\'2026-01-10\'"} '.$wfa.',"when":"[a_val]>0"} @UVRANGE={"hard":[5,40]}');$res=$m->scanProject(PID);
    $why=[];foreach($res['unconfigurable'] as $u)$why[]=$u['why'];
    check('two true branches of one reference, extended: a problem, nothing judged (got '.json_encode([$res['violations'],$why]).')',
        !$res['violations']&&count(array_filter($why,function($w){return strpos($w,'multiple active branches')!==false;}))>=1);
    // One group that cannot be prepared (who-wfh's "by" reads a saved value that cannot be
    // read: a list where a number belongs) is named, and the other group is still judged,
    // in the scan and the audit. A reference that can never resolve, such as a repeating
    // form with no instance, is a configuration error instead (wargame C3).
    $withHt=function($ann)use($growth){
        $m=$growth($ann);
        REDCap::$dictionary['ht']=['field_type'=>'text','form_name'=>'fb','field_annotation'=>'','text_validation_type_or_show_slider_number'=>'number_1dp'];
        REDCap::$data[1]['repeat_instances'][1]['fb'][2]['ht']=['80'];
        return $m;
    };
    $wfh=' @UVRANGE={"reference":"who-wfh","sex":"[baseline_arm_1][key_b][2]","male":"A","female":"B","by":"[baseline_arm_1][ht][2]","hard":[-5,5]}';
    $m=$withHt($wfa.'}'.$wfh);$res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'].':'.$v['locus'];sort($hit);
    $why=[];foreach($res['unconfigurable'] as $u)$why[]=$u['why'];
    check('one group not prepared, extended: the other still judged, that one named (got '.json_encode([$hit,$why]).')',
        $hit===['1/1:soft-low:who-wfa','2/1:hard-low:who-wfa']&&$why
        &&count(array_filter($why,function($w){return strpos($w,'for part of this field: reference "who-wfh": ')!==false;}))===count($why));
    // Saving one entry can change only that entry's verdict: the rule reads its own
    // form in its own row, and the other entries' inputs on fb did not change (wargame P4).
    $m=$withHt($wfa.'}'.$wfh);$m->redcap_save_record(PID,'1','fa',1,1);
    $u=array_map(function($c){return $c[1]['why'];},unconf($m));
    check('... and in the audit of one entry\'s save, at that entry (got '.json_encode([count(invalid($m)),$u]).')',count(invalid($m))===1&&count($u)===1
        &&strpos($u[0],'Extended validation unavailable for part of this field: reference "who-wfh": invalid')===0);
    $m=$withHt($wfa.'}'.$wfh);$m->redcap_save_record(PID,'1','fb',1,null,null,null,2);
    $u=array_map(function($c){return $c[1]['why'];},unconf($m));
    check('... and in the audit of the entry all three read, at each of the three (got '.json_encode([count(invalid($m)),$u]).')',count(invalid($m))===2&&count($u)===3
        &&count(array_filter($u,function($w){return strpos($w,'Extended validation unavailable for part of this field: reference "who-wfh": invalid')===0;}))===3);
    // A selector that cannot be resolved sets aside its own group: here who-wfa's
    // "when" reads the unreadable [baseline_arm_1][ht][2], and the fixed limits judge.
    $sel=$wfa.',"when":"[baseline_arm_1][ht][2]=\'80\'"} @UVRANGE={"hard":[5,40]}';
    $m=$withHt($sel);REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='4.0';$res=$m->scanProject(PID);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.$v['reason'];sort($hit);
    $why=[];foreach($res['unconfigurable'] as $u)$why[]=$u['why'];
    check('an unresolved selector of one group, extended: the fixed limits still judge (got '.json_encode([$hit,$why]).')',
        $hit===['2/1:hard-low']&&$why&&count(array_filter($why,function($w){return strpos($w,'reference "who-wfa": unresolved branch selector')!==false;}))===count($why));
    $r=ruleOf(render($withHt($sel),'fa'),'a_val');
    $marks=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['deferred']),!empty($b['selectorBlocked'])];},$r['branches']??[]):[];
    check('... on the page: that group deferred and named, the other not (got '.json_encode([$marks,$r['deferred']??null]).')',
        $r&&empty($r['deferred'])&&$marks===[['who-wfa',true,true],['',false,false]]);
    check('... its line\'s reason does not name the reference, which the page puts in front (got '.json_encode($r['branches'][0]['deferredWhy']??null).')',
        ($r['branches'][0]['deferredWhy']??null)===['Extended validation unavailable: unresolved branch selector.']);
    // A group that names an event, even one that is this entry, never blocks, as a rule alone does.
    $r=ruleOf(render($withHt($wfa.'} @UVRANGE={"hard":[5,40],"when":"[baseline_arm_1][visit][1]<>\'\'"}'),'fa'),'a_val');
    $adv=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['extendedAdvisory']),$b['blockSave']??null,$b['whenAst'][1][2]??null];},$r['branches']??[]):[];
    check('a group naming this entry\'s event: live, and still advisory (got '.json_encode($adv).')',
        $adv===[['',true,'off',['ref','visit',null]],['who-wfa',true,'off',null]]);
    // Without groups, a stale selector marks every branch, which the page reads (branches do not inherit).
    $r=ruleOf(render($withHt('@UVRANGE={"hard":[0,10],"when":"[baseline_arm_1][key_b][2]=\'B\'"} @UVRANGE={"hard":[0,5]}'),'fa'),'a_val');
    $marks=$r?array_map(function($b){return [$b['snapshotFields']??null,!empty($b['snapshotGate'])];},$r['branches']??[]):[];
    check('one group, a stale selector: each branch carries its snapshot fields (got '.json_encode($marks).')',
        count($marks)===2&&$marks[0]===[['saved event/instance values'],true]&&$marks[1]===[['saved event/instance values'],true]);
    // A selector read when the page was built makes only its own group advisory.
    $r=ruleOf(render($withHt($wfa.',"when":"[baseline_arm_1][key_b][2]=\'A\'"} @UVRANGE={"hard":[5,40]}'),'fa'),'a_val');
    $marks=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['snapshotGate']),$b['snapshotFields']??null];},$r['branches']??[]):[];
    check('a stale selector of one group, on the page: only that group is advisory (got '.json_encode([$marks,$r['snapshotFields']??null]).')',
        $r&&!isset($r['snapshotFields'])&&$marks[0][0]==='who-wfa'&&$marks[0][1]===true&&$marks[1]===['',false,null]);
    $adv=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['extendedAdvisory']),$b['blockSave']??null];},$r['branches']??[]):[];
    check('... and the fixed limits, which read nothing off this page, are not event/instance advisory (got '.json_encode($adv).')',
        $adv===[['who-wfa',true,'off'],['',false,null]]);
    // No selector at all: who-wfa reads saved event/instance inputs and is advisory, the fixed limits are not.
    $r=ruleOf(render($withHt($wfa.'} @UVRANGE={"hard":[5,40]}'),'fa'),'a_val');
    $adv=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['extendedAdvisory']),$b['blockSave']??null];},$r['branches']??[]):[];
    check('two references, one reading event/instance values: only that one is advisory (got '.json_encode($adv).')',
        $adv===[['who-wfa',true,'off'],['',false,null]]);
    // ... and with its inputs withheld from this viewer: it still names another event, so it stays advisory
    $m=$withHt($wfa.'} @UVRANGE={"hard":[5,40]}');REDCap::$rights['nurse']['forms']['fb']='0';$r=ruleOf(render($m,'fa'),'a_val');REDCap::$rights['nurse']['forms']['fb']='1';
    $adv=$r?array_map(function($b){return [$b['rangeReference']??'',!empty($b['extendedAdvisory']),$b['blockSave']??null,$b['rangeSexOp']??null];},$r['branches']??[]):[];
    check('... inputs withheld from this viewer: that reference is advisory, the fixed limits are not (got '.json_encode($adv).')',
        $adv===[['who-wfa',true,'off',['withheld']],['',false,null,null]]);
    // Every group with a problem: no part of the field is judged, and the problems are the rule's.
    $m=$withHt($wfa.',"when":"[baseline_arm_1][ht][2]=\'80\'"}'.$wfh);$res=$m->scanProject(PID);
    $why=[];foreach($res['unconfigurable'] as $u)$why[]=$u['why'];
    check('every group with a problem, extended: the field as a whole is named (got '.json_encode([$res['violations'],$why]).')',
        !$res['violations']&&$why&&count(array_filter($why,function($w){return strpos($w,'part of this field')===false
            &&strpos($w,'reference "who-wfa": unresolved branch selector; reference "who-wfh": ')!==false;}))===count($why));

    // A branched extended rule: each scan finding names the branch that judged
    // it, so the report shows that branch's message and not the first one's.
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    REDCap::$dictionary['a_val']['field_annotation']='@UVASSERT={"assert":"[a_val]<[baseline_arm_1][b_open][2]","when":"[key_a]=\'A\'","message":"under"} '
        .'@UVASSERT={"assert":"[a_val]>[baseline_arm_1][b_open][2]","when":"[key_a]=\'B\'","message":"over"}';
    REDCap::$data[1]['repeat_instances'][1]['fa'][3]['a_val']='5';
    REDCap::$data[1]['repeat_instances'][2]['fa'][1]['a_val']='20';
    $res=$m->scanProject(PID);$dims=$m->scanDimensions(PID,$res['rules']??null);
    $hit=[];foreach($res['violations'] as $v)$hit[]=$v['event_id'].'/'.$v['instance'].':'.(isset($v['branch'])?$dims->rule($v['rule'],$v['branch'])['message']:'none');sort($hit);
    check('branched extended rule: each scan finding names its branch (got '.json_encode($hit).')',$hit===['1/3:over','2/1:under']&&!$res['unconfigurable']);

    $goldenPath=__DIR__.'/temporal_golden.json';
    if(in_array('--update-golden',$argv,true))file_put_contents($goldenPath,json_encode($golden,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    check('deterministic payload audit reads findings and identities',$golden===json_decode(file_get_contents($goldenPath),true));
    echo "temporal_integration_php: $n checks, $fail failures\n";exit($fail?1:0);
}
