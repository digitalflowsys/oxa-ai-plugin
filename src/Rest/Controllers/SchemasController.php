<?php
/**
 * `GET /schemas` — compact schema map keyed by component name.
 *
 * Useful for AI clients that already know the framework and just want
 * the contract without the surrounding metadata.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest\Controllers;

use OxaAi\Core\Container;
use OxaAi\Rest\RestController;
use OxaAi\Schema\SchemaRegistry;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class SchemasController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        register_rest_route(RestController::NAMESPACE, '/schemas', [
            'methods'             => 'GET',
            'permission_callback' => [RestController::class, 'canRead'],
            'callback'            => [$this, 'index'],
        ]);
    }

    public function index(): WP_REST_Response
    {
        /** @var SchemaRegistry $registry */
        $registry = $this->container->get(SchemaRegistry::class);

        $map = [];
        foreach ($registry->all() as $name => $schema) {
            $map[$name] = [
                'fields'      => $schema['fields'] ?? [],
                'description' => $schema['description'] ?? '',
            ];
        }
        return new WP_REST_Response(['data' => $map]);
    }
}
