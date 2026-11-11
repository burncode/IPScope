<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/lib/LanScanResultStore.php';
use IPScope\LanScanResultStore;
$dir = sys_get_temp_dir() . '/ipscope-lan-results-' . getmypid();
$path = $dir . '/latest.json';
$store = new LanScanResultStore($path);
$task = ['id'=>str_repeat('a',24),'status'=>'completed','interface'=>'bond0','completed_at'=>'2026-11-11T08:00:00+08:00','target_count'=>2,'counts'=>['responding'=>1,'no_response'=>1],'results'=>[
 ['ip'=>'192.168.50.1','status'=>'responding','mac'=>'aa:bb:cc:dd:ee:01'],
 ['ip'=>'192.168.50.2','status'=>'no_response','mac'=>null],
]];
$saved=$store->save($task);
$loaded=$store->load();
$checks=[
 is_dir($dir),
 $saved['availability_inferred']===false,
 $loaded['task_id']===$task['id'],
 $loaded['interface']==='bond0',
 count($loaded['results'])===2,
 $loaded['results'][1]['status']==='no_response',
];
$rejected=false;
try{$store->save(array_merge($task,['status'=>'running']));}catch(InvalidArgumentException $e){$rejected=true;}
$checks[]=$rejected;
foreach($checks as $i=>$ok) if(!$ok){fwrite(STDERR,'LAN scan result store test failed at assertion '.($i+1).PHP_EOL);exit(1);}
foreach(glob($dir.'/*')?:[] as $file)@unlink($file);@rmdir($dir);
echo 'LAN scan result store tests passed ('.count($checks).' assertions).'.PHP_EOL;
