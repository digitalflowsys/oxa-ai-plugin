# Architecture

Two packages, one boundary:

```
┌─────────────────────────────────────────────────────────────────┐
│                          oxa theme                              │
│                                                                 │
│  /components/<name>/  ← schema.json  template.php  styles.css   │
│                                                                 │
│  ComponentLoader  →  ComponentRenderer  →  template.php         │
│                                                                 │
│  /inc/block-registry.php  registers `oxa/component` block       │
└────────────────────────────▲────────────────────────────────────┘
                             │  reads schemas, renders blocks
                             │
┌────────────────────────────┴────────────────────────────────────┐
│                          oxa-ai plugin                          │
│                                                                 │
│   Settings ─┐                                                   │
│             ▼                                                   │
│   PromptBuilder ──▶ Provider ──▶ ResponseParser ──▶ Validator   │
│         ▲                                              │        │
│         │                                              ▼        │
│   SchemaRegistry ◀───────── reads ─────────────  GutenbergComposer
│         │                                              │        │
│         │                                              ▼        │
│         │                                       wp_insert_post()│
│         │                                                       │
│   REST  controllers expose every step                           │
│   Admin UI consumes those REST endpoints                        │
└─────────────────────────────────────────────────────────────────┘
```

## Boundary

The plugin **reads** schemas from the theme but never writes to the theme. The theme **renders** blocks but never makes network calls or imports plugin code. This isolation is what makes the system safe:

1. You can deactivate the plugin and pages still render — they're stored as Gutenberg blocks, and the block type lives in the theme.
2. You can switch themes (to one that exposes the same `/components/` convention) without touching the plugin.
3. You can swap the AI provider without touching either side.

## DI container

`OxaAi\Core\Plugin::buildContainer()` is the single composition root. It is dumb on purpose: no auto-wiring, no scanning, no surprises. Adding a service is two lines.

## Data flow on a generation

1. User submits prompt via REST or admin form.
2. `GenerateController` resolves `PageGenerator` from the container.
3. `PageGenerator` asks `PromptBuilder` for `{system, user}`. The system prompt **inlines the full schema catalog** so the model has the exact contract in context.
4. `PageGenerator` calls `Provider.complete()`. Provider returns raw text.
5. `ResponseParser` strips markdown fences / extracts the first balanced JSON object, decodes it.
6. `SchemaValidator` walks every component node and every field. Each type has a dedicated validator that also sanitizes (`sanitize_text_field`, `esc_url_raw`, `wp_kses_post`). Unknown fields are dropped. Required fields produce errors.
7. If valid, `GutenbergComposer` serializes each node as `<!-- wp:oxa/component {"component":"...","props":{...}} /-->`.
8. Optionally `wp_insert_post` persists as a draft `page`.
9. On view, WordPress invokes the block's `render_callback`, which delegates to the theme's `ComponentRenderer`.

## Why a single `oxa/component` block instead of one block per component?

- One block type per component means N block registrations, N React components, N entries in the inserter — and AI has to keep them all straight.
- One generic block means AI only ever needs to know about one Gutenberg construct, and adding a new component requires **zero block code**.
- The trade-off — slightly less rich editor UX — is acceptable because Oxa's authoring mode is "describe what you want and let AI structure it." A future editor sidebar can layer richer affordances on top of the same block.

## Error handling philosophy

- **Transport errors** (network, HTTP 5xx) → `ProviderException` with the upstream status code → REST returns it.
- **AI hallucinations** (unknown component, missing required field, wrong type) → `SchemaValidator` returns a list of human-readable errors → REST returns 500 with the list.
- **Bad input** (empty prompt, unknown provider slug) → REST returns 4xx immediately, no provider call.

The validator never auto-corrects. If the AI hallucinates, the request fails loudly so the operator can adjust the prompt or model.

## Where to extend

| Want to… | Touch |
|---|---|
| Add a component | New folder in `themes/oxa/components/` |
| Add a provider | New class in `plugins/oxa-ai/src/Providers/`; register on `oxa_ai_register_providers` |
| Add a field type | Add a `validate<Type>()` method in `SchemaValidator` and a sanitizer branch |
| Add a REST endpoint | New controller in `src/Rest/Controllers/`; register from `RestController::register()` |
| Add an admin page | New `add_submenu_page()` call in `Admin::menu()`, new view under `src/Admin/views/` |
