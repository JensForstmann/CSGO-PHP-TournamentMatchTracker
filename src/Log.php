<?php

namespace TMT;

/**
 * Class Log
 * Provides static functions to write stuff into the logfile.
 */
class Log {
    /**
     * @var resource
     */
    private static $fp;

    /**
     * Writes a single line into the logfile.
     * If not already done, the file is opened before writing.
     * A timestamp will be preceded.
     * @param string $string
     */
    private static function writeLine($string) {
        if (!is_resource(self::$fp)) {
            self::$fp = fopen('tmt.log', 'a');
        }
        fwrite(self::$fp, date('Y-m-d H:i:s') . ' | ' . $string . "\n");
        echo date('Y-m-d H:i:s') . ' | ' . $string . PHP_EOL;
    }

    /**
     * Writes a warning into the log.
     * @param string $message
     */
    public static function warning($message) {
        self::writeLine('WARN   | ' . $message);
    }

    /**
     * Writes a debug message into the log.
     * @param string $message
     */
    public static function debug($message) {
        self::writeLine('DEBUG  | ' . $message);
    }

    /**
     * Writes a info message into the log.
     * @param string $message
     */
    public static function info($message) {
        self::writeLine('INFO   | ' . $message);
    }

    /**
     * Writes an error into the log.
     * @param string $message
     */
    public static function error($message) {
        self::writeLine('ERROR  | ' . $message);
    }

    /**
     * Writes a notice into the log.
     * @param string $message
     */
    public static function notice($message) {
        self::writeLine('NOTICE | ' . $message);
    }
}
