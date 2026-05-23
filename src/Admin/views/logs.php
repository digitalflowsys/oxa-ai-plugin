<?php
/**
 * Recent activity log (scrubbed of secrets).
 *
 * @var array<int,array{ts:int,level:string,message:string,context:array}> $rows
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap oxa-admin">
  <h1>Oxa AI — Logs</h1>

  <?php if (empty($rows)) : ?>
    <p>No log entries yet. Generate a page to see activity here.</p>
  <?php else : ?>
    <table class="widefat striped oxa-logs">
      <thead>
        <tr><th>When</th><th>Level</th><th>Message</th><th>Context</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row) : ?>
          <tr class="oxa-log oxa-log--<?php echo esc_attr($row['level']); ?>">
            <td><?php echo esc_html(gmdate('Y-m-d H:i:s', (int) $row['ts'])); ?> UTC</td>
            <td><code><?php echo esc_html($row['level']); ?></code></td>
            <td><?php echo esc_html($row['message']); ?></td>
            <td><pre><?php echo esc_html((string) wp_json_encode($row['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
