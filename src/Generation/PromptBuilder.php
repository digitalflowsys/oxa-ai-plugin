<?php
/**
 * Builds the system + user prompt that instructs the LLM to emit a
 * strict, schema-valid page layout.
 *
 * The system prompt enumerates every registered component and its
 * schema so the model has the exact contract in context. We do not
 * rely on the model's training-time knowledge of the theme.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Generation;

use OxaAi\Schema\SchemaRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class PromptBuilder
{
    public function __construct(private readonly SchemaRegistry $registry) {}

    /**
     * @return array{system:string,user:string}
     */
    public function build(string $userPrompt): array
    {
        return [
            'system' => $this->systemPrompt(),
            'user'   => $this->userPrompt($userPrompt),
        ];
    }

    private function systemPrompt(): string
    {
        $schemas = $this->registry->all();
        $catalog = wp_json_encode($schemas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<TXT
You are Oxa, a structured-output assistant that generates WordPress page layouts for the Oxa theme.

You ALWAYS respond with a single JSON object — no prose, no markdown fences, no explanations.

The JSON object MUST have this exact shape:

{
  "page_title": "<short, plain-text title>",
  "layout": [
    { "component": "<component_name>", "props": { ... } },
    ...
  ]
}

Rules:
- Use ONLY components from the catalog below. Do not invent components.
- "props" MUST conform to each component's "fields" schema (types, required, options, item_schema).
- Default field values may be omitted.
- Keep copy concrete and benefit-oriented. No filler adjectives.
- Most pages start with one "hero" and end with one "footer". Insert "cta", "features", "pricing", "testimonials", "faq" between as appropriate to the user's intent.

Component catalog (authoritative):

{$catalog}
TXT;
    }

    private function userPrompt(string $request): string
    {
        $request = trim($request);
        return "User request:\n{$request}\n\nReturn the page JSON now.";
    }
}
