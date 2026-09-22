<?php
/**
 * Site-wide globals (header, footer, announcement bar, contact, social, logos).
 *
 * Stored in a single `oxa_site_globals` option. Reads and writes are
 * merged deeply so callers can update a single nested key without
 * restating the rest of the document.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class Globals
{
    public const OPTION = 'oxa_site_globals';

    /** Top-level sections we accept. Anything else is rejected. */
    private const ALLOWED_SECTIONS = [
        'header', 'footer', 'announcement_bar', 'contact', 'social', 'navigation',
        'cta', 'logo', 'favicon', 'meta', 'misc',
    ];

    /** @return array<string,mixed> */
    public function all(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    /**
     * @param string|null $section limit the result to one section (or null for all)
     * @return array<string,mixed>
     */
    public function read(?string $section = null): array
    {
        $all = $this->all();
        if ($section === null || $section === '') {
            return $all;
        }
        $this->assertSection($section);
        $value = $all[$section] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * Merge `$patch` into the stored globals. Top-level keys must be in the allow-list.
     *
     * @return array<string,mixed> the new full document
     */
    public function update(array $patch, bool $replace = false): array
    {
        foreach (array_keys($patch) as $k) {
            if (!is_string($k)) {
                throw new RuntimeException('Top-level keys must be strings.');
            }
            $this->assertSection($k);
        }
        $patch = $this->sanitizeTree($patch);

        if ($replace) {
            $next = $patch;
        } else {
            $next = $this->deepMerge($this->all(), $patch);
        }
        update_option(self::OPTION, $next, false);
        return $next;
    }

    private function assertSection(string $section): void
    {
        if (!in_array($section, self::ALLOWED_SECTIONS, true)) {
            throw new RuntimeException(sprintf(
                'Unknown section "%s". Allowed: %s.',
                $section,
                implode(', ', self::ALLOWED_SECTIONS)
            ));
        }
    }

    private function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !$this->isList($v) && !$this->isList($base[$k])) {
                $base[$k] = $this->deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private function isList(array $a): bool
    {
        if ($a === []) return false;
        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * Walk and sanitize strings; numbers/booleans/null pass through.
     */
    private function sanitizeTree(mixed $value): mixed
    {
        if (is_string($value)) {
            // Allow inline HTML in long-form text via `wp_kses_post`; everything
            // else gets `sanitize_text_field`. We detect "looks like HTML" loosely.
            if (preg_match('/<[a-z][^>]*>/i', $value) === 1) {
                return wp_kses_post($value);
            }
            return sanitize_text_field($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? sanitize_key($k) : $k] = $this->sanitizeTree($v);
            }
            return $out;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }
        // Drop anything we don't recognise (objects, resources).
        return null;
    }
}
