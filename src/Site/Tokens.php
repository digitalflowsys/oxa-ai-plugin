<?php
/**
 * Design tokens stored as CSS custom properties.
 *
 * The file lives at `<theme>/tokens.css`. The theme is expected to
 * enqueue it on every request; if it does not yet exist, `tokens_get`
 * returns the empty default and `tokens_update` creates it.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class Tokens
{
    private const FILENAME = 'tokens.css';
    private const MAX_BYTES = 256 * 1024;

    public function read(string $scope = ThemePaths::SCOPE_TEMPLATE): array
    {
        $abs = ThemePaths::root($scope) . self::FILENAME;
        if (!is_file($abs)) {
            return ['exists' => false, 'path' => $abs, 'css' => '', 'bytes' => 0];
        }
        $raw = file_get_contents($abs);
        if ($raw === false) {
            throw new RuntimeException('Could not read tokens.css.');
        }
        return ['exists' => true, 'path' => $abs, 'css' => $raw, 'bytes' => strlen($raw)];
    }

    public function update(string $css, string $scope = ThemePaths::SCOPE_TEMPLATE): array
    {
        if (strlen($css) > self::MAX_BYTES) {
            throw new RuntimeException(sprintf('tokens.css too large (>%d bytes).', self::MAX_BYTES));
        }
        // Tokens are CSS — reject any HTML/JS smuggling.
        if (stripos($css, '</style') !== false || stripos($css, '<script') !== false) {
            throw new RuntimeException('tokens.css must not contain </style or <script tokens.');
        }
        $abs = ThemePaths::root($scope) . self::FILENAME;
        ThemePaths::assertInside($abs, $scope);

        $existed = is_file($abs);
        $tmp = $abs . '.oxa.tmp';
        $bytes = file_put_contents($tmp, $css);
        if ($bytes === false) {
            @unlink($tmp);
            throw new RuntimeException('Could not write tokens.css.');
        }
        if (!rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException('Could not finalize tokens.css.');
        }
        return ['path' => $abs, 'bytes' => (int) $bytes, 'created' => !$existed];
    }
}
