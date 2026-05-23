<?php
/**
 * Component catalog (read-only).
 *
 * @var array<string,array> $catalog
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap oxa-admin">
  <h1>Oxa AI — Component Schemas</h1>
  <p>The active theme exposes the following components. AI generation is constrained to this catalog.</p>

  <?php if (empty($catalog)) : ?>
    <div class="notice notice-warning"><p>
      No components were discovered. Activate the <strong>Oxa</strong> theme (or a compatible theme that exposes a <code>components/</code> directory).
    </p></div>
  <?php else : ?>
    <div class="oxa-components">
      <?php foreach ($catalog as $name => $schema) : ?>
        <details class="oxa-component">
          <summary>
            <strong><?php echo esc_html($schema['title'] ?? $name); ?></strong>
            <code class="oxa-pill"><?php echo esc_html($name); ?></code>
            <?php if (!empty($schema['description'])) : ?>
              <span class="oxa-component__desc"><?php echo esc_html((string) $schema['description']); ?></span>
            <?php endif; ?>
          </summary>
          <pre><?php echo esc_html((string) wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
        </details>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
