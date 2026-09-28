<?php
/**
 * `site_capabilities` — can this host run git-backed plugin installs?
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\SiteCapabilities;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteCapabilitiesTool extends AbstractTool
{
    public function __construct(private readonly SiteCapabilities $caps) {}

    public function name(): string        { return 'site_capabilities'; }
    public function description(): string { return 'Report whether this host can support git-backed plugin installs: PHP user and disabled functions, git binary and version, plugins-directory writability, which plugin directories are already git working trees, and optionally whether a given repo URL is actually reachable from this server. Read-only.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'probe_repo' => [
                    'type' => 'string',
                    'description' => 'Optional clone URL to test read access against, e.g. git@github.com:acme/widget.git. Runs ls-remote, which writes nothing.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $probe = isset($arguments['probe_repo']) && is_string($arguments['probe_repo']) && $arguments['probe_repo'] !== ''
            ? $arguments['probe_repo']
            : null;

        $report = $this->caps->report($probe);

        $summary = sprintf(
            'git %s · proc_open %s · plugins dir %s',
            $report['git']['available'] ? $report['git']['version'] : 'UNAVAILABLE',
            $report['php']['proc_open'] ? 'on' : 'OFF',
            $report['paths']['writable'] ? 'writable' : 'NOT writable'
        );

        if (is_array($report['github'])) {
            $summary .= ' · repo ' . ($report['github']['reachable'] ? 'reachable' : 'UNREACHABLE');
        }

        return $this->text($summary, $report);
    }
}
