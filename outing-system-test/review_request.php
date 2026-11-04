<?php

// Compatibility entry point. All authentication, CSRF and actions run in Laravel.
$_SERVER['SCRIPT_NAME'] = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/').'/index.php';
require dirname(__DIR__).'/public/index.php';
