<?php
/**
 * Run git inside the active theme directory.
 *
 * Backs the theme_git_* tools. Each method validates that the theme
 * dir is actually a git working tree and shells out via Process safely.
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

final class ThemeGit
{
    private const COMMIT_AUTHOR_NAME  = 'Oxa AI';
    private const COMMIT_AUTHOR_EMAIL = 'oxa-ai@local';

    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array{ok:bool,is_repo:bool,branch:?string,porcelain:string,clean:bool}
     */
    public function status(string $scope): array
    {
        $root = $this->repoRoot($scope);
        $branch = trim($this->run($root, ['rev-parse', '--abbrev-ref', 'HEAD'])['stdout']);
        $porc   = $this->run($root, ['status', '--porcelain=v1'])['stdout'];
        return [
            'ok'        => true,
            'is_repo'   => true,
            'branch'    => $branch === '' ? null : $branch,
            'porcelain' => $porc,
            'clean'     => trim($porc) === '',
        ];
    }

    /**
     * @return array{ok:bool,diff:string,paths:array<int,string>}
     */
    public function diff(string $scope, bool $staged = false, ?string $path = null): array
    {
        $root = $this->repoRoot($scope);
        $args = ['diff', '--no-color'];
        if ($staged) {
            $args[] = '--staged';
        }
        if (is_string($path) && $path !== '') {
            $args[] = '--';
            $args[] = $path;
        }
        $diff = $this->run($root, $args)['stdout'];

        $listArgs = ['diff', '--name-only'];
        if ($staged) $listArgs[] = '--staged';
        $names = array_values(array_filter(preg_split('/\R/', $this->run($root, $listArgs)['stdout']) ?: []));

        return ['ok' => true, 'diff' => $diff, 'paths' => $names];
    }

    /**
     * @return array{ok:bool,commits:array<int,array{hash:string,subject:string,author:string,date:string}>}
     */
    public function log(string $scope, int $limit = 20): array
    {
        $root = $this->repoRoot($scope);
        $limit = max(1, min($limit, 200));
        // Use a sentinel-delimited format that's safe to parse.
        $sep = "\x1f"; $rs = "\x1e";
        $fmt = "%H{$sep}%s{$sep}%an <%ae>{$sep}%ad{$rs}";
        $out = $this->run($root, ['log', '--no-color', '--date=iso-strict', '-n', (string) $limit, "--pretty=format:{$fmt}"])['stdout'];
        $commits = [];
        foreach (explode($rs, $out) as $row) {
            $row = trim($row, "\n\r");
            if ($row === '') continue;
            $parts = explode($sep, $row);
            if (count($parts) < 4) continue;
            $commits[] = [
                'hash'    => $parts[0],
                'subject' => $parts[1],
                'author'  => $parts[2],
                'date'    => $parts[3],
            ];
        }
        return ['ok' => true, 'commits' => $commits];
    }

    /**
     * @return array{ok:bool,hash:?string,added:int,message:string}
     */
    public function commit(string $scope, string $message, array $paths = []): array
    {
        $root = $this->repoRoot($scope);
        if ($message === '') {
            throw new RuntimeException('Commit message is required.');
        }
        if (empty($paths)) {
            $this->run($root, ['add', '-A']);
        } else {
            foreach ($paths as $p) {
                if (!is_string($p) || $p === '') continue;
                $this->run($root, ['add', '--', $p]);
            }
        }

        $staged = trim($this->run($root, ['diff', '--cached', '--name-only'])['stdout']);
        if ($staged === '') {
            return ['ok' => true, 'hash' => null, 'added' => 0, 'message' => 'Nothing to commit; working tree clean.'];
        }
        $env = [
            'GIT_AUTHOR_NAME'     => self::COMMIT_AUTHOR_NAME,
            'GIT_AUTHOR_EMAIL'    => self::COMMIT_AUTHOR_EMAIL,
            'GIT_COMMITTER_NAME'  => self::COMMIT_AUTHOR_NAME,
            'GIT_COMMITTER_EMAIL' => self::COMMIT_AUTHOR_EMAIL,
        ];
        $this->run($root, ['commit', '-m', $message], $env);
        $hash = trim($this->run($root, ['rev-parse', 'HEAD'])['stdout']);

        $this->logger->info('theme git commit', ['hash' => $hash, 'message' => $message]);
        return [
            'ok'      => true,
            'hash'    => $hash,
            'added'   => count(array_filter(preg_split('/\R/', $staged) ?: [])),
            'message' => $message,
        ];
    }

    /**
     * Revert one commit (default HEAD) without opening an editor.
     *
     * @return array{ok:bool,reverted:string,new_head:string}
     */
    public function revert(string $scope, string $ref = 'HEAD'): array
    {
        $root = $this->repoRoot($scope);
        if (!preg_match('/^[A-Za-z0-9_./-]+$/', $ref)) {
            throw new RuntimeException('Invalid ref.');
        }
        $env = [
            'GIT_AUTHOR_NAME'     => self::COMMIT_AUTHOR_NAME,
            'GIT_AUTHOR_EMAIL'    => self::COMMIT_AUTHOR_EMAIL,
            'GIT_COMMITTER_NAME'  => self::COMMIT_AUTHOR_NAME,
            'GIT_COMMITTER_EMAIL' => self::COMMIT_AUTHOR_EMAIL,
        ];
        $this->run($root, ['revert', '--no-edit', $ref], $env);
        $head = trim($this->run($root, ['rev-parse', 'HEAD'])['stdout']);
        $this->logger->info('theme git revert', ['ref' => $ref, 'new_head' => $head]);
        return ['ok' => true, 'reverted' => $ref, 'new_head' => $head];
    }

    // -------- internals --------

    private function repoRoot(string $scope): string
    {
        $root = rtrim(ThemePaths::root($scope), '/');
        if (!is_dir($root . '/.git')) {
            // Walk up: maybe the repo is one or two levels above the theme dir.
            $cursor = $root;
            for ($i = 0; $i < 6; $i++) {
                if (is_dir($cursor . '/.git')) {
                    return $cursor;
                }
                $parent = dirname($cursor);
                if ($parent === $cursor) break;
                $cursor = $parent;
            }
            throw new RuntimeException(sprintf('Theme directory is not inside a git working tree: %s', $root));
        }
        return $root;
    }

    /**
     * @param array<int,string> $args
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function run(string $cwd, array $args, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is disabled; git tools are unavailable.');
        }
        $cmd = array_merge(['git'], $args);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $merged = array_merge($_ENV ?: [], $env);
        // Force C locale for stable parsing.
        $merged['LC_ALL'] = 'C';

        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $merged);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to launch git.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf("git %s failed (exit %d):\n%s", implode(' ', $args), $exit, trim($stderr) ?: trim($stdout)));
        }
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }
}
