<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/** Registry of the emails enabled modules send. Listed on Stackless → Emails. */
final class Emails
{
    /** @var array<string,Email> */
    private static $emails = [];

    public static function register(Email $email): void
    {
        self::$emails[$email->id()] = $email;
    }

    public static function get(string $id): ?Email
    {
        return self::$emails[$id] ?? null;
    }

    /** @return array<string,Email> */
    public static function all(): array
    {
        return (array) apply_filters('stackless_edd_emails', self::$emails);
    }

    /** Send a registered email by id; false when it is unknown or switched off. */
    public static function send(string $id, string $to, array $args = [], array $placeholders = [], array $attachments = []): bool
    {
        $email = self::get($id);
        return $email ? $email->send($to, $args, $placeholders, $attachments) : false;
    }
}
