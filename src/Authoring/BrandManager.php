<?php
/**
 * Read/write the per-site brand override.
 *
 * The brand lives in a single JSON file at `themes/oxa/brand.json`.
 * The theme reads it on request and emits CSS custom property
 * overrides plus a `wp_theme_json_data_theme` filter so the block
 * editor reflects the brand too.
 *
 * Shape (all keys optional):
 *
 *   {
 *     "name": "...",
 *     "description": "...",
 *     "colors": {
 *       "bg": "...", "surface": "...", "border": "...", "muted": "...",
 *       "text": "...", "primary": "...", "on_primary": "...", "accent": "..."
 *     },
 *     "typography": {
 *       "font_sans": "..."
 *     },
 *     "radius": { "default": "...", "sm": "...", "lg": "..." }
 *   }
 *
 * Anything not specified falls back to the values shipped in tokens.css.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Authoring;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class BrandManager
{
    /** @var array<string,string> CSS custom property name → brand JSON path */
    private const COLOR_MAP = [
        'bg'         => '--oxa-bg',
        'surface'    => '--oxa-surface',
        'border'     => '--oxa-border',
        'muted'      => '--oxa-muted',
        'text'       => '--oxa-text',
        'primary'    => '--oxa-primary',
        'on_primary' => '--oxa-on-primary',
        'accent'     => '--oxa-accent',
    ];

    private const RADIUS_MAP = [
        'default' => '--oxa-radius',
        'sm'      => '--oxa-radius-sm',
        'lg'      => '--oxa-radius-lg',
    ];

    private function brandFile(): string
    {
        return trailingslashit(get_template_directory()) . 'brand.json';
    }

    public function exists(): bool
    {
        return is_file($this->brandFile());
    }

    /** @return array<string,mixed> */
    public function read(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $raw = file_get_contents($this->brandFile());
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist a new brand definition. Validates structure and color
     * strings. Partial brands are allowed — anything missing falls
     * back to the theme defaults.
     *
     * @param array<string,mixed> $brand
     * @return array{path:string,bytes:int,brand:array}
     */
    public function write(array $brand): array
    {
        $clean = $this->validate($brand);
        $json  = wp_json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode brand JSON.');
        }
        $path = $this->brandFile();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException(sprintf('Theme directory missing: %s', $dir));
        }
        $bytes = file_put_contents($path, $json);
        if ($bytes === false) {
            throw new RuntimeException(sprintf(
                'Could not write brand.json (path=%s). Check filesystem permissions.',
                $path
            ));
        }
        return ['path' => $path, 'bytes' => $bytes, 'brand' => $clean];
    }

    public function clear(): bool
    {
        if (!$this->exists()) {
            return true;
        }
        return @unlink($this->brandFile());
    }

    /**
     * Render the brand's overrides as CSS custom-property declarations.
     * Returns "" when no brand is set.
     */
    public function renderCss(): string
    {
        $brand = $this->read();
        if (!$brand) {
            return '';
        }
        $lines = [];
        foreach (self::COLOR_MAP as $key => $cssVar) {
            $value = $brand['colors'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $lines[] = sprintf('  %s: %s;', $cssVar, $this->cssSafeValue($value));
            }
        }
        $fontSans = $brand['typography']['font_sans'] ?? null;
        if (is_string($fontSans) && $fontSans !== '') {
            $lines[] = sprintf('  --oxa-font-sans: %s;', $this->cssSafeValue($fontSans));
        }
        foreach (self::RADIUS_MAP as $key => $cssVar) {
            $value = $brand['radius'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $lines[] = sprintf('  %s: %s;', $cssVar, $this->cssSafeValue($value));
            }
        }

        if (empty($lines)) {
            return '';
        }
        return ":root {\n" . implode("\n", $lines) . "\n}\n";
    }

    /**
     * Translate the brand into a partial theme.json data fragment, suitable
     * for the `wp_theme_json_data_theme` filter (so the block editor's
     * palette/typography reflects the brand too).
     *
     * @return array
     */
    public function renderThemeJsonFragment(): array
    {
        $brand = $this->read();
        if (!$brand) {
            return [];
        }
        $palette = [];
        $labels = [
            'bg'         => 'Background',
            'surface'    => 'Surface',
            'border'     => 'Border',
            'muted'      => 'Muted',
            'text'       => 'Text',
            'primary'    => 'Primary',
            'on_primary' => 'On Primary',
            'accent'     => 'Accent',
        ];
        foreach ($labels as $key => $name) {
            $value = $brand['colors'][$key] ?? null;
            if (is_string($value) && $value !== '') {
                $palette[] = [
                    'slug'  => str_replace('_', '-', $key),
                    'name'  => $name,
                    'color' => $this->cssSafeValue($value),
                ];
            }
        }

        $fragment = ['version' => 3, 'settings' => []];
        if ($palette) {
            $fragment['settings']['color'] = ['palette' => $palette];
        }
        $fontSans = $brand['typography']['font_sans'] ?? null;
        if (is_string($fontSans) && $fontSans !== '') {
            $fragment['settings']['typography'] = [
                'fontFamilies' => [[
                    'slug'       => 'sans',
                    'name'       => 'Sans',
                    'fontFamily' => $this->cssSafeValue($fontSans),
                ]],
            ];
        }
        return $fragment;
    }

    /**
     * @param array<string,mixed> $brand
     * @return array<string,mixed>
     */
    private function validate(array $brand): array
    {
        $clean = [];
        if (isset($brand['name']) && is_string($brand['name'])) {
            $clean['name'] = sanitize_text_field($brand['name']);
        }
        if (isset($brand['description']) && is_string($brand['description'])) {
            $clean['description'] = sanitize_text_field($brand['description']);
        }

        if (isset($brand['colors']) && is_array($brand['colors'])) {
            $colors = [];
            foreach (self::COLOR_MAP as $key => $_) {
                $value = $brand['colors'][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $colors[$key] = $this->validateColor($value, "colors.{$key}");
                }
            }
            if ($colors) {
                $clean['colors'] = $colors;
            }
        }

        if (isset($brand['typography']) && is_array($brand['typography'])) {
            $fontSans = $brand['typography']['font_sans'] ?? null;
            if (is_string($fontSans) && $fontSans !== '') {
                $clean['typography'] = ['font_sans' => $this->validateFontFamily($fontSans)];
            }
        }

        if (isset($brand['radius']) && is_array($brand['radius'])) {
            $radius = [];
            foreach (self::RADIUS_MAP as $key => $_) {
                $value = $brand['radius'][$key] ?? null;
                if (is_string($value) && $value !== '') {
                    $radius[$key] = $this->validateLengthOrZero($value, "radius.{$key}");
                }
            }
            if ($radius) {
                $clean['radius'] = $radius;
            }
        }
        return $clean;
    }

    private function validateColor(string $value, string $where): string
    {
        $v = trim($value);
        // hex, rgb()/rgba(), hsl()/hsla(), oklch(), or named — we don't go full
        // CSS parser here; we just reject obviously dangerous strings.
        if (preg_match('/[<>"\'`;{}]/', $v) === 1) {
            throw new RuntimeException(sprintf('%s contains illegal characters.', $where));
        }
        return $v;
    }

    private function validateFontFamily(string $value): string
    {
        $v = trim($value);
        if (preg_match('/[<>{}`;]/', $v) === 1) {
            throw new RuntimeException('typography.font_sans contains illegal characters.');
        }
        return $v;
    }

    private function validateLengthOrZero(string $value, string $where): string
    {
        $v = trim($value);
        if (preg_match('/^(?:0|\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw|ch))$/', $v) !== 1) {
            throw new RuntimeException(sprintf('%s must be a CSS length (e.g. "12px", "1rem", "0").', $where));
        }
        return $v;
    }

    /**
     * Strip newlines / control chars that could break out of a CSS rule.
     */
    private function cssSafeValue(string $value): string
    {
        return trim(preg_replace('/[\r\n\0]+/', ' ', $value) ?? '');
    }
}
