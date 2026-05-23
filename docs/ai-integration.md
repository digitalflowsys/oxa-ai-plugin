# AI integration

## How the prompt is constructed

The system prompt has three parts:

1. A short statement of the assistant's role and output rules.
2. The required JSON shape: `{ "page_title": "...", "layout": [ {component, props}, ... ] }`.
3. **The entire component catalog**, inlined as JSON, including every component's `fields` schema.

This is intentional. We never assume the model has trained on this codebase. The catalog is the contract.

```php
$messages = (new PromptBuilder($schemaRegistry))->build('Landing page for a privacy-first analytics SaaS.');
```

`$messages['system']` will be ~1-3 KB depending on how many components are registered.

## Provider contract

```php
interface ProviderInterface {
    public function slug(): string;
    public function label(): string;
    public function isConfigured(): bool;
    public function defaultModel(): string;
    public function complete(array $messages, array $options = []): string;
}
```

`complete()` receives `['system' => '...', 'user' => '...']` and must return the **raw assistant text**. It must throw `OxaAi\Providers\ProviderException` on any failure (transport, HTTP 4xx/5xx, empty content). It must not try to parse JSON — that is `ResponseParser`'s job.

## Built-in providers

| Slug | Model default | API |
|---|---|---|
| `mock` | `mock-v1` | none — returns a deterministic payload |
| `openai` | `gpt-4o-mini` | `POST https://api.openai.com/v1/chat/completions` with `response_format=json_object` |
| `claude` | `claude-sonnet-4-6` | `POST https://api.anthropic.com/v1/messages` |

The `mock` provider is the default after install so you can exercise the full pipeline (REST → validator → composer → renderer) before adding any API keys.

## Adding a custom provider

```php
<?php

use OxaAi\Providers\AbstractHttpProvider;
use OxaAi\Providers\ProviderException;

final class GroqProvider extends AbstractHttpProvider
{
    public function slug(): string  { return 'groq'; }
    public function label(): string { return 'Groq'; }
    public function defaultModel(): string { return 'llama-3.1-70b-versatile'; }

    public function complete(array $messages, array $options = []): string
    {
        $apiKey = $this->settings->secret($this->slug());
        if ($apiKey === '') {
            throw new ProviderException('Groq API key is not configured.', 412);
        }
        $config = $this->settings->config();

        $json = $this->postJson(
            'https://api.groq.com/openai/v1/chat/completions',
            ['Authorization' => 'Bearer ' . $apiKey],
            [
                'model'           => $options['model'] ?? $config['model'] ?: $this->defaultModel(),
                'temperature'     => $config['temperature'],
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $messages['system']],
                    ['role' => 'user',   'content' => $messages['user']],
                ],
            ]
        );
        return (string) ($json['choices'][0]['message']['content'] ?? '');
    }
}
```

Register it once:

```php
add_action('oxa_ai_register_providers', function ($registry) {
    $registry->add(new GroqProvider(
        OxaAi\Core\Plugin::container()->get(OxaAi\Core\Settings::class),
        OxaAi\Core\Plugin::container()->get(OxaAi\Core\Logger::class),
    ));
});
```

It now shows up in the settings dropdown and in `/wp-json/oxa/v1/providers`.

## Prompt-engineering notes

- The catalog is the constraint, not the prompt. Don't restate component names in the user prompt.
- Lower temperature (0.2-0.4) produces more reliably valid JSON. The default is 0.4.
- If a particular component is being abused (e.g. AI always picks `features` for everything), edit that component's `description` in `schema.json` to be more specific. The catalog is the only thing the AI sees.

## Failure modes & how to debug

| Symptom | Likely cause | Where to look |
|---|---|---|
| `AI response was not valid JSON.` | Model returned prose despite instructions | Use `mock` to isolate; raise `temperature` floor; check the Logs page for the upstream response |
| `Unknown component "..."` | Hallucination | Confirm component is registered (`GET /components`); strengthen the prompt rule |
| `<x>: required field missing` | Schema mismatch | Check the corresponding component's `schema.json` |
| `Transport error: ...` | Network / API key | Verify the key in **Settings**; check Logs |
