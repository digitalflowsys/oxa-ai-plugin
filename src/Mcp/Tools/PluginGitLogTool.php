<?php
/**
 * `plugin_git_log` — recent commits in an installed plugin's directory.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\PluginGit;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginGitLogTool extends AbstractTool
{
    public function __construct(private readonly PluginGit $git) {}

    public function name(): string        { return 'plugin_git_log'; }
    public function description(): string { return 'List recent commits in an installed plugin directory, newest first. Useful for seeing work committed directly on the server. Read-only.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'  => ['type' => 'string', 'description' => 'Plugin directory name.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Default 20.'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug  = (string) ($arguments['slug'] ?? '');
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 20;
        $result = $this->git->log($slug, $limit);

        if (!$result['is_repo']) {
            return $this->text(sprintf('%s is not a git working tree (installed from an archive).', $slug), $result);
        }

        return $this->text(sprintf('%d commit(s).', count($result['commits'])), $result);
    }
}
