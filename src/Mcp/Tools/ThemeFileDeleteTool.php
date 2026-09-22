<?php
/**
 * `theme_file_delete` — delete a single file under the theme directory.
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

final class ThemeFileDeleteTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_file_delete'; }
    public function description(): string { return 'Delete a single file under the active theme directory. To remove a folder use theme_dir_delete.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path'  => ['type' => 'string'],
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path  = (string) ($arguments['path'] ?? '');
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $result = $this->fs->deleteFile($path, $scope);
        return $this->text(sprintf('Deleted %s.', $result['path']), $result);
    }
}
