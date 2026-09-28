<?php
/**
 * Run git in an arbitrary working directory.
 *
 * Extracted from ThemeGit so the theme_git_* and plugin_git_* tools share
 * one hardened process launcher. Callers are responsible for deciding
 * WHICH directory is legitimate to run in — this class only runs git.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class GitRunner
{
    public const COMMIT_AUTHOR_NAME  = 'Oxa AI';
    public const COMMIT_AUTHOR_EMAIL = 'oxa-ai@local';

    /**
     * Absolute path to the git binary.
     *
     * proc_open() with a bare "git" depends on PATH being present in the
     * CHILD environment. Under FrankenPHP and most PHP-FPM setups $_ENV is
     * empty (variables_order), so the child inherited no PATH and the
     * launch failed with nothing more useful than "Failed to launch git" —
     * indistinguishable from git not being installed at all. Resolve to an
     * absolute path up front, exactly as WpCliRunner does for wp.
     */
    public function resolveBinary(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $candidates = [
            (string) (getenv('OXA_GIT_BIN') ?: ''),
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/bin/git',
        ];

        foreach ($candidates as $bin) {
            if ($bin !== '' && is_executable($bin)) {
                return $resolved = $bin;
            }
        }

        // Last resort: ask the shell, if we are allowed one.
        if (function_exists('shell_exec')) {
            $which = trim((string) @shell_exec('command -v git 2>/dev/null'));
            if ($which !== '' && is_executable($which)) {
                return $resolved = $which;
            }
        }

        throw new RuntimeException(
            'git binary not found. Install git on this host, or set the OXA_GIT_BIN environment variable to its absolute path.'
        );
    }

    /** Whether a usable git binary exists, without throwing. */
    public function isAvailable(): bool
    {
        try {
            $this->resolveBinary();

            return function_exists('proc_open');
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     *
     * @throws RuntimeException when git is unavailable or exits non-zero.
     */
    public function run(string $cwd, array $args, array $env = [], int $timeout = 30): array
    {
        $result = $this->tryRun($cwd, $args, $env, $timeout);

        if ($result['exit'] !== 0) {
            throw new RuntimeException(sprintf(
                "git %s failed (exit %d):\n%s",
                implode(' ', $args),
                $result['exit'],
                trim($result['stderr']) ?: trim($result['stdout'])
            ));
        }

        return $result;
    }

    /**
     * Same as run(), but a non-zero exit is returned rather than thrown.
     * Used for probes where "git said no" is a legitimate answer — e.g.
     * asking for an upstream that isn't configured.
     *
     * @param array<int,string>    $args
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    public function tryRun(string $cwd, array $args, array $env = [], int $timeout = 30): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is disabled; git tools are unavailable.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $merged = array_merge($_ENV ?: [], $env);
        // Force C locale for stable parsing.
        $merged['LC_ALL'] = 'C';
        // git shells out to its own helpers (git-remote-https, ssh);
        // an empty $_ENV would leave them unfindable.
        $merged['PATH'] = $merged['PATH']
            ?? (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        $merged['HOME'] = $merged['HOME'] ?? (getenv('HOME') ?: sys_get_temp_dir());
        // Never let git block the request waiting on a credential prompt.
        // Sites authenticate to GitHub with a deploy key over SSH; if that
        // key is missing we want a fast failure, not a hung PHP worker.
        $merged['GIT_TERMINAL_PROMPT'] = '0';
        $merged['GIT_ASKPASS'] = $merged['GIT_ASKPASS'] ?? 'echo';
        $merged['SSH_ASKPASS'] = $merged['SSH_ASKPASS'] ?? 'echo';
        $merged['GIT_SSH_COMMAND'] = $merged['GIT_SSH_COMMAND']
            ?? 'ssh -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new';

        $binary = $this->resolveBinary();

        $proc = proc_open(array_merge([$binary], $args), $descriptors, $pipes, $cwd, $merged);
        if (!is_resource($proc)) {
            throw new RuntimeException(sprintf('Failed to launch git (%s) in %s.', $binary, $cwd));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $timeout);
        $timedOut = false;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                // SIGTERM first, then make sure it is gone.
                proc_terminate($proc, 15);
                usleep(200000);
                if (proc_get_status($proc)['running']) {
                    proc_terminate($proc, 9);
                }
                break;
            }

            usleep(20000);
        }

        // Drain whatever landed between the last read and exit.
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        if ($timedOut) {
            $stderr = trim($stderr."\ngit timed out after {$timeout}s");
            $exit = $exit === 0 ? 124 : $exit;
        }

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /**
     * Author/committer identity for commits this plugin creates.
     *
     * @return array<string,string>
     */
    public function commitEnv(): array
    {
        return [
            'GIT_AUTHOR_NAME'     => self::COMMIT_AUTHOR_NAME,
            'GIT_AUTHOR_EMAIL'    => self::COMMIT_AUTHOR_EMAIL,
            'GIT_COMMITTER_NAME'  => self::COMMIT_AUTHOR_NAME,
            'GIT_COMMITTER_EMAIL' => self::COMMIT_AUTHOR_EMAIL,
        ];
    }

    /**
     * Strip any credentials embedded in a remote URL before it is reported
     * back to the hub. `https://user:token@github.com/...` must never be
     * echoed into a dashboard or a log.
     */
    public static function redactRemote(string $url): string
    {
        return (string) preg_replace('#://[^/@]*@#', '://***@', $url);
    }
}
