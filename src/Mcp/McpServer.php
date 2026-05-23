<?php
/**
 * MCP JSON-RPC dispatcher.
 *
 * Handles the small set of methods MCP clients invoke:
 *   - initialize
 *   - notifications/initialized   (notification — no response)
 *   - tools/list
 *   - tools/call
 *   - ping
 *
 * Transport is "Streamable HTTP": every client request arrives as
 * a single POST with a JSON-RPC envelope and we return a single
 * JSON response. We do not need SSE for our tool set — all calls
 * are short-lived and synchronous.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

use OxaAi\Core\Logger;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class McpServer
{
    /** MCP protocol version we implement. Negotiated during initialize. */
    private const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly Logger $logger,
    ) {}

    /**
     * Dispatch a single JSON-RPC envelope.
     *
     * Returns the response envelope, or null for notifications
     * (which by JSON-RPC spec receive no response).
     */
    public function dispatch(array $envelope): ?array
    {
        $id     = $envelope['id'] ?? null;
        $method = (string) ($envelope['method'] ?? '');
        $params = $envelope['params'] ?? [];
        if (!is_array($params)) {
            $params = [];
        }

        // Notifications have no id; they receive no response.
        $isNotification = !array_key_exists('id', $envelope);

        try {
            $result = match ($method) {
                'initialize'                => $this->initialize($params),
                'notifications/initialized' => null,
                'notifications/cancelled'   => null,
                'tools/list'                => $this->toolsList(),
                'tools/call'                => $this->toolsCall($params),
                'ping'                      => (object) [],
                default                     => throw new McpException(
                    sprintf('Method "%s" not found.', $method),
                    McpError::METHOD_NOT_FOUND
                ),
            };

            if ($isNotification) {
                return null;
            }

            return [
                'jsonrpc' => '2.0',
                'id'      => $id,
                'result'  => $result,
            ];
        } catch (McpException $e) {
            $this->logger->warn('MCP method error', [
                'method' => $method,
                'code'   => $e->getCode(),
                'msg'    => $e->getMessage(),
            ]);
            return $isNotification ? null : $this->errorEnvelope($id, $e->getCode(), $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('MCP internal error', [
                'method' => $method,
                'msg'    => $e->getMessage(),
            ]);
            return $isNotification ? null : $this->errorEnvelope($id, McpError::INTERNAL_ERROR, 'Internal error.');
        }
    }

    private function initialize(array $params): array
    {
        $clientVersion = is_string($params['protocolVersion'] ?? null)
            ? $params['protocolVersion']
            : self::PROTOCOL_VERSION;

        return [
            // Per the spec we echo the client's protocol version when we support it.
            'protocolVersion' => $clientVersion,
            'capabilities'    => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'oxa-ai',
                'title'   => 'Oxa AI',
                'version' => OXA_AI_VERSION,
            ],
            'instructions' => 'Use these tools to inspect Oxa components and to generate, read, or update WordPress pages composed of Oxa components.',
        ];
    }

    private function toolsList(): array
    {
        return ['tools' => $this->tools->descriptors()];
    }

    private function toolsCall(array $params): array
    {
        $name      = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === '') {
            throw new McpException('Missing tool name.', McpError::INVALID_PARAMS);
        }
        $tool = $this->tools->get($name);
        if ($tool === null) {
            throw new McpException(
                sprintf('Tool "%s" not found.', $name),
                McpError::TOOL_NOT_FOUND
            );
        }

        try {
            return $tool->run($arguments);
        } catch (Throwable $e) {
            // Tool-level errors are returned as `isError: true` content,
            // not protocol errors. The model sees them and can recover.
            return [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    private function errorEnvelope(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}
