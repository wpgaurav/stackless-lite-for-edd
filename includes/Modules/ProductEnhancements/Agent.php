<?php

namespace StacklessEDD\Modules\ProductEnhancements;

use StacklessEDD\Core\Agent as Dispatcher;

defined('ABSPATH') || exit;

/** product.details.* commands for stackless_edd_agent(): read and edit a download's details table. */
final class Agent
{
    public function register(): void
    {
        add_filter('stackless_edd_agent_command', [$this, 'handle'], 10, 3);
        add_filter('stackless_edd_agent_commands', [$this, 'describe']);
    }

    /** @param array<string,string> $commands */
    public function describe($commands): array
    {
        return (array) $commands + [
            'product.details.get' => 'A download\'s details table (product_id = download id): preset, field values, per-price values, hidden fields, custom rows and what shoppers see.',
            'product.details.set' => 'Merge values (by field key), preset, show, hidden and custom rows into a download\'s details (dry_run validates only).',
        ];
    }

    /**
     * @param mixed $result
     * @param mixed $command
     * @param mixed $input
     * @return mixed
     */
    public function handle($result, $command, $input)
    {
        if ($result !== null || !in_array($command, ['product.details.get', 'product.details.set'], true)) {
            return $result;
        }
        $input = is_array($input) ? $input : [];
        return Dispatcher::run(function () use ($command, $input) {
            $downloadId = self::download($input);
            if ($command === 'product.details.get') {
                if (!current_user_can('edit_product', $downloadId)) {
                    throw new \RuntimeException('This WordPress user cannot edit this download.');
                }
                return self::details($downloadId);
            }
            return self::set($downloadId, $input);
        });
    }

    /** The download id from product_id (or download_id). */
    private static function download(array $input): int
    {
        $key = isset($input['product_id']) ? 'product_id' : 'download_id';
        $id = Dispatcher::id($input, $key);
        if (get_post_type($id) !== 'download') {
            throw new \RuntimeException('Download not found. Pass the id of an EDD download.');
        }
        return $id;
    }

    public static function details(int $downloadId): array
    {
        $stored = Repository::get($downloadId);
        $presets = Fields::presets();
        $fields = Fields::all();
        $prices = [];
        foreach (Repository::priceIds($downloadId) as $priceId) {
            $values = Repository::priceValues($downloadId, $priceId);
            if ($values) {
                $prices[$priceId] = $values;
            }
        }
        return [
            'product_id' => $downloadId,
            'title' => get_the_title($downloadId),
            'saved' => $stored['saved'],
            'show' => $stored['show'],
            'preset' => $stored['preset'],
            'presets' => array_keys($presets),
            'preset_fields' => array_map(static function ($key) use ($fields) {
                return ['key' => $key, 'label' => (string) ($fields[$key]['label'] ?? $key)];
            }, (array) ($presets[$stored['preset']]['fields'] ?? [])),
            'values' => $stored['values'],
            'price_values' => $prices,
            'hidden' => $stored['hidden'],
            'custom' => $stored['custom'],
            'shown' => array_map(static function ($row) {
                return ['key' => (string) $row['key'], 'label' => (string) $row['label'], 'value' => (string) $row['value']];
            }, Repository::rows($downloadId)),
        ];
    }

    private static function set(int $downloadId, array $input): array
    {
        if (!current_user_can('edit_product', $downloadId)) {
            throw new \RuntimeException('This WordPress user cannot edit this download.');
        }
        $current = Repository::get($downloadId);
        $custom = array_key_exists('custom', $input) ? (array) $input['custom'] : $current['custom'];
        $next = [
            'preset' => Dispatcher::text($input, 'preset', 40) ?: $current['preset'],
            'show' => array_key_exists('show', $input) ? !empty($input['show']) && $input['show'] !== 'false' : ($current['saved'] ? $current['show'] : true),
            'values' => array_merge($current['values'], array_map('strval', array_filter((array) ($input['values'] ?? []), 'is_scalar'))),
            'hidden' => array_key_exists('hidden', $input) ? array_map('strval', (array) $input['hidden']) : $current['hidden'],
            'custom' => Repository::customText(array_values(array_filter(array_map(static function ($row) {
                return is_array($row) && isset($row['label'], $row['value']) ? ['label' => (string) $row['label'], 'value' => (string) $row['value']] : null;
            }, $custom)))),
        ];
        [$data, $errors] = Repository::validate($downloadId, $next);
        if ($errors) {
            throw new \InvalidArgumentException(esc_html('Fix these fields: ' . implode('; ', $errors)));
        }
        if (Dispatcher::dryRun($input)) {
            return ['dry_run' => true, 'details' => $data];
        }
        update_post_meta($downloadId, Repository::META, $data);
        AutoFill::flush($downloadId);
        return self::details($downloadId);
    }
}
