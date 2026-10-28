<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/** The gateway objects Stackless registered on this request, by EDD gateway id. */
final class Gateways
{
    /** @var array<string,Gateway> */
    private static $gateways = [];

    public static function add(Gateway $gateway): void
    {
        if (isset(self::$gateways[$gateway->id()])) {
            return;
        }
        self::$gateways[$gateway->id()] = $gateway;
        $gateway->register();
    }

    public static function get(string $id): ?Gateway
    {
        return self::$gateways[$id] ?? null;
    }

    /** @return array<string,Gateway> */
    public static function all(): array
    {
        return self::$gateways;
    }
}
