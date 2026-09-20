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

    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');
    $m->projectSettings['qualified-audit-max-contexts']=1;$m->redcap_save_record(PID,'1','fb',1,null);
    check('audit truncation explicit',saying(array_column(unconf($m),1),'why','context limit')===1);
    $m=temporal('[a_val]<[baseline_arm_1][b_open][2]');REDCap::$getDataMode='throw';
    $m->redcap_save_record(PID,'1','fb',1,null);
    check('failed audit read incomplete',saying(array_column(unconf($m),1),'why','incomplete')===1);
    check('failed audit read never violation',!invalid($m));
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
    $rule=['type'=>'unique','fields'=>['a_val'],'branches'=>[
        ['type'=>'unique','uniqueScope'=>'record','when'=>'[baseline_arm_1][b_open][2]>0'],
        ['type'=>'unique','uniqueScope'=>'record','when'=>null]]];
    $pr=$prepared->invoke($m,$rule,$shape,REDCap::$data[1],$context,true,function(){return true;});
    check('record unique branches preserve mode',$pr['rule']['type']==='unique'&&!$pr['problems']);
    check('record unique branch retains condition',$pr['rule']['branches'][0]['whenAst'][0]==='temporal');
    $off=$m->validateSettings(['enable-event-instance-refs'=>false,'rules'=>[1],'rule-type'=>['constraint'],'fields-csv'=>['a_val'],'assert'=>['[b_open][2]>0']]);
    check('disabling preserves authored rules',$off===null);
    $m=temporal('[a_val]>0');$m->projectSettings['enable-event-instance-refs']=false;$before=render($m,'fa')['raw'];
    $m->projectSettings['enable-event-instance-refs']=true;$after=render($m,'fa')['raw'];
    check('enabled legacy-only browser payload identical',$before===$after);

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

    $goldenPath=__DIR__.'/temporal_golden.json';
    if(in_array('--update-golden',$argv,true))file_put_contents($goldenPath,json_encode($golden,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
    check('deterministic payload audit reads findings and identities',$golden===json_decode(file_get_contents($goldenPath),true));
    echo "temporal_integration_php: $n checks, $fail failures\n";exit($fail?1:0);
}
