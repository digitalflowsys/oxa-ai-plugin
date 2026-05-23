<?php
/**
 * `update_component` — modify an existing Oxa component on disk.
 *
 * Same validation surface as `create_component`. Accepts any subset of
 * the four files — fields you omit are left untouched.
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

final class UpdateComponentTool extends AbstractTool
{
    public function __construct(private readonly ComponentAuthor $author) {}

    public function name(): string        { return 'update_component'; }
    public function description(): string { return 'Modify an EXISTING Oxa component. Send only the files you want to change (schema, template_php, styles_css, readme_md). The plugin lints PHP, validates the schema if provided, and refreshes caches. Note: schema changes can invalidate props on already-published pages — use get_page + update_section if you also need to migrate content.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Existing component slug.'],
                'schema' => [
                    'type'        => 'object',
                    'description' => 'Replacement schema object: { title, description, category, fields }. If omitted, schema is left unchanged.',
                ],
                'template_php' => ['type' => 'string', 'description' => 'Replacement template.php. Same rules as create_component.'],
                'styles_css'   => ['type' => 'string', 'description' => 'Replacement styles.css.'],
                'readme_md'    => ['type' => 'string', 'description' => 'Replacement README.md.'],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $name = (string) ($arguments['name'] ?? '');

        $payload = [];
        if (isset($arguments['schema'])) {
            if (!is_array($arguments['schema'])) {
                return $this->error('"schema" must be an object.');
            }
            // Normalize: caller provides title/description/fields, we
            // pack them into our schema shape and force name = $name.
            $payload['schema'] = array_merge(
                ['name' => $name],
                $arguments['schema']
            );
        }
        foreach (['template_php', 'styles_css', 'readme_md'] as $k) {
            if (isset($arguments[$k])) {
                $payload[$k] = (string) $arguments[$k];
            }
        }
        if (empty($payload)) {
            return $this->error('Provide at least one of: schema, template_php, styles_css, readme_md.');
        }

        $result = $this->author->write($name, $payload, 'update');

        return $this->text(
            sprintf('Updated component "%s" (%d bytes across %d files).',
                $result['name'], $result['bytes'], count($result['written'])
            ),
            ['name' => $result['name'], 'written' => $result['written']]
        );
    }
}
