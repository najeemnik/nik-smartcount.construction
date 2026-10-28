<?php
/** Run only on a fresh installation: php api/.secrets/create-install-key.php */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (is_file(__DIR__.'/installed.lock')) { fwrite(STDERR,"Installation is already sealed.\n"); exit(1); }
$file=__DIR__.'/install.key';
$previous=umask(0077);
$handle=fopen($file,'x');
umask($previous);
if(!$handle){fwrite(STDERR,"Key already exists or directory is not writable. Existing key preserved.\n");exit(1);}
$key='NSC-'.bin2hex(random_bytes(24));
if(fwrite($handle,$key."\n")!==strlen($key)+1){fclose($handle);fwrite(STDERR,"Key write failed.\n");exit(1);}
fclose($handle);
fwrite(STDOUT,"New private installer key: ".$key."\nKeep it outside the repository.\n");
