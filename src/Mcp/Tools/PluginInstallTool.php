<?php
/**
 * `plugin_install` — install a plugin from the WordPress.org repository.
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

final class PluginInstallTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugin_install'; }
    public function description(): string { return 'Install a plugin from the WordPress.org repository by slug (e.g. "woocommerce", "wordpress-seo"). Activates after install by default.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'     => ['type' => 'string', 'description' => 'Wordpress.org plugin slug.'],
                'activate' => ['type' => 'boolean', 'description' => 'Default true.'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug     = (string) ($arguments['slug'] ?? '');
        $activate = array_key_exists('activate', $arguments) ? (bool) $arguments['activate'] : true;
        $info = $this->plugins->installFromRepo($slug, $activate);
        return $this->text(sprintf('Installed %s from %s%s.', $info['slug'], $info['source'], $info['active'] ? ' (active)' : ''), $info);
    }
}
