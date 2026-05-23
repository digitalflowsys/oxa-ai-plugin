<?php
/**
 * Lightweight logger.
 *
 * Persists structured log lines to `wp_options` under `oxa_ai_logs`
 * (capped) so the admin UI can render them without a custom table.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Logger
{
    private const OPTION  = 'oxa_ai_logs';
    private const MAX_ROWS = 100;

    public function info(string $message, array $context = []): void  { $this->write('info',  $message, $context); }
    public function warn(string $message, array $context = []): void  { $this->write('warn',  $message, $context); }
    public function error(string $message, array $context = []): void { $this->write('error', $message, $context); }

    /** @return array<int,array{ts:int,level:string,message:string,context:array}> */
    public function recent(int $limit = 50): array
    {
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            return [];
        }
        return array_slice(array_reverse($rows), 0, max(1, $limit));
    }

    public function clear(): void
    {
        update_option(self::OPTION, [], false);
    }

    private function write(string $level, string $message, array $context): void
    {
        $rows = get_option(self::OPTION, []);
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows[] = [
            'ts'      => time(),
            'level'   => $level,
            'message' => $message,
            'context' => $this->scrub($context),
        ];
        if (count($rows) > self::MAX_ROWS) {
            $rows = array_slice($rows, -self::MAX_ROWS);
        }
        update_option(self::OPTION, $rows, false);
    }

    /**
     * Remove anything that looks like a secret before persisting.
     */
    private function scrub(array $context): array
    {
        array_walk_recursive($context, static function (mixed &$value, mixed $key): void {
            if (is_string($key) && preg_match('/(api[_-]?key|secret|token|authorization)/i', $key) === 1) {
                $value = '***';
            }
        });
        return $context;
    }
}
