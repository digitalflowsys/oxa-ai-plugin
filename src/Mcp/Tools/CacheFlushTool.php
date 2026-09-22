<?php
/**
 * `cache_flush` — drop the object cache.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class CacheFlushTool extends AbstractTool
{
    public function name(): string        { return 'cache_flush'; }
    public function description(): string { return 'Flush the WordPress object cache. Works with built-in cache and persistent backends (Redis, Memcached) that implement wp_cache_flush().'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $ok = function_exists('wp_cache_flush') ? wp_cache_flush() : false;
        return $this->text($ok ? 'Object cache flushed.' : 'wp_cache_flush() returned false; backend may not support flushing.', ['flushed' => (bool) $ok]);
    }
}
