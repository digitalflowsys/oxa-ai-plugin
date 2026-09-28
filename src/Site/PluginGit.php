<?php
/**
 * Report the git state of an installed plugin's directory.
 *
 * Backs the plugin_git_* tools. This is the hub's only way to learn what
 * a site is ACTUALLY running: plugin_installations.resolved_sha records
 * what the hub shipped, which says nothing about edits made on the server
 * afterwards.
 *
 * status/log/diff are read-only. checkout() is the one mutating call,
 * and it refuses to run over uncommitted work unless explicitly forced.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use OxaAi\Core\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginGit
{
    public function __construct(
        private readonly GitRunner $git,
        private readonly Logger $logger,
    ) {}

    /**
     * Full state of one plugin directory.
     *
     * A plugin that is not a git working tree is NOT an error: most
     * installs are zipball extractions with no history at all. Those
     * return is_repo=false so the hub can show "untracked" rather than
     * a failure the user cannot act on.
     *
     * @return array{
     *     ok:bool, slug:string, is_repo:bool, head:?string, head_short:?string,
     *     branch:?string, detached:bool, clean:?bool, dirty_files:int,
     *     porcelain:string, upstream:?string, ahead:?int, behind:?int,
     *     remote:?string, committed_at:?string
     * }
     */
    public function status(string $slug): array
    {
        $dir = PluginPaths::dir($slug);

        if (!PluginPaths::isRepo($dir)) {
            return $this->untracked($slug);
        }

        $head   = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);
        $branch = trim($this->git->run($dir, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);
        $porc   = $this->git->run($dir, ['status', '--porcelain=v1'])['stdout'];
        $dirty  = array_values(array_filter(preg_split('/\R/', trim($porc)) ?: []));

        $detached = $branch === 'HEAD';

        // Upstream may legitimately be absent (detached HEAD, or a branch
        // never pushed). tryRun keeps that from reading as a failure.
        $upstreamResult = $this->git->tryRun($dir, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
        $upstream = $upstreamResult['exit'] === 0 ? trim($upstreamResult['stdout']) : null;
        $upstream = ($upstream === '' ? null : $upstream);

        $ahead = $behind = null;
        if ($upstream !== null) {
            $counts = $this->git->tryRun($dir, ['rev-list', '--left-right', '--count', 'HEAD...@{u}']);
            if ($counts['exit'] === 0 && preg_match('/^(\d+)\s+(\d+)/', trim($counts['stdout']), $m)) {
                $ahead  = (int) $m[1];
                $behind = (int) $m[2];
            }
        }

        $remoteResult = $this->git->tryRun($dir, ['config', '--get', 'remote.origin.url']);
        $remote = $remoteResult['exit'] === 0 ? trim($remoteResult['stdout']) : null;
        $remote = ($remote === null || $remote === '') ? null : GitRunner::redactRemote($remote);

        $dateResult = $this->git->tryRun($dir, ['log', '-1', '--date=iso-strict', '--pretty=format:%cd']);
        $committedAt = $dateResult['exit'] === 0 ? trim($dateResult['stdout']) : null;

        return [
            'ok'           => true,
            'slug'         => $slug,
            'is_repo'      => true,
            'head'         => $head === '' ? null : $head,
            'head_short'   => $head === '' ? null : substr($head, 0, 7),
            'branch'       => $detached ? null : $branch,
            'detached'     => $detached,
            'clean'        => $dirty === [],
            'dirty_files'  => count($dirty),
            'porcelain'    => $porc,
            'upstream'     => $upstream,
            'ahead'        => $ahead,
            'behind'       => $behind,
            'remote'       => $remote,
            'committed_at' => ($committedAt === '' ? null : $committedAt),
        ];
    }

    /**
     * Recent commits for a plugin directory.
     *
     * @return array{ok:bool,slug:string,is_repo:bool,commits:array<int,array{hash:string,short:string,subject:string,author:string,date:string}>}
     */
    public function log(string $slug, int $limit = 20): array
    {
        $dir = PluginPaths::dir($slug);

        if (!PluginPaths::isRepo($dir)) {
            return ['ok' => true, 'slug' => $slug, 'is_repo' => false, 'commits' => []];
        }

        $limit = max(1, min($limit, 200));
        $sep = "\x1f";
        $rs  = "\x1e";
        $fmt = "%H{$sep}%s{$sep}%an <%ae>{$sep}%ad{$rs}";

        $out = $this->git->run($dir, [
            'log', '--no-color', '--date=iso-strict', '-n', (string) $limit, "--pretty=format:{$fmt}",
        ])['stdout'];

        $commits = [];
        foreach (explode($rs, $out) as $row) {
            $row = trim($row, "\n\r");
            if ($row === '') {
                continue;
            }
            $parts = explode($sep, $row);
            if (count($parts) < 4) {
                continue;
            }
            $commits[] = [
                'hash'    => $parts[0],
                'short'   => substr($parts[0], 0, 7),
                'subject' => $parts[1],
                'author'  => $parts[2],
                'date'    => $parts[3],
            ];
        }

        return ['ok' => true, 'slug' => $slug, 'is_repo' => true, 'commits' => $commits];
    }

    /**
     * Uncommitted changes in a plugin directory — what a hand-edit on the
     * server looks like before anyone commits it.
     *
     * @return array{ok:bool,slug:string,is_repo:bool,diff:string,paths:array<int,string>,truncated:bool}
     */
    public function diff(string $slug, int $maxBytes = 200000): array
    {
        $dir = PluginPaths::dir($slug);

        if (!PluginPaths::isRepo($dir)) {
            return ['ok' => true, 'slug' => $slug, 'is_repo' => false, 'diff' => '', 'paths' => [], 'truncated' => false];
        }

        $diff = $this->git->run($dir, ['diff', '--no-color', 'HEAD'])['stdout'];
        $names = array_values(array_filter(
            preg_split('/\R/', $this->git->run($dir, ['diff', '--name-only', 'HEAD'])['stdout']) ?: []
        ));

        $truncated = false;
        if (strlen($diff) > $maxBytes) {
            $diff = substr($diff, 0, $maxBytes);
            $truncated = true;
        }

        return [
            'ok'        => true,
            'slug'      => $slug,
            'is_repo'   => true,
            'diff'      => $diff,
            'paths'     => $names,
            'truncated' => $truncated,
        ];
    }

    /**
     * Move a plugin's working tree to a specific commit.
     *
     * Used to resolve drift by restoring the commit the hub recorded. This
     * is the only call here that writes, so it is deliberately strict:
     *
     *  - a tree with uncommitted changes is refused unless $force, because
     *    those edits exist nowhere else and a checkout would discard them
     *  - the ref must already be present locally, or be fetchable; we never
     *    silently end up on a different commit than asked for
     *  - the result reports the resulting HEAD so the caller can verify
     *    rather than assume
     *
     * @return array{ok:bool,slug:string,previous_head:string,head:string,detached:bool,forced:bool}
     */
    public function checkout(string $slug, string $ref, bool $force = false): array
    {
        $dir = PluginPaths::dir($slug);

        if (!PluginPaths::isRepo($dir)) {
            throw new RuntimeException(sprintf('%s is not a git working tree; nothing to check out.', $slug));
        }

        // Anything git would accept as a rev, without shell metacharacters.
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,255}$/', $ref) || str_contains($ref, '..')) {
            throw new RuntimeException('Invalid ref.');
        }

        $previous = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);

        $porcelain = trim($this->git->run($dir, ['status', '--porcelain=v1'])['stdout']);
        if ($porcelain !== '' && !$force) {
            $count = count(array_filter(preg_split('/\R/', $porcelain) ?: []));
            throw new RuntimeException(sprintf(
                '%s has %d uncommitted change(s). Those edits exist only on this server and a checkout would discard them. Commit or stash them first, or pass force=true to discard them deliberately.',
                $slug,
                $count
            ));
        }

        // Fetch only when the ref is not already known, so a restore to an
        // older commit keeps working on hosts with no GitHub credentials.
        if ($this->git->tryRun($dir, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}'])['exit'] !== 0) {
            $fetch = $this->git->tryRun($dir, ['fetch', '--tags', 'origin'], [], 60);
            if ($fetch['exit'] !== 0) {
                throw new RuntimeException(sprintf(
                    'Ref %s is not present locally and fetching from origin failed: %s',
                    $ref,
                    trim($fetch['stderr']) ?: 'unknown error'
                ));
            }
            if ($this->git->tryRun($dir, ['rev-parse', '--verify', '--quiet', $ref.'^{commit}'])['exit'] !== 0) {
                throw new RuntimeException(sprintf('Ref %s does not exist in this repository.', $ref));
            }
        }

        $args = ['checkout'];
        if ($force) {
            $args[] = '--force';
        }
        // Detach deliberately: restoring to a recorded commit pins the site
        // to that commit, and pretending it is "on a branch" would misreport
        // the state at the next status check.
        $args[] = '--detach';
        $args[] = $ref;

        $this->git->run($dir, $args, [], 60);

        $head = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);
        $branch = trim($this->git->run($dir, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);

        $this->logger->info('plugin git checkout', [
            'slug' => $slug, 'from' => $previous, 'to' => $head, 'forced' => $force,
        ]);

        return [
            'ok'            => true,
            'slug'          => $slug,
            'previous_head' => $previous,
            'head'          => $head,
            'detached'      => $branch === 'HEAD',
            'forced'        => $force,
        ];
    }

    /**
     * Install or update a plugin as a git working tree.
     *
     * Replaces the zip path for git-capable hosts. The upgrader deletes the
     * plugin directory wholesale, so installing over a git-backed plugin
     * would destroy the history that makes drift detectable — the exact
     * trap that made converting a site to git leave it MORE fragile than
     * leaving it as an archive.
     *
     * Three situations, resolved here rather than by the caller guessing:
     *
     *   clone    nothing on disk — a fresh git install at $ref
     *   update   already a working tree — fetch and check out $ref
     *   adopt    files present but untracked (an old zip install) — wrap
     *            them in a repo WITHOUT touching file contents, so any
     *            local modifications survive and surface as drift
     *
     * A credential is used for this call only: it is passed to git through
     * the environment, never written into .git/config or the remote URL,
     * so nothing durable is left on the site.
     *
     * @return array{ok:bool,slug:string,mode:string,head:string,previous_head:?string,detached:bool,dirty_files:int,dir:string}
     */
    public function deploy(
        string $slug,
        string $repoUrl,
        string $ref,
        ?string $token = null,
        bool $adoptExisting = true,
    ): array {
        if (!preg_match('#^https://[A-Za-z0-9._\-/]+$#', $repoUrl) && !preg_match('#^git@[A-Za-z0-9._\-]+:[A-Za-z0-9._\-/]+$#', $repoUrl)) {
            throw new RuntimeException('Invalid repository URL.');
        }
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,255}$/', $ref) || str_contains($ref, '..')) {
            throw new RuntimeException('Invalid ref.');
        }

        $dir = PluginPaths::targetDir($slug);
        $env = $this->credentialEnv($token);

        if (!is_dir($dir)) {
            return $this->cloneFresh($slug, $dir, $repoUrl, $ref, $env);
        }

        if (PluginPaths::isRepo($dir)) {
            return $this->updateExisting($slug, $dir, $ref, $env);
        }

        if (!$adoptExisting) {
            throw new RuntimeException(sprintf(
                '%s already exists but is not a git working tree. Pass adopt_existing=true to convert it in place, or uninstall it first.',
                $slug
            ));
        }

        return $this->adoptExisting($slug, $dir, $repoUrl, $ref, $env);
    }

    /** @param array<string,string> $env */
    private function cloneFresh(string $slug, string $dir, string $repoUrl, string $ref, array $env): array
    {
        $parent = dirname($dir);
        if (!is_writable($parent)) {
            throw new RuntimeException(sprintf('Plugins directory is not writable: %s', $parent));
        }

        // Clone the clean URL: any credential travels via $env, so nothing
        // secret is persisted into .git/config by the clone itself.
        $this->git->run($parent, ['clone', '--no-checkout', $repoUrl, $slug], $env, 300);
        $this->checkoutRef($dir, $ref, $env, force: true);

        $head = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);
        $this->logger->info('plugin git clone', ['slug' => $slug, 'ref' => $ref, 'head' => $head]);

        return $this->result($slug, 'clone', $dir, $head, null, 0);
    }

    /** @param array<string,string> $env */
    private function updateExisting(string $slug, string $dir, string $ref, array $env): array
    {
        $previous = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);

        $porcelain = trim($this->git->run($dir, ['status', '--porcelain=v1'])['stdout']);
        $dirty = count(array_filter(preg_split('/\R/', $porcelain) ?: []));
        if ($dirty > 0) {
            throw new RuntimeException(sprintf(
                '%s has %d uncommitted change(s) on this server. Updating would discard them. Commit or stash them first.',
                $slug,
                $dirty
            ));
        }

        $this->git->run($dir, ['fetch', '--tags', 'origin'], $env, 120);
        $this->checkoutRef($dir, $ref, $env, force: false);

        $head = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);
        $this->logger->info('plugin git update', ['slug' => $slug, 'from' => $previous, 'to' => $head]);

        return $this->result($slug, 'update', $dir, $head, $previous, 0);
    }

    /**
     * Wrap an existing untracked directory in a repo without rewriting a
     * single file: init, fetch, then reset --mixed. The working tree is
     * left exactly as found, so anything edited on the server survives and
     * shows up as drift instead of being silently overwritten.
     *
     * @param array<string,string> $env
     */
    private function adoptExisting(string $slug, string $dir, string $repoUrl, string $ref, array $env): array
    {
        if (!is_writable($dir)) {
            throw new RuntimeException(sprintf('%s is not writable.', $dir));
        }

        $this->git->run($dir, ['init', '-q']);
        if ($this->git->tryRun($dir, ['remote', 'get-url', 'origin'])['exit'] !== 0) {
            $this->git->run($dir, ['remote', 'add', 'origin', $repoUrl]);
        }
        $this->git->run($dir, ['fetch', '--tags', 'origin'], $env, 300);

        $target = $this->resolveRef($dir, $ref);
        // --mixed: moves HEAD and the index, never the working tree.
        $this->git->run($dir, ['reset', '--mixed', $target]);

        $porcelain = trim($this->git->run($dir, ['status', '--porcelain=v1'])['stdout']);
        $dirty = count(array_filter(preg_split('/\R/', $porcelain) ?: []));

        $head = trim($this->git->run($dir, ['rev-parse', 'HEAD'])['stdout']);
        $this->logger->info('plugin git adopt', ['slug' => $slug, 'head' => $head, 'dirty_files' => $dirty]);

        return $this->result($slug, 'adopt', $dir, $head, null, $dirty);
    }

    /** @param array<string,string> $env */
    private function checkoutRef(string $dir, string $ref, array $env, bool $force): void
    {
        $target = $this->resolveRef($dir, $ref);
        $args = ['checkout'];
        if ($force) {
            $args[] = '--force';
        }
        $args[] = '--detach';
        $args[] = $target;
        $this->git->run($dir, $args, $env, 120);
    }

    /**
     * Turn a caller-supplied ref into something checkoutable, preferring the
     * remote-tracking copy so "main" means origin's main rather than a stale
     * local branch.
     */
    private function resolveRef(string $dir, string $ref): string
    {
        foreach (['refs/remotes/origin/'.$ref, $ref] as $candidate) {
            if ($this->git->tryRun($dir, ['rev-parse', '--verify', '--quiet', $candidate.'^{commit}'])['exit'] === 0) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('Ref %s does not exist in this repository.', $ref));
    }

    /**
     * Credential plumbing for one invocation.
     *
     * The token goes in the environment and is read by an inline credential
     * helper, so it never reaches argv (visible in ps) nor .git/config
     * (persisted on disk). The helper string itself holds no secret.
     *
     * @return array<string,string>
     */
    private function credentialEnv(?string $token): array
    {
        if ($token === null || $token === '') {
            return [];
        }

        return [
            'OXA_GIT_TOKEN' => $token,
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'credential.helper',
            'GIT_CONFIG_VALUE_0' => '!f() { echo username=x-access-token; echo password=$OXA_GIT_TOKEN; }; f',
        ];
    }

    /**
     * @return array{ok:bool,slug:string,mode:string,head:string,previous_head:?string,detached:bool,dirty_files:int,dir:string}
     */
    private function result(string $slug, string $mode, string $dir, string $head, ?string $previous, int $dirty): array
    {
        $branch = trim($this->git->run($dir, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);

        return [
            'ok' => true,
            'slug' => $slug,
            'mode' => $mode,
            'head' => $head,
            'previous_head' => $previous,
            'detached' => $branch === 'HEAD',
            'dirty_files' => $dirty,
            'dir' => $dir,
        ];
    }

    /**
     * @return array{ok:bool,slug:string,is_repo:bool,head:null,head_short:null,branch:null,detached:bool,clean:null,dirty_files:int,porcelain:string,upstream:null,ahead:null,behind:null,remote:null,committed_at:null}
     */
    private function untracked(string $slug): array
    {
        return [
            'ok'           => true,
            'slug'         => $slug,
            'is_repo'      => false,
            'head'         => null,
            'head_short'   => null,
            'branch'       => null,
            'detached'     => false,
            'clean'        => null,
            'dirty_files'  => 0,
            'porcelain'    => '',
            'upstream'     => null,
            'ahead'        => null,
            'behind'       => null,
            'remote'       => null,
            'committed_at' => null,
        ];
    }
}
