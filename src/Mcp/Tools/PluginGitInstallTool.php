<?php
/**
 * `plugin_git_install` — install or update a plugin as a git working tree.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\PluginGit;
use OxaAi\Site\PluginService;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginGitInstallTool extends AbstractTool
{
    public function __construct(
        private readonly PluginGit $git,
        private readonly PluginService $plugins,
    ) {}

    public function name(): string        { return 'plugin_git_install'; }
    public function description(): string { return 'Install or update a plugin as a git working tree: clones when absent, fetches and checks out when already tracked, and adopts an existing untracked directory in place without rewriting files. Preserves history, unlike the zip installer which replaces the whole directory. Destructive: writes to the site.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'            => ['type' => 'string', 'description' => 'Plugin directory name to install into.'],
                'repo_url'        => ['type' => 'string', 'description' => 'Clone URL, e.g. https://github.com/acme/widget.git'],
                'ref'             => ['type' => 'string', 'description' => 'Branch, tag or commit to check out.'],
                'token'           => ['type' => 'string', 'description' => 'Optional credential for a private repo. Used for this call only — never written to .git/config or the remote URL.'],
                'activate'        => ['type' => 'boolean', 'description' => 'Activate after install. Default true.'],
                'adopt_existing'  => ['type' => 'boolean', 'description' => 'Convert an existing untracked directory in place instead of refusing. Default true; file contents are never rewritten.'],
            ],
            'required' => ['slug', 'repo_url', 'ref'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $slug     = (string) ($arguments['slug'] ?? '');
        $repoUrl  = (string) ($arguments['repo_url'] ?? '');
        $ref      = (string) ($arguments['ref'] ?? '');
        $token    = isset($arguments['token']) && is_string($arguments['token']) && $arguments['token'] !== ''
            ? $arguments['token'] : null;
        $activate = array_key_exists('activate', $arguments) ? (bool) $arguments['activate'] : true;
        $adopt    = array_key_exists('adopt_existing', $arguments) ? (bool) $arguments['adopt_existing'] : true;

        $result = $this->git->deploy($slug, $repoUrl, $ref, $token, $adopt);

        // Report the same shape the zip installer does, so the hub records
        // version and plugin_file identically whichever path ran.
        $file = $this->plugins->resolveInstalledFile($slug);
        $result['file'] = $file;
        $result['version'] = $file !== null ? $this->plugins->versionForInstalledFile($file) : null;

        if ($activate && $file !== null) {
            $this->plugins->activateInstalledFile($file);
            $result['active'] = true;
        } else {
            $result['active'] = false;
        }

        return $this->text(
            sprintf(
                '%s %s at %s%s.',
                $slug,
                $result['mode'] === 'clone' ? 'cloned' : ($result['mode'] === 'adopt' ? 'adopted' : 'updated'),
                substr($result['head'], 0, 7),
                $result['dirty_files'] > 0 ? sprintf(' (%d local change(s) preserved)', $result['dirty_files']) : ''
            ),
            $result
        );
    }
}
