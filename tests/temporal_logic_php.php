<?php
require_once __DIR__.'/../php/TemporalLogic.php';
use INSPIRE\UniversalValidator\TemporalLogic;
$n=0;
foreach(json_decode(file_get_contents(__DIR__.'/temporal_fixture.json'),true) as $row){
    $actual=TemporalLogic::evaluate($row['ast'],function($field)use($row){return $row['values'][$field]??'';});
    if($actual!==$row['expected'])throw new RuntimeException($row['name'].': '.json_encode($actual));$n++;
}
echo "temporal_logic_php: $n checks, 0 failures\n";
