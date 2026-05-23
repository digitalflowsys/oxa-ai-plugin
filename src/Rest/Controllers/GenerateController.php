<?php
/**
 * `POST /generate` — generate a page layout without saving it.
 *
 * Body:
 *   { "prompt": "...", "provider": "claude" }
 *
 * Response:
 *   { "page_title": "...", "layout": [...], "blocks": "..." }
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

final class GenerateController
{
    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        register_rest_route(RestController::NAMESPACE, '/generate', [
            'methods'             => 'POST',
            'permission_callback' => [RestController::class, 'canEdit'],
            'args'                => [
                'prompt'   => ['type' => 'string', 'required' => true],
                'provider' => ['type' => 'string', 'required' => false],
            ],
            'callback'            => [$this, 'create'],
        ]);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $prompt   = (string) $request->get_param('prompt');
        $provider = (string) ($request->get_param('provider') ?? '');

        $prompt = trim($prompt);
        if ($prompt === '') {
            return new WP_REST_Response(['error' => 'Prompt is required.'], 400);
        }

        try {
            /** @var PageGenerator $generator */
            $generator = $this->container->get(PageGenerator::class);
            $page      = $generator->generate($prompt, $provider !== '' ? $provider : null);
            return new WP_REST_Response(['data' => $page]);
        } catch (ProviderException $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], $e->status > 0 ? $e->status : 502);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
}
