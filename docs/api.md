# REST API

Base URL: `/wp-json/oxa/v1/`

Authentication: standard WordPress cookie auth + `X-WP-Nonce`, or application passwords. All endpoints require at minimum `edit_posts`; `POST /pages` requires `publish_pages`.

---

## `GET /components`

List the full component catalog.

```http
GET /wp-json/oxa/v1/components
```

```json
{
  "count": 7,
  "data": [
    {
      "name": "hero",
      "title": "Hero",
      "description": "Above-the-fold hero section…",
      "fields": { "title": { "type": "string", "required": true }, "...": "..." }
    },
    "..."
  ]
}
```

## `GET /components/<name>`

Single component schema.

```http
GET /wp-json/oxa/v1/components/hero
```

`404` if not found.

## `GET /schemas`

Compact view: only `fields` and `description`, keyed by component name. Useful for AI clients that already know the framework conventions.

```json
{ "data": { "hero": { "fields": {...}, "description": "..." }, "...": "..." } }
```

## `GET /providers`

```json
{
  "data": [
    { "slug": "mock",   "label": "Mock (offline)",     "configured": true,  "default_model": "mock-v1" },
    { "slug": "openai", "label": "OpenAI",             "configured": false, "default_model": "gpt-4o-mini" },
    { "slug": "claude", "label": "Anthropic Claude",   "configured": false, "default_model": "claude-sonnet-4-6" }
  ]
}
```

## `POST /generate`

Validate the pipeline end-to-end without saving anything.

```http
POST /wp-json/oxa/v1/generate
Content-Type: application/json

{ "prompt": "Pricing page with 3 tiers for a project management SaaS.", "provider": "claude" }
```

Response:

```json
{
  "data": {
    "page_title": "Pricing",
    "layout": [ { "component": "...", "props": {...} } ],
    "blocks": "<!-- wp:oxa/component {...} /-->\n\n<!-- wp:oxa/component {...} /-->"
  }
}
```

Errors:

- `400` — empty prompt
- `412` — provider not configured (no API key)
- `500` — validation failure (response body includes the schema error list)
- `502` — provider returned an error

## `POST /pages`

Like `/generate`, but persists the result as a WordPress `page` in draft status.

```json
{
  "data": {
    "post_id": 42,
    "edit_url": "https://example.com/wp-admin/post.php?post=42&action=edit",
    "page": { "page_title": "...", "layout": [...], "blocks": "..." }
  }
}
```

Requires `publish_pages` capability.

---

## Error shape

All non-2xx responses have the form:

```json
{ "error": "human-readable message" }
```

Validation failures concatenate all schema errors into a single string separated by `; `. Inspect `WP Admin → Oxa AI → Logs` for the structured form.
