<?php
/**
 * JSON-RPC 2.0 error codes used by the MCP server.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

final class McpError
{
    public const PARSE_ERROR      = -32700;
    public const INVALID_REQUEST  = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS   = -32602;
    public const INTERNAL_ERROR   = -32603;
    // MCP-specific (per spec):
    public const TOOL_NOT_FOUND   = -32001;
    public const UNAUTHORIZED     = -32002;
}
