<?php
/**
 * `update_section` — replace one section's component and/or props.
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

final class UpdateSectionTool extends AbstractTool
{
    public function __construct(
        private readonly LayoutReader $reader,
        private readonly PageWriter $writer,
        private readonly SchemaValidator $validator,
    ) {}

    public function name(): string        { return 'update_section'; }
    public function description(): string { return 'Replace a single section in a page. Provide post_id, the 0-based section index, optional new component name, and new props. Props are validated against the component schema before writing.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'post_id'   => ['type' => 'integer', 'description' => 'WordPress page ID.'],
                'index'     => ['type' => 'integer', 'description' => '0-based section index (see get_page).'],
                'component' => ['type' => 'string',  'description' => 'Optional: change the section to a different component.'],
                'props'     => ['type' => 'object',  'description' => 'New props object. Must conform to the (possibly new) component schema.'],
            ],
            'required' => ['post_id', 'index', 'props'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $postId = (int) ($arguments['post_id'] ?? 0);
        $index  = (int) ($arguments['index']   ?? -1);
        $props  = is_array($arguments['props'] ?? null) ? $arguments['props'] : null;
        if ($postId <= 0 || $index < 0 || $props === null) {
            return $this->error('"post_id", "index" (>=0), and "props" are required.');
        }

        $post = get_post($postId);
        if ($post === null || $post->post_type !== 'page') {
            return $this->error(sprintf('Page #%d not found.', $postId));
        }

        $layout = $this->reader->read($post->post_content);
        if (!isset($layout[$index])) {
            return $this->error(sprintf('Page has no section at index %d.', $index));
        }

        $newComponent = (string) ($arguments['component'] ?? $layout[$index]['component']);

        $validation = $this->validator->validateComponentNode([
            'component' => $newComponent,
            'props'     => $props,
        ]);
        if (!$validation->valid) {
            return $this->error('Validation failed: ' . implode('; ', $validation->errors));
        }

        $layout[$index] = $validation->value;
        $this->writer->save($postId, $layout);

        return $this->text(
            sprintf('Updated section %d on page #%d ("%s" → "%s").',
                $index, $postId, $post->post_title, $newComponent),
            ['post_id' => $postId, 'index' => $index, 'section' => $layout[$index]]
        );
    }
}
