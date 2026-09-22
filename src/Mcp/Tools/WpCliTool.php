<?php
/**
 * `wp_cli` — run an arbitrary WP-CLI command.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\WpCliRunner;

if (!defined('ABSPATH')) {
    exit;
}

final class WpCliTool extends AbstractTool
{
    public function __construct(private readonly WpCliRunner $runner) {}

    public function name(): string        { return 'wp_cli'; }
    public function description(): string { return 'Run a WP-CLI command. Pass the command WITHOUT the leading "wp" prefix (e.g. "cache flush", "transient delete --all", "search-replace old.com new.com --dry-run"). Destructive commands (db drop, plugin install/delete, theme install/delete, core update, search-replace without --dry-run, eval, user delete, site empty/delete) require acknowledge=true. Default timeout 60s (max 300s).'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command'     => ['type' => 'string', 'description' => 'WP-CLI command without the leading "wp" prefix.'],
                'cwd'         => ['type' => 'string', 'description' => 'Working dir relative to WP root. Defaults to WP root (ABSPATH).'],
                'timeout'     => ['type' => 'integer', 'minimum' => 5, 'maximum' => 300, 'description' => 'Seconds before the process is killed. Default 60.'],
                'acknowledge' => ['type' => 'boolean', 'description' => 'Required for destructive commands.'],
                'dry_run'     => ['type' => 'boolean', 'description' => 'If true, return the command that would be executed without running it.'],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $command     = (string) ($arguments['command'] ?? '');
        $cwd         = isset($arguments['cwd']) && is_string($arguments['cwd']) ? $arguments['cwd'] : null;
        $timeout     = isset($arguments['timeout']) ? (int) $arguments['timeout'] : 60;
        $acknowledge = (bool) ($arguments['acknowledge'] ?? false);
        $dryRun      = (bool) ($arguments['dry_run'] ?? false);

        if (!$this->runner->isAvailable() && !$dryRun) {
            return $this->error('WP-CLI binary not found on this host. Install wp-cli or set the OXA_WP_CLI_BIN env var.');
        }

        $result = $this->runner->run($command, $cwd, $timeout, $acknowledge, $dryRun);
        $summary = sprintf('exit=%d (%dms)', $result['exit'], $result['duration_ms']);
        return $this->text($summary, $result);
    }
}
