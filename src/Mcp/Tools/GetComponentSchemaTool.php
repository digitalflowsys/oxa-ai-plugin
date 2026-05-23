<?php
/**
 * `get_component_schema` — return the full schema for one component.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Schema\SchemaRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class GetComponentSchemaTool extends AbstractTool
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    public function name(): string        { return 'get_component_schema'; }
    public function description(): string { return 'Return the JSON schema of a single component (field names, types, required flags, defaults). Use this before composing props for that component.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type'        => 'string',
                    'description' => 'Component name, e.g. "hero". List with list_components.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $name = (string) ($arguments['name'] ?? '');
        if ($name === '') {
            return $this->error('Missing required argument "name".');
        }
        $schema = $this->schemas->get($name);
        if ($schema === null) {
            return $this->error(sprintf('Component "%s" not found.', $name));
        }
        return $this->text(sprintf('Schema for "%s":', $name), $schema);
    }
}
