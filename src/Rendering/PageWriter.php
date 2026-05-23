<?php
/**
 * Save a validated layout back into a WP page's `post_content`.
 *
 * Pairs with LayoutReader on the read path and GutenbergComposer
 * on the write path so the section-mutation tools stay short.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rendering;

use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class PageWriter
{
    public function __construct(private readonly GutenbergComposer $composer) {}

    /**
     * @param array<int,array{component:string,props:array}> $layout
     */
    public function save(int $postId, array $layout): void
    {
        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'page') {
            throw new RuntimeException(sprintf('Page #%d not found.', $postId));
        }

        $blocks = $this->composer->compose($layout);
        $result = wp_update_post([
            'ID'           => $postId,
            'post_content' => $blocks,
        ], true);

        if ($result instanceof WP_Error) {
            throw new RuntimeException('Failed to update page: ' . $result->get_error_message());
        }
    }
}
