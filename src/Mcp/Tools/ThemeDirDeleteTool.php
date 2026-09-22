<?php
/**
 * `theme_dir_delete` — delete a directory under the theme.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\ThemeFs;
use OxaAi\Site\ThemePaths;

if (!defined('ABSPATH')) {
    exit;
}

final class ThemeDirDeleteTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_dir_delete'; }
    public function description(): string { return 'Delete a directory under the active theme. Refuses to delete the theme root. Pass recursive=true to remove non-empty directories.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path'      => ['type' => 'string'],
                'scope'     => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
                'recursive' => ['type' => 'boolean'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path  = (string) ($arguments['path'] ?? '');
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $rec   = (bool) ($arguments['recursive'] ?? false);
        $result = $this->fs->deleteDir($path, $scope, $rec);
        return $this->text(sprintf('Deleted %s (%d entries).', $result['path'], $result['entries_removed']), $result);
    }
}
