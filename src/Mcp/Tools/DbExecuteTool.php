<?php
/**
 * `db_execute` — write SQL (INSERT/UPDATE/DELETE/REPLACE) with acknowledge gate for DDL.
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

final class DbExecuteTool extends AbstractTool
{
    public function __construct(private readonly DbGate $db) {}

    public function name(): string        { return 'db_execute'; }
    public function description(): string { return 'Execute a write SQL statement: INSERT/UPDATE/DELETE/REPLACE. Destructive DDL (DROP/TRUNCATE/ALTER/RENAME/GRANT/REVOKE/CREATE) is rejected unless acknowledge=true. Multiple statements are rejected. Use %s/%d/%f placeholders + args for safe parameterisation.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql'         => ['type' => 'string'],
                'args'        => ['type' => 'array'],
                'acknowledge' => ['type' => 'boolean', 'description' => 'Required to proceed with DDL (DROP/TRUNCATE/ALTER/RENAME/GRANT/REVOKE/CREATE).'],
            ],
            'required' => ['sql'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $sql  = (string) ($arguments['sql'] ?? '');
        $args = is_array($arguments['args'] ?? null) ? $arguments['args'] : [];
        $ack  = (bool) ($arguments['acknowledge'] ?? false);
        $result = $this->db->execute($sql, $args, $ack);
        return $this->text(sprintf('Affected %d row(s).', $result['affected']), $result);
    }
}
