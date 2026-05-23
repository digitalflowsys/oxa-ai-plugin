<?php
/**
 * Registers all REST routes under /wp-json/oxa/v1/.
 *
 * Routes intentionally use the short `oxa` namespace (not `oxa-ai`)
 * so the theme and plugin share a single, stable surface.
 *
 *   GET  /components                          → list of components
 *   GET  /components/(?P<name>[a-z0-9-_]+)    → single component schema
 *   GET  /schemas                             → schemas only (compact)
 *   POST /generate                            → generate + return blocks
 *   POST /pages                               → generate + persist as page
 *   GET  /providers                           → list available providers
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest;

use OxaAi\Core\Container;
use OxaAi\Rest\Controllers\ComponentsController;
use OxaAi\Rest\Controllers\GenerateController;
use OxaAi\Rest\Controllers\McpController;
use OxaAi\Rest\Controllers\PagesController;
use OxaAi\Rest\Controllers\ProvidersController;
use OxaAi\Rest\Controllers\SchemasController;

if (!defined('ABSPATH')) {
    exit;
}

final class RestController
{
    public const NAMESPACE = 'oxa/v1';

    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        (new ComponentsController($this->container))->register();
        (new SchemasController($this->container))->register();
        (new GenerateController($this->container))->register();
        (new PagesController($this->container))->register();
        (new ProvidersController($this->container))->register();
        (new McpController($this->container))->register();
    }

    /**
     * Standard permission callback for write endpoints.
     */
    public static function canEdit(): bool
    {
        return current_user_can('edit_posts');
    }

    public static function canRead(): bool
    {
        return current_user_can('edit_posts');
    }
}
