<?php
/**
 * Prompt-test page view.
 *
 * Pure-vanilla JS calls the REST endpoint directly.
 *
 * @var \OxaAi\Providers\ProviderRegistry $providers
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap oxa-admin">
  <h1>Oxa AI — Prompt Test</h1>
  <p>Describe the page you want, optionally pick a provider, and inspect the generated structure or create it as a draft page.</p>

  <div class="oxa-prompt">
    <textarea id="oxa-prompt" rows="5" placeholder="Create a SaaS landing page for a developer-focused observability tool. Include a hero, three feature cards, pricing with three tiers, and a closing CTA."></textarea>

    <div class="oxa-prompt__row">
      <label>
        Provider override
        <select id="oxa-prompt-provider">
          <option value="">(use settings default)</option>
          <?php foreach ($providers->all() as $p) : ?>
            <option value="<?php echo esc_attr($p->slug()); ?>">
              <?php echo esc_html($p->label()); ?><?php echo $p->isConfigured() ? '' : ' — not configured'; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <button id="oxa-btn-generate" class="button">Generate (preview JSON)</button>
      <button id="oxa-btn-create"   class="button button-primary">Generate + create draft page</button>
    </div>

    <div id="oxa-prompt-status" class="oxa-status"></div>
    <pre id="oxa-prompt-output" class="oxa-output" aria-live="polite"></pre>
  </div>
</div>
