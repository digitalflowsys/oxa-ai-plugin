<?php
/**
 * `block_scaffold` — scaffold a new Gutenberg block under blocks/<slug>/.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\BlockScaffolder;
use OxaAi\Site\ThemePaths;

if (!defined('ABSPATH')) {
    exit;
}

final class BlockScaffoldTool extends AbstractTool
{
    public function __construct(private readonly BlockScaffolder $scaffolder) {}

    public function name(): string        { return 'block_scaffold'; }
    public function description(): string { return 'Scaffold a new server-rendered Gutenberg block under <theme>/blocks/<slug>/. Writes block.json, render.php, and style.css. Useful for bespoke blocks that do not fit the Oxa component contract — for Oxa components prefer create_component.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'slug'        => ['type' => 'string', 'description' => 'Lowercase slug (letters, digits, hyphens). Becomes the folder name and the second half of the block name.'],
                'namespace'   => ['type' => 'string', 'description' => 'Block namespace. Defaults to "oxa".'],
                'title'       => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'category'    => ['type' => 'string', 'description' => 'Block editor category. Defaults to the namespace.'],
                'icon'        => ['type' => 'string', 'description' => 'Dashicon slug or SVG. Defaults to "block-default".'],
                'attributes'  => ['type' => 'object', 'description' => 'Map of attribute name to attribute spec, per block.json.'],
                'render_php'  => ['type' => 'string', 'description' => 'Optional full render.php source. If omitted, a sane default is generated.'],
                'styles_css'  => ['type' => 'string', 'description' => 'Optional CSS for style.css. If omitted, a minimal stub is generated.'],
                'scope'       => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'required' => ['slug'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        unset($arguments['scope']);
        $result = $this->scaffolder->scaffold($arguments, $scope);
        return $this->text(
            sprintf('Scaffolded block %s (%d bytes across %d files).', $result['name'], $result['bytes'], count($result['written'])),
            $result
        );
    }
}
