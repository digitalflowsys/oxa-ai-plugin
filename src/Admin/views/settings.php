<?php
/**
 * Settings page view.
 *
 * @var \OxaAi\Core\Settings           $settings
 * @var \OxaAi\Providers\ProviderRegistry $providers
 * @var array                          $config
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap oxa-admin">
  <h1>Oxa AI — Settings</h1>
  <?php settings_errors('oxa_ai'); ?>

  <form method="post" class="oxa-card-form">
    <?php wp_nonce_field('oxa_ai_settings'); ?>

    <h2>Provider</h2>
    <table class="form-table">
      <tr>
        <th><label for="oxa-provider">Active provider</label></th>
        <td>
          <select id="oxa-provider" name="provider">
            <?php foreach ($providers->all() as $p) : ?>
              <option value="<?php echo esc_attr($p->slug()); ?>" <?php selected($config['provider'], $p->slug()); ?>>
                <?php echo esc_html($p->label()); ?>
                <?php if (!$p->isConfigured() && $p->slug() !== 'mock') : ?> — (no key set)<?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <th><label for="oxa-model">Model</label></th>
        <td>
          <input id="oxa-model" type="text" name="model" value="<?php echo esc_attr($config['model']); ?>" class="regular-text" placeholder="leave blank to use provider default" />
        </td>
      </tr>
      <tr>
        <th><label for="oxa-max-tokens">Max tokens</label></th>
        <td><input id="oxa-max-tokens" type="number" min="256" max="16384" step="64" name="max_tokens" value="<?php echo esc_attr((string) $config['max_tokens']); ?>" /></td>
      </tr>
      <tr>
        <th><label for="oxa-temperature">Temperature</label></th>
        <td><input id="oxa-temperature" type="number" min="0" max="2" step="0.05" name="temperature" value="<?php echo esc_attr((string) $config['temperature']); ?>" /></td>
      </tr>
    </table>

    <h2>API Keys</h2>
    <p class="description">Leave a field blank to keep the current value. Enter <code>-</code> (a single dash) to clear it.</p>
    <table class="form-table">
      <?php foreach ($providers->all() as $provider) :
        $slug = $provider->slug();
        if ($slug === 'mock') { continue; }
        $configured = $provider->isConfigured();
      ?>
        <tr>
          <th><label for="oxa-secret-<?php echo esc_attr($slug); ?>"><?php echo esc_html($provider->label()); ?> API key</label></th>
          <td>
            <input id="oxa-secret-<?php echo esc_attr($slug); ?>" type="password" autocomplete="off" name="secret_<?php echo esc_attr($slug); ?>" class="regular-text" placeholder="<?php echo $configured ? '••••••••' : 'sk-...'; ?>" />
            <p class="description">
              <?php echo $configured ? '<span style="color:#46b450">●</span> Currently set.' : '<span style="color:#c00">●</span> Not set.'; ?>
            </p>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <p class="submit">
      <button type="submit" name="oxa_ai_save" class="button button-primary">Save settings</button>
    </p>
  </form>
</div>
