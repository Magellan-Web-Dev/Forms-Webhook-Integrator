<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Updates;

if (!defined('ABSPATH')) exit;

/**
 * Connects this plugin to its GitHub releases for automatic update notifications.
 *
 * Hooks into WordPress's built-in update check pipeline so that any published
 * GitHub release triggers the standard "update available" banner in the Plugins
 * screen.  Also adds a "Check for updates" row action so admins can force a
 * fresh check without waiting for the next scheduled transient refresh.
 *
 * Release detection flow:
 *  1. WordPress fires pre_set_site_transient_update_plugins on a scheduled check.
 *  2. check_for_update() fetches (or returns a 12-hour cached copy of) the latest
 *     GitHub release via the REST API.
 *  3. If the remote version is newer than FWI_VERSION the plugin is injected into
 *     WordPress's $transient->response array, which triggers the standard update UI.
 *
 * Folder integrity:
 *  GitHub archives are extracted into a version-stamped folder by default
 *  (e.g. Forms-Webhook-Integrator-1.2.3/).  Two complementary filters ensure the
 *  plugin is always installed into the canonical forms-webhook-integrator/ folder
 *  so the plugin does not deactivate after updating.
 */
final class GitHubUpdater
{
    /** @var string GitHub owner/repo path. */
    private const REPO = 'Magellan-Web-Dev/Forms-Webhook-Integrator';

    /** @var string GitHub REST API endpoint for the latest release. */
    private const API_URL = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

    /** @var string Download URL template; %s is replaced with the URL-encoded tag name. */
    private const ZIP_URL = 'https://github.com/' . self::REPO . '/archive/refs/tags/%s.zip';

    /** @var string Site transient key used to cache the latest release data. */
    private const CACHE_KEY = 'fwi_github_release';

    /** @var int Cache lifetime in seconds (12 hours). */
    private const CACHE_TTL = 43200;

    /** @var string Slug used in the WordPress updates API and row actions. */
    private const PLUGIN_SLUG = 'forms-webhook-integrator';

    /** @var string Main plugin file name (relative to the plugin folder). */
    private const PLUGIN_FILE = 'forms-webhook-integrator.php';

    /** @var string The folder name this plugin must always occupy under wp-content/plugins/. */
    private const DESIRED_FOLDER = 'forms-webhook-integrator';

    /**
     * Registers all hooks needed for GitHub-based update checking.
     *
     * Called once from {@see Plugin::init()}.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check_for_update']);
        add_filter('plugins_api',                           [self::class, 'plugins_api'], 10, 3);
        add_filter('upgrader_package_options',              [self::class, 'force_destination_folder'], 10, 1);
        add_filter('upgrader_post_install',                 [self::class, 'normalize_folder_after_install'], 10, 3);

        if (is_admin()) {
            add_filter('plugin_action_links_' . self::plugin_basename(), [self::class, 'add_action_links']);
            add_action('admin_init',    [self::class, 'handle_check_for_updates']);
            add_action('admin_notices', [self::class, 'maybe_show_checked_notice']);
        }
    }

    /**
     * Injects an update entry into the WordPress update transient when a newer
     * GitHub release is available.
     *
     * @param false|object|array $transient The current value of the update_plugins transient.
     *
     * @return false|object|array
     */
    public static function check_for_update(mixed $transient): mixed
    {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $transient;
        }

        $current = defined('FWI_VERSION') ? FWI_VERSION : '0.0.0';

        if (version_compare($release['version'], $current, '>')) {
            $plugin_basename = self::plugin_basename();

            $transient->response[$plugin_basename] = (object) [
                'slug'        => self::PLUGIN_SLUG,
                'plugin'      => $plugin_basename,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['zip_url'],
            ];
        }

