<?php
/**
 * Snapshot of site identity, active stack, content counts.
 *
 * Backs the `site_status` tool. Read-only; safe to call anytime.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

if (!defined('ABSPATH')) {
    exit;
}

final class SiteStatus
{
    public function snapshot(): array
    {
        global $wp_version;

        $theme = wp_get_theme();
        $parent = $theme->parent();

        $activePlugins = (array) get_option('active_plugins', []);
        $allPlugins    = function_exists('get_plugins') ? get_plugins() : [];

        return [
            'site' => [
                'name'        => get_bloginfo('name'),
                'description' => get_bloginfo('description'),
                'url'         => home_url('/'),
                'admin_url'   => admin_url('/'),
                'language'    => get_bloginfo('language'),
                'timezone'    => wp_timezone_string(),
                'multisite'   => is_multisite(),
                'debug'       => defined('WP_DEBUG') && WP_DEBUG,
            ],
            'wordpress' => [
                'version' => $wp_version,
                'php'     => PHP_VERSION,
                'mysql'   => $this->mysqlVersion(),
            ],
            'theme' => [
                'name'        => $theme->get('Name'),
                'stylesheet'  => $theme->get_stylesheet(),
                'template'    => $theme->get_template(),
                'version'     => $theme->get('Version'),
                'parent'      => $parent ? $parent->get('Name') : null,
            ],
            'plugins' => [
                'active' => array_values(array_map(
                    static fn (string $f): array => [
                        'file' => $f,
                        'name' => $allPlugins[$f]['Name']    ?? $f,
                        'ver'  => $allPlugins[$f]['Version'] ?? '',
                    ],
                    $activePlugins,
                )),
                'count_active' => count($activePlugins),
                'count_total'  => count($allPlugins),
            ],
            'woocommerce' => [
                'active'  => in_array('woocommerce/woocommerce.php', $activePlugins, true) || class_exists('WooCommerce'),
                'version' => defined('WC_VERSION') ? WC_VERSION : null,
            ],
            'multilingual' => [
                'polylang'    => function_exists('pll_languages_list'),
                'wpml'        => defined('ICL_LANGUAGE_CODE'),
            ],
            'content' => [
                'posts'      => (int) wp_count_posts('post')->publish,
                'pages'      => (int) wp_count_posts('page')->publish,
                'products'   => post_type_exists('product') ? (int) wp_count_posts('product')->publish : 0,
                'comments'   => (int) wp_count_comments()->approved,
                'users'      => (int) count_users()['total_users'],
                'media'      => (int) wp_count_posts('attachment')->inherit,
            ],
        ];
    }

    private function mysqlVersion(): string
    {
        global $wpdb;
        if (!isset($wpdb)) return '';
        $v = $wpdb->get_var('SELECT VERSION()');
        return is_string($v) ? $v : '';
    }
}
