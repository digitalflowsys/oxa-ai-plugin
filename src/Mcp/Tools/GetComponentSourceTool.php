<?php
/**
 * `get_component_source` — read the four source files of a component.
 *
 * The intended workflow:
 *   1. Claude calls `list_components` to see what's available.
 *   2. Before authoring a new one, calls this on a similar existing
 *      component (e.g. `hero`) to learn the conventions.
 *   3. Mirrors the same shape in a `create_component` call.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Authoring\ComponentAuthor;
use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class GetComponentSourceTool extends AbstractTool
{
    public function __construct(private readonly ComponentAuthor $author) {}

    public function name(): string        { return 'get_component_source'; }
    public function description(): string { return 'Read the raw source of an existing component: schema.json, template.php, styles.css, README.md. Use this to study conventions before calling create_component, or to fetch the current source before calling update_component.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Component slug, e.g. "hero".'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return $this->error('"name" is required.');
        }
        $source = $this->author->read($name);
        if (!$source['exists']) {
            return $this->error(sprintf('Component "%s" does not exist.', $name));
        }

        $summary = sprintf(
            "Component \"%s\":\n- schema.json: %s\n- template.php: %s\n- styles.css:  %s\n- README.md:   %s",
            $name,
            $source['schema'] === null ? 'missing' : 'present',
            $source['template_php'] === null ? 'missing' : sprintf('%d bytes', strlen($source['template_php'])),
            $source['styles_css']   === null ? 'missing' : sprintf('%d bytes', strlen($source['styles_css'])),
            $source['readme_md']    === null ? 'missing' : sprintf('%d bytes', strlen($source['readme_md']))
        );

        return $this->text($summary, [
            'name'         => $source['name'],
            'schema'       => $source['schema'],
            'template_php' => $source['template_php'],
            'styles_css'   => $source['styles_css'],
            'readme_md'    => $source['readme_md'],
        ]);
    }
}
