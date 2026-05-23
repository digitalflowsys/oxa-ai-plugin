<?php
/**
 * Plugin Name:       Oxa AI
 * Plugin URI:        https://github.com/muhameti1/oxa-ai
 * Description:       AI-native orchestration layer for the Oxa theme. Generates and manages WordPress pages from natural-language prompts via Claude, OpenAI, or any provider you plug in.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            Oxa Contributors
 * Author URI:        https://github.com/muhameti1
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       oxa-ai
 * Domain Path:       /languages
 *
 * @package OxaAi
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('OXA_AI_VERSION', '0.1.0');
define('OXA_AI_FILE', __FILE__);
define('OXA_AI_DIR', plugin_dir_path(__FILE__));
define('OXA_AI_URL', plugin_dir_url(__FILE__));
define('OXA_AI_BLOCK_NAME', 'oxa/component');

require_once OXA_AI_DIR . 'src/Core/Autoloader.php';
\OxaAi\Core\Autoloader::register();

add_action('plugins_loaded', static function (): void {
    \OxaAi\Core\Plugin::boot();
});

register_activation_hook(__FILE__, static function (): void {
    \OxaAi\Core\Plugin::onActivate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \OxaAi\Core\Plugin::onDeactivate();
});
