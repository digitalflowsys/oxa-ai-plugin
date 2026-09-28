<?php
/**
 * `plugin_git_checkout` — move an installed plugin to a specific commit.
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

final class PluginGitCheckoutTool extends AbstractTool
{
    public function __construct(private readonly PluginGit $git) {}

    public function name(): string        { return 'plugin_git_checkout'; }
    public function description(): string { return 'Move an installed plugin\'s working tree to a specific commit, tag or branch — used to restore a site to a known version. Refuses to run when the tree has uncommitted changes unless force=true, since those edits exist only on that server. Destructive: changes files on the site.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'  => ['type' => 'string', 'description' => 'Plugin directory name.'],
                'ref'   => ['type' => 'string', 'description' => 'Commit SHA, tag or branch to check out.'],
                'force' => ['type' => 'boolean', 'description' => 'Discard uncommitted changes. Default false.'],
            ],
            'required' => ['slug', 'ref'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug  = (string) ($arguments['slug'] ?? '');
        $ref   = (string) ($arguments['ref'] ?? '');
        $force = (bool) ($arguments['force'] ?? false);

        $result = $this->git->checkout($slug, $ref, $force);

        return $this->text(
            sprintf('%s moved from %s to %s.', $slug, substr($result['previous_head'], 0, 7), substr($result['head'], 0, 7)),
            $result
        );
    }
}
