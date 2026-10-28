<?php

namespace StacklessEDD\Modules\EddOptimizer;

defined('ABSPATH') || exit;

/**
 * Pure decisions for the optimizer: which assets belong to EDD, what counts as store markup, and
 * how long scheduled-action logs stay. No WordPress calls, so it is unit tested directly.
 */
final class Rules
{
    /** EDD's front-end stylesheet, needed only where EDD renders something. */
    public const STORE_STYLES = ['edd-styles'];

    /** EDD's AJAX script (add to cart, cart widget, discount codes). Checkout scripts already load on checkout only. */
    public const STORE_SCRIPTS = ['edd-ajax'];

    /** EDD front-end scripts that initialise on DOM ready and can run deferred outside checkout. */
    public const DEFERRABLE_SCRIPTS = ['edd-ajax'];

    /** Shortcodes that render store UI. */
    public const SHORTCODES = [
        'purchase_link', 'downloads', 'edd_downloads', 'download_cart', 'download_checkout', 'download_discounts',
        'download_history', 'purchase_history', 'purchase_collection', 'edd_price', 'edd_receipt', 'edd_login',
        'edd_register', 'edd_profile_editor',
    ];

    /** Does post content contain EDD or Stackless blocks or shortcodes? */
    public static function contentHasStoreMarkup(string $content): bool
    {
        if ($content === '') {
            return false;
        }
        if (strpos($content, '<!-- wp:edd/') !== false || strpos($content, '<!-- wp:stackless-edd/') !== false) {
            return true;
        }
        if (strpos($content, '[') === false) {
            return false;
        }
        if (preg_match('/\[stackless_edd_[a-z_]+/', $content)) {
            return true;
        }
        return (bool) preg_match('/\[(' . implode('|', array_map('preg_quote', self::SHORTCODES)) . ')[\s\]\/]/', $content);
    }

    /**
     * Post IDs referenced by synced patterns (`<!-- wp:block {"ref":12} /-->`) in content.
     *
     * @return int[]
     */
    public static function syncedPatternRefs(string $content): array
    {
        if (strpos($content, 'wp:block') === false && strpos($content, 'wp:core/block') === false) {
            return [];
        }
        // Block attributes are JSON with ">" escaped, so [^>] stays inside the comment.
        if (!preg_match_all('/<!--\s+wp:(?:core\/)?block\s+\{[^>]*?"ref"\s*:\s*(\d+)/', $content, $matches)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $matches[1]))));
    }

    /**
     * Like contentHasStoreMarkup(), but follows synced pattern references through $load
     * (post ID => that pattern's content), recursively, each pattern at most once.
     *
     * @param callable(int): string $load
     * @param array<int, bool> $seen
     */
    public static function contentHasStoreMarkupDeep(string $content, callable $load, int $depth = 5, array &$seen = []): bool
    {
        if (self::contentHasStoreMarkup($content)) {
            return true;
        }
        if ($depth <= 0) {
            return false;
        }
        foreach (self::syncedPatternRefs($content) as $ref) {
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            if (self::contentHasStoreMarkupDeep((string) $load($ref), $load, $depth - 1, $seen)) {
                return true;
            }
        }
        return false;
    }

    /** Is this a block that needs EDD assets around it? */
    public static function isStoreBlock(string $blockName): bool
    {
        return strpos($blockName, 'edd/') === 0 || strpos($blockName, 'stackless-edd/') === 0;
    }

    /**
     * Whether a request path or page id is one the merchant listed as a store page.
     *
     * @param string[] $lines Page IDs or URL paths, one per entry.
     */
    public static function isKeptPage(array $lines, int $pageId, string $path): bool
    {
        $path = '/' . trim($path, '/') . '/';
        $path = $path === '//' ? '/' : $path;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (ctype_digit($line) ? $pageId > 0 && $pageId === (int) $line : $path === str_replace('//', '/', '/' . trim($line, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    /** Retention in seconds from a day count, or null to keep Action Scheduler's default. */
    public static function retentionSeconds($days, int $daySeconds = 86400): ?int
    {
        $days = (int) $days;
        if ($days < 1 || $days === 31) {
            return null;
        }
        return min($days, 365) * $daySeconds;
    }
}
