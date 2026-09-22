<?php
/**
 * Plugin install / activate / deactivate / uninstall, plus listing.
 *
 * Mirrors the bridge tool surface but uses WP core APIs directly so
 * no WP-CLI dependency is introduced for the basic operations.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use OxaAi\Core\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class PluginService
{
    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $active = (array) get_option('active_plugins', []);

        $out = [];
        foreach ($all as $file => $data) {
            $out[] = [
                'file'        => $file,
                'slug'        => dirname($file) !== '.' ? dirname($file) : basename($file, '.php'),
                'name'        => $data['Name']        ?? '',
                'version'     => $data['Version']     ?? '',
                'description' => wp_strip_all_tags((string) ($data['Description'] ?? '')),
                'author'      => wp_strip_all_tags((string) ($data['Author'] ?? '')),
                'active'      => in_array($file, $active, true),
            ];
        }
        return $out;
    }

    public function activate(string $slug, bool $networkWide = false): array
    {
        $this->loadAdminIncludes();
        $file = $this->resolveFile($slug);
        $result = activate_plugin($file, '', $networkWide);
        if (is_wp_error($result)) {
            throw new RuntimeException('Activate failed: ' . $result->get_error_message());
        }
        $this->logger->info('plugin activated', ['file' => $file]);
        return ['slug' => $slug, 'file' => $file, 'active' => true];
    }

    public function deactivate(string $slug, bool $networkWide = false): array
    {
        $this->loadAdminIncludes();
        $file = $this->resolveFile($slug);
        deactivate_plugins([$file], false, $networkWide);
        $this->logger->info('plugin deactivated', ['file' => $file]);
        return ['slug' => $slug, 'file' => $file, 'active' => false];
    }

    /**
     * Install from the WordPress.org plugin repository by slug.
     */
    public function installFromRepo(string $slug, bool $activate = true): array
    {
        $this->loadAdminIncludes();
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['short_description' => false, 'sections' => false]]);
        if (is_wp_error($api)) {
            throw new RuntimeException('Plugin lookup failed: ' . $api->get_error_message());
        }
        $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
        $install  = $upgrader->install($api->download_link);
        if (is_wp_error($install)) {
            throw new RuntimeException('Install failed: ' . $install->get_error_message());
        }
        if ($install !== true) {
            throw new RuntimeException('Install returned an unexpected result.');
        }

        $file = $upgrader->plugin_info();
        if (!is_string($file) || $file === '') {
            $file = $this->resolveFile($slug);
        }
        if ($activate) {
            $this->activate($slug);
        }
        $this->logger->info('plugin installed from repo', ['slug' => $slug, 'file' => $file]);
        return ['slug' => $slug, 'file' => $file, 'active' => $activate, 'source' => 'wp.org'];
    }

    public function installFromUrl(string $url, ?string $slug = null, bool $activate = true, bool $overwrite = true): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid URL.');
        }
        $this->loadAdminIncludes();
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // When a target slug is supplied, force the archive's extracted root
        // directory to that slug. GitHub zipballs unpack to a volatile
        // "<owner>-<repo>-<sha>/" folder; without this the plugin lands in a
        // different directory on every install (stale copies pile up, and
        // overwrite_package can never match), and slug-based activation /
        // tracking downstream would never find it.
        $renamer = null;
        if ($slug !== null) {
            $renamer = function ($source, $remoteSource) use ($slug) {
                return $this->renameSourceToSlug((string) $source, (string) $remoteSource, $slug);
            };
            add_filter('upgrader_source_selection', $renamer, 10, 2);
        }

        try {
            $upgrader = new \Plugin_Upgrader(new \WP_Ajax_Upgrader_Skin());
            $install  = $upgrader->install($url, ['overwrite_package' => $overwrite]);
        } finally {
            if ($renamer !== null) {
                remove_filter('upgrader_source_selection', $renamer, 10);
            }
        }

        if (is_wp_error($install)) {
            throw new RuntimeException('Install failed: ' . $install->get_error_message());
        }
        $file = $upgrader->plugin_info();
        if (!is_string($file) || $file === '') {
            if ($slug === null) {
                throw new RuntimeException('Could not determine plugin file from archive; pass "slug" to disambiguate.');
            }
            $file = $this->resolveFile($slug);
        }

        // Activate the exact file we just installed. Never re-resolve from the
        // caller-supplied slug: the on-disk folder may differ from it (e.g. a
        // GitHub archive root we could not rename), and a slug lookup would
        // then fail with a misleading "not installed".
        if ($activate) {
            $this->activateFile($file);
        }

        $resolvedSlug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
        $this->logger->info('plugin installed from url', ['url' => $url, 'file' => $file]);
        return ['slug' => $resolvedSlug, 'file' => $file, 'active' => $activate, 'source' => $url];
    }

    public function uninstall(string $slug, bool $deactivateIfActive = true): array
    {
        $this->loadAdminIncludes();
        $file = $this->resolveFile($slug);
        if (is_plugin_active($file)) {
            if (!$deactivateIfActive) {
                throw new RuntimeException(sprintf('Plugin "%s" is active; pass deactivate=true to uninstall.', $slug));
            }
            deactivate_plugins([$file]);
        }
        $result = delete_plugins([$file]);
        if (is_wp_error($result)) {
            throw new RuntimeException('Uninstall failed: ' . $result->get_error_message());
        }
        if ($result === false) {
            throw new RuntimeException('Uninstall returned false.');
        }
        $this->logger->info('plugin uninstalled', ['file' => $file]);
        return ['slug' => $slug, 'file' => $file, 'deleted' => true];
    }

    /**
     * Activate a plugin by its real, installed file path (e.g.
     * "my-plugin/my-plugin.php") rather than a slug guess.
     */
    private function activateFile(string $file): void
    {
        $this->loadAdminIncludes();
        $result = activate_plugin($file, '', false);
        if (is_wp_error($result)) {
            throw new RuntimeException('Activate failed: ' . $result->get_error_message());
        }
        $this->logger->info('plugin activated', ['file' => $file]);
    }

    /**
     * `upgrader_source_selection` callback: rename the unpacked archive's
     * root directory to $slug so the plugin installs at a stable, predictable
     * path. Falls back to the original source untouched if the move fails, so
     * a rename problem never aborts an otherwise-valid install.
     */
    private function renameSourceToSlug(string $source, string $remoteSource, string $slug): string
    {
        global $wp_filesystem;

        $desired = trailingslashit($remoteSource) . $slug;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source; // already correctly named
        }
        if ($wp_filesystem instanceof \WP_Filesystem_Base
            && $wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return trailingslashit($desired);
        }
        return $source;
    }

    private function loadAdminIncludes(): void
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // Initialise WP_Filesystem if not already.
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            WP_Filesystem();
        }
    }

    private function resolveFile(string $slug): string
    {
        $this->loadAdminIncludes();
        $all = get_plugins();
        foreach ($all as $file => $data) {
            if (str_starts_with($file, $slug . '/')) {
                return $file;
            }
            if ($file === $slug . '.php') {
                return $file;
            }
        }
        throw new RuntimeException(sprintf('Plugin "%s" is not installed.', $slug));
    }
}
