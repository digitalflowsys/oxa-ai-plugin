<?php
/**
 * `error_log_tail` — read the last N lines of WP debug.log.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class ErrorLogTailTool extends AbstractTool
{
    private const MAX_BYTES = 512 * 1024;

    public function name(): string        { return 'error_log_tail'; }
    public function description(): string { return 'Read the last N lines of wp-content/debug.log (or the file pointed at by WP_DEBUG_LOG). Returns the most recent slice up to 512 KiB.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'description' => 'Default 200.'],
                'grep'  => ['type' => 'string', 'description' => 'Optional substring filter applied to each returned line.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path = $this->resolveLog();
        if ($path === null || !is_file($path)) {
            return $this->text('Debug log is not enabled or does not exist yet.', ['path' => $path, 'lines' => []]);
        }
        $lines = (int) ($arguments['lines'] ?? 200);
        $lines = max(1, min(5000, $lines));
        $grep  = (string) ($arguments['grep'] ?? '');

        $size = (int) filesize($path);
        $start = max(0, $size - self::MAX_BYTES);
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return $this->error('Could not open debug log.');
        }
        try {
            fseek($fh, $start);
            $tail = (string) stream_get_contents($fh);
        } finally {
            fclose($fh);
        }
        $rows = preg_split('/\R/', $tail) ?: [];
        if ($start > 0 && !empty($rows)) {
            array_shift($rows); // probably a partial line
        }
        if ($grep !== '') {
            $rows = array_values(array_filter($rows, static fn (string $r): bool => str_contains($r, $grep)));
        }
        $rows = array_slice($rows, -$lines);

        return $this->text(sprintf('%d lines from %s.', count($rows), basename($path)), [
            'path'  => $path,
            'size'  => $size,
            'lines' => $rows,
        ]);
    }

    private function resolveLog(): ?string
    {
        if (defined('WP_DEBUG_LOG')) {
            $val = WP_DEBUG_LOG;
            if (is_string($val) && $val !== '' && $val !== '1' && $val !== '0') {
                return $val;
            }
        }
        return WP_CONTENT_DIR . '/debug.log';
    }
}
