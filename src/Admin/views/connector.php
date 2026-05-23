<?php
/**
 * Connector page: generate / revoke MCP bearer token,
 * show the server URL, and give claude.ai setup instructions.
 *
 * @var array|null $info       Stored token metadata (or null if none)
 * @var string     $newToken   Freshly-generated plain token (shown once)
 * @var string     $mcpUrl     Full URL to /wp-json/oxa/v1/mcp
 * @var string     $healthUrl  Full URL to /wp-json/oxa/v1/mcp/health
 * @var string[]   $toolNames  Names of tools advertised by the server
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap oxa-admin">
  <h1>Oxa AI — Connector</h1>
  <p>Expose this WordPress site as a custom Connector at <code>claude.ai</code> over MCP.</p>

  <?php settings_errors('oxa_mcp'); ?>

  <h2>1 · Server URL</h2>
  <div class="oxa-card-form">
    <p>This is the URL claude.ai will connect to:</p>
    <code class="oxa-codebox"><?php echo esc_html($mcpUrl); ?></code>
    <p class="description">
      Health check:
      <a href="<?php echo esc_url($healthUrl); ?>" target="_blank" rel="noreferrer noopener"><?php echo esc_html($healthUrl); ?></a>
      (open this in a new tab — you should see a small JSON object with <code>"ok": true</code>).
    </p>
  </div>

  <h2>2 · Bearer token</h2>
  <div class="oxa-card-form">
    <?php if ($newToken !== '') : ?>
      <div class="notice notice-warning inline" style="margin: 0 0 1em 0;">
        <p><strong>Copy this token now.</strong> For security it is only displayed once — refresh this page and it's gone.</p>
      </div>
      <code class="oxa-codebox oxa-codebox--big" id="oxa-new-token"><?php echo esc_html($newToken); ?></code>
      <p>
        <button class="button" type="button" onclick="navigator.clipboard.writeText(document.getElementById('oxa-new-token').textContent); this.textContent='Copied'">Copy token</button>
      </p>
    <?php endif; ?>

    <?php if ($info !== null) : ?>
      <p>
        <strong>Status:</strong> <span style="color:#46b450">●</span> Token configured
        &nbsp;·&nbsp; Fingerprint: <code><?php echo esc_html($info['fingerprint']); ?></code>
        &nbsp;·&nbsp; Created: <?php echo esc_html(gmdate('Y-m-d H:i', $info['created_at'])); ?> UTC
      </p>
    <?php else : ?>
      <p><strong>Status:</strong> <span style="color:#c00">●</span> No token configured. Generate one to enable the connector.</p>
    <?php endif; ?>

    <form method="post" style="display:inline">
      <?php wp_nonce_field('oxa_mcp_action'); ?>
      <input type="hidden" name="oxa_mcp_action" value="rotate" />
      <button class="button button-primary" type="submit">
        <?php echo $info ? 'Generate new token (revokes the current one)' : 'Generate token'; ?>
      </button>
    </form>
    <?php if ($info !== null) : ?>
      <form method="post" style="display:inline; margin-left: 0.5rem;" onsubmit="return confirm('Revoke the MCP token? claude.ai will lose access until you generate a new one.');">
        <?php wp_nonce_field('oxa_mcp_action'); ?>
        <input type="hidden" name="oxa_mcp_action" value="revoke" />
        <button class="button" type="submit">Revoke</button>
      </form>
    <?php endif; ?>
  </div>

  <h2>3 · Add to claude.ai</h2>
  <div class="oxa-card-form">
    <p>The MCP server accepts the token in three places. Pick the one your client supports — they are equivalent for authorization but not equivalent for security.</p>

    <h3 style="margin-top:0">Option A — <code>Authorization</code> header <span class="oxa-badge oxa-badge--good">recommended</span></h3>
    <ul>
      <li><strong>Server URL:</strong> <code class="oxa-codebox"><?php echo esc_html($mcpUrl); ?></code></li>
      <li><strong>Header name:</strong> <code>Authorization</code></li>
      <li><strong>Header value:</strong> <code>Bearer &lt;your-token&gt;</code></li>
    </ul>

    <h3>Option B — <code>X-Oxa-Token</code> header <span class="oxa-badge">fallback</span></h3>
    <p>Use when the client UI blocks <code>Authorization</code>.</p>
    <ul>
      <li><strong>Server URL:</strong> <code class="oxa-codebox"><?php echo esc_html($mcpUrl); ?></code></li>
      <li><strong>Header name:</strong> <code>X-Oxa-Token</code></li>
      <li><strong>Header value:</strong> <code>&lt;your-token&gt;</code> (no "Bearer " prefix)</li>
    </ul>

    <h3>Option C — token in the URL <span class="oxa-badge oxa-badge--warn">last resort</span></h3>
    <p>Use only when the client supports no custom headers at all. The token will appear in your web server's access logs, in any reverse-proxy logs, and possibly in browser history. <strong>Rotate immediately if a log file is ever shared.</strong></p>
    <ul>
      <li><strong>Server URL:</strong> <code class="oxa-codebox"><?php echo esc_html($mcpUrl . (str_contains($mcpUrl, '?') ? '&' : '?') . 'token=YOUR_TOKEN_HERE'); ?></code></li>
      <li><strong>Headers:</strong> none required</li>
    </ul>

    <p style="margin-top:1.25em">Steps:</p>
    <ol class="oxa-steps">
      <li>Open <strong><a href="https://claude.ai/settings/connectors" target="_blank" rel="noreferrer noopener">claude.ai → Settings → Connectors</a></strong>.</li>
      <li>Click <strong>Add custom connector</strong>.</li>
      <li>Paste the Server URL and configure auth using one of the three options above.</li>
      <li>Save. claude.ai will probe the server and you should see the tools below appear in your chat tool list.</li>
    </ol>
  </div>

  <h2>4 · Tools exposed</h2>
  <div class="oxa-card-form">
    <p>The connector advertises <?php echo count($toolNames); ?> tools:</p>
    <ul class="oxa-tools-list">
      <?php foreach ($toolNames as $name) : ?>
        <li><code><?php echo esc_html($name); ?></code></li>
      <?php endforeach; ?>
    </ul>
    <p class="description">Use the <a href="<?php echo esc_url(admin_url('admin.php?page=oxa-ai-components')); ?>">Component Schemas</a> page to see the catalog Claude will work against.</p>
  </div>

  <h2>5 · Try it from your terminal</h2>
  <div class="oxa-card-form">
    <p>Once the token is generated, you can verify the server with curl. Replace <code>$TOKEN</code> with your token:</p>
    <pre class="oxa-output" style="max-height:none">curl -sS <?php echo esc_html($mcpUrl); ?> \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | jq .</pre>
  </div>
</div>

<style>
  .oxa-codebox { display:block; padding:.6rem .8rem; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-all; }
  .oxa-codebox--big { font-size:13px; padding:.8rem 1rem; }
  .oxa-steps { line-height: 1.7; padding-left: 1.2rem; }
  .oxa-tools-list { columns: 2; max-width: 520px; }
  .oxa-tools-list li { break-inside: avoid; padding: 0.15rem 0; }
  .oxa-badge { display:inline-block; font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; padding: 2px 8px; border-radius: 9999px; vertical-align: middle; background: #e6e6ea; color: #50575e; margin-left: .25rem; }
  .oxa-badge--good { background: #d7f3df; color: #1e6f3f; }
  .oxa-badge--warn { background: #fdecc8; color: #875300; }
</style>
