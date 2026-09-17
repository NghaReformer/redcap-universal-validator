<?php
namespace INSPIRE\UniversalValidator;
require_once __DIR__.'/ProjectShape.php';

/** REDCap adapter. Each component remains unknown unless its source succeeds. */
final class TemporalMetadata
{
    public static function load($pid,array $dictionary)
    {
        $project=null;
        if(isset($GLOBALS['Proj']) && is_object($GLOBALS['Proj']) && isset($GLOBALS['Proj']->project_id)
            && (string)$GLOBALS['Proj']->project_id===(string)$pid)$project=$GLOBALS['Proj'];
        if($project===null && class_exists('\\Project')){try{$project=new \Project($pid);}catch(\Throwable $e){}}
        $names=null;$mapping=null;$repeats=null;
        try{if($project && is_callable([$project,'getUniqueEventNames']))$names=$project->getUniqueEventNames();}catch(\Throwable $e){}
        try{if(is_callable(['\\REDCap','getInstrumentEventMappings']))$mapping=\REDCap::getInstrumentEventMappings($pid);}catch(\Throwable $e){}
        try{if($project && is_callable([$project,'getRepeatingFormsEvents']))$repeats=$project->getRepeatingFormsEvents();
            elseif(is_callable(['\\REDCap','getRepeatingFormsEvents']))$repeats=\REDCap::getRepeatingFormsEvents($pid);}catch(\Throwable $e){}
        if(!is_array($names))$names=[];
        $info=$project && isset($project->eventInfo)&&is_array($project->eventInfo)?$project->eventInfo:[];
        foreach($info as $id=>$row)if(!isset($names[$id])&&isset($row['unique_event_name']))$names[$id]=$row['unique_event_name'];
        $fields=[];foreach($dictionary as $f=>$meta)$fields[$f]=['form'=>$meta['form_name']??null,'type'=>$meta['field_type']??null,'validation'=>$meta['text_validation_type_or_show_slider_number']??''];
        $events=[];
        foreach($names as $id=>$name){
            $row=$info[$id]??[];$forms=null;
            if(is_array($mapping)){
                $forms=[];
                foreach($mapping as $m){if(!is_array($m))continue;$match=(isset($m['event_id'])&&(string)$m['event_id']===(string)$id)||(isset($m['unique_event_name'])&&$m['unique_event_name']===$name);
                    if($match && isset($m['form']))$forms[]=(string)$m['form'];}
            }
            if($project && isset($project->eventsForms[$id])&&is_array($project->eventsForms[$id]))$forms=array_values($project->eventsForms[$id]);
            if($project && isset($project->longitudinal) && !$project->longitudinal)$forms=array_values(array_unique(array_column($fields,'form')));
            $eventRepeats=null;$repeatForms=null;
            if(is_array($repeats)){
                $r=$repeats[$id]??[];
                if($r==='WHOLE'){$eventRepeats=true;$repeatForms=[];}
                elseif(is_array($r)){
                    $eventRepeats=false;$repeatForms=[];$knownForms=array_column($fields,'form');
                    foreach($r as $key=>$value){
                        $form=is_int($key)?$value:$key;
                        if($form===''||$form==='WHOLE'){$eventRepeats=true;continue;}
                        if(!is_string($form)||!in_array($form,$knownForms,true)){$eventRepeats=null;$repeatForms=null;break;}
                        $repeatForms[]=$form;
                    }
                }
            }
            if($project && is_callable([$project,'isRepeatingEvent'])){try{$eventRepeats=(bool)$project->isRepeatingEvent($id);}catch(\Throwable $e){}}
            $events[$id]=['name'=>(string)$name,'arm'=>$row['arm_num']??($row['arm_id']??null),
                // Native eventInfo is ordered by REDCap; names alone prove no ordering.
                'order'=>array_key_exists($id,$info)?array_search($id,array_keys($info),true):null,
                'forms'=>$forms,'repeats'=>$repeatForms,'eventRepeats'=>$eventRepeats];
        }
        return new ProjectShape($events,$fields,$dictionary!==[]);
    }
}
