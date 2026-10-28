<?php

namespace StacklessEDD\Modules\HidePriceUntilLogin;

defined('ABSPATH') || exit;

/**
 * Pure rules: which downloads are hidden, and which viewers may see prices.
 */
final class Scope
{
    public const ALL = 'all';
    public const CATEGORIES = 'categories';
    public const SELECTED = 'selected';

    /**
     * @param string $scope             all|categories|selected
     * @param string $downloadOverride  ''|yes|no from the download editor
     * @param int[]  $downloadCategories
     * @param int[]  $hiddenCategories  Selected categories, already expanded with their children
     */
    public static function productHidden(string $scope, string $downloadOverride, array $downloadCategories, array $hiddenCategories): bool
    {
        if ($downloadOverride === 'yes') {
            return true;
        }
        if ($downloadOverride === 'no') {
            return false;
        }
        if ($scope === self::ALL) {
            return true;
        }
        if ($scope === self::CATEGORIES) {
            return (bool) array_intersect(array_map('intval', $downloadCategories), array_map('intval', $hiddenCategories));
        }
        return false;
    }

    /**
     * @param string[] $userRoles
     * @param string[] $allowedRoles Empty = every logged-in user sees prices
     */
    public static function viewerSeesPrices(bool $loggedIn, array $userRoles, array $allowedRoles): bool
    {
        if (!$loggedIn) {
            return false;
        }
        $allowedRoles = array_values(array_filter(array_map('strval', $allowedRoles)));
        if (!$allowedRoles) {
            return true;
        }
        return (bool) array_intersect(array_map('strval', $userRoles), $allowedRoles);
    }

    public static function normalizeScope(string $scope): string
    {
        return in_array($scope, [self::ALL, self::CATEGORIES, self::SELECTED], true) ? $scope : self::ALL;
    }
}
