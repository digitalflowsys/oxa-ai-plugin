<?php
/**
 * `theme_dir_create` — create a directory inside the theme. Idempotent.
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

final class ThemeDirCreateTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_dir_create'; }
    public function description(): string { return 'Create a directory inside the active theme. Parents are created recursively. Safe to call when the directory already exists — it returns existed=true.'; }

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
        $result = $this->fs->createDir($path, $scope);
        return $this->text(
            $result['created'] ? sprintf('Created %s.', $result['path']) : sprintf('Already exists: %s.', $result['path']),
            $result
        );
    }
}
