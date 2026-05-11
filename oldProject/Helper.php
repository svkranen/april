<?php

class Helper
{
    public static function log($message): void
    {
        $baseDir = dirname(__DIR__);
        $logDir = $baseDir.'/var/log';
        $logFile = $logDir.'/oldproject_debug.txt';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        $line = date('d.m.Y H:i:s').'|'.$message."\n";
        if (@file_put_contents($logFile, $line, FILE_APPEND) === false) {
            $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'oldproject_debug.txt';
            @file_put_contents($fallback, $line, FILE_APPEND);
        }
    }
}
