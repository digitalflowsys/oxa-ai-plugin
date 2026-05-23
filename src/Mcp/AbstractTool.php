<?php
/**
 * Shared tool helpers.
 *
 * Provides ergonomic response builders so each tool focuses on its
 * own logic, not boilerplate.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractTool implements ToolInterface
{
    /**
     * Build a successful tool response with a single text block.
     */
    protected function text(string $message, ?array $structured = null): array
    {
        $blocks = [['type' => 'text', 'text' => $message]];
        if ($structured !== null) {
            $blocks[] = [
                'type' => 'text',
                'text' => (string) wp_json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }
        return ['content' => $blocks, 'isError' => false];
    }

    /**
     * Build a tool error response. `isError: true` is the MCP-defined
     * way to surface tool-level errors (vs. protocol-level errors).
     */
    protected function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}
