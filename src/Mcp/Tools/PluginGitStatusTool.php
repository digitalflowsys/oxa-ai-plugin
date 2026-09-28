<?php
/**
 * `plugin_git_status` — what an installed plugin's directory is ACTUALLY at.
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

final class PluginGitStatusTool extends AbstractTool
{
    public function __construct(private readonly PluginGit $git) {}

    public function name(): string        { return 'plugin_git_status'; }
    public function description(): string { return 'Report the real git state of an installed plugin directory: commit SHA at HEAD, branch, whether the working tree has uncommitted edits, and how far ahead/behind its upstream it is. Returns is_repo=false for plugins installed from a zip archive, which have no history. Read-only.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug' => ['type' => 'string', 'description' => 'Plugin directory name, e.g. "digitalflow-analytics".'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug = (string) ($arguments['slug'] ?? '');
        $result = $this->git->status($slug);

        if (!$result['is_repo']) {
            return $this->text(sprintf('%s is not a git working tree (installed from an archive).', $slug), $result);
        }

        $summary = sprintf(
            '%s at %s on %s — %s.',
            $slug,
            $result['head_short'] ?? '(unknown)',
            $result['detached'] ? 'detached HEAD' : ($result['branch'] ?? '(unknown)'),
            $result['clean'] ? 'clean' : sprintf('%d file(s) modified', $result['dirty_files'])
        );

        return $this->text($summary, $result);
    }
}
