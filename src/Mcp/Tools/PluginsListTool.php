<?php
/**
 * `plugins_list` — every plugin installed on this site, active or not.
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

final class PluginsListTool extends AbstractTool
{
    public function __construct(private readonly PluginService $plugins) {}

    public function name(): string        { return 'plugins_list'; }
    public function description(): string { return 'List every installed plugin on this site, with slug, name, version, author and whether it is currently active.'; }

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
        $list = $this->plugins->listAll();
        $active = array_values(array_filter($list, static fn (array $p): bool => $p['active']));
        return $this->text(sprintf('%d installed, %d active.', count($list), count($active)), ['plugins' => $list]);
    }
}
