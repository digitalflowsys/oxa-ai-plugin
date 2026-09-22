<?php
/**
 * `theme_file_read` — read a file from the active theme directory.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\ThemeFs;
use OxaAi\Site\ThemePaths;

if (!defined('ABSPATH')) {
    exit;
}

final class ThemeFileReadTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_file_read'; }
    public function description(): string { return 'Read a file from inside the active theme directory. Returns UTF-8 contents when text, base64 when binary. Paths are relative to the theme root and may not traverse outside it.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path'   => ['type' => 'string', 'description' => 'Path relative to theme root, e.g. "header.php" or "components/hero/template.php".'],
                'scope'  => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET], 'description' => 'Which theme to read from. "template" = parent theme (default), "stylesheet" = active (possibly child).'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Byte offset to start reading from.'],
                'length' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Max bytes to return (clamped to 2 MiB).'],
            ],
            'required' => ['path'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path   = (string) ($arguments['path'] ?? '');
        $scope  = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $offset = isset($arguments['offset']) ? (int) $arguments['offset'] : null;
        $length = isset($arguments['length']) ? (int) $arguments['length'] : null;

        $result = $this->fs->readFile($path, $scope, $offset, $length);
        return $this->text(
            sprintf('%s (%d bytes, %s)', $result['path'], $result['bytes'], $result['encoding']),
            $result
        );
    }
}
