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
     * @param array<int,string>    $args
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     *
     * @throws RuntimeException when git is unavailable or exits non-zero.
     */
    public function run(string $cwd, array $args, array $env = []): array
    {
        $result = $this->tryRun($cwd, $args, $env);

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
    public function tryRun(string $cwd, array $args, array $env = []): array
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
        // Never let git block the request waiting on a credential prompt.
        // Sites authenticate to GitHub with a deploy key over SSH; if that
        // key is missing we want a fast failure, not a hung PHP worker.
        $merged['GIT_TERMINAL_PROMPT'] = '0';
        $merged['GIT_ASKPASS'] = $merged['GIT_ASKPASS'] ?? 'echo';
        $merged['SSH_ASKPASS'] = $merged['SSH_ASKPASS'] ?? 'echo';

        $proc = proc_open(array_merge(['git'], $args), $descriptors, $pipes, $cwd, $merged);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to launch git.');
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

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
