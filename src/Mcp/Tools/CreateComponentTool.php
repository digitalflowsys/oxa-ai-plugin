<?php
/**
 * `create_component` — author a new Oxa component on disk.
 *
 * Writes four files to `themes/oxa/components/<name>/`:
 *   schema.json, template.php, styles.css, README.md
 *
 * Strongly recommended: call `get_component_source` on an existing
 * component first to learn the conventions, then mirror them.
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

final class CreateComponentTool extends AbstractTool
{
    public function __construct(private readonly ComponentAuthor $author) {}

    public function name(): string        { return 'create_component'; }
    public function description(): string { return 'Author a NEW Oxa component on disk: schema.json, template.php, styles.css, README.md. The template runs server-side PHP — escape all output with esc_html/esc_url/esc_attr. The plugin lints the PHP, validates the schema, refuses dangerous calls (exec/eval/file I/O), and refreshes caches so the component is usable in the same chat turn. Read an existing component first via get_component_source to learn the conventions.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Lowercase slug, letters+digits+hyphens, starts with a letter. Becomes the folder name and the schema name.',
                ],
                'title'       => ['type' => 'string', 'description' => 'Human-readable title shown in admin.'],
                'description' => ['type' => 'string', 'description' => 'One-sentence purpose summary. Surfaced to AI in the system prompt.'],
                'category'    => ['type' => 'string', 'description' => 'Free-form tag, e.g. "marketing", "content", "social-proof".'],
                'fields' => [
                    'type' => 'object',
                    'description' => 'Field definitions. Each field is {type, required?, default?, description?, options? (enum), item_schema? (array of objects), min_items?, max_items?}. Valid types: string, text, url, boolean, enum, array.',
                ],
                'template_php' => [
                    'type'        => 'string',
                    'description' => 'Full template.php source, starting with "<?php". Receives $props (validated/defaulted), $component_name, $schema. MUST escape every dynamic output (esc_html, esc_url, esc_attr). File-system writes, eval(), shell calls, and HTTP calls are rejected.',
                ],
                'styles_css' => [
                    'type'        => 'string',
                    'description' => 'Scoped CSS for the component. Prefer class names prefixed with oxa-<component>-. Use CSS custom properties from tokens.css for colors/typography.',
                ],
                'readme_md' => [
                    'type'        => 'string',
                    'description' => 'Component README in Markdown. Document props table and "AI guidance" tips.',
                ],
            ],
            'required' => ['name', 'title', 'fields', 'template_php'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $name = (string) ($arguments['name'] ?? '');
        $schema = [
            'name'        => $name,
            'title'       => (string) ($arguments['title'] ?? ''),
            'description' => (string) ($arguments['description'] ?? ''),
            'category'    => (string) ($arguments['category'] ?? ''),
            'fields'      => is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [],
        ];

        $result = $this->author->write($name, [
            'schema'       => $schema,
            'template_php' => (string) ($arguments['template_php'] ?? ''),
            'styles_css'   => isset($arguments['styles_css']) ? (string) $arguments['styles_css'] : null,
            'readme_md'    => isset($arguments['readme_md'])  ? (string) $arguments['readme_md']  : null,
        ], 'create');

        return $this->text(
            sprintf('Created component "%s" (%d bytes across %d files). It is live immediately — you can use it in compose_page or add_section right now.',
                $result['name'], $result['bytes'], count($result['written'])
            ),
            ['name' => $result['name'], 'dir' => $result['dir'], 'written' => $result['written']]
        );
    }
}
