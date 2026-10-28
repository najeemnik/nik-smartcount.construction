<?php
require_once __DIR__.'/api/config.php';
header('Cache-Control: no-store');header('Location: '.(DB_NAME===''?'/installer.php':'/workspace.php'),true,302);exit;
