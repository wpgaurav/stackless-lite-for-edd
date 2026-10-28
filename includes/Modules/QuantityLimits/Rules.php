<?php

namespace StacklessEDD\Modules\QuantityLimits;

defined('ABSPATH') || exit;

/**
 * Pure quantity rule math. Levels are ordered most specific first (price option, download, store);
 * a null value means "inherit from the next level".
 */
final class Rules
{
    /**
     * @param array<int, array{min?: int|null, max?: int|null, step?: int|null}> $levels
     * @return array{min: int, max: int, step: int} max 0 = no limit
     */
    public static function resolve(array $levels): array
    {
        $resolved = ['min' => null, 'max' => null, 'step' => null];
        foreach ($levels as $level) {
            foreach (['min', 'max', 'step'] as $key) {
                if ($resolved[$key] === null && isset($level[$key])) {
                    $resolved[$key] = (int) $level[$key];
                }
            }
        }
        return self::normalize((int) ($resolved['min'] ?? 1), (int) ($resolved['max'] ?? 0), (int) ($resolved['step'] ?? 1));
    }

    /**
     * Make min, max and step agree: step ≥ 1, min ≥ 1 and a multiple of step,
     * max (when set) a multiple of step and never below min.
     *
     * @return array{min: int, max: int, step: int}
     */
    public static function normalize(int $min, int $max, int $step): array
    {
        $step = max(1, $step);
        $min = max(1, $min);
        if ($min % $step !== 0) {
            $min = (int) (ceil($min / $step) * $step);
        }
        if ($max > 0) {
            $max = (int) (floor($max / $step) * $step);
            if ($max < $min) {
                $max = $min;
            }
        } else {
            $max = 0;
        }
        return ['min' => $min, 'max' => $max, 'step' => $step];
    }

    /**
     * @param array{min: int, max: int, step: int} $limits
     * @return string '' when valid, otherwise "min", "max" or "step"
     */
    public static function check(int $quantity, array $limits): string
    {
        if ($quantity < $limits['min']) {
            return 'min';
        }
        if ($limits['max'] > 0 && $quantity > $limits['max']) {
            return 'max';
        }
        if ($limits['step'] > 1 && $quantity % $limits['step'] !== 0) {
            return 'step';
        }
        return '';
    }

    /**
     * The nearest valid quantity: raised to the minimum, rounded up to the next multiple of the
     * step, and capped at the maximum (rounded down to a multiple when the cap is hit).
     *
     * @param array{min: int, max: int, step: int} $limits
     */
    public static function clamp(int $quantity, array $limits): int
    {
        $step = max(1, $limits['step']);
        $quantity = max($limits['min'], $quantity);
        if ($quantity % $step !== 0) {
            $quantity = (int) (ceil($quantity / $step) * $step);
        }
        if ($limits['max'] > 0 && $quantity > $limits['max']) {
            $quantity = max($limits['min'], (int) (floor($limits['max'] / $step) * $step));
        }
        return $quantity;
    }

    /**
     * Fill {product}, {min}, {max}, {step} and {quantity} in a message template.
     *
     * @param array<string, string|int> $vars
     */
    public static function message(string $template, array $vars): string
    {
        $search = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = (string) $value;
        }
        return str_replace($search, $replace, $template);
    }

    /**
     * Parse a stored meta value: '' or non-numeric = inherit (null), otherwise a non-negative int.
     *
     * @param mixed $value
     */
    public static function parse($value): ?int
    {
        if ($value === '' || $value === null || !is_numeric($value)) {
            return null;
        }
        return max(0, (int) $value);
    }

    /**
     * Group key for cart lines that share limits: the same download and price option, whatever
     * other options (add-ons) split them into separate lines.
     *
     * @param mixed $priceId
     */
    public static function lineKey(int $downloadId, $priceId): string
    {
        return $downloadId . ':' . (is_numeric($priceId) ? (string) (int) $priceId : '');
    }
}
