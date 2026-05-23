<?php
/**
 * Composes a validated layout into Gutenberg block markup.
 *
 * Each component becomes a self-closing `<!-- wp:oxa/component { ... } /-->`
 * comment. The theme's render_callback (registered in the theme) then
 * expands each comment back into the actual rendered HTML at request
 * time. AI never produces HTML directly — only structured JSON.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Rendering;

if (!defined('ABSPATH')) {
    exit;
}

final class GutenbergComposer
{
    /**
     * @param array<int,array{component:string,props:array}> $layout
     */
    public function compose(array $layout): string
    {
        $blocks = [];
        foreach ($layout as $node) {
            if (!is_array($node) || empty($node['component'])) {
                continue;
            }
            $attributes = [
                'component' => (string) $node['component'],
                'props'     => is_array($node['props'] ?? null) ? $node['props'] : [],
            ];
            // JSON_UNESCAPED_SLASHES so URLs do not get mangled in
            // post_content; JSON_UNESCAPED_UNICODE for readable copy.
            $json = wp_json_encode(
                $attributes,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            if (!is_string($json)) {
                continue;
            }
            $blocks[] = sprintf('<!-- wp:%s %s /-->', OXA_AI_BLOCK_NAME, $json);
        }
        return implode("\n\n", $blocks);
    }
}
