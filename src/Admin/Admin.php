<?php
/**
 * Admin UI mount point.
 *
 * Adds a top-level "Oxa AI" menu with three subpages:
 *   - Settings (provider + API keys)
 *   - Prompt Test (try the pipeline)
 *   - Schema Viewer (read-only component catalog)
 *   - Logs (recent activity, scrubbed)
 *
 * Pages are rendered server-side with minimal vanilla JS — no React
 * build pipeline is required to use the admin.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Admin;

use OxaAi\Core\Container;
use OxaAi\Core\Logger;
use OxaAi\Core\Settings;
use OxaAi\Mcp\Auth\BearerAuth;
use OxaAi\Mcp\ToolRegistry;
use OxaAi\Providers\ProviderRegistry;
use OxaAi\Schema\SchemaRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    public const SLUG       = 'oxa-ai';
    public const CAPABILITY = 'manage_options';

    public function __construct(private readonly Container $container) {}

    public function register(): void
    {
        add_action('admin_menu',           [$this, 'menu']);
        add_action('admin_init',           [$this, 'handleSave']);
        add_action('admin_init',           [$this, 'handleConnectorAction']);
        add_action('admin_enqueue_scripts',[$this, 'assets']);
    }

    public function menu(): void
    {
        add_menu_page(
            'Oxa AI',
            'Oxa AI',
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderSettings'],
            'dashicons-superhero',
            58
        );

        add_submenu_page(self::SLUG, 'Settings',         'Settings',       self::CAPABILITY, self::SLUG,                       [$this, 'renderSettings']);
        add_submenu_page(self::SLUG, 'Connector',        'Connector',      self::CAPABILITY, self::SLUG . '-connector',        [$this, 'renderConnector']);
        add_submenu_page(self::SLUG, 'Prompt Test',      'Prompt Test',    self::CAPABILITY, self::SLUG . '-prompt',           [$this, 'renderPrompt']);
        add_submenu_page(self::SLUG, 'Component Schemas','Components',     self::CAPABILITY, self::SLUG . '-components',       [$this, 'renderComponents']);
        add_submenu_page(self::SLUG, 'Logs',             'Logs',           self::CAPABILITY, self::SLUG . '-logs',             [$this, 'renderLogs']);
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, self::SLUG)) {
            return;
        }
        wp_enqueue_style(
            'oxa-ai-admin',
            OXA_AI_URL . 'assets/admin/admin.css',
            [],
            OXA_AI_VERSION
        );
        wp_enqueue_script(
            'oxa-ai-admin',
            OXA_AI_URL . 'assets/admin/admin.js',
            [],
            OXA_AI_VERSION,
            true
        );
        wp_add_inline_script('oxa-ai-admin',
            'window.OxaAdmin = ' . wp_json_encode([
                'restRoot' => esc_url_raw(rest_url('oxa/v1/')),
                'nonce'    => wp_create_nonce('wp_rest'),
            ]) . ';',
            'before'
        );
    }

    public function handleSave(): void
    {
        if (!isset($_POST['oxa_ai_save'])) {
            return;
        }
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        check_admin_referer('oxa_ai_settings');

        /** @var Settings $settings */
        $settings = $this->container->get(Settings::class);

        $settings->saveConfig([
            'provider'    => sanitize_key((string) ($_POST['provider'] ?? 'mock')),
            'model'       => sanitize_text_field(wp_unslash((string) ($_POST['model'] ?? ''))),
            'max_tokens'  => (int) ($_POST['max_tokens'] ?? 4096),
            'temperature' => (float) ($_POST['temperature'] ?? 0.4),
        ]);

        /** @var ProviderRegistry $providers */
        $providers = $this->container->get(ProviderRegistry::class);
        foreach ($providers->all() as $provider) {
            $field = 'secret_' . $provider->slug();
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $value = trim((string) wp_unslash($_POST[$field]));
            // Empty string means "leave unchanged"; only update when value
            // is the literal "-" sentinel (clear) or non-empty.
            if ($value === '-') {
                $settings->saveSecret($provider->slug(), '');
            } elseif ($value !== '') {
                $settings->saveSecret($provider->slug(), $value);
            }
        }

        add_settings_error('oxa_ai', 'oxa_ai_saved', 'Settings saved.', 'updated');
    }

    /**
     * Handle connector actions (rotate / revoke MCP token).
     *
     * Uses a transient to surface the freshly-generated token to the
     * view *exactly once* and never re-display it.
     */
    public function handleConnectorAction(): void
    {
        if (!isset($_POST['oxa_mcp_action']) || !current_user_can(self::CAPABILITY)) {
            return;
        }
        check_admin_referer('oxa_mcp_action');

        /** @var BearerAuth $auth */
        $auth   = $this->container->get(BearerAuth::class);
        $action = (string) ($_POST['oxa_mcp_action'] ?? '');

        if ($action === 'rotate') {
            $token = $auth->rotate();
            set_transient('oxa_mcp_token_new_' . get_current_user_id(), $token, 30);
            add_settings_error('oxa_mcp', 'oxa_mcp_rotated', 'New MCP token generated. Copy it now — it will not be shown again.', 'updated');
        } elseif ($action === 'revoke') {
            $auth->revoke();
            add_settings_error('oxa_mcp', 'oxa_mcp_revoked', 'MCP token revoked. The connector at claude.ai will stop working until you generate a new one.', 'updated');
        }
    }

    public function renderSettings(): void
    {
        /** @var Settings $settings */
        $settings  = $this->container->get(Settings::class);
        /** @var ProviderRegistry $providers */
        $providers = $this->container->get(ProviderRegistry::class);

        $config = $settings->config();

        include OXA_AI_DIR . 'src/Admin/views/settings.php';
    }

    public function renderConnector(): void
    {
        /** @var BearerAuth $auth */
        $auth = $this->container->get(BearerAuth::class);
        /** @var ToolRegistry $tools */
        $tools = $this->container->get(ToolRegistry::class);

        $info       = $auth->info();
        $newToken   = get_transient('oxa_mcp_token_new_' . get_current_user_id()) ?: '';
        if ($newToken !== '') {
            // One-shot: clear immediately after read.
            delete_transient('oxa_mcp_token_new_' . get_current_user_id());
        }
        $mcpUrl     = esc_url_raw(rest_url('oxa/v1/mcp'));
        $healthUrl  = esc_url_raw(rest_url('oxa/v1/mcp/health'));
        $toolNames  = array_map(static fn(array $d): string => $d['name'], $tools->descriptors());

        include OXA_AI_DIR . 'src/Admin/views/connector.php';
    }

    public function renderPrompt(): void
    {
        /** @var ProviderRegistry $providers */
        $providers = $this->container->get(ProviderRegistry::class);
        include OXA_AI_DIR . 'src/Admin/views/prompt.php';
    }

    public function renderComponents(): void
    {
        /** @var SchemaRegistry $schemas */
        $schemas = $this->container->get(SchemaRegistry::class);
        $catalog = $schemas->all();
        include OXA_AI_DIR . 'src/Admin/views/components.php';
    }

    public function renderLogs(): void
    {
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);
        $rows   = $logger->recent(100);
        include OXA_AI_DIR . 'src/Admin/views/logs.php';
    }
}
