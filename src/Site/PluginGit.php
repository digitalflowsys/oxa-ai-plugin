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
