<?php
/**
 * `delete_section` — remove a section from a page.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Rendering\LayoutReader;
use OxaAi\Rendering\PageWriter;

if (!defined('ABSPATH')) {
    exit;
}

final class DeleteSectionTool extends AbstractTool
{
    public function __construct(
        private readonly LayoutReader $reader,
        private readonly PageWriter $writer,
    ) {}

    public function name(): string        { return 'delete_section'; }
    public function description(): string { return 'Remove one section from a page by 0-based index.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id' => ['type' => 'integer'],
                'index'   => ['type' => 'integer'],
            ],
            'required' => ['post_id', 'index'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        $index  = (int) ($arguments['index']   ?? -1);
        if ($postId <= 0 || $index < 0) {
            return $this->error('"post_id" and "index" (>=0) are required.');
        }
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'page') {
            return $this->error(sprintf('Page #%d not found.', $postId));
        }

        $layout = $this->reader->read($post->post_content);
        if (!isset($layout[$index])) {
            return $this->error(sprintf('Page has no section at index %d.', $index));
        }
        $removed = $layout[$index];
        array_splice($layout, $index, 1);

        $this->writer->save($postId, $layout);
        return $this->text(
            sprintf('Removed "%s" at index %d from page #%d. Remaining sections: %d.',
                $removed['component'], $index, $postId, count($layout)),
            ['post_id' => $postId, 'removed' => $removed, 'remaining' => count($layout)]
        );
    }
}
