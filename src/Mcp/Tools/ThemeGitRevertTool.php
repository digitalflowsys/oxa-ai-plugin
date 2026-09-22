<?php
/**
 * `theme_git_revert` — revert a commit on the theme repo.
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

final class ThemeGitRevertTool extends AbstractTool
{
    public function __construct(private readonly ThemeGit $git) {}

    public function name(): string        { return 'theme_git_revert'; }
    public function description(): string { return 'Revert a commit on the theme repo and create the inverse commit. Defaults to HEAD. Does not open an editor.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ref'   => ['type' => 'string', 'description' => 'Commit ref to revert. Defaults to HEAD.'],
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $ref   = (string) ($arguments['ref'] ?? 'HEAD');
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $result = $this->git->revert($scope, $ref);
        return $this->text(sprintf('Reverted %s — new HEAD %s.', $result['reverted'], substr($result['new_head'], 0, 12)), $result);
    }
}
