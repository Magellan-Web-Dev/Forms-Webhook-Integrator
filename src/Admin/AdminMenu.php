<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\WebhookTester;

/**
 * Registers the plugin's WordPress admin menu and wires up related hooks.
 *
 * Responsible for three concerns:
 *  - Adding the top-level menu page and its sub-pages via add_menu_page /
 *    add_submenu_page.
 *  - Delegating early (pre-output) form-submission handling to the individual
 *    page classes so that wp_safe_redirect() can be called cleanly.
 *  - Enqueueing the shared admin stylesheet and JavaScript only on plugin pages.
 */
final class AdminMenu
{
    /**
     * The settings page renderer and form processor.
     *
     * @var SettingsPage
     */
    private readonly SettingsPage $settingsPage;

    /**
     * The analytics page renderer and log-clear processor.
     *
     * @var AnalyticsPage
     */
    private readonly AnalyticsPage $analyticsPage;

    /**
     * Runs connectivity tests against the configured webhook endpoint.
     *
     * @var WebhookTester
     */
    private readonly WebhookTester $webhookTester;

    /**
     * Constructs the menu and instantiates the two admin page objects.
     *
     * @param SettingsManager $settingsManager Shared settings store injected into
     *                                         the settings page.
     */
    public function __construct(
        private readonly SettingsManager $settingsManager
    ) {
        $this->settingsPage  = new SettingsPage($settingsManager);
        $this->analyticsPage = new AnalyticsPage($settingsManager);
        $this->webhookTester = new WebhookTester($settingsManager);
    }

    /**
     * Attaches all WordPress action hooks needed by this class.
     *
     * Should be called once from {@see Plugin::init()}.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_init', [$this, 'handleFormSubmissions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_fwi_test_webhook',         [$this, 'handleTestWebhookAjax']);
        add_action('wp_ajax_fwi_delete_log',           [$this, 'handleDeleteLogAjax']);
        add_action('wp_ajax_fwi_get_logs',             [$this, 'handleGetLogsAjax']);
        add_action('wp_ajax_fwi_toggle_analytics_api', [$this, 'handleApiToggleAjax']);
        add_action('wp_ajax_fwi_regen_analytics_api_key', [$this, 'handleApiRegenKeyAjax']);
    }

    /**
     * Registers the top-level menu entry and its two sub-pages with WordPress.
     *
     * Hooked onto admin_menu.
     *
     * @return void
     */
    public function addMenuPages(): void
    {
        add_menu_page(
            page_title: 'Forms Webhook Integrator',
            menu_title: 'Webhook Integrator',
            capability: 'manage_options',
            menu_slug:  'FWI-settings',
            callback:   [$this->settingsPage, 'render'],
            icon_url:   'dashicons-rest-api',
            position:   80
        );

        add_submenu_page(
            parent_slug: 'FWI-settings',
            page_title:  'Settings',
            menu_title:  'Settings',
            capability:  'manage_options',
            menu_slug:   'FWI-settings',
            callback:    [$this->settingsPage, 'render']
        );

        add_submenu_page(
            parent_slug: 'FWI-settings',
            page_title:  'Analytics',
            menu_title:  'Analytics',
            capability:  'manage_options',
            menu_slug:   'FWI-analytics',
            callback:    [$this->analyticsPage, 'render']
        );
    }

    /**
     * Delegates early request handling to each page class before any output is sent.
     *
     * Runs on the admin_init hook, which fires early enough to allow both
     * wp_safe_redirect() and raw header() calls to succeed. Each page class
     * guards itself and exits early when its own nonce/action is not present.
     *
     * @return void
     */
    public function handleFormSubmissions(): void
    {
        $this->settingsPage->processSave();
        $this->analyticsPage->processClearLogs();
        $this->analyticsPage->processExport();
    }

    /**
     * AJAX handler for the "Test Webhook" button on the settings page.
     *
     * Accepts the webhook URL from the POST body (so it can test an unsaved
     * value currently typed in the form field), performs a test POST, persists
     * the result, and returns a JSON response.
     *
     * @return never
     */
    public function handleTestWebhookAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fwi_test_webhook') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized or invalid nonce.']);
        }

        $url    = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        $result = $this->webhookTester->test(!empty($url) ? $url : null);

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * AJAX handler for paginated log fetching on the analytics page.
     *
     * Delegates to AnalyticsPage::handleGetLogsAjax() which owns the query and
     * rendering logic.
     *
     * @return never
     */
    public function handleGetLogsAjax(): never
    {
        $this->analyticsPage->handleGetLogsAjax();
    }

    /**
     * AJAX handler that toggles the analytics API active state.
     *
     * @return never
     */
    public function handleApiToggleAjax(): never
    {
        $this->analyticsPage->handleApiToggleAjax();
    }

    /**
     * AJAX handler that regenerates the analytics API key.
     *
     * @return never
     */
    public function handleApiRegenKeyAjax(): never
    {
        $this->analyticsPage->handleApiRegenKeyAjax();
    }

    /**
     * AJAX handler for the per-entry delete button on the analytics page.
     *
     * Verifies the nonce and capability, then delegates to WebhookLogger::deleteLog().
     *
     * @return never
     */
    public function handleDeleteLogAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fwi_delete_log') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized or invalid nonce.']);
        }

        $id = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;

        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid log ID.']);
        }

        (new \FormsWebhookIntegrator\Webhook\WebhookLogger())->deleteLog($id);

        wp_send_json_success();
    }

    /**
     * Enqueues the plugin's admin stylesheet and script on plugin pages only.
     *
     * Uses a substring check on the hook suffix so assets are not loaded on
     * unrelated admin screens.
     *
     * @param string $hookSuffix The hook suffix for the current admin screen,
     *                           as provided by WordPress to admin_enqueue_scripts.
     *
     * @return void
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (!str_contains($hookSuffix, 'FWI')) {
            return;
        }

        wp_enqueue_style(
            handle: 'FWI-admin',
            src:    FWI_PLUGIN_URL . 'assets/css/admin.css',
            deps:   [],
            ver:    FWI_VERSION
        );

        wp_enqueue_script(
            handle: 'FWI-admin',
            src:    FWI_PLUGIN_URL . 'assets/js/admin.js',
            deps:   [],
            ver:    FWI_VERSION,
            args:   true
        );

        wp_localize_script('FWI-admin', 'FWI', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'testNonce'    => wp_create_nonce('fwi_test_webhook'),
            'deleteNonce'  => wp_create_nonce('fwi_delete_log'),
            'logsNonce'    => wp_create_nonce('fwi_get_logs'),
            'apiToggleNonce' => wp_create_nonce('fwi_toggle_analytics_api'),
            'apiRegenNonce'  => wp_create_nonce('fwi_regen_analytics_api_key'),
        ]);
    }
}
