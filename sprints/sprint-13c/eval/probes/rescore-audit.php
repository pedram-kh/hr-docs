<?php
require '/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-backend/vendor/autoload.php';
$app = require '/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Agent\Rules\GeneralLanePostCheck as P;
$dir='/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-docs/sprints/sprint-13c/eval/results/';
$sets=['S2x3 new prompt (s2d+s2e)'=>['s2d-lane-forced-raw.log','s2e-lane-forced-raw.log'],'held-out'=>['ho-lane-forced-raw.log'],'S2c x1 (prev)'=>['s2c-lane-forced-raw.log'],'S1c positives'=>['s1c-lane-forced-raw.log'],'old S2x3 (old prompt)'=>['s2-lane-forced-raw.log'],'S1b'=>['s1b-lane-forced-raw.log']];
foreach ($sets as $name=>$files){ $n=0;$byp=0;$fig=0;$changed=[];$remain=[];
 foreach($files as $f) foreach(file($dir.$f) as $l){ if(!str_starts_with($l,'LANE '))continue; $r=json_decode(substr($l,5),true); $n++;
  if(!in_array($r['verdict'],['passed_clean','AUDIT_BYPASS'])||empty($r['answer']))continue;
  if(P::scan($r['answer'])!==null) continue; // now blocked by E2 -> counted as blocked (not bypass)
  $a=P::audit($r['answer']); if($a){ $byp++; $remain[]=[$r['id'],$a]; if(array_intersect($a,['digit','spelled_number']))$fig++; }
  if($r['verdict']==='AUDIT_BYPASS' && !$a) $changed[]=$r['id'];
 }
 // also rows previously bypass that are now scan-blocked
 $nowBlocked=[];foreach($files as $f) foreach(file($dir.$f) as $l){ if(!str_starts_with($l,'LANE '))continue; $r=json_decode(substr($l,5),true); if($r['verdict']==='AUDIT_BYPASS'&&P::scan($r['answer'])!==null)$nowBlocked[]=$r['id'];}
 echo "$name: drafts=$n bypass_now=$byp figures_leaked=$fig bypass_cleared_by_audit_align=".json_encode($changed)." bypass_cleared_by_E2=".json_encode($nowBlocked)." remaining=".json_encode($remain)."\n";
}
