<?php
/**
 * `add_section` — insert a new section into a page.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Rendering\LayoutReader;
use OxaAi\Rendering\PageWriter;
use OxaAi\Schema\SchemaValidator;

if (!defined('ABSPATH')) {
    exit;
}

final class AddSectionTool extends AbstractTool
{
    public function __construct(
        private readonly LayoutReader $reader,
        private readonly PageWriter $writer,
        private readonly SchemaValidator $validator,
    ) {}

    public function name(): string        { return 'add_section'; }
    public function description(): string { return 'Insert a new section into a page. Specify post_id, component name, props, and an optional position (0-based; default appends to the end).'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id'   => ['type' => 'integer', 'description' => 'WordPress page ID.'],
                'component' => ['type' => 'string',  'description' => 'Component name (see list_components).'],
                'props'     => ['type' => 'object',  'description' => 'Props object conforming to the component schema.'],
                'position'  => ['type' => 'integer', 'description' => 'Optional 0-based insert position. Omit to append.'],
            ],
            'required' => ['post_id', 'component', 'props'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $postId    = (int) ($arguments['post_id']   ?? 0);
        $component = (string) ($arguments['component'] ?? '');
        $props     = is_array($arguments['props'] ?? null) ? $arguments['props'] : null;
        if ($postId <= 0 || $component === '' || $props === null) {
            return $this->error('"post_id", "component", and "props" are required.');
        }

        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'page') {
            return $this->error(sprintf('Page #%d not found.', $postId));
        }

        $validation = $this->validator->validateComponentNode([
            'component' => $component,
            'props'     => $props,
        ]);
        if (!$validation->valid) {
            return $this->error('Validation failed: ' . implode('; ', $validation->errors));
        }

        $layout = $this->reader->read($post->post_content);

        if (array_key_exists('position', $arguments)) {
            $position = max(0, min(count($layout), (int) $arguments['position']));
            array_splice($layout, $position, 0, [$validation->value]);
            $insertedAt = $position;
        } else {
            $layout[]   = $validation->value;
            $insertedAt = count($layout) - 1;
        }

        $this->writer->save($postId, $layout);

        return $this->text(
            sprintf('Added "%s" section at index %d on page #%d "%s". Total sections: %d.',
                $component, $insertedAt, $postId, $post->post_title, count($layout)),
            ['post_id' => $postId, 'index' => $insertedAt, 'section' => $validation->value]
        );
    }
}
