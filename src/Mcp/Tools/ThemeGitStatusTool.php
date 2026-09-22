<?php
/**
 * `theme_git_status` — git status for the theme's working tree.
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

final class ThemeGitStatusTool extends AbstractTool
{
    public function __construct(private readonly ThemeGit $git) {}

    public function name(): string        { return 'theme_git_status'; }
    public function description(): string { return 'Show the current branch and dirty/clean state of the git repo containing the theme. Returns porcelain output for parsing.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $result = $this->git->status($scope);
        $summary = sprintf('Branch %s — %s.', $result['branch'] ?? '(detached)', $result['clean'] ? 'clean' : 'dirty');
        return $this->text($summary, $result);
    }
}
