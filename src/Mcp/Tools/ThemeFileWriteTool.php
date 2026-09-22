<?php
/**
 * `theme_file_write` — create or overwrite a file inside the theme.
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

final class ThemeFileWriteTool extends AbstractTool
{
    public function __construct(private readonly ThemeFs $fs) {}

    public function name(): string        { return 'theme_file_write'; }
    public function description(): string { return 'Create or overwrite a file inside the active theme directory. Parent directories are created as needed. Use encoding="base64" for binary content (images, fonts). Path is relative to the theme root.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path'     => ['type' => 'string', 'description' => 'Path relative to theme root.'],
                'content'  => ['type' => 'string', 'description' => 'File contents. Plain UTF-8 unless encoding="base64".'],
                'encoding' => ['type' => 'string', 'enum' => ['utf-8', 'base64'], 'description' => 'Defaults to "utf-8".'],
                'scope'    => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET], 'description' => 'Defaults to "template".'],
            ],
            'required' => ['path', 'content'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $path     = (string) ($arguments['path'] ?? '');
        $content  = (string) ($arguments['content'] ?? '');
        $encoding = (string) ($arguments['encoding'] ?? 'utf-8');
        $scope    = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);

        $result = $this->fs->writeFile($path, $content, $scope, $encoding);
        return $this->text(
            sprintf('%s %s (%d bytes).', $result['created'] ? 'Created' : 'Updated', $result['path'], $result['bytes']),
            $result
        );
    }
}
