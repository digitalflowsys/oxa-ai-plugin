<?php
/**
 * `list_components` — enumerate available components.
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

final class ListComponentsTool extends AbstractTool
{
    public function __construct(private readonly SchemaRegistry $schemas) {}

    public function name(): string        { return 'list_components'; }
    public function description(): string { return 'List every Oxa component available on this site, with name and description. Call this first when planning a page.'; }

    public function inputSchema(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $components = $this->schemas->all();
        if (empty($components)) {
            return $this->error('No Oxa components are registered. Activate an Oxa-compatible theme.');
        }

        $summary = "Available components:\n";
        $rows    = [];
        foreach ($components as $name => $schema) {
            $summary .= sprintf("- %s: %s\n",
                $name,
                (string) ($schema['description'] ?? '')
            );
            $rows[$name] = $schema['description'] ?? '';
        }

        return $this->text(trim($summary), $rows);
    }
}
