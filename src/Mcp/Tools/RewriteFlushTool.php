<?php
/**
 * `rewrite_flush` — flush the rewrite rules.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class RewriteFlushTool extends AbstractTool
{
    public function name(): string        { return 'rewrite_flush'; }
    public function description(): string { return 'Regenerate the WordPress rewrite rules. Call after registering new post types, taxonomies, or endpoints that affect URLs.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hard' => ['type' => 'boolean', 'description' => 'If true, also rewrites the .htaccess file. Default false.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $hard = (bool) ($arguments['hard'] ?? false);
        flush_rewrite_rules($hard);
        return $this->text(
            $hard ? 'Rewrite rules + .htaccess flushed.' : 'Rewrite rules flushed.',
            ['hard' => $hard]
        );
    }
}
