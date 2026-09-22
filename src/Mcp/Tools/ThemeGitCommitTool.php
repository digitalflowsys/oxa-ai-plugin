<?php
/**
 * `theme_git_commit` — stage and commit changes inside the theme repo.
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

final class ThemeGitCommitTool extends AbstractTool
{
    public function __construct(private readonly ThemeGit $git) {}

    public function name(): string        { return 'theme_git_commit'; }
    public function description(): string { return 'Stage changes and commit them in the theme repo. By default stages everything (git add -A); pass paths to scope. Commits are attributed to "Oxa AI <oxa-ai@local>".'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => ['type' => 'string', 'description' => 'Commit message.'],
                'paths'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional paths to stage. If omitted, all changes are staged.'],
                'scope'   => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $message = (string) ($arguments['message'] ?? '');
        $paths   = is_array($arguments['paths'] ?? null) ? $arguments['paths'] : [];
        $scope   = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $result  = $this->git->commit($scope, $message, $paths);
        $summary = $result['hash'] === null
            ? 'Nothing to commit — working tree was clean.'
            : sprintf('Committed %s (%d file(s)): %s', substr($result['hash'], 0, 12), $result['added'], $result['message']);
        return $this->text($summary, $result);
    }
}
