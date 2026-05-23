# Example payloads

Reference structures you can submit to `POST /generate` or write directly into a post as Gutenberg block markup. Real AI output should look like these.

## A complete SaaS landing page

```json
{
  "page_title": "Build faster with Oxa",
  "layout": [
    {
      "component": "hero",
      "props": {
        "eyebrow": "Open source",
        "title": "Build faster with AI-native WordPress",
        "subtitle": "Oxa turns natural-language briefs into validated, schema-driven WordPress pages.",
        "primary_cta_text": "Start free",
        "primary_cta_link": "/start",
        "secondary_cta_text": "Read the docs",
        "secondary_cta_link": "/docs",
        "align": "center"
      }
    },
    {
      "component": "features",
      "props": {
        "heading": "Why teams choose Oxa",
        "columns": "3",
        "items": [
          { "icon": "⚡", "title": "Fast",         "description": "Ship landing pages in seconds, not days." },
          { "icon": "🧩", "title": "Composable",   "description": "Every section is a typed, validated component." },
          { "icon": "🛡", "title": "Safe by design","description": "AI never writes raw HTML — only validated JSON." }
        ]
      }
    },
    {
      "component": "pricing",
      "props": {
        "heading": "Simple pricing",
        "tiers": [
          {
            "name": "Free",
            "price": "0",
            "tagline": "Everything to get started.",
            "features": [
              { "label": "Unlimited prompts (mock provider)" },
              { "label": "Full component library" }
            ],
            "cta_text": "Get started",
            "cta_link": "/signup"
          },
          {
            "name": "Pro",
            "price": "29",
            "tagline": "For solo builders.",
            "features": [
              { "label": "OpenAI + Claude providers" },
              { "label": "Priority support" },
              { "label": "Page templates" }
            ],
            "cta_text": "Start Pro",
            "cta_link": "/signup?plan=pro",
            "highlight": true
          },
          {
            "name": "Team",
            "price": "Custom",
            "tagline": "For agencies & in-house teams.",
            "features": [
              { "label": "SSO & SAML" },
              { "label": "Multi-site management" },
              { "label": "Dedicated support" }
            ],
            "cta_text": "Talk to sales",
            "cta_link": "/contact"
          }
        ]
      }
    },
    {
      "component": "faq",
      "props": {
        "heading": "Frequently asked questions",
        "items": [
          { "question": "Does Oxa replace the block editor?", "answer": "No — Oxa generates blocks that the standard editor can edit." },
          { "question": "Can I add my own components?", "answer": "Yes. Drop a folder under /components/ with a schema.json and template.php." },
          { "question": "Which AI models are supported?", "answer": "OpenAI and Claude out of the box, plus any provider you implement against ProviderInterface." }
        ]
      }
    },
    {
      "component": "cta",
      "props": {
        "title": "Ready to build with AI?",
        "subtitle": "Install Oxa and ship your first AI-generated page in five minutes.",
        "cta_text": "Get started free",
        "cta_link": "/start",
        "variant": "solid"
      }
    }
  ]
}
```

## How that page looks in `post_content`

```html
<!-- wp:oxa/component {"component":"hero","props":{"title":"Build faster…","subtitle":"…"}} /-->

<!-- wp:oxa/component {"component":"features","props":{"heading":"…","items":[…]}} /-->

<!-- wp:oxa/component {"component":"pricing","props":{"heading":"…","tiers":[…]}} /-->
```

WordPress stores exactly this string. On render, the theme's `render_callback` decodes each block's attributes and delegates to `ComponentRenderer::render('hero', $props)` etc.
