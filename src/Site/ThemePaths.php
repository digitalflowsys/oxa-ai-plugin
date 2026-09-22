<?php
/**
 * Resolve and sandbox paths inside the active theme.
 *
 * All theme_file_* and theme_dir_* tools route through here so that
 * a relative path argument cannot escape the theme directory via
 * `..`, absolute paths, or null bytes.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class ThemePaths
{
    public const SCOPE_TEMPLATE   = 'template';
    public const SCOPE_STYLESHEET = 'stylesheet';

    /**
     * Trailing-slashed absolute path to the theme root for the given scope.
     */
    public static function root(string $scope = self::SCOPE_TEMPLATE): string
    {
        $dir = $scope === self::SCOPE_STYLESHEET
            ? get_stylesheet_directory()
            : get_template_directory();
        return trailingslashit($dir);
    }

    /**
     * Map a caller-provided relative path to an absolute path under the
     * theme root. Rejects traversal, null bytes, and absolute paths.
     */
    public static function resolve(string $relative, string $scope = self::SCOPE_TEMPLATE): string
    {
        $clean = self::normalizeRelative($relative);
        return self::root($scope) . $clean;
    }

    /**
     * Verify that an absolute path resolves inside the theme directory.
     * For files/dirs that do not yet exist we validate the parent dir.
     */
    public static function assertInside(string $abs, string $scope = self::SCOPE_TEMPLATE): void
    {
        $base = realpath(self::root($scope));
        if ($base === false) {
            throw new RuntimeException('Theme directory could not be resolved.');
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $real = realpath($abs);
        if ($real === false) {
            $parent = realpath(dirname($abs));
            if ($parent === false) {
                throw new RuntimeException(sprintf('Parent directory does not exist for "%s".', $abs));
            }
            $real = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($abs);
        }

        $probe = is_dir($real) ? rtrim($real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : $real;
        if (!str_starts_with($probe, $base)) {
            throw new RuntimeException('Refusing to operate outside the theme directory.');
        }
    }

    private static function normalizeRelative(string $relative): string
    {
        $relative = (string) $relative;
        if (str_contains($relative, "\0")) {
            throw new RuntimeException('Path contains null byte.');
        }
        // Convert backslashes (Windows-style) to forward slashes for analysis.
        $r = str_replace('\\', '/', $relative);
        $r = ltrim($r, '/');
        if ($r === '') {
            throw new RuntimeException('Path must not be empty.');
        }
        // Disallow drive letters or absolute hints.
        if (preg_match('#^[A-Za-z]:#', $r)) {
            throw new RuntimeException('Absolute paths are not allowed.');
        }
        // Reject `..` segments — even harmless-looking ones — because realpath
        // alone is insufficient when intermediate dirs do not yet exist.
        foreach (explode('/', $r) as $seg) {
            if ($seg === '..' || $seg === '.') {
                throw new RuntimeException('Path traversal segments ("." or "..") are not allowed.');
            }
        }
        return $r;
    }
}
