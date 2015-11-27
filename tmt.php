#!/usr/bin/php
<?php

if (version_compare(PHP_VERSION, '5.4.0', '<')) {
    echo 'WE NEED AT LEAST PHP 5.4.0' . PHP_EOL;
    exit(101);
}

$needed_extensions = array('json');
$missing_extensions = array();
foreach ($needed_extensions as $needed_extension) {
    if (!extension_loaded($needed_extension)) {
        $missing_extensions[] = $needed_extension;
    }
}
if (count($missing_extensions) > 0) {
    echo 'This software needs the following extensions, please install/enable them: ' . implode(', ', $missing_extensions) . PHP_EOL;
    exit(102);
}

function __autoload($class_name) {
    require_once(str_replace('TMT', 'src', $class_name) . '.php');
}

date_default_timezone_set('Europe/Berlin');

$oo = new \TMT\OvalOffice();
$oo->loop();
