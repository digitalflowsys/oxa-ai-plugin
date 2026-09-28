<?php
/**
 * `plugin_git_diff` — uncommitted changes in an installed plugin's directory.
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

final class PluginGitDiffTool extends AbstractTool
{
    public function __construct(private readonly PluginGit $git) {}

    public function name(): string        { return 'plugin_git_diff'; }
    public function description(): string { return 'Show uncommitted changes in an installed plugin directory against HEAD — i.e. edits made on the server that exist nowhere else. Large diffs are truncated. Read-only.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'      => ['type' => 'string', 'description' => 'Plugin directory name.'],
                'max_bytes' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 1000000, 'description' => 'Truncate the diff past this many bytes. Default 200000.'],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug = (string) ($arguments['slug'] ?? '');
        $max  = isset($arguments['max_bytes']) ? (int) $arguments['max_bytes'] : 200000;
        $result = $this->git->diff($slug, $max);

        if (!$result['is_repo']) {
            return $this->text(sprintf('%s is not a git working tree (installed from an archive).', $slug), $result);
        }

        $summary = $result['paths'] === []
            ? sprintf('%s has no uncommitted changes.', $slug)
            : sprintf('%s has %d modified file(s)%s.', $slug, count($result['paths']), $result['truncated'] ? ' (diff truncated)' : '');

        return $this->text($summary, $result);
    }
}
