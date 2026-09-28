<?php
/**
 * Resolve and sandbox paths inside the plugins directory.
 *
 * The plugin_git_* tools take a caller-supplied slug. This class is the
 * only place that turns one into a filesystem path, so traversal, null
 * bytes and absolute paths are rejected in exactly one spot.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginPaths
{
    /**
     * A plugin slug is a single directory name: letters, digits, dot,
     * underscore and hyphen. No separators, so no traversal is expressible.
     */
    private const SLUG_PATTERN = '/^[A-Za-z0-9._-]+$/';

    /** Trailing-slashed absolute path to the plugins directory. */
    public static function root(): string
    {
        return trailingslashit(defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins');
    }

    /**
     * Absolute path to one plugin's directory. Throws unless the slug is
     * well-formed, the directory exists, and it really sits directly
     * inside the plugins root once symlinks are resolved.
     */
    public static function dir(string $slug): string
    {
        if ($slug === '' || str_contains($slug, "\0") || !preg_match(self::SLUG_PATTERN, $slug)) {
            throw new RuntimeException(sprintf('Invalid plugin slug: %s', $slug));
        }
        if ($slug === '.' || $slug === '..') {
            throw new RuntimeException('Invalid plugin slug.');
        }

        $base = realpath(self::root());
        if ($base === false) {
            throw new RuntimeException('Plugins directory could not be resolved.');
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $real = realpath($base . $slug);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException(sprintf('Plugin directory not found: %s', $slug));
        }

        // Must be a direct child of the plugins root. A symlink pointing
        // somewhere else on disk is refused rather than silently followed.
        if (dirname($real) . DIRECTORY_SEPARATOR !== $base) {
            throw new RuntimeException(sprintf('Plugin directory resolves outside the plugins root: %s', $slug));
        }

        return $real;
    }

    /**
     * Whether the plugin directory is itself a git working tree.
     *
     * Deliberately does NOT walk up the tree: wp-content or the WordPress
     * root may be a git repo of its own, and reporting that as the
     * plugin's repo would be actively misleading.
     */
    public static function isRepo(string $dir): bool
    {
        return is_dir($dir . DIRECTORY_SEPARATOR . '.git')
            || is_file($dir . DIRECTORY_SEPARATOR . '.git');
    }

    /**
     * Where a plugin slug WOULD live, without requiring it to exist yet.
     *
     * dir() deliberately refuses missing directories; cloning needs the
     * target path before anything is there. Validation and containment are
     * identical — only the existence check differs.
     */
    public static function targetDir(string $slug): string
    {
        if ($slug === '' || str_contains($slug, "\0") || !preg_match(self::SLUG_PATTERN, $slug)) {
            throw new RuntimeException(sprintf('Invalid plugin slug: %s', $slug));
        }
        if ($slug === '.' || $slug === '..') {
            throw new RuntimeException('Invalid plugin slug.');
        }

        $base = realpath(self::root());
        if ($base === false) {
            throw new RuntimeException('Plugins directory could not be resolved.');
        }

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $slug;
    }
}
