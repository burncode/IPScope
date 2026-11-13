<?php
declare(strict_types=1);
require_once __DIR__.'/../src/lib/IPv4Calculator.php';
require_once __DIR__.'/../src/lib/AddressExclusionStore.php';
require_once __DIR__.'/../src/lib/AddressInventory.php';
use IPScope\AddressExclusionStore;
use IPScope\AddressInventory;
$path=sys_get_temp_dir().'/ipscope-exclusions-'.getmypid().'/exclusions.json';
$store=new AddressExclusionStore($path); $checks=[];
$checks[]=$store->load()===[];
$rules=$store->save([
 ['network'=>'bond0','match_type'=>'ip','value'=>'192.168.50.20','reason'=>'NAS management'],
 ['network'=>'*','match_type'=>'cidr','value'=>'192.168.50.200/30','reason'=>'DHCP pool edge'],
 ['network'=>'bond0','match_type'=>'ip','value'=>'192.168.50.21','reason'=>'Disabled sample','enabled'=>false],
]);
$checks[]=count($rules)===3; $checks[]=count($store->load())===3; $checks[]=strlen($rules[0]['id'])===16;
$bad=false; try{$store->save([['network'=>'bond0','match_type'=>'ip','value'=>'999.1.1.1','reason'=>'bad']]);}catch(InvalidArgumentException $e){$bad=true;} $checks[]=$bad;
$networks=[['name'=>'bond0','ipv4'=>[['subnet'=>'192.168.50.0/24','gateway'=>'192.168.50.1','auxiliary_addresses'=>[]]]]];
$containers=[['name'=>'running-app','state'=>'running','running'=>true,'networks'=>['bond0'=>['ipv4'=>'192.168.50.22','ipam_ipv4'=>null]]]];
$result=(new AddressInventory())->build($networks,$containers,[],$store->load()); $map=[];
foreach($result['networks'][0]['known_addresses'] as $a)$map[$a['ip']]=$a;
$checks[]=($map['192.168.50.20']['status']??null)==='excluded';
$checks[]=($map['192.168.50.201']['status']??null)==='excluded';
$checks[]=($map['192.168.50.202']['status']??null)==='excluded';
$checks[]=($map['192.168.50.21']['status']??null)==='available';
$checks[]=($map['192.168.50.22']['status']??null)==='occupied';
$checks[]=count(array_filter($result['conflicts'],static fn($c)=>$c['type']==='excluded_address_in_use'))===0;
$rules=$store->save([['network'=>'bond0','match_type'=>'ip','value'=>'192.168.50.22','reason'=>'Do not assign']]);
$collision=(new AddressInventory())->build($networks,$containers,[],$rules);
$checks[]=($collision['networks'][0]['known_addresses'][22]['status']??null)!=='excluded';
$checks[]=count(array_filter($collision['conflicts'],static fn($c)=>$c['type']==='excluded_address_in_use'&&$c['ip']==='192.168.50.22'))===1;
foreach($checks as $i=>$ok)if(!$ok){fwrite(STDERR,'Address exclusion test failed at assertion '.($i+1).PHP_EOL);exit(1);}
@unlink($path); @rmdir(dirname($path));
echo 'Address exclusion tests passed ('.count($checks).' assertions).'.PHP_EOL;
