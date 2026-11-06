<?php
declare(strict_types=1);
// Execute only the embedded configuration writer, never the VPS setup shell.
$source=file_get_contents(__DIR__.'/../tools/vps/setup-test-site.sh');
$start=strpos($source,"  ISMILE_PSOOLA_CONFIG=\"\$PSOOLA_CONFIG\" php -r '");
$end=$start===false?false:strpos($source,"\n  ' \"\$BACKEND/config.php\"",$start);
if($start===false||$end===false)throw new RuntimeException('Configuration writer not found.');
$start=strpos($source,"\n",$start)+1;
$folder=sys_get_temp_dir().'/ismile-psoola-deploy-'.bin2hex(random_bytes(6));mkdir($folder,0700);
$writer=$folder.'/writer.php';$config=$folder.'/config.php';
file_put_contents($writer,"<?php\n".substr($source,$start,$end-$start));
$fixture=['api_base'=>'https://stage-actions.psoola.com','api_key'=>'local-fixture-only','webhook_secret'=>'local-webhook-only',
    'event_id'=>'event','show_id'=>'show','ticket_types'=>['professional'=>'p','student'=>'s','vip'=>'v'],
    'add_ons'=>['lunchDay1'=>'d1','lunchDay2'=>'d2']];
$passed=0;
try {
    foreach([
        'configured partner enables staff guest-ticket flow by default'=>[[],true,true],
        'portal full-prefix base accepted'=>[['api_base'=>'https://stage-actions.psoola.com/api/partner/v1/'],true,true],
        'explicit complimentary opt-in survives deployment'=>[['complimentary_enabled'=>true],true,true],
        'explicit disable survives deployment'=>[['complimentary_enabled'=>false],true,false],
        'string opt-in is rejected'=>[['complimentary_enabled'=>'true'],false,false],
        'wrong API prefix is rejected'=>[['api_base'=>'https://stage-actions.psoola.com/api/other'],false,false],
        'insecure remote API rejected'=>[['api_base'=>'http://stage-actions.psoola.com'],false,false],
    ] as $name=>[$changes,$valid,$enabled]) {
        $original="<?php return ['payments'=>['gateway'=>'fake'],'preserved'=>'yes'];\n";file_put_contents($config,$original);
        $env=getenv();$env['ISMILE_PSOOLA_CONFIG']=json_encode(array_replace($fixture,$changes),JSON_THROW_ON_ERROR);
        $process=proc_open([PHP_BINARY,$writer,$config],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('Could not run fixture writer.');fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
        $stored=include $config;
        $ok=$valid?($exit===0&&$stored['preserved']==='yes'&&$stored['payments']['psoola']['api_base']===rtrim(($changes+$fixture)['api_base'],'/')&&$stored['payments']['psoola']['complimentary_enabled']===$enabled):($exit!==0&&file_get_contents($config)===$original);
        if(!$ok||str_contains($out,'local-fixture-only')||str_contains($out,'local-webhook-only'))throw new RuntimeException('FAILED: '.$name);
        echo 'PASS: '.$name."\n";$passed++;
    }
} finally {unlink($writer);unlink($config);rmdir($folder);}
echo "$passed deployment configuration checks passed.\n";
