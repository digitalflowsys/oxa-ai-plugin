# Component reference

Every component lives at `wp-content/themes/oxa/components/<name>/`.

Each component is **four files**:

```
hero/
├── schema.json    ← AI contract (this file is the source of truth)
├── template.php   ← Server-side renderer; receives sanitized $props
├── styles.css     ← Scoped styles; auto-enqueued on first use
└── README.md      ← Human + AI guidance
```

## Schema dialect

```jsonc
{
  "name": "hero",                  // must match folder name
  "title": "Hero",                 // shown in admin
  "description": "…",              // shown to AI in the system prompt
  "category": "marketing",         // free-form tag
  "fields": {
    "title": {
      "type": "string",            // string | text | url | boolean | enum | array
      "required": true,            // bool, defaults to false
      "default": "",               // any JSON value
      "description": "…",          // optional, surfaced to AI
      "options": ["a", "b"],       // enum only
      "min_items": 1,              // array only
      "max_items": 12,             // array only
      "item_schema": {             // array of objects only
        "label": { "type": "string", "required": true }
      }
    }
  }
}
```

### Type → sanitizer

| Type | Validator behavior | Output sanitization |
|---|---|---|
| `string` | scalar coerced to string | `sanitize_text_field()` |
| `text` | multiline allowed | `wp_kses_post()` |
| `url` | non-empty becomes empty if invalid | `esc_url_raw()` |
| `boolean` | accepts `true/false/0/1/"true"/"false"` | cast to bool |
| `enum` | must match an option exactly | string |
| `array` | min/max enforced, each item recursively validated | array |

Sanitization happens **before** the layout is composed and persisted. The renderer escapes again at render time for defense in depth.

## Bundled components

| Name | Description | Required fields |
|---|---|---|
| [`hero`](../../../themes/oxa/components/hero/README.md) | Above-the-fold introduction | `title` |
| [`features`](../../../themes/oxa/components/features/README.md) | Grid of 2-12 feature cards | `items[]` |
| [`testimonials`](../../../themes/oxa/components/testimonials/README.md) | Quote-card grid | `items[].quote`, `items[].author` |
| [`faq`](../../../themes/oxa/components/faq/README.md) | Disclosure-list FAQ | `items[].question`, `items[].answer` |
| [`cta`](../../../themes/oxa/components/cta/README.md) | Conversion call-to-action | `title`, `cta_text`, `cta_link` |
| [`pricing`](../../../themes/oxa/components/pricing/README.md) | Tiered pricing grid | `tiers[]` |
| [`footer`](../../../themes/oxa/components/footer/README.md) | Brand + link columns | — |

## Authoring a new component

```bash
mkdir wp-content/themes/oxa/components/newsletter
```

Create `schema.json`:

```json
{
  "name": "newsletter",
  "title": "Newsletter Signup",
  "description": "Email capture with optional incentive.",
  "fields": {
    "title":     { "type": "string", "required": true },
    "subtitle":  { "type": "text",   "default": "" },
    "placeholder":{"type": "string", "default": "you@example.com" },
    "button":    { "type": "string", "required": true }
  }
}
```

Create `template.php`:

```php
<?php
/** @var array $props */
declare(strict_types=1);
use function Oxa\Theme\Helpers\array_get;
if (!defined('ABSPATH')) { exit; }
?>
<section class="oxa-newsletter oxa-section">
  <div class="oxa-container oxa-content oxa-stack">
    <h2><?php echo esc_html((string) array_get($props, 'title', '')); ?></h2>
    <?php if ($s = array_get($props, 'subtitle')) : ?>
      <p><?php echo esc_html((string) $s); ?></p>
    <?php endif; ?>
    <form action="" method="post" class="oxa-newsletter__form">
      <input type="email" name="email" required placeholder="<?php echo esc_attr((string) array_get($props, 'placeholder', '')); ?>" />
      <button class="oxa-btn" type="submit"><?php echo esc_html((string) array_get($props, 'button', '')); ?></button>
    </form>
  </div>
</section>
```

Add `styles.css` and `README.md`. That's it — the loader will pick it up on the next request, and AI generation will start using it on the next prompt.
