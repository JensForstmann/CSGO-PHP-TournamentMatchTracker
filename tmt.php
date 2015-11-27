#!/usr/bin/php
<?php

// check php version
if (version_compare(PHP_VERSION, '5.4.0', '<')) {
    echo 'WE NEED AT LEAST PHP 5.4.0' . PHP_EOL;
    exit(101);
}

// check php extensions
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

// define class autoload method
function __autoload($class_name) {
    $parts = array_slice(explode('\\', $class_name), 1);
    require_once('src/' . implode('/', $parts) . '.php');
}

// suppress php's warning of relying on the default timezone bla bla
date_default_timezone_set('Europe/Berlin');

// run the actual program
$tmt = new \TMT\TournamentMatchTracker();
$tmt->loop();
