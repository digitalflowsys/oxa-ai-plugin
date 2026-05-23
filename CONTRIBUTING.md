# Contributing

Thank you for thinking about contributing to Oxa. This document explains how to set up a development environment and the conventions the codebase follows.

## Development setup

```bash
# Bring up a local WP via wp-env (or your tool of choice)
git clone https://github.com/oxa/oxa-theme wp-content/themes/oxa
git clone https://github.com/oxa/oxa-ai    wp-content/plugins/oxa-ai
# Activate both in WP Admin.
```

No build step is required for the runtime. Linting is handled with `phpcs` and the WordPress coding standard.

## Conventions

- **PHP 8.3+ only.** `declare(strict_types=1)` in every file. Final classes by default. Readonly properties for constructor-injected dependencies.
- **PSR-4 autoloading.** Namespace `OxaAi\` for the plugin, `Oxa\Theme\` for the theme.
- **Single Responsibility.** Each class lives in its own file; one public concept per file.
- **Schema-first.** Any new component is defined by its `schema.json` first. Templates render that schema; they do not invent their own props.
- **AI is downstream.** AI provider code never knows about specific components. It only talks to the prompt builder, which reads the registry.
- **Sanitize at the edge.** Validate AI output once at the validator boundary. Render with `esc_html` / `esc_url` / `wp_kses_post` for defense in depth.

## Adding a component

1. Create `themes/oxa/components/<name>/`.
2. Add `schema.json` (use existing components as templates).
3. Add `template.php` — receives `$props`, `$component_name`, `$schema`. Escape all output.
4. Add `styles.css` (optional). The enqueue layer registers it automatically.
5. Add `README.md` with field-level docs and "AI guidance" notes.
6. Done. The registry will discover it on the next request.

## Adding a provider

1. Create `plugins/oxa-ai/src/Providers/<Name>Provider.php`.
2. Extend `AbstractHttpProvider` (or implement `ProviderInterface`).
3. Register it via the `oxa_ai_register_providers` filter, or add it directly in `Plugin::buildContainer()` if it ships in core.
4. Update `docs/ai-integration.md`.

## Pull requests

- One concern per PR. Refactors live in their own PR, not bundled with features.
- Include screenshots for any visual changes.
- Tests are encouraged but not required for the initial MVP.
