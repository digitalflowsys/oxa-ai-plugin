<?php
/**
 * `plugin_activate` — activate an installed plugin by slug.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\PluginService;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginActivateTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugin_activate'; }
    public function description(): string { return 'Activate an installed plugin by folder slug, e.g. "woocommerce". Multisite: pass network=true to activate network-wide.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'    => ['type' => 'string'],
                'network' => ['type' => 'boolean', 'description' => 'Multisite network activation. Default false.'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug = (string) ($arguments['slug'] ?? '');
        $net  = (bool) ($arguments['network'] ?? false);
        $info = $this->plugins->activate($slug, $net);
        return $this->text(sprintf('Activated %s (%s).', $info['slug'], $info['file']), $info);
    }
}
