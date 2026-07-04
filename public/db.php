<?php

$_MY_SERV = getenv('DB_HOST')        ?: 'db';          // Docker service name
$_MY_DB   = getenv('MYSQL_DATABASE') ?: 'shaye067_phsa_db';
$_MY_USER = getenv('MYSQL_USER')     ?: 'comet_app';
$_MY_PASS = trim(file_get_contents('/run/secrets/comet_app_pw'));

?>