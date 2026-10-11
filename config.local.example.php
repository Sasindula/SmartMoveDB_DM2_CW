<?php
// Copy to config.local.php and enter YOUR local Oracle credentials.
// config.local.php is ignored by Git. Never commit real passwords.
return [
    'oracle_dsn' => 'oci:dbname=//localhost:1521/XEPDB1;charset=AL32UTF8',
    'oracle_user' => 'SMARTMOVE',
    'oracle_password' => '',
    'mongo_uri' => 'mongodb://localhost:27017',
];