        return $transient;
    }

    /**
     * Supplies plugin metadata for the "View version X details" thickbox popup
     * that appears on the Plugins and Updates screens.
     *
     * @param false|object|array $result The result so far (passed through when not handling).
     * @param string             $action The type of information being requested.
     * @param mixed              $args   Additional arguments, including the requested slug.
     *
     * @return false|object|array
     */
    public static function plugins_api(mixed $result, string $action, mixed $args): mixed
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $release = self::get_latest_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => 'Forms Webhook Integrator',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $release['version'],
            'author'        => 'Chris Paschall',
            'homepage'      => $release['html_url'],
            'download_link' => $release['zip_url'],
            'sections'      => [
                'description' => 'Integrates Elementor and other form submissions via an action hook with a configurable webhook endpoint, with admin settings and analytics.',
            ],
            'banners' => [],
        ];
    }

    /**
     * Adds a "Check for updates" link to the plugin's row on the Plugins screen.
     *
     * @param array<int|string, string> $links Existing action links for this plugin row.
     *
     * @return array<int|string, string>
     */
    public static function add_action_links(array $links): array
    {
        $check_url = wp_nonce_url(
            add_query_arg('action', 'fwi_check_for_updates', self_admin_url('plugins.php')),
            'fwi_check_for_updates',
            'fwi_nonce'
        );

        $links[] = '<a href="' . esc_url($check_url) . '">Check for updates</a>';

        return $links;
    }

    /**
     * Handles the "Check for updates" admin action triggered from the Plugins screen.
     *
     * Clears the cached release data and the WordPress update_plugins transient so
     * the next page load performs a fresh check, then redirects back to the Plugins
     * screen with a query flag so maybe_show_checked_notice() can display a result.
     *
     * @return void
     */
    public static function handle_check_for_updates(): void
    {
        if (
            empty($_GET['action']) ||
            $_GET['action'] !== 'fwi_check_for_updates' ||
            empty($_GET['fwi_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['fwi_nonce'])), 'fwi_check_for_updates') ||
            !current_user_can('update_plugins')
        ) {
            return;
        }

        // Clear cached release so the API is hit fresh.
        delete_site_transient(self::CACHE_KEY);

        // Force WordPress to re-evaluate all plugin updates immediately.
        delete_site_transient('update_plugins');
        wp_update_plugins();

        wp_safe_redirect(self_admin_url('plugins.php?fwi_update_checked=1'));
        exit;
    }

    /**
     * Displays an admin notice after a manual "Check for updates" action completes.
     *
     * Shows whether a newer version is available or confirms the plugin is up to date.
     *
     * @return void
     */
    public static function maybe_show_checked_notice(): void
    {
        if (empty($_GET['fwi_update_checked']) || $_GET['fwi_update_checked'] !== '1') {
            return;
        }

        $release = self::get_latest_release();
        $current = defined('FWI_VERSION') ? FWI_VERSION : '0.0.0';

        if ($release && version_compare($release['version'], $current, '>')) {
            $msg = sprintf(
                'Forms Webhook Integrator: Version <strong>%s</strong> is available. <a href="%s">Go to Updates</a>.',
                esc_html($release['version']),
                esc_url(self_admin_url('update-core.php'))
            );
        } else {
            $msg = 'Forms Webhook Integrator: You are running the latest version.';
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post($msg) . '</p></div>';
    }

    /**
     * Fetches the latest release from GitHub, caching the result for 12 hours to
     * avoid hitting the API rate limit on every page load.
     *
     * @return array{tag: string, version: string, zip_url: string, html_url: string}|null
     *         Null when the API request fails or the response is malformed.
     */
    private static function get_latest_release(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/Forms-Webhook-Integrator',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        $tag     = (string) $data['tag_name'];
        $version = ltrim($tag, 'v');

        $release = [
            'tag'      => $tag,
            'version'  => $version,
            'zip_url'  => sprintf(self::ZIP_URL, rawurlencode($tag)),
            'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : '',
        ];

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);

        return $release;
    }

    /**
     * Returns the plugin basename WordPress uses for update matching.
     *
     * Example: forms-webhook-integrator/forms-webhook-integrator.php
     *
     * @return string
     */
    private static function plugin_basename(): string
    {
        return self::DESIRED_FOLDER . '/' . self::PLUGIN_FILE;
    }

    /**
     * Forces WordPress to extract the plugin archive into the canonical folder name
     * rather than the GitHub-generated version-stamped folder.
     *
     * This is the primary mechanism for keeping the install directory correct.
     *
     * @param array<string, mixed> $options Upgrader package options.
     *
     * @return array<string, mixed>
     */
    public static function force_destination_folder(array $options): array
    {
        if (empty($options['hook_extra']['type'])   || $options['hook_extra']['type']   !== 'plugin') return $options;
        if (empty($options['hook_extra']['action']) || $options['hook_extra']['action'] !== 'update') return $options;
        if (empty($options['hook_extra']['plugin']) || $options['hook_extra']['plugin'] !== self::plugin_basename()) return $options;

        $options['destination']      = WP_PLUGIN_DIR;
        $options['destination_name'] = self::DESIRED_FOLDER;
        $options['clear_destination'] = true;

        return $options;
    }

    /**
     * Safety net: if WordPress still installs into a version-stamped folder after
     * an update, moves it to the canonical folder and fixes the active-plugins list.
     *
     * @param mixed                $response   The upgrader response passed through.
     * @param mixed                $hook_extra Extra hook data (type, action, plugin basename).
     * @param array<string, mixed> $result     Result data including the destination path.
     *
     * @return mixed
     */
    public static function normalize_folder_after_install(mixed $response, mixed $hook_extra, mixed $result): mixed
    {
        if (empty($hook_extra['type'])   || $hook_extra['type']   !== 'plugin') return $response;
        if (empty($hook_extra['action']) || $hook_extra['action'] !== 'update') return $response;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== self::plugin_basename()) return $response;
        if (empty($result['destination'])) return $response;

        $desired_dir   = trailingslashit(WP_PLUGIN_DIR) . self::DESIRED_FOLDER;
        $installed_dir = rtrim((string) $result['destination'], '/\\');

        if (wp_normalize_path($installed_dir) === wp_normalize_path($desired_dir)) {
            return $response;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if (!$wp_filesystem) {
            return $response;
        }

        // Only move if the installed directory actually contains our plugin file.
        if (!$wp_filesystem->exists(trailingslashit($installed_dir) . self::PLUGIN_FILE)) {
            return $response;
        }

        if ($wp_filesystem->is_dir($desired_dir)) {
            $wp_filesystem->delete($desired_dir, true);
        }

        $wp_filesystem->move($installed_dir, $desired_dir, true);

        // Keep the active-plugins list in sync.
        $old_basename = $hook_extra['plugin'];
        $new_basename = self::plugin_basename();

        $active_plugins = get_option('active_plugins', []);
        if (is_array($active_plugins)) {
            $index = array_search($old_basename, $active_plugins, true);
            if ($index !== false) {
                $active_plugins[$index] = $new_basename;
                update_option('active_plugins', array_values($active_plugins));
            }
        }

        if (is_multisite()) {
            $network_active = get_site_option('active_sitewide_plugins', []);
            if (is_array($network_active) && isset($network_active[$old_basename])) {
                $network_active[$new_basename] = $network_active[$old_basename];
                unset($network_active[$old_basename]);
                update_site_option('active_sitewide_plugins', $network_active);
            }
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }

        return $response;
    }

    /**
     * On-demand folder normalisation called from admin_init.
     *
     * Catches any case where a previous update left the plugin in a
     * version-stamped folder (e.g. after a failed move), without requiring a
     * new update to trigger the fix.
     *
     * @return void
     */
    public static function normalize_plugin_folder(): void
    {
        $desired_dir = WP_PLUGIN_DIR . '/' . self::DESIRED_FOLDER;

        if (is_dir($desired_dir) && file_exists($desired_dir . '/' . self::PLUGIN_FILE)) {
            return;
        }

        $candidates = glob(WP_PLUGIN_DIR . '/*/' . self::PLUGIN_FILE);
        if (empty($candidates)) {
            return;
        }

        $current_dir    = dirname($candidates[0]);
        $current_folder = basename($current_dir);

        if ($current_folder === self::DESIRED_FOLDER) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        global $wp_filesystem;

        if (!$wp_filesystem) {
            return;
        }

        if ($wp_filesystem->is_dir($desired_dir)) {
            $wp_filesystem->delete($desired_dir, true);
        }

        $wp_filesystem->move($current_dir, $desired_dir, true);

        $old_basename = $current_folder . '/' . self::PLUGIN_FILE;
        $new_basename = self::DESIRED_FOLDER . '/' . self::PLUGIN_FILE;

        $active_plugins = get_option('active_plugins', []);
        if (is_array($active_plugins)) {
            $idx = array_search($old_basename, $active_plugins, true);
            if ($idx !== false) {
                $active_plugins[$idx] = $new_basename;
                update_option('active_plugins', array_values($active_plugins));
            }
        }

        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }
    }
}
