<?php
/**
 * `GET /components` and `GET /components/<name>`
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest\Controllers;

use OxaAi\Core\Container;
use OxaAi\Rest\RestController;
use OxaAi\Schema\SchemaRegistry;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class ComponentsController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        register_rest_route(RestController::NAMESPACE, '/components', [
            'methods'             => 'GET',
            'permission_callback' => [RestController::class, 'canRead'],
            'callback'            => [$this, 'index'],
        ]);

        register_rest_route(RestController::NAMESPACE, '/components/(?P<name>[a-z0-9_-]+)', [
            'methods'             => 'GET',
            'permission_callback' => [RestController::class, 'canRead'],
            'args'                => [
                'name' => ['type' => 'string', 'required' => true],
            ],
            'callback'            => [$this, 'show'],
        ]);
    }

    public function index(): WP_REST_Response
    {
        /** @var SchemaRegistry $registry */
        $registry = $this->container->get(SchemaRegistry::class);
        $items    = array_values($registry->all());
        return new WP_REST_Response([
            'data'  => $items,
            'count' => count($items),
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        /** @var SchemaRegistry $registry */
        $registry = $this->container->get(SchemaRegistry::class);
        $name     = (string) $request->get_param('name');
        $schema   = $registry->get($name);

        if ($schema === null) {
            return new WP_REST_Response([
                'error' => sprintf('Component "%s" not found.', $name),
            ], 404);
        }
        return new WP_REST_Response(['data' => $schema]);
    }
}
