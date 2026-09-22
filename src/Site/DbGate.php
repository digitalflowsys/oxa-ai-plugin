<?php
/**
 * Allow-list gatekeeper for raw SQL execution.
 *
 * `db_query`   — SELECT/SHOW/DESCRIBE/EXPLAIN only. Always read-only.
 * `db_execute` — INSERT/UPDATE/DELETE/REPLACE on the WP prefix tables.
 *                Destructive verbs (DROP/TRUNCATE/ALTER/RENAME/GRANT)
 *                require `acknowledge=true`.
 *
 * The caller passes parameterized SQL (`%s`, `%d`, `%f` per wpdb) and
 * an `args` array; we delegate to `$wpdb->prepare()`.
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

final class DbGate
{
    private const READ_PATTERN  = '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i';
    private const WRITE_PATTERN = '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i';
    private const DESTRUCTIVE_PATTERN = '/^\s*(DROP|TRUNCATE|ALTER|RENAME|GRANT|REVOKE|CREATE)\b/i';

    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array{
     *   rows:array<int,array<string,mixed>>,
     *   row_count:int,
     *   sql:string
     * }
     */
    public function query(string $sql, array $args = [], int $limit = 100): array
    {
        global $wpdb;
        $sql = trim($sql);
        if (!preg_match(self::READ_PATTERN, $sql)) {
            throw new RuntimeException('db_query only accepts SELECT/SHOW/DESCRIBE/EXPLAIN.');
        }
        if (preg_match('/;\s*\S/', $sql)) {
            throw new RuntimeException('Multiple statements are not allowed.');
        }
        $sql = $this->maybeAppendLimit($sql, $limit);
        $prepared = $args !== [] ? $wpdb->prepare($sql, ...array_values($args)) : $sql;
        if (!is_string($prepared)) {
            throw new RuntimeException('Failed to prepare SQL.');
        }
        $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $err  = $wpdb->last_error;
        $wpdb->suppress_errors(false);
        if ($err) {
            throw new RuntimeException('SQL error: ' . $err);
        }
        return [
            'rows'      => is_array($rows) ? $rows : [],
            'row_count' => is_array($rows) ? count($rows) : 0,
            'sql'       => $prepared,
        ];
    }

    /**
     * @return array{affected:int,insert_id:int,sql:string}
     */
    public function execute(string $sql, array $args = [], bool $acknowledge = false): array
    {
        global $wpdb;
        $sql = trim($sql);
        if (preg_match('/;\s*\S/', $sql)) {
            throw new RuntimeException('Multiple statements are not allowed.');
        }
        if (preg_match(self::DESTRUCTIVE_PATTERN, $sql)) {
            if (!$acknowledge) {
                throw new RuntimeException('Destructive DDL (DROP/TRUNCATE/ALTER/RENAME/GRANT/REVOKE/CREATE) requires acknowledge=true.');
            }
        } elseif (!preg_match(self::WRITE_PATTERN, $sql)) {
            throw new RuntimeException('db_execute accepts INSERT/UPDATE/DELETE/REPLACE (or destructive DDL with acknowledge=true).');
        }
        $prepared = $args !== [] ? $wpdb->prepare($sql, ...array_values($args)) : $sql;
        if (!is_string($prepared)) {
            throw new RuntimeException('Failed to prepare SQL.');
        }
        $wpdb->suppress_errors(true);
        $affected = $wpdb->query($prepared);
        $err = $wpdb->last_error;
        $wpdb->suppress_errors(false);
        if ($affected === false) {
            throw new RuntimeException('SQL error: ' . ($err ?: 'unknown'));
        }
        $this->logger->info('db_execute', ['sql' => $prepared, 'affected' => $affected]);
        return [
            'affected'  => (int) $affected,
            'insert_id' => (int) $wpdb->insert_id,
            'sql'       => $prepared,
        ];
    }

    private function maybeAppendLimit(string $sql, int $limit): string
    {
        $limit = max(1, min(1000, $limit));
        if (preg_match('/\bLIMIT\b/i', $sql)) {
            return $sql;
        }
        if (preg_match('/^\s*SELECT\b/i', $sql)) {
            return rtrim($sql, "; \t\n") . ' LIMIT ' . $limit;
        }
        return $sql;
    }
}
