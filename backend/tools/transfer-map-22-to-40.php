<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--apply'||!preg_match('/^SPN26-[A-Z0-9]+$/D',$argv[2]??'')){fwrite(STDERR,"Usage: php transfer-map-22-to-40.php --apply SPN26-REFERENCE\n");exit(1);}
require __DIR__.'/../bootstrap.php';
echo json_encode(\Ismile\Map40Upgrade::transfer($argv[2]),JSON_THROW_ON_ERROR).PHP_EOL;
