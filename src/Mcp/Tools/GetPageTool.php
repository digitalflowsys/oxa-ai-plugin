<?php
/**
 * `get_page` — return the layout of an existing page.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Rendering\LayoutReader;

if (!defined('ABSPATH')) {
    exit;
}

final class GetPageTool extends AbstractTool
{
    public function __construct(private readonly LayoutReader $reader) {}

    public function name(): string        { return 'get_page'; }
    public function description(): string { return 'Return the Oxa layout (ordered list of components + props) for an existing WordPress page, by post ID. Each section has an index (0-based) used by update_section / delete_section.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'WordPress page ID.'],
            ],
            'required' => ['post_id'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        if ($postId <= 0) {
            return $this->error('"post_id" must be a positive integer.');
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'page') {
            return $this->error(sprintf('Page #%d not found.', $postId));
        }

        $layout = $this->reader->read($post->post_content);

        // Surface indices to the model so it can reference sections.
        $indexed = [];
        foreach ($layout as $i => $node) {
            $indexed[] = ['index' => $i, 'component' => $node['component'], 'props' => $node['props']];
        }

        $summary = sprintf(
            "Page #%d \"%s\" [%s] has %d Oxa sections: %s",
            $post->ID,
            $post->post_title,
            $post->post_status,
            count($layout),
            implode(', ', array_column($layout, 'component')) ?: 'none'
        );

        return $this->text($summary, [
            'post_id'  => $post->ID,
            'title'    => $post->post_title,
            'status'   => $post->post_status,
            'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
            'sections' => $indexed,
        ]);
    }
}
