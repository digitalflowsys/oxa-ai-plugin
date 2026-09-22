<?php
/**
 * Shell out to WP-CLI with an allow-list / acknowledge gate.
 *
 * The caller passes a command WITHOUT the leading "wp" prefix
 * (matching the bridge contract). Destructive families require
 * `acknowledge: true` or they are rejected before exec.
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

final class WpCliRunner
{
    /** Verb prefixes that are blocked unless `acknowledge=true`. */
    private const DESTRUCTIVE_PATTERNS = [
        '/^db\s+drop\b/i',
        '/^db\s+reset\b/i',
        '/^db\s+import\b/i',
        '/^plugin\s+install\b/i',
        '/^plugin\s+delete\b/i',
        '/^plugin\s+uninstall\b/i',
        '/^theme\s+install\b/i',
        '/^theme\s+delete\b/i',
        '/^core\s+update\b/i',
        '/^core\s+install\b/i',
        '/^core\s+download\b/i',
        '/^user\s+delete\b/i',
        '/^site\s+empty\b/i',
        '/^site\s+delete\b/i',
        '/^eval\b/i',
        '/^eval-file\b/i',
        '/^search-replace(?!\s+(?:.*\s)?--dry-run)/i',
    ];

    /** Verbs that are never allowed at all on this site. */
    private const HARD_FORBIDDEN_PATTERNS = [
        '/^shell\b/i',
        '/^cli\s+update\b/i',
    ];

    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array{stdout:string,stderr:string,exit:int,binary:string,duration_ms:int}
     */
    public function run(string $command, ?string $cwd = null, int $timeout = 60, bool $acknowledge = false, bool $dryRun = false): array
    {
        $command = trim($command);
        if ($command === '') {
            throw new RuntimeException('Command is empty.');
        }
        if (preg_match('/^wp\s+/i', $command)) {
            throw new RuntimeException('Pass the command WITHOUT the leading "wp" prefix.');
        }
        foreach (self::HARD_FORBIDDEN_PATTERNS as $re) {
            if (preg_match($re, $command)) {
                throw new RuntimeException('That command is not permitted via the MCP runner.');
            }
        }
        foreach (self::DESTRUCTIVE_PATTERNS as $re) {
            if (preg_match($re, $command) && !$acknowledge) {
                throw new RuntimeException('This command is destructive. Re-call with acknowledge=true to proceed.');
            }
        }
        if ($dryRun) {
            return ['stdout' => '(dry-run) wp ' . $command, 'stderr' => '', 'exit' => 0, 'binary' => 'wp', 'duration_ms' => 0];
        }

        $binary = $this->resolveBinary();
        $args   = $this->tokenize($command);
        // Always run with --path pointing at ABSPATH so wp finds the install.
        $args[] = '--path=' . rtrim(ABSPATH, '/\\');

        $cwd = $cwd !== null && $cwd !== '' ? $this->resolveCwd($cwd) : rtrim(ABSPATH, '/\\');
        $timeout = max(5, min($timeout, 300));

        $start = microtime(true);
        $result = $this->exec($binary, $args, $cwd, $timeout);
        $duration = (int) ((microtime(true) - $start) * 1000);

        $this->logger->info('wp-cli', [
            'command'  => $command,
            'exit'     => $result['exit'],
            'duration' => $duration,
        ]);
        return [
            'stdout'      => $result['stdout'],
            'stderr'      => $result['stderr'],
            'exit'        => $result['exit'],
            'binary'      => $binary,
            'duration_ms' => $duration,
        ];
    }

    public function isAvailable(): bool
    {
        try {
            $this->resolveBinary();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function resolveBinary(): string
    {
        $candidates = [
            getenv('OXA_WP_CLI_BIN') ?: '',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            'wp',
        ];
        foreach ($candidates as $bin) {
            if ($bin === '') continue;
            if ($bin === 'wp') {
                $which = trim((string) @shell_exec('command -v wp 2>/dev/null'));
                if ($which !== '' && is_executable($which)) {
                    return $which;
                }
                continue;
            }
            if (is_executable($bin)) {
                return $bin;
            }
        }
        throw new RuntimeException('WP-CLI binary not found. Install it or set OXA_WP_CLI_BIN.');
    }

    private function resolveCwd(string $cwd): string
    {
        $abs = rtrim(ABSPATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($cwd, '/\\');
        $abs = realpath($abs) ?: '';
        if ($abs === '' || !is_dir($abs)) {
            throw new RuntimeException('Invalid cwd.');
        }
        if (!str_starts_with($abs, rtrim(ABSPATH, '/\\'))) {
            throw new RuntimeException('cwd must be inside the WordPress installation.');
        }
        return $abs;
    }

    /**
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function exec(string $bin, array $args, string $cwd, int $timeout): array
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is disabled; cannot execute WP-CLI.');
        }
        $cmd = array_merge([$bin], $args);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = array_merge($_ENV ?: [], ['LC_ALL' => 'C']);
        $proc = proc_open($cmd, $desc, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('Could not launch WP-CLI.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $timeout;
        $stdout = '';
        $stderr = '';
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $stderr .= "\n[wp-cli killed after {$timeout}s timeout]";
                break;
            }
            usleep(50_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /**
     * Lightweight shell-style tokenizer that respects single/double quotes
     * and backslash escapes. We avoid passing through `sh -c` entirely.
     *
     * @return array<int,string>
     */
    private function tokenize(string $cmd): array
    {
        $tokens = [];
        $buf = '';
        $len = strlen($cmd);
        $i = 0;
        $quote = null;
        while ($i < $len) {
            $c = $cmd[$i];
            if ($quote !== null) {
                if ($c === '\\' && $quote === '"' && $i + 1 < $len) {
                    $buf .= $cmd[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                } else {
                    $buf .= $c;
                }
            } else {
                if ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '\\' && $i + 1 < $len) {
                    $buf .= $cmd[++$i];
                } elseif (preg_match('/\s/', $c)) {
                    if ($buf !== '') { $tokens[] = $buf; $buf = ''; }
                } else {
                    $buf .= $c;
                }
            }
            $i++;
        }
        if ($buf !== '') $tokens[] = $buf;
        if ($quote !== null) {
            throw new RuntimeException('Unterminated quoted string in command.');
        }
        return $tokens;
    }
}
