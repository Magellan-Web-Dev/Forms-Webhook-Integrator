<?php
/**
 * Plugin Name: Forms Webhook Integrator
 * Description: Integrates Elementor and other form submissions via an action hook with a configurable webhook endpoint, with admin settings and analytics.
 * Version:     1.2.0
 * Requires PHP: 8.1
 * Author:      Chris Paschall
 * License:     GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * Must use PHP 5.x-compatible syntax because this code runs before the version
 * is confirmed. If the requirement is not met, an admin notice is registered and
 * the rest of the file is skipped via return so none of the PHP 8.1 code below
 * is parsed or executed.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Forms Webhook Integrator</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin class and bootstrap logic.
 * Contains the Plugin class which serves as the composition root for the entire plugin,
 * and the activation/deactivation hooks and plugins_loaded handler that instantiate and initialize the plugin.
 */
} else {

    define('FWI_VERSION', '1.2.0');
    define('FWI_PLUGIN_FILE', __FILE__);
    define('FWI_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('FWI_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once FWI_PLUGIN_DIR . 'src/Autoloader.php';

    FormsWebhookIntegrator\Autoloader::register();

    /**
     * Plugin activation: create the custom log table and schedule the daily cleanup cron.
     *
     * register_activation_hook must be called in the main plugin file (not inside a
     * class method) to fire reliably. The DatabaseManager handles idempotent table
     * creation via dbDelta, so re-activating the plugin is safe.
     */
    register_activation_hook(__FILE__, static function (): void {
        FormsWebhookIntegrator\Database\DatabaseManager::createTable();

        if (!wp_next_scheduled('FWI_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'FWI_cleanup_old_logs');
        }
    });

    /**
     * Plugin deactivation: unschedule the daily cleanup cron event.
     *
     * The table and its data are intentionally preserved on deactivation so that
     * logs survive a deactivate/reactivate cycle. Data is only removed when the
     * admin manually clears logs or when rows age past three months.
     */
    register_deactivation_hook(__FILE__, static function (): void {
        wp_clear_scheduled_hook('FWI_cleanup_old_logs');
    });

    add_action('plugins_loaded', static function (): void {
        FormsWebhookIntegrator\Plugin::getInstance()->init();
    });
}