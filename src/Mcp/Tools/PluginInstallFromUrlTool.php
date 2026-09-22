<?php
/**
 * `plugin_install_from_url` — install (or reinstall) from a fully-qualified zip URL.
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

final class PluginInstallFromUrlTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugin_install_from_url'; }
    public function description(): string { return 'Install or reinstall a plugin from a fully-qualified zip URL (pre-signed for private repos). Activates after install by default; pass overwrite=false to refuse upgrading an existing copy.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'archive_url' => ['type' => 'string', 'format' => 'uri'],
                'slug'        => ['type' => 'string', 'description' => 'Optional — used as a fallback if the archive root cannot be auto-detected.'],
                'activate'    => ['type' => 'boolean'],
                'overwrite'   => ['type' => 'boolean', 'description' => 'Default true.'],
            ],
            'required' => ['archive_url'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $url       = (string) ($arguments['archive_url'] ?? '');
        $slug      = isset($arguments['slug']) && is_string($arguments['slug']) && $arguments['slug'] !== '' ? $arguments['slug'] : null;
        $activate  = array_key_exists('activate', $arguments) ? (bool) $arguments['activate'] : true;
        $overwrite = array_key_exists('overwrite', $arguments) ? (bool) $arguments['overwrite'] : true;

        $info = $this->plugins->installFromUrl($url, $slug, $activate, $overwrite);
        return $this->text(sprintf('Installed %s from URL%s.', $info['slug'], $info['active'] ? ' (active)' : ''), $info);
    }
}
