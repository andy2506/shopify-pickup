<?php
/**
 * Logger that writes structured lines to a log file
*/

class Logger
{
    private static array $levels = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
    ];

    /**
     * Write a log entry.
     *
     * @param string       $level   DEBUG | INFO | WARNING | ERROR
     * @param string       $message Human-readable message
     * @param array<mixed> $context Extra key→value data to serialise as JSON
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $configLevel = defined('LOG_LEVEL') ? LOG_LEVEL : 'DEBUG';

        // Skip entries below the configured minimum level
        if ((self::$levels[$level] ?? 0) < (self::$levels[$configLevel] ?? 0)) {
            return;
        }

        $logFile = defined('LOG_FILE') ? LOG_FILE : __DIR__ . '/logs/webhook.log';
        $logDir  = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s T');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
        $entry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    // Convenience wrappers ---------------------------------------------------

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }
}
