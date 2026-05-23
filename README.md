# Oxa AI

> Companion plugin for the [Oxa theme](../../themes/oxa/). The orchestration layer between AI systems and WordPress.

Generate, validate, compose, and persist WordPress pages from natural-language prompts using Claude, OpenAI, or any provider you plug in.

---

## What it does

```
prompt ─┐
         ├─▶ PromptBuilder ──▶ Provider.complete()
         │                        │
         │                        ▼
         │                  raw assistant text
         │                        │
         │                        ▼
         │                  ResponseParser  (defensive JSON extraction)
         │                        │
         │                        ▼
         │                  SchemaValidator (type, required, defaults, enums, arrays)
         │                        │
         │                        ▼
         └──────────────▶  GutenbergComposer ──▶ <!-- wp:oxa/component {...} /-->
                                                    │
                                                    ▼
                                                wp_insert_post(draft)
```

Every step is independently testable and swappable through the DI container.

---

## Requirements

- WordPress 6.4+
- PHP 8.3+
- The Oxa theme **active** (the plugin reads its component catalog from the theme)
- An API key for OpenAI or Anthropic (optional — a built-in `mock` provider lets you try the pipeline offline)

---

## Install

```bash
cd wp-content/plugins
git clone https://github.com/oxa/oxa-ai
# WP Admin → Plugins → activate "Oxa AI"
```

Then visit **WP Admin → Oxa AI → Settings** to choose a provider and paste a key.

---

## Use it from claude.ai (no API key required)

Oxa AI ships a built-in **MCP server** so you can add this WordPress site as a custom Connector at <https://claude.ai/settings/connectors> and edit it directly from a chat.

1. WP Admin → **Oxa AI → Connector** → **Generate token**.
2. At claude.ai, **Add custom connector**, paste the URL `https://your-site.tld/wp-json/oxa/v1/mcp`, and add header `Authorization: Bearer <token>`.
3. In a chat, just describe the page you want — Claude will discover the components, compose a valid layout, and the server will validate + save it as a draft.

Full setup, tool reference, and troubleshooting: [docs/mcp-connector.md](docs/mcp-connector.md).

## REST API

All routes are under `/wp-json/oxa/v1/` and require `edit_posts` capability (writes require `publish_pages`).

| Method | Endpoint | Purpose |
|---|---|---|
| GET  | `/components`               | Full component catalog |
| GET  | `/components/<name>`        | Single component schema |
| GET  | `/schemas`                  | Compact `{name: {fields, description}}` map |
| GET  | `/providers`                | Installed providers + readiness |
| POST | `/generate`                 | Prompt → validated layout + Gutenberg blocks (no save) |
| POST | `/pages`                    | Prompt → draft page (returns `post_id`, `edit_url`) |

Example:

```bash
curl -X POST "$WP/wp-json/oxa/v1/generate" \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $NONCE" \
  -b cookie.jar \
  -d '{"prompt": "Landing page for a privacy-focused analytics SaaS."}'
```

---

## Provider model

Implement `OxaAi\Providers\ProviderInterface` and register it via the `oxa_ai_register_providers` filter:

```php
add_action('oxa_ai_register_providers', function ($registry) {
    $registry->add(new MyCustomProvider($settings, $logger));
});
```

Bundled: `claude`, `openai`, `mock`. See [docs/ai-integration.md](docs/ai-integration.md) for details.

---

## Security

- API keys live in a **separate `wp_options` row** from non-secret config, and are scrubbed from logs by key pattern (`api_key`, `secret`, `token`, `authorization`).
- All AI output is **validated** against the component schemas before it touches `post_content`. Anything the AI invents is rejected with a clear error.
- All component output is sanitized through WordPress' own `esc_html`, `esc_url`, and `wp_kses_post` at render time.
- REST writes require `publish_pages`; reads require `edit_posts`.

---

## License

MIT
