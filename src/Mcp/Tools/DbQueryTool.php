<?php
/**
 * `db_query` — read-only SQL.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\DbGate;

if (!defined('ABSPATH')) {
    exit;
}

final class DbQueryTool extends AbstractTool
{
    public function __construct(private readonly DbGate $db) {}

    public function name(): string        { return 'db_query'; }
    public function description(): string { return 'Run a read-only SQL query (SELECT/SHOW/DESCRIBE/EXPLAIN). Multiple statements are rejected. SELECTs without a LIMIT clause get one auto-appended. Use %s/%d/%f placeholders + args for safe parameterisation.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql'   => ['type' => 'string', 'description' => 'A single SELECT/SHOW/DESCRIBE/EXPLAIN statement.'],
                'args'  => ['type' => 'array', 'description' => 'Values to substitute into %s/%d/%f placeholders, in order.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Auto-appended LIMIT when missing. Default 100.'],
            ],
            'required' => ['sql'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $sql   = (string) ($arguments['sql'] ?? '');
        $args  = is_array($arguments['args'] ?? null) ? $arguments['args'] : [];
        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : 100;
        $result = $this->db->query($sql, $args, $limit);
        return $this->text(sprintf('%d row(s).', $result['row_count']), $result);
    }
}
