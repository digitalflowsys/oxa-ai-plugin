# Roadmap

The MVP (v0.1) covers the end-to-end pipeline with three providers, seven components, and a minimal admin UI. Below is the path to a 1.0 release.

## v0.2 — Authoring tools

- Editor sidebar: list available components with descriptions, insert as `oxa/component` blocks
- Per-component "Generate this section" prompt in the block inspector
- Streaming responses (SSE) for long generations
- Export/import of full pages as JSON

## v0.3 — Multilingual & content updates

- `POST /sections/{n}/update` — surgical updates to a single section in an existing page
- Multilingual page generation against the same schema (one prompt, N posts)
- Schema-driven A/B copy variants

## v0.4 — Quality + DX

- PHPUnit coverage for validator, parser, and composer
- `wp oxa generate` CLI command
- A dedicated `oxa_log` table once the option-backed log starts to feel small
- Block editor visual preview that round-trips through the validator

## v0.5 — Component ecosystem

- Theme.json-aware components (read user palette/typography overrides)
- `oxa-pack-*` add-on plugins for vertical-specific component libraries (e-commerce, restaurants, agencies)
- Schema versioning so add-ons can declare minimum framework versions

## 1.0 — Stability

- Frozen REST surface
- Documented provider extension API
- Tested upgrade path from v0.x
- Localization-ready

## Out of scope (intentionally)

- Visual drag-and-drop editor
- Page-builder-style nested layout primitives
- Server-stored prompts/templates (those belong upstream of WordPress)
