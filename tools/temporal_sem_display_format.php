<?php
/**
 * REPRO: live page values of date_dmy / date_mdy fields are in DISPLAY order, while every
 * off-page snapshot the server ships is the SAVED Y-M-D string. Every untyped comparison that
 * mixes the two gives a browser verdict different from the audit/scan verdict for the same data.
 *   A. the documented example  [visit_date]>=[previous-event-name][visit_date]
 *   B. @UVUNIQUE=record on a date_dmy field (duplicate missed in the browser)
 *   C. a match binding keyed on a date_dmy field (guard never matches: always "save and reload")
 * Run: php tools/temporal_sem_display_format.php
 */
namespace {
require_once __DIR__ . '/temporal_sem_harness.php';
require_once __DIR__ . '/temporal_sem_lib.php';

$eventInfo = [1=>['unique_event_name'=>'base_arm_1','arm_num'=>1], 2=>['unique_event_name'=>'fu_arm_1','arm_num'=>1]];
$maps = [['event_id'=>1,'form'=>'visit'],['event_id'=>2,'form'=>'visit'],['event_id'=>1,'form'=>'spec'],['event_id'=>1,'form'=>'res']];
$eventsForms = [1=>['visit','spec','res'],2=>['visit']];
$dmy = function ($ymd) { return substr($ymd,8,2).'-'.substr($ymd,5,2).'-'.substr($ymd,0,4); };

// ---- A: documented example, date_dmy -------------------------------------------------------
$dict = sem_dict(['record_id'=>['visit'],
    'visit_date'=>['visit','text','date_dmy','@UVASSERT={"assert":"[visit_date]>=[previous-event-name][visit_date]","message":"Visit is dated before the previous visit"}']]);
$data = ['1'=>[1=>['record_id'=>'1','visit_date'=>'2023-12-31','visit_complete'=>'2'], 2=>['record_id'=>'1','visit_date'=>'2024-01-05','visit_complete'=>'2']]];
$m = sem_module($dict, $data, [1=>[],2=>[]], $maps, $eventInfo, $eventsForms);
$rule = sem_rule_of(sem_render($m, 'visit', '1', 2, 1), 'visit_date');
echo 'A page AST: ' . json_encode($rule['assertAst']) . "\n";
$browser = sem_js_eval($rule['assertAst'], ['visit_date'=>$dmy('2024-01-05')], true);   // REDCap shows 05-01-2024
$m->redcap_save_record(SEM_PID, '1', 'visit', 2, null, null, null, 1);
$audit = count(sem_logs($m, 'invalid-id-saved'));
sem_expect('A browser verdict (true = passes) for 05-01-2024 after 2023-12-31', $browser, true);
sem_expect('A audit violations for the same saved data', $audit, 0);
// control: the audit path is live (an earlier follow-up date IS reported)
$data['1'][2]['visit_date'] = '2023-12-01';
$m = sem_module($dict, $data, [1=>[],2=>[]], $maps, $eventInfo, $eventsForms);
$m->redcap_save_record(SEM_PID, '1', 'visit', 2, null, null, null, 1);
sem_expect('A control: audit reports a real violation', count(sem_logs($m, 'invalid-id-saved')), 1);

// ---- B: record uniqueness on a date_dmy field ------------------------------------------------
$dict = sem_dict(['record_id'=>['visit'], 'sdate'=>['spec','text','date_dmy','@UVUNIQUE=record']]);
$data = ['1'=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>['spec'=>[
    1=>['sdate'=>'2024-01-05','spec_complete'=>'2'], 2=>['sdate'=>'2024-01-05','spec_complete'=>'2']]]]]];
$m = sem_module($dict, $data, [1=>['spec'=>'']], $maps, $eventInfo, $eventsForms);
$rule = sem_rule_of(sem_render($m, 'spec', '1', 1, 2), 'sdate');
$browser = sem_js_eval($rule['uniqueRecordAsts']['sdate'], ['sdate'=>$dmy('2024-01-05')], true);
$res = $m->scanProject(SEM_PID);
sem_expect('B browser: instance 2 duplicate of instance 1 (false = duplicate flagged)', $browser, false);
sem_expect('B scan: both entries reported', count($res['violations']), 2);

// ---- C: match keyed on a date_dmy field ------------------------------------------------------
$dict = sem_dict(['record_id'=>['visit'], 'spec_date'=>['spec','text','date_dmy'], 'thr'=>['spec','text','number'],
    'res_date'=>['res','text','date_dmy'],
    'res_val'=>['res','text','number','@UVASSERT={"assert":"[res_val]>={t}","references":{"t":{"field":"thr","match":{"spec_date":"[res_date]"}}}}']]);
$data = ['1'=>[1=>['record_id'=>'1'], 'repeat_instances'=>[1=>[
    'spec'=>[1=>['spec_date'=>'2024-01-05','thr'=>'10','spec_complete'=>'2']],
    'res'=>[1=>['res_date'=>'2024-01-05','res_val'=>'5','res_complete'=>'2']]]]]];
$m = sem_module($dict, $data, [1=>['spec'=>'','res'=>'']], $maps, $eventInfo, $eventsForms);
$rule = sem_rule_of(sem_render($m, 'res', '1', 1, 1), 'res_val');
$browser = sem_js_eval($rule['assertAst'], ['res_val'=>'5','res_date'=>$dmy('2024-01-05')], true);
$res = $m->scanProject(SEM_PID);
sem_expect('C browser verdict with the unchanged key (false = violation shown)', $browser, false);
sem_expect('C scan violations', count($res['violations']), 1);
sem_done('temporal_sem_display_format');
}
