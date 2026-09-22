<?php
/**
 * `theme_dir_list` — list entries in a directory inside the theme.
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

final class ThemeDirListTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_dir_list'; }
    public function description(): string { return 'List files and folders inside the active theme. Pass "/" or an empty path for the theme root. Set recursive=true for a full tree.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path'      => ['type' => 'string', 'description' => 'Relative dir. Defaults to theme root.'],
                'scope'     => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
                'recursive' => ['type' => 'boolean', 'description' => 'Walk subdirectories.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path  = (string) ($arguments['path'] ?? '');
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $rec   = (bool) ($arguments['recursive'] ?? false);
        $result = $this->fs->listDir($path, $scope, $rec);
        return $this->text(
            sprintf('%d entries in %s%s', count($result['entries']), $result['path'], $rec ? ' (recursive)' : ''),
            $result
        );
    }
}
