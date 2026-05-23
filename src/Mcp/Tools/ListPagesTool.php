<?php
/**
 * `list_pages` — list WordPress pages with their Oxa section counts.
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

final class ListPagesTool extends AbstractTool
{
    public function __construct(private readonly LayoutReader $reader) {}

    public function name(): string        { return 'list_pages'; }
    public function description(): string { return 'List WordPress pages with their status, slug, edit URL, and Oxa section count. Use this to find a page before reading or editing it.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['any', 'publish', 'draft', 'pending', 'private'],
                    'description' => 'Filter by post_status. Defaults to "any".',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional title/content substring filter.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max number of pages to return (1-50, default 20).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $status = (string) ($arguments['status'] ?? 'any');
        $search = (string) ($arguments['search'] ?? '');
        $limit  = (int)    ($arguments['limit']  ?? 20);
        $limit  = max(1, min(50, $limit));

        $query = [
            'post_type'      => 'page',
            'post_status'    => $status === 'any' ? ['publish','draft','pending','private','future'] : $status,
            'posts_per_page' => $limit,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];
        if ($search !== '') {
            $query['s'] = $search;
        }

        $pages = get_posts($query);
        if (empty($pages)) {
            return $this->text('No pages found matching the criteria.', ['pages' => []]);
        }

        $rows    = [];
        $summary = "Pages:\n";
        foreach ($pages as $post) {
            $layout  = $this->reader->read($post->post_content);
            $sections = array_column($layout, 'component');
            $row = [
                'id'       => $post->ID,
                'title'    => $post->post_title,
                'slug'     => $post->post_name,
                'status'   => $post->post_status,
                'modified' => $post->post_modified_gmt,
                'edit_url' => (string) get_edit_post_link($post->ID, 'raw'),
                'oxa_sections' => $sections,
            ];
            $rows[] = $row;
            $summary .= sprintf(
                "- #%d %s [%s] — %d sections (%s)\n",
                $post->ID,
                $post->post_title,
                $post->post_status,
                count($sections),
                $sections ? implode(', ', $sections) : 'no Oxa components'
            );
        }

        return $this->text(trim($summary), ['pages' => $rows]);
    }
}
