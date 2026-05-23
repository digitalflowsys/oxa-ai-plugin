<?php
/**
 * `compose_page` — create a page from an explicit layout the caller
 * has built, WITHOUT any LLM call.
 *
 * This is the primary tool when chatting from claude.ai: the model
 * already knows the schemas (via list_components + get_component_schema)
 * and composes the layout itself, then ships it here for validation
 * and storage. No API keys required on the WordPress side.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Rendering\GutenbergComposer;
use OxaAi\Schema\SchemaValidator;
use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class ComposePageTool extends AbstractTool
{
    public function __construct(
        private readonly SchemaValidator $validator,
        private readonly GutenbergComposer $composer,
    ) {}

    public function name(): string        { return 'compose_page'; }
    public function description(): string { return 'Create a WordPress page from an explicit layout you assembled. Provide page_title and a layout array of {component, props} items. The server validates every section against its component schema, sanitizes input, composes Gutenberg blocks, and saves as a draft. Returns post_id and edit_url. This is the preferred tool when the user has dictated the structure.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_title' => ['type' => 'string', 'description' => 'Plain-text title for the new page.'],
                'layout' => [
                    'type'        => 'array',
                    'description' => 'Ordered list of sections. Each item: { "component": "<name>", "props": { ... } }. Use list_components / get_component_schema to learn valid shapes.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'component' => ['type' => 'string'],
                            'props'     => ['type' => 'object'],
                        ],
                        'required' => ['component', 'props'],
                    ],
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['draft', 'publish', 'pending', 'private'],
                    'description' => 'post_status. Defaults to "draft".',
                ],
            ],
            'required' => ['page_title', 'layout'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $title  = trim((string) ($arguments['page_title'] ?? ''));
        $layout = $arguments['layout'] ?? null;
        $status = (string) ($arguments['status'] ?? 'draft');
        if ($title === '' || !is_array($layout) || empty($layout)) {
            return $this->error('"page_title" and a non-empty "layout" are required.');
        }

        $validation = $this->validator->validateLayout($layout);
        if (!$validation->valid) {
            return $this->error('Validation failed: ' . implode('; ', $validation->errors));
        }

        $clean  = $validation->value;
        $blocks = $this->composer->compose($clean);

        $postId = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => in_array($status, ['draft','publish','pending','private'], true) ? $status : 'draft',
            'post_title'   => sanitize_text_field($title),
            'post_content' => $blocks,
            'meta_input'   => ['_oxa_generated' => '1'],
        ], true);

        if ($postId instanceof WP_Error) {
            throw new RuntimeException('Failed to create page: ' . $postId->get_error_message());
        }

        return $this->text(
            sprintf('Created page #%d "%s" with %d sections (%s). Edit it at: %s',
                (int) $postId,
                $title,
                count($clean),
                implode(', ', array_column($clean, 'component')),
                (string) get_edit_post_link($postId, 'raw')
            ),
            [
                'post_id'    => (int) $postId,
                'edit_url'   => (string) get_edit_post_link($postId, 'raw'),
                'page_title' => $title,
                'layout'     => $clean,
            ]
        );
    }
}
