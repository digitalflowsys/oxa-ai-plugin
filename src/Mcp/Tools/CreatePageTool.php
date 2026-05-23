<?php
/**
 * `create_page` — generate from a prompt AND persist as a draft page.
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

final class CreatePageTool extends AbstractTool
{
    public function __construct(private readonly PageGenerator $generator) {}

    public function name(): string        { return 'create_page'; }
    public function description(): string { return 'Generate a page from a prompt AND save it as a WordPress draft page. Returns post_id, edit_url, page_title, and the layout. Prefer compose_page when the user has dictated exact sections.'; }

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
        $result   = $this->generator->generateAndStore($prompt, $provider !== '' ? $provider : null);

        $summary = sprintf(
            "Created draft page #%d \"%s\" with %d components. Edit it at: %s",
            $result['post_id'],
            $result['page']['page_title'],
            count($result['page']['layout']),
            $result['edit_url']
        );

        return $this->text($summary, [
            'post_id'    => $result['post_id'],
            'edit_url'   => $result['edit_url'],
            'page_title' => $result['page']['page_title'],
            'layout'     => $result['page']['layout'],
        ]);
    }
}
