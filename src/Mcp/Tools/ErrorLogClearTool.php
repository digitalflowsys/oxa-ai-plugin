<?php
/**
 * `error_log_clear` — truncate the WP debug log.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class ErrorLogClearTool extends AbstractTool
{
    public function name(): string        { return 'error_log_clear'; }
    public function description(): string { return 'Truncate the WordPress debug log (wp-content/debug.log or WP_DEBUG_LOG path). Returns the previous file size for reference.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path = defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && WP_DEBUG_LOG !== '' && WP_DEBUG_LOG !== '1' && WP_DEBUG_LOG !== '0'
            ? WP_DEBUG_LOG
            : WP_CONTENT_DIR . '/debug.log';

        if (!is_file($path)) {
            return $this->text('No debug log to clear.', ['path' => $path, 'prior_bytes' => 0]);
        }
        $prior = (int) filesize($path);
        if (file_put_contents($path, '') === false) {
            return $this->error(sprintf('Could not truncate %s.', $path));
        }
        return $this->text(sprintf('Cleared %s (was %d bytes).', basename($path), $prior), ['path' => $path, 'prior_bytes' => $prior]);
    }
}
