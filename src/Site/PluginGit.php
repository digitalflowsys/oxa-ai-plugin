<?php
/**
 * Report the git state of an installed plugin's directory.
 *
 * Backs the plugin_git_* tools. This is the hub's only way to learn what
 * a site is ACTUALLY running: plugin_installations.resolved_sha records
 * what the hub shipped, which says nothing about edits made on the server
 * afterwards.
 *
 * Read-only by design. Nothing here writes to the working tree.
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
