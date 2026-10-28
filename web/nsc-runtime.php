<?php
ini_set('zlib.output_compression','0');
header('X-Content-Type-Options: nosniff');
if(($_GET['asset']??'')!=='accounting'){http_response_code(404);exit;}
header('Content-Type: text/javascript; charset=UTF-8');header('Cache-Control: public, max-age=3600');header('Vary: Accept-Encoding');
$file=__DIR__.'/nsc-accounting-r3.js.gz';
if(!is_file($file)){http_response_code(503);echo 'throw new Error("Accounting asset missing; extract every file from the update.");';exit;}
if(strpos($_SERVER['HTTP_ACCEPT_ENCODING']??'','gzip')!==false){header('Content-Encoding: gzip');header('Content-Length: '.filesize($file));readfile($file);}else{echo gzdecode(file_get_contents($file));}
