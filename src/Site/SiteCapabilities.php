<?php
/**
 * Answer "could this host run git-backed plugin installs?"
 *
 * Migrating installs from zip extraction to git working trees depends on
 * facts that vary per host and cannot be assumed: whether proc_open is
 * enabled, whether a git binary exists, whether the web user can write to
 * the plugins directory, and — the decisive one — whether this server can
 * actually reach a private GitHub repo.
 *
 * Read-only. Nothing here writes to disk or mutates the site.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteCapabilities
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly PluginService $plugins,
    ) {}

    /**
     * @param  string|null  $probeRepo  Clone URL to test read access against,
     *                                  e.g. git@github.com:acme/widget.git
     * @return array<string,mixed>
     */
    public function report(?string $probeRepo = null): array
    {
        return [
            'php'     => $this->php(),
            'git'     => $this->gitBinary(),
            'paths'   => $this->paths(),
            'plugins' => $this->pluginRepos(),
            'github'  => $probeRepo === null ? null : $this->probeRepo($probeRepo),
        ];
    }

    /** @return array<string,mixed> */
    private function php(): array
    {
        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

        $user = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());
            $user = is_array($info) ? ($info['name'] ?? null) : null;
        }
        $user ??= (function_exists('get_current_user') ? get_current_user() : null);

        return [
            'version'            => PHP_VERSION,
            'user'               => $user ?: null,
            'proc_open'          => function_exists('proc_open'),
            'disabled_functions' => array_values($disabled),
        ];
    }

    /** @return array<string,mixed> */
    private function gitBinary(): array
    {
        if (!function_exists('proc_open')) {
            return ['available' => false, 'binary' => null, 'version' => null, 'error' => 'proc_open is disabled'];
        }

        // Report the resolved path separately from the run: "no binary on
        // this host" and "binary exists but would not execute" need
        // different fixes, and conflating them cost a deploy cycle once.
        try {
            $binary = $this->git->resolveBinary();
        } catch (\RuntimeException $e) {
            return ['available' => false, 'binary' => null, 'version' => null, 'error' => $e->getMessage()];
        }

        $result = $this->git->tryRun(ABSPATH, ['--version'], [], 10);

        if ($result['exit'] !== 0) {
            return [
                'available' => false,
                'binary'    => $binary,
                'version'   => null,
                'error'     => trim($result['stderr']) ?: 'git found but would not execute',
            ];
        }

        return [
            'available' => true,
            'binary'    => $binary,
            'version'   => trim($result['stdout']),
            'error'     => null,
        ];
    }

    /** @return array<string,mixed> */
    private function paths(): array
    {
        $dir = rtrim(PluginPaths::root(), '/');

        return [
            'plugin_dir'      => $dir,
            'writable'        => is_writable($dir),
            // The hub uses this to check from OUTSIDE whether a .git
            // directory would be served over HTTP — which is the only way
            // to test that honestly.
            'plugin_url'      => defined('WP_PLUGIN_URL') ? WP_PLUGIN_URL : null,
            'home_url'        => home_url(),
        ];
    }

    /**
     * Which installed plugin directories are already git working trees.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pluginRepos(): array
    {
        $out = [];

        foreach ($this->plugins->listAll() as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            try {
                $dir = PluginPaths::dir($slug);
            } catch (\Throwable) {
                continue;
            }

            $out[] = [
                'slug'     => $slug,
                'version'  => $plugin['version'] ?? null,
                'active'   => (bool) ($plugin['active'] ?? false),
                'is_repo'  => PluginPaths::isRepo($dir),
                'writable' => is_writable($dir),
            ];
        }

        return $out;
    }

    /**
     * Can this host actually read the repo? ls-remote needs no working
     * tree and writes nothing, so it is the cheapest honest test of
     * "binary + network + credentials" in one call.
     *
     * @return array<string,mixed>
     */
    private function probeRepo(string $url): array
    {
        if (!function_exists('proc_open')) {
            return ['url' => $url, 'reachable' => false, 'error' => 'proc_open is disabled'];
        }

        $result = $this->git->tryRun(ABSPATH, ['ls-remote', '--heads', $url], [], 20);

        if ($result['exit'] !== 0) {
            return [
                'url'       => GitRunner::redactRemote($url),
                'reachable' => false,
                'error'     => trim($result['stderr']) ?: 'ls-remote failed',
            ];
        }

        $heads = array_values(array_filter(preg_split('/\R/', trim($result['stdout'])) ?: []));

        return [
            'url'       => GitRunner::redactRemote($url),
            'reachable' => true,
            'branches'  => count($heads),
            'error'     => null,
        ];
    }
}
