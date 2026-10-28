<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

final class Logger
{
    private const ALWAYS = ['emergency', 'alert', 'critical', 'error', 'warning'];

    /**
     * Writes to the EDD debug log (Downloads → Tools → Debug Log) as "[stackless-edd-{channel}] LEVEL message".
     * Warnings and errors are always written; info and debug only while EDD debug mode is on.
     * Never log secrets or card data.
     */
    public static function log(string $channel, string $message, string $level = 'info', array $context = []): void
    {
        if (!function_exists('edd_debug_log')) {
            return;
        }
        $level = strtolower($level);
        $line = sprintf('[stackless-edd-%s] %s %s', sanitize_key($channel), strtoupper($level), $message);
        if ($context) {
            $line .= ' ' . wp_json_encode($context);
        }
        edd_debug_log($line, in_array($level, self::ALWAYS, true));
    }
}
