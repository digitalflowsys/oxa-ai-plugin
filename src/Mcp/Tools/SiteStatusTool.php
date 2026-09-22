<?php
/**
 * `site_status` — site identity, stack, content counts.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\SiteStatus;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteStatusTool extends AbstractTool
{
    public function __construct(private readonly SiteStatus $status) {}

    public function name(): string        { return 'site_status'; }
    public function description(): string { return 'Get site identity, WordPress version, active theme, active plugins, WooCommerce status, multilingual hints, and content counts (posts/pages/products/users/media).'; }

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
        $snap = $this->status->snapshot();
        $summary = sprintf(
            '%s — WP %s, theme "%s" v%s, %d active plugins.',
            $snap['site']['name'] ?? '',
            $snap['wordpress']['version'] ?? '',
            $snap['theme']['name'] ?? '',
            $snap['theme']['version'] ?? '',
            $snap['plugins']['count_active'] ?? 0
        );
        return $this->text($summary, $snap);
    }
}
