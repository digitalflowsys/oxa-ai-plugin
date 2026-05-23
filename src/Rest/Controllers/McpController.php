<?php
/**
 * MCP transport endpoint.
 *
 * Single route: POST /wp-json/oxa/v1/mcp
 *
 * Auth: Bearer token (Authorization header OR X-Oxa-Token fallback for
 * connector UIs that don't allow setting Authorization).
 *
 * Body: a single JSON-RPC 2.0 envelope (no batching — clients send one
 * request per HTTP call over the Streamable HTTP transport).
 *
 * Response: a single JSON-RPC envelope, or 204 No Content for
 * notifications (which by spec receive no response).
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest\Controllers;

use OxaAi\Core\Container;
use OxaAi\Mcp\Auth\BearerAuth;
use OxaAi\Mcp\McpServer;
use OxaAi\Rest\RestController;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class McpController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        // Add permissive CORS specifically for the /mcp routes so MCP
        // clients (claude.ai) can hit them cross-origin. Scoped to our
        // namespace to avoid loosening security elsewhere.
        add_filter('rest_pre_serve_request', function (bool $served, $result, $request): bool {
            $route = (string) $request->get_route();
            if (str_starts_with($route, '/' . RestController::NAMESPACE . '/mcp')) {
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Oxa-Token, Mcp-Session-Id');
                header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
                header('Access-Control-Expose-Headers: Mcp-Session-Id');
            }
            return $served;
        }, 10, 3);

        register_rest_route(RestController::NAMESPACE, '/mcp', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'authorize'],
            'callback'            => [$this, 'handle'],
        ]);

        // Discovery endpoint — some MCP clients probe for a manifest before
        // the actual handshake. We return a tiny server hint here so a
        // misconfigured client gets a useful 200 instead of a 401 maze.
        register_rest_route(RestController::NAMESPACE, '/mcp/health', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function (): WP_REST_Response {
                /** @var BearerAuth $auth */
                $auth = $this->container->get(BearerAuth::class);
                return new WP_REST_Response([
                    'ok'              => true,
                    'server'          => 'oxa-ai',
                    'version'         => OXA_AI_VERSION,
                    'auth'            => 'bearer',
                    'token_configured'=> $auth->hasToken(),
                ]);
            },
        ]);
    }

    public function authorize(WP_REST_Request $request): bool|\WP_Error
    {
        /** @var BearerAuth $auth */
        $auth = $this->container->get(BearerAuth::class);

        if (!$auth->hasToken()) {
            return new \WP_Error(
                'oxa_mcp_no_token',
                'MCP token is not configured on the server.',
                ['status' => 503]
            );
        }

        $authHeader = $request->get_header('authorization');
        $altHeader  = $request->get_header('x_oxa_token');
        $queryToken = $request->get_param('token');
        $queryToken = is_string($queryToken) ? $queryToken : null;

        if (!$auth->validate($authHeader, $altHeader, $queryToken)) {
            return new \WP_Error(
                'oxa_mcp_unauthorized',
                'Invalid or missing MCP bearer token.',
                ['status' => 401]
            );
        }
        return true;
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $envelope = json_decode($body, true);

        if (!is_array($envelope)) {
            return $this->jsonRpcError(null, -32700, 'Parse error.');
        }

        // JSON-RPC may also be a batch (array of envelopes). MCP's
        // Streamable HTTP transport mandates single envelopes, but we
        // accept batches for safety.
        if (array_is_list($envelope) && $envelope !== []) {
            $responses = [];
            foreach ($envelope as $single) {
                if (!is_array($single)) continue;
                $r = $this->server()->dispatch($single);
                if ($r !== null) $responses[] = $r;
            }
            return new WP_REST_Response($responses, 200, $this->corsHeaders());
        }

        $response = $this->server()->dispatch($envelope);
        if ($response === null) {
            // Notification — no response.
            return new WP_REST_Response(null, 204, $this->corsHeaders());
        }
        return new WP_REST_Response($response, 200, $this->corsHeaders());
    }

    private function server(): McpServer
    {
        /** @var McpServer $server */
        $server = $this->container->get(McpServer::class);
        return $server;
    }

    private function jsonRpcError(mixed $id, int $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ], 200, $this->corsHeaders());
    }

    /**
     * MCP clients (claude.ai) make cross-origin requests. WP doesn't add
     * permissive CORS by default for REST, so we surface the minimum
     * headers needed for the connector to function.
     */
    private function corsHeaders(): array
    {
        return [
            'Content-Type'                  => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin'   => '*',
            'Access-Control-Allow-Headers'  => 'Authorization, Content-Type, X-Oxa-Token, Mcp-Session-Id',
            'Access-Control-Allow-Methods'  => 'POST, GET, OPTIONS',
            'Access-Control-Expose-Headers' => 'Mcp-Session-Id',
        ];
    }
}
