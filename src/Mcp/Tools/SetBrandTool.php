<?php
/**
 * `set_brand` — define or update the site-wide brand tokens
 * (colors, fonts, radii).
 *
 * Writes `themes/oxa/brand.json`, which the theme reads on every request
 * to emit CSS custom-property overrides and to filter `theme.json` for
 * the block editor.
 *
 * Conversational flow this is designed for:
 *
 *   user:    "I want a warm, earthy brand."
 *   claude:  set_brand({ colors: { bg: "#fcf8f0", primary: "#2d5a1f", ... } })
 *   tool:    returns the applied brand + a preview hint
 *   user:    "darker primary"
 *   claude:  set_brand({ colors: { primary: "#1f3f15" } })   // partial update
 *
 * Partial updates are supported: only the keys you send are changed;
 * everything else falls back to the theme defaults (or the previous
 * brand if one is set).
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Authoring\BrandManager;
use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class SetBrandTool extends AbstractTool
{
    public function __construct(private readonly BrandManager $brand) {}

    public function name(): string        { return 'set_brand'; }
    public function description(): string { return 'Define or update the site-wide brand: color palette, font family, and corner-radius scale. The new values are applied immediately to both the frontend and the block editor. Supports partial updates — fields you omit are merged with the existing brand. Use this BEFORE composing pages so generated content inherits the brand. Reset to defaults by passing { "reset": true }.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name'        => ['type' => 'string', 'description' => 'Human-readable brand name (e.g. "Sage & Stone").'],
                'description' => ['type' => 'string', 'description' => 'One-sentence description of the brand mood.'],
                'colors' => [
                    'type' => 'object',
                    'description' => 'CSS color values (hex, rgb(), hsl(), oklch(), named). Keys: bg, surface, border, muted, text, primary, on_primary, accent. Send any subset.',
                    'properties' => [
                        'bg'         => ['type' => 'string'],
                        'surface'    => ['type' => 'string'],
                        'border'     => ['type' => 'string'],
                        'muted'      => ['type' => 'string'],
                        'text'       => ['type' => 'string'],
                        'primary'    => ['type' => 'string'],
                        'on_primary' => ['type' => 'string'],
                        'accent'     => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'typography' => [
                    'type' => 'object',
                    'properties' => [
                        'font_sans' => ['type' => 'string', 'description' => 'CSS font-family value, e.g. "Fraunces, Georgia, serif". Include fallbacks.'],
                    ],
                    'additionalProperties' => false,
                ],
                'radius' => [
                    'type' => 'object',
                    'description' => 'Corner radii as CSS lengths (e.g. "12px", "0.5rem", "0"). Sharper = corporate, rounder = friendly.',
                    'properties' => [
                        'default' => ['type' => 'string'],
                        'sm'      => ['type' => 'string'],
                        'lg'      => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'reset' => [
                    'type'        => 'boolean',
                    'description' => 'If true, clears the brand and reverts to theme defaults. Ignores all other fields.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        if (!empty($arguments['reset'])) {
            $this->brand->clear();
            return $this->text('Brand cleared — site is back to the default Oxa palette and typography.');
        }

        // Partial-update: merge incoming fields with the current brand
        // so callers can tweak one color without restating everything.
        $existing = $this->brand->read();
        $incoming = $arguments;
        unset($incoming['reset']);

        $merged = $this->deepMerge($existing, $incoming);

        $result = $this->brand->write($merged);

        return $this->text(
            sprintf('Brand updated. Wrote %d bytes to %s. Reload any open frontend tab to see the change.',
                $result['bytes'],
                $result['path']
            ),
            ['brand' => $result['brand']]
        );
    }

    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = $this->deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }
}
