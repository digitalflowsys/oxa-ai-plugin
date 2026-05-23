<?php
/**
 * `generate_page` — preview a generated layout WITHOUT persisting.
 *
 * Useful when the user (via claude.ai chat) wants to iterate on the
 * structure before committing. To actually save, the model should
 * follow up with `create_page` or use `compose_page`.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Generation\PageGenerator;
use OxaAi\Mcp\AbstractTool;

if (!defined('ABSPATH')) {
    exit;
}

final class GeneratePageTool extends AbstractTool
{
    public function __construct(private readonly PageGenerator $generator) {}

    public function name(): string        { return 'generate_page'; }
    public function description(): string { return 'Generate a page layout from a natural-language prompt using the configured AI provider, WITHOUT saving it. Returns page_title, validated layout, and the Gutenberg blocks. Use create_page to persist, or compose_page to build a layout from explicit components instead.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt'   => ['type' => 'string', 'description' => 'Describe the page you want.'],
                'provider' => ['type' => 'string', 'description' => 'Optional override: "openai", "claude", or "mock".'],
            ],
            'required' => ['prompt'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        if ($prompt === '') {
            return $this->error('"prompt" is required.');
        }
        $provider = (string) ($arguments['provider'] ?? '');
        $page     = $this->generator->generate($prompt, $provider !== '' ? $provider : null);

        $summary = sprintf(
            "Generated page \"%s\" with %d components: %s.",
            $page['page_title'],
            count($page['layout']),
            implode(', ', array_column($page['layout'], 'component'))
        );

        return $this->text($summary, [
            'page_title' => $page['page_title'],
            'layout'     => $page['layout'],
        ]);
    }
}
