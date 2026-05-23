<?php
/**
 * End-to-end page generation pipeline.
 *
 *   prompt ─▶ PromptBuilder
 *           ─▶ Provider.complete()
 *           ─▶ ResponseParser
 *           ─▶ SchemaValidator
 *           ─▶ GutenbergComposer
 *           ─▶ wp_insert_post()
 *
 * The pipeline is deliberately linear so each step can be tested
 * independently and any one of them can be swapped out.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Generation;

use OxaAi\Core\Logger;
use OxaAi\Core\Settings;
use OxaAi\Providers\ProviderException;
use OxaAi\Providers\ProviderRegistry;
use OxaAi\Rendering\GutenbergComposer;
use OxaAi\Schema\SchemaValidator;
use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class PageGenerator
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SchemaValidator $validator,
        private readonly PromptBuilder $prompts,
        private readonly GutenbergComposer $composer,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {}

    /**
     * Generate a page payload from a user prompt without persisting it.
     *
     * @return array{page_title:string,layout:array<int,array>,blocks:string}
     */
    public function generate(string $prompt, ?string $providerOverride = null): array
    {
        $config       = $this->settings->config();
        $providerSlug = $providerOverride !== null && $providerOverride !== ''
            ? $providerOverride
            : $config['provider'];

        if (!$this->providers->has($providerSlug)) {
            throw new RuntimeException(sprintf('Unknown provider "%s".', $providerSlug));
        }
        $provider = $this->providers->get($providerSlug);
        if (!$provider->isConfigured()) {
            throw new ProviderException(
                sprintf('Provider "%s" is not configured. Add an API key in settings.', $providerSlug),
                412
            );
        }

        $messages = $this->prompts->build($prompt);

        $this->logger->info('Generating page', [
            'provider' => $providerSlug,
            'prompt'   => mb_substr($prompt, 0, 200),
        ]);

        $raw      = $provider->complete($messages);
        $parsed   = (new ResponseParser())->parse($raw);

        $validation = $this->validator->validateLayout($parsed['layout']);
        if (!$validation->valid) {
            $this->logger->error('Layout validation failed', [
                'errors' => $validation->errors,
            ]);
            throw new RuntimeException(
                'AI output failed schema validation: ' . implode('; ', $validation->errors)
            );
        }

        $cleanLayout = $validation->value;
        $blocks      = $this->composer->compose($cleanLayout);

        return [
            'page_title' => sanitize_text_field($parsed['page_title']),
            'layout'     => $cleanLayout,
            'blocks'     => $blocks,
        ];
    }

    /**
     * Generate and persist as a draft WordPress page.
     *
     * @return array{post_id:int,edit_url:string,page:array}
     */
    public function generateAndStore(string $prompt, ?string $providerOverride = null): array
    {
        $page = $this->generate($prompt, $providerOverride);

        $postId = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'draft',
            'post_title'   => $page['page_title'],
            'post_content' => $page['blocks'],
            'meta_input'   => [
                '_oxa_generated' => '1',
            ],
        ], true);

        if ($postId instanceof WP_Error) {
            throw new RuntimeException('Failed to create page: ' . $postId->get_error_message());
        }

        return [
            'post_id'  => (int) $postId,
            'edit_url' => (string) get_edit_post_link($postId, 'raw'),
            'page'     => $page,
        ];
    }
}
