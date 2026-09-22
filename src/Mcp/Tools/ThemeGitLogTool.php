<?php
/**
 * `theme_git_log` — list recent commits.
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

final class ThemeGitLogTool extends AbstractTool
{
    public function __construct(private readonly ThemeGit $git) {}

    public function name(): string        { return 'theme_git_log'; }
    public function description(): string { return 'List the most recent commits on the current branch of the theme repo.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 20.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
        $result = $this->git->log($scope, $limit);
        return $this->text(sprintf('%d commits.', count($result['commits'])), $result);
    }
}
