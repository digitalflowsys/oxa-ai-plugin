<?php
/**
 * Read an Oxa layout back out of a WP post's `post_content`.
 *
 * Inverse of GutenbergComposer::compose(). We parse the post's blocks
 * and pull out every `oxa/component` block's attributes.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rendering;

if (!defined('ABSPATH')) {
    exit;
}

final class LayoutReader
{
    /**
     * @return array<int,array{component:string,props:array}>
     */
    public function read(string $postContent): array
    {
        if ($postContent === '' || !function_exists('parse_blocks')) {
            return [];
        }
        $blocks = parse_blocks($postContent);
        $layout = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') !== OXA_AI_BLOCK_NAME) {
                continue;
            }
            $attrs = $block['attrs'] ?? [];
            if (!is_array($attrs) || empty($attrs['component'])) {
                continue;
            }
            $props = $attrs['props'] ?? [];
            if (!is_array($props)) {
                $props = [];
            }
            $layout[] = [
                'component' => (string) $attrs['component'],
                'props'     => $props,
            ];
        }
        return $layout;
    }
}
