<?php
/**
 * `transients_delete` — clear one or all transients.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class TransientsDeleteTool extends AbstractTool
{
    public function name(): string        { return 'transients_delete'; }
    public function description(): string { return 'Delete one transient by key, or every transient when all=true. Optionally restrict by prefix.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key'    => ['type' => 'string', 'description' => 'Transient key (without the "_transient_" prefix).'],
                'prefix' => ['type' => 'string', 'description' => 'When clearing all, only delete keys starting with this prefix.'],
                'all'    => ['type' => 'boolean', 'description' => 'Delete every transient in the database.'],
                'site'   => ['type' => 'boolean', 'description' => 'Operate on site transients (multisite) instead of regular ones.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $site   = (bool) ($arguments['site'] ?? false);
        $all    = (bool) ($arguments['all'] ?? false);
        $key    = (string) ($arguments['key'] ?? '');
        $prefix = (string) ($arguments['prefix'] ?? '');

        if (!$all) {
            if ($key === '') {
                return $this->error('Provide "key" or set "all" to true.');
            }
            $ok = $site ? delete_site_transient($key) : delete_transient($key);
            return $this->text($ok ? sprintf('Deleted transient "%s".', $key) : sprintf('No transient "%s" found.', $key), ['deleted' => (bool) $ok, 'key' => $key]);
        }

        global $wpdb;
        $tPrefix = $site ? '_site_transient_' : '_transient_';
        $tTimeout = $site ? '_site_transient_timeout_' : '_transient_timeout_';

        if ($prefix !== '') {
            $like = $wpdb->esc_like($tPrefix . $prefix) . '%';
            $likeT = $wpdb->esc_like($tTimeout . $prefix) . '%';
        } else {
            $like = $wpdb->esc_like($tPrefix) . '%';
            $likeT = $wpdb->esc_like($tTimeout) . '%';
        }
        $deleted = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $likeT));
        return $this->text(sprintf('Deleted %d transient rows.', $deleted), ['deleted_rows' => $deleted, 'prefix' => $prefix, 'site' => $site]);
    }
}
