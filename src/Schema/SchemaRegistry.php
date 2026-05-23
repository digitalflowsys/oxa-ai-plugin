<?php
/**
 * Bridge between the plugin and the theme's component registry.
 *
 * We do NOT duplicate component definitions. The active theme is the
 * canonical source: any folder in <theme>/components/ with a schema.json.
 * If the user is not running an Oxa-compatible theme, the registry is
 * simply empty and the plugin shows a notice.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class SchemaRegistry
{
    /** @var array<string,array>|null */
    private ?array $cache = null;

    /**
     * Locate the active theme's components directory.
     */
    private function componentsDir(): string
    {
        return trailingslashit(get_template_directory()) . 'components/';
    }

    /** @return array<string,array> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $dir = $this->componentsDir();
        if (!is_dir($dir)) {
            return $this->cache = [];
        }

        $schemas = [];
        foreach (new \DirectoryIterator($dir) as $entry) {
            if (!$entry->isDir() || $entry->isDot()) {
                continue;
            }
            $name   = $entry->getFilename();
            $schema = $dir . $name . '/schema.json';
            if (!is_file($schema)) {
                continue;
            }
            $raw = file_get_contents($schema);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            // Normalize: ensure name field is always present and matches folder.
            $decoded['name'] = $name;
            $schemas[$name]  = $decoded;
        }

        ksort($schemas);
        return $this->cache = $schemas;
    }

    public function get(string $name): ?array
    {
        $all = $this->all();
        return $all[$name] ?? null;
    }

    public function exists(string $name): bool
    {
        return $this->get($name) !== null;
    }

    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Reset cache. Useful for tests or after a theme switch.
     */
    public function reset(): void
    {
        $this->cache = null;
    }
}
