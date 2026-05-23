<?php
/**
 * `GET /providers` — list registered providers and their state.
 *
 * Used by the admin UI to render the provider picker and by external
 * clients that need to know what is wired up.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest\Controllers;

use OxaAi\Core\Container;
use OxaAi\Providers\ProviderRegistry;
use OxaAi\Rest\RestController;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class ProvidersController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        register_rest_route(RestController::NAMESPACE, '/providers', [
            'methods'             => 'GET',
            'permission_callback' => [RestController::class, 'canRead'],
            'callback'            => [$this, 'index'],
        ]);
    }

    public function index(): WP_REST_Response
    {
        /** @var ProviderRegistry $registry */
        $registry = $this->container->get(ProviderRegistry::class);

        $items = [];
        foreach ($registry->all() as $provider) {
            $items[] = [
                'slug'         => $provider->slug(),
                'label'        => $provider->label(),
                'configured'   => $provider->isConfigured(),
                'default_model'=> $provider->defaultModel(),
            ];
        }
        return new WP_REST_Response(['data' => $items]);
    }
}
