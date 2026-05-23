<?php
/**
 * `POST /pages` — generate AND persist as a WP draft page.
 *
 * Body:
 *   { "prompt": "...", "provider": "claude" }
 *
 * Response:
 *   { "post_id": 123, "edit_url": "...", "page": { ... } }
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rest\Controllers;

use OxaAi\Core\Container;
use OxaAi\Generation\PageGenerator;
use OxaAi\Providers\ProviderException;
use OxaAi\Rest\RestController;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class PagesController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        register_rest_route(RestController::NAMESPACE, '/pages', [
            'methods'             => 'POST',
            'permission_callback' => static fn(): bool => current_user_can('publish_pages'),
            'args'                => [
                'prompt'   => ['type' => 'string', 'required' => true],
                'provider' => ['type' => 'string', 'required' => false],
            ],
            'callback'            => [$this, 'create'],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $prompt   = trim((string) $request->get_param('prompt'));
        $provider = (string) ($request->get_param('provider') ?? '');

        if ($prompt === '') {
            return new WP_REST_Response(['error' => 'Prompt is required.'], 400);
        }

        try {
            /** @var PageGenerator $generator */
            $generator = $this->container->get(PageGenerator::class);
            $result    = $generator->generateAndStore($prompt, $provider !== '' ? $provider : null);
            return new WP_REST_Response(['data' => $result], 201);
        } catch (ProviderException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], $e->status > 0 ? $e->status : 502);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
}
