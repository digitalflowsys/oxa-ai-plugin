<?php
/**
 * `plugin_uninstall` — delete a plugin from disk after deactivating it.
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

final class PluginUninstallTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugin_uninstall'; }
    public function description(): string { return 'Delete a plugin from disk by folder slug. If the plugin is active it is deactivated first (unless deactivate=false). Runs the plugin\'s uninstall hooks if present.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'       => ['type' => 'string'],
                'deactivate' => ['type' => 'boolean', 'description' => 'Default true.'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug = (string) ($arguments['slug'] ?? '');
        $deactivate = array_key_exists('deactivate', $arguments) ? (bool) $arguments['deactivate'] : true;
        $info = $this->plugins->uninstall($slug, $deactivate);
        return $this->text(sprintf('Uninstalled %s.', $info['slug']), $info);
    }
}
