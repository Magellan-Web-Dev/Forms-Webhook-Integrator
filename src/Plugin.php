<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Admin\AdminMenu;
use FormsWebhookIntegrator\Api\AnalyticsApiHandler;
use FormsWebhookIntegrator\Database\DatabaseManager;
use FormsWebhookIntegrator\Forms\ElementorFormsBridge;
use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Updates\GitHubUpdater;
use FormsWebhookIntegrator\Webhook\WebhookHandler;
use FormsWebhookIntegrator\Webhook\WebhookLogger;

/**
 * Main plugin bootstrap class.
 *
 * Acts as the composition root for the entire plugin. Instantiated once via the
 * singleton pattern on the plugins_loaded hook, it wires together the settings
 * layer, the admin UI, the webhook handler, the database layer, and the daily
 * log-cleanup cron event, then delegates initialisation to each subsystem.
 */
final class Plugin
{
    /**
     * The single shared instance of this class.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Central store for all plugin options read from and written to the
     * WordPress options table.
     *
     * @var SettingsManager
     */
    private readonly SettingsManager $settingsManager;

    /**
     * Responsible for registering the admin menu pages and enqueueing assets.
     *
     * @var AdminMenu
     */
    private readonly AdminMenu $adminMenu;

    /**
     * Listens for fwi_submission actions and forwards submissions to the webhook.
     *
     * @var WebhookHandler
     */
    private readonly WebhookHandler $webhookHandler;

    /**
     * Bridges Elementor Pro form submissions to the fwi_submission action hook.
     *
     * @var ElementorFormsBridge
     */
    private readonly ElementorFormsBridge $elementorFormsBridge;

    /**
     * Registers and handles the read-only analytics REST API endpoint.
     *
     * @var AnalyticsApiHandler
     */
    private readonly AnalyticsApiHandler $analyticsApiHandler;

    /**
     * Constructs and wires the plugin's core dependencies.
     *
     * Private to enforce the singleton pattern; use {@see self::getInstance()}
     * to obtain the shared instance.
     */
    private function __construct()
    {
        $this->settingsManager      = new SettingsManager();
        $this->adminMenu            = new AdminMenu($this->settingsManager);
        $this->webhookHandler       = new WebhookHandler($this->settingsManager);
        $this->elementorFormsBridge = new ElementorFormsBridge($this->settingsManager, $this->webhookHandler);
        $this->analyticsApiHandler  = new AnalyticsApiHandler($this->settingsManager, new WebhookLogger());
    }

    /**
     * Returns the single shared instance of the plugin, creating it if necessary.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Returns the WebhookHandler instance for result-aware direct submissions.
     *
     * Used by {@see fwi_submit_form()} so callers outside the plugin can invoke
     * handleFormSubmission() and inspect its return value without going through
     * do_action(), which discards return values.
     *
     * @return WebhookHandler
     */
    public function getWebhookHandler(): WebhookHandler
    {
        return $this->webhookHandler;
    }

    /**
     * Bootstraps all plugin subsystems by delegating to their register methods.
     *
     * Execution order:
     *  1. Ensure the custom DB table exists (handles updates where the activation
     *     hook does not re-fire).
     *  2. Register the WP-Cron action handler for log cleanup, and reschedule
     *     the daily event if it was somehow cleared.
     *  3. Register admin menu pages and webhook hook.
     *
     * Called once on the plugins_loaded hook from the main plugin file.
     *
     * @return void
     */
    public function init(): void
    {
        // Register GitHub update checks and the "Check for updates" Plugins-screen action.
        GitHubUpdater::init();

        // Ensure the custom log table exists for updates that skip the activation hook.
        DatabaseManager::maybeCreateTable();

        // Register the cron callback and ensure the daily event is scheduled.
        add_action('FWI_cleanup_old_logs', [DatabaseManager::class, 'purgeOldLogs']);

        if (!wp_next_scheduled('FWI_cleanup_old_logs')) {
            wp_schedule_event(time(), 'daily', 'FWI_cleanup_old_logs');
        }

        $this->adminMenu->register();
        $this->webhookHandler->register();
        $this->elementorFormsBridge->register();
        $this->analyticsApiHandler->register();
    }
}
