<?php
/**
 * Registry of MCP tools.
 *
 * Order of registration determines order in `tools/list`, which is
 * how the model sees them — keep the most-used tools first.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

final class ToolRegistry
{
    /** @var array<string,ToolInterface> */
    private array $tools = [];

    public function add(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<int,array{name:string,description:string,inputSchema:array}> */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $out;
    }
}
