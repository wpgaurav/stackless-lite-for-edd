<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

final class ModuleRegistry
{
    public const OPTION = 'stackless_edd_enabled_modules';

    /** @var array<string, Module> */
    private $modules = [];

    /** @var array<string, string> module id => reason it did not boot */
    private $blocked = [];

    /** @var array<string, bool> */
    private $booted = [];

    public function discover(): void
    {
        foreach (glob(STACKLESS_EDD_PLUGIN_PATH . 'includes/Modules/*/Module.php') ?: [] as $file) {
            $dir = basename(dirname($file));
            $class = 'StacklessEDD\\Modules\\' . $dir . '\\Module';
            if (!class_exists($class)) {
                continue;
            }
            $module = new $class();
            if ($module instanceof Module) {
                $this->modules[$module->id()] = $module;
            }
        }
        ksort($this->modules);
    }

    /** @return array<string, Module> */
    public function all(): array
    {
        return $this->modules;
    }

    public function get(string $id): ?Module
    {
        return $this->modules[$id] ?? null;
    }

    public function enabledIds(): array
    {
        $stored = get_option(self::OPTION, null);
        if (!is_array($stored)) {
            $stored = [];
            foreach ($this->modules as $id => $module) {
                if ($module->enabledByDefault()) {
                    $stored[] = $id;
                }
            }
        }
        return array_values(array_intersect($stored, array_keys($this->modules)));
    }

    public function isEnabled(string $id): bool
    {
        return in_array($id, $this->enabledIds(), true);
    }

    public function isActive(string $id): bool
    {
        return !empty($this->booted[$id]);
    }

    /**
     * Stores the enabled modules. Ids of modules this build does not have (the Pro modules, when
     * Stackless Lite runs on a site that had Stackless for EDD) are kept, so they come back on upgrade.
     */
    public function setEnabled(array $ids): void
    {
        update_option(self::OPTION, self::mergeEnabled(get_option(self::OPTION, null), $ids, array_keys($this->modules)));
    }

    /**
     * Pure: the list to store. Known modules come from $ids; unknown ones stay as they were stored.
     *
     * @param mixed    $stored the option's current value
     * @param string[] $ids    enabled ids requested for the known modules
     * @param string[] $known  ids of the modules in this build
     * @return string[]
     */
    public static function mergeEnabled($stored, array $ids, array $known): array
    {
        $stored = is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : [];
        $kept = array_diff($stored, $known);
        return array_values(array_unique(array_merge(array_intersect(array_map('strval', $ids), $known), $kept)));
    }

    /**
     * Switch one module on or off against the freshly stored list, so two toggles sent at
     * the same time each change only their own module instead of overwriting each other.
     *
     * @return string[] enabled module ids after the change
     */
    public function setModuleEnabled(string $id, bool $on): array
    {
        // Another request may have saved since this one loaded its options: drop the
        // request's cached copy and read the current value right before writing.
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        wp_cache_delete(self::OPTION, 'options');
        $stored = get_option(self::OPTION, null);
        $ids = is_array($stored) ? array_values(array_filter(array_map('strval', $stored))) : $this->enabledIds();
        $ids = $on ? array_values(array_unique(array_merge($ids, [$id]))) : array_values(array_diff($ids, [$id]));
        $this->setEnabled($ids);
        return $this->enabledIds();
    }

    public function blockedReason(string $id): ?string
    {
        return $this->blocked[$id] ?? null;
    }

    public function bootEnabled(): void
    {
        $enabled = $this->enabledIds();
        foreach ($enabled as $id) {
            $this->bootOne($id, $enabled, []);
        }
    }

    private function bootOne(string $id, array $enabled, array $stack): bool
    {
        if (isset($this->booted[$id])) {
            return $this->booted[$id];
        }
        $module = $this->modules[$id] ?? null;
        if (!$module || in_array($id, $stack, true)) {
            return false;
        }

        $conflict = ConflictGuard::firstActive($module->conflicts());
        if ($conflict !== null) {
            /* translators: %s: plugin name */
            $this->blocked[$id] = sprintf(__('Paused because %s is active and does the same job.', 'stackless-lite-edd'), $conflict);
            return $this->booted[$id] = false;
        }

        foreach ($module->dependencies() as $dep) {
            if (!in_array($dep, $enabled, true) || !$this->bootOne($dep, $enabled, array_merge($stack, [$id]))) {
                $title = isset($this->modules[$dep]) ? $this->modules[$dep]->title() : $dep;
                /* translators: %s: module title */
                $this->blocked[$id] = sprintf(__('Needs the %s module enabled.', 'stackless-lite-edd'), $title);
                return $this->booted[$id] = false;
            }
        }

        try {
            $module->boot();
        } catch (\Throwable $e) {
            Logger::log($id, 'Boot failed: ' . $e->getMessage(), 'error');
            $this->blocked[$id] = __('Failed to start. See Downloads → Tools → Debug Log (stackless-edd-*).', 'stackless-lite-edd');
            return $this->booted[$id] = false;
        }
        return $this->booted[$id] = true;
    }
}
