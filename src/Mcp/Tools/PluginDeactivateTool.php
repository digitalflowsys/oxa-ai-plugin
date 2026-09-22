<?php
/**
 * `plugin_deactivate` — deactivate an installed plugin.
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

final class PluginDeactivateTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugin_deactivate'; }
    public function description(): string { return 'Deactivate an installed plugin by folder slug.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'    => ['type' => 'string'],
                'network' => ['type' => 'boolean'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug = (string) ($arguments['slug'] ?? '');
        $net  = (bool) ($arguments['network'] ?? false);
        $info = $this->plugins->deactivate($slug, $net);
        return $this->text(sprintf('Deactivated %s.', $info['slug']), $info);
    }
}
