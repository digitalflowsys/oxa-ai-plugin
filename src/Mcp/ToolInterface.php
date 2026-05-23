<?php
/**
 * MCP tool contract.
 *
 * Tools expose Oxa operations to MCP clients (claude.ai, Claude Desktop,
 * any compliant client). Each tool advertises its inputSchema so the
 * client can validate arguments before invoking it.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

interface ToolInterface
{
    /**
     * Stable identifier the client uses to invoke the tool.
     */
    public function name(): string;

    /**
     * Short, action-first description rendered in the model's tool list.
     */
    public function description(): string;

    /**
     * JSON Schema object describing the tool's arguments.
     *
     * @return array
     */
    public function inputSchema(): array;

    /**
     * Execute the tool. Implementations may throw on caller errors;
     * the dispatcher will translate exceptions into structured tool
     * errors (isError=true) rather than transport-level failures.
     *
     * @param array $arguments
     * @return array  shape: { content: [...], isError?: bool }
     */
    public function run(array $arguments): array;
}
