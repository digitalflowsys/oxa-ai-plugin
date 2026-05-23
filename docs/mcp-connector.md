# MCP Connector for claude.ai

Oxa AI ships a built-in [MCP](https://modelcontextprotocol.io/) server at:

```
POST /wp-json/oxa/v1/mcp
```

This lets you add your WordPress site as a **custom Connector at claude.ai** and edit your site directly from a chat. No OpenAI / Anthropic API key is required on the WordPress side — Claude (the chat) composes the layout, ships it through the connector, and the server validates and saves it.

---

## How it works

```
┌─────────────────────────────┐
│   claude.ai chat            │
│                             │
│   "Add a pricing section    │
│    to page 42 with 3 tiers" │
└──────────────┬──────────────┘
               │ MCP / JSON-RPC over HTTPS
               │ Authorization: Bearer <token>
               ▼
┌──────────────────────────────────────────────────────────┐
│  POST /wp-json/oxa/v1/mcp                                │
│                                                          │
│  McpController → BearerAuth → McpServer → ToolRegistry   │
│                                                                │
│  Tools execute against:                                   │
│    • SchemaRegistry (read components)                     │
│    • SchemaValidator (sanitize props)                     │
│    • GutenbergComposer (build blocks)                     │
│    • LayoutReader/PageWriter (read/write post_content)    │
│    • PageGenerator (optional LLM fallback)                │
└──────────────────────────────────────────────────────────┘
```

The model in the claude.ai chat:

1. Calls `list_components` once to learn what's available.
2. Calls `get_component_schema` for any component it intends to use.
3. Composes a layout client-side and calls `compose_page` to create it,
   or calls `add_section` / `update_section` / `delete_section` to edit
   an existing page.

The plugin's `oxa_ai` settings (provider, OpenAI/Claude API keys, etc.) are **only** consulted when the model invokes `generate_page` / `create_page`, which delegate to a server-side LLM. For chat-driven workflows you don't need any of that — `compose_page` is fully deterministic.

---

## Setup

### 1. Generate a token

WP Admin → **Oxa AI → Connector** → **Generate token**.

The plain token is shown **once**. Copy it immediately; only its hash is stored.

Token format: `oxa_` + 43 url-safe base64 chars.

### 2. Add the connector at claude.ai

1. Go to <https://claude.ai/settings/connectors>.
2. **Add custom connector**.
3. **Server URL**: `https://your-domain.tld/wp-json/oxa/v1/mcp`
4. **Authentication.** Three options, in decreasing order of safety:

   **A. `Authorization` header (recommended).**
   - Header name: `Authorization`
   - Header value: `Bearer <your-token>`

   **B. `X-Oxa-Token` header (fallback for clients that block `Authorization`).**
   - Header name: `X-Oxa-Token`
   - Header value: `<your-token>` (no `Bearer ` prefix)

   **C. `?token=` query parameter (last resort).** Use only if the client supports no headers at all. The token will appear in web-server access logs, reverse-proxy logs, browser history, and potentially `Referer` headers. **Rotate immediately if any log file is ever shared.**
   ```
   https://example.com/index.php/wp-json/oxa/v1/mcp?token=oxa_xxxxxxxxxxxx
   ```

5. Save. claude.ai will call `initialize` and `tools/list`. You should see the Oxa tools appear in the chat tool list.

### 3. Talk to it

In a claude.ai chat, just describe what you want:

> "Create a landing page for a privacy-first analytics SaaS. Hero, 3 features, pricing with 3 tiers (mark Pro as popular), FAQ with 5 entries, closing CTA."

Claude will:
1. Call `list_components` (sees 7 components).
2. Call `get_component_schema` for `hero`, `features`, `pricing`, `faq`, `cta`.
3. Compose a layout matching every schema.
4. Call `compose_page` → server validates, composes blocks, creates a draft, returns `post_id` and `edit_url`.

You get back a clickable link to the draft.

---

## Tools

The connector exposes 14 tools, grouped by what they do.

### Discovery (read-only)

| Tool | What it does |
|---|---|
| `list_components` | Catalog of available components |
| `get_component_schema` | JSON schema for one component (props, types, required) |
| `get_component_source` | Raw source of an existing component — schema.json, template.php, styles.css, README.md. Call this before `create_component` to learn the conventions. |
| `list_pages` | List WP pages with their Oxa section counts |
| `get_page` | Read one page's layout (with indexed sections) |

### Authoring — design system (file writes to theme)

| Tool | What it does |
|---|---|
| `set_brand` | Define/update site-wide brand: palette, font family, radius scale. Partial updates supported. Reset with `{ reset: true }`. |
| `create_component` | Author a new component on disk (schema + PHP template + CSS + README). PHP is linted; dangerous calls are rejected; caches refresh; component is usable in the same chat turn. |
| `update_component` | Modify an existing component (any subset of the four files). |

### Page composition (writes to `post_content`)

| Tool | What it does |
|---|---|
| `compose_page` | Create a page from an explicit layout — primary tool for chat-driven workflows |
| `generate_page` | Generate a layout from a prompt via the configured LLM (preview only) |
| `create_page` | Generate + persist as draft via the configured LLM |

### Page mutations

| Tool | What it does |
|---|---|
| `add_section` | Insert a section into an existing page |
| `update_section` | Replace a section by index |
| `delete_section` | Remove a section by index |

### Typical conversational flow

```
user:   "Build me a vegan restaurant site, warm and earthy."

claude: list_components()                          → sees the 7 starters
claude: get_component_source('hero')               → learns the conventions
claude: set_brand({colors: {bg, primary, accent, ...}, typography: {font_sans}, radius: {...}})
claude: create_component('menu_item', schema, template_php, styles_css, readme_md)
claude: create_component('chef_quote', ...)
claude: compose_page('Home', [{hero}, {menu_item × 6}, {chef_quote}, {cta}, {footer}])

user:   "Make the primary color darker."

claude: set_brand({colors: {primary: '#1f3f15'}})  → partial update merges with existing
```

Every mutating tool validates against the schema, sanitizes input, and returns a precise error the model can read and recover from. Authoring tools additionally lint the PHP and reject `exec`/`eval`/`file_put_contents`/etc.

---

## Auth & security

| | |
|---|---|
| Transport | HTTPS — claude.ai will not connect to plain HTTP |
| Auth | Bearer token (SHA-256 hashed at rest) |
| Token rotation | One-click in admin; rotation revokes the previous token |
| Logging | Tokens never written to logs (key-pattern scrubbing in [Logger](../src/Core/Logger.php)) |
| Capabilities | Token-based — the connector acts with the privileges of "any caller who has the token". Treat it like a long-lived API key. |
| Input validation | Every section's props are validated against the schema; sanitization at the validator boundary, escaping at render time |
| **PHP authoring scope** | Component file writes are restricted to `themes/oxa/components/<name>/` (name pattern enforced). Brand writes go to `themes/oxa/brand.json` only. No other path is reachable through MCP. |
| **PHP lint** | Every `template.php` is syntax-checked with `php -l` before being written. Templates that call `exec`/`eval`/`shell_exec`/`file_put_contents`/`fopen`/`unlink`/`rename`/`curl_exec`/`wp_remote_*` are rejected. |
| CORS | Permissive (`Access-Control-Allow-Origin: *`) **only on `/mcp` and `/mcp/health`** — the rest of the REST API is untouched |
| Revocation | Single click in admin; immediately denies further requests |

### What anyone with the token can do

Important to internalize before sharing or exposing the token:

- Generate and publish WordPress pages on your site.
- Read existing pages (Oxa-rendered or not).
- Write PHP component templates under `themes/oxa/components/<name>/` — even with the lint and deny-list above, this is **a code-write surface**. The deny-list catches the obvious, but a determined attacker with the token can write a component that exfiltrates rendered page contents to a third party via a `<img src="https://attacker/?...">` injected into HTML.
- Overwrite the theme's brand tokens.

What they **cannot** do via the connector:
- Write outside `themes/oxa/components/<name>/` or `themes/oxa/brand.json`.
- Execute shell commands, eval code, or call out via curl/wp_remote_* from a template (rejected at write time).
- Delete or modify other plugins or themes.
- Touch the database directly.

Rotate the token immediately if it's ever been logged, screenshared, copy-pasted into chat, or shared.

---

## Troubleshooting

| Symptom | What to check |
|---|---|
| claude.ai shows "Connection failed" | Open the **health URL** (`/wp-json/oxa/v1/mcp/health`) in a browser. If you see JSON with `"ok": true`, the server is reachable. If you get HTML, your URL is wrong. |
| 401 from the server | Token mismatch — regenerate one in admin and re-paste into claude.ai. Verify your header value starts with `Bearer ` (with the space). |
| 503 "MCP token is not configured" | You haven't clicked **Generate token** yet, or the token was revoked. |
| Tools never appear in the chat | Make sure the connector is **enabled** in the claude.ai chat (the toggle in the connectors menu). |
| "Validation failed" error from a tool | Claude tried to set a prop the schema doesn't allow. The error message tells you exactly which field — usually Claude will re-try on its own. |
| Pages created but missing styles | The Oxa **theme** must be the active theme. The plugin reads schemas from the active theme's `/components/` directory. |
| Slow first request | First call lazy-loads parsed component schemas and providers. Subsequent calls are fast. |

For deeper introspection, use **Oxa AI → Logs** — every MCP method invocation is logged with the method name, code, and (scrubbed) context.

---

## Calling the server manually

Useful for debugging. The MCP server is a plain JSON-RPC 2.0 HTTP endpoint, so any HTTP client works.

```bash
# Health
curl -s "https://example.com/wp-json/oxa/v1/mcp/health"

# Tool catalog
curl -sS "https://example.com/wp-json/oxa/v1/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq .

# Call a tool
curl -sS "https://example.com/wp-json/oxa/v1/mcp" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0","id":2,"method":"tools/call",
    "params":{
      "name":"compose_page",
      "arguments":{
        "page_title":"Demo",
        "layout":[{"component":"hero","props":{"title":"Hi","subtitle":"This is a demo."}}]
      }
    }
  }' | jq .
```

---

## Limitations

- **One token at a time.** A future version will support multiple named tokens with per-token scopes; for now, regenerate when you want to "rotate".
- **No OAuth yet.** Bearer-only. OAuth 2.1 with PKCE is on the [roadmap](../ROADMAP.md).
- **No SSE.** All tools complete quickly, so we return single JSON responses. SSE (for streaming long generations) is planned.
- **No per-tool ACL.** Anyone with the token can call any tool, including mutating ones.
