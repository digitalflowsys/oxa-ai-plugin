<?php
/**
 * `theme_git_diff` — unified diff for the theme repo.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\ThemeGit;
use OxaAi\Site\ThemePaths;

if (!defined('ABSPATH')) {
    exit;
}

final class ThemeGitDiffTool extends AbstractTool
{
    public function __construct(private readonly ThemeGit $git) {}

    public function name(): string        { return 'theme_git_diff'; }
    public function description(): string { return 'Show an unsigned unified diff for the theme repo. Set staged=true to diff the index; pass path to scope the diff to one file or directory.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope'  => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
                'staged' => ['type' => 'boolean'],
                'path'   => ['type' => 'string', 'description' => 'Optional pathspec relative to the repo root.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $scope  = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $staged = (bool) ($arguments['staged'] ?? false);
        $path   = isset($arguments['path']) && is_string($arguments['path']) && $arguments['path'] !== '' ? $arguments['path'] : null;
        $result = $this->git->diff($scope, $staged, $path);
        return $this->text(sprintf('%d path(s) changed.', count($result['paths'])), $result);
    }
}
