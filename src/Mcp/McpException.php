<?php
/**
 * Protocol-level error thrown by the MCP dispatcher.
 *
 * Tool-level errors do NOT use this — they return `isError: true`
 * content instead so the model can see and react to them.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class McpException extends RuntimeException
{
    public function __construct(string $message, int $code)
    {
        parent::__construct($message, $code);
    }
}
