<?php
/**
 * PSR-4 autoloader for the OxaAi namespace.
 *
 * Deliberately hand-rolled instead of Composer-required so the plugin
 * remains drop-in installable from a zip with zero build step.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Autoloader
{
    private const PREFIX  = 'OxaAi\\';
    private const BASE_DIR = OXA_AI_DIR . 'src/';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::PREFIX)) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        $path     = self::BASE_DIR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    }
}
