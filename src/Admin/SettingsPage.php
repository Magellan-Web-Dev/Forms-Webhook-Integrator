<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Forms\ElementorFormsHelper;

/**
 * Handles the plugin's main settings admin page.
 *
 * Responsible for two distinct concerns:
 *  - Processing the settings form POST early (via admin_init / processSave) so
 *    that a post-redirect-get pattern can be applied cleanly.
 *  - Rendering the full settings page HTML, including the webhook toggle,
 *    excluded-forms list, webhook URL, URL query-parameter builder, client
 *    fields, and the outside-US blocking select.
 */
final class SettingsPage
{
    /**
     * Helper that discovers all Elementor form names present on the site,
     * used to populate the excluded-forms dropdown.
     *
     * @var ElementorFormsHelper
     */
    private readonly ElementorFormsHelper $formsHelper;

    /**
     * Constructs the settings page and its form-discovery dependency.
     *
     * @param SettingsManager $settingsManager Shared settings store used to read
     *                                         current values and persist new ones.
     */
    public function __construct(
        private readonly SettingsManager $settingsManager
    ) {
        $this->formsHelper = new ElementorFormsHelper();
    }

    /**
     * Validates and saves the settings form if a valid nonce-protected POST is detected.
     *
     * Runs on admin_init — before any page output — so wp_safe_redirect() can be
     * called to implement a post-redirect-get flow and prevent duplicate submissions
     * on page refresh. Exits early if the request is not a POST, the nonce is
     * invalid, or the current user lacks the manage_options capability.
     *
     * @return void
     */
    public function processSave(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !isset($_POST['fwi_settings_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fwi_settings_nonce'])), 'fwi_save_settings') ||
            !current_user_can('manage_options')
        ) {
            return;
        }

        $oldUrl = $this->settingsManager->getWebhookUrl();

        $this->settingsManager->save(wp_unslash($_POST));

        if ($this->settingsManager->getWebhookUrl() !== $oldUrl) {
            $this->settingsManager->clearLastTestResult();
        }

        wp_safe_redirect(
            add_query_arg(['page' => 'FWI-settings', 'fwi_saved' => '1'], admin_url('admin.php'))
        );
        exit;
    }

    /**
     * Renders the full settings page HTML inside the WordPress admin wrapper.
     *
     * Outputs the webhook status toggle, excluded-forms section, webhook URL,
     * URL query-parameter list, client name fields, and the outside-US blocking
     * select. A success notice is shown when redirected back after a successful save.
     * Exits immediately if the current user does not have manage_options capability.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $saved          = isset($_GET['fwi_saved']) && $_GET['fwi_saved'] === '1';
        $allForms       = $this->formsHelper->getAllFormNames();
        $excludedForms  = $this->settingsManager->getExcludedForms();
        $activeForms    = array_values(array_filter($allForms, fn($f) => !in_array($f, $excludedForms, true)));
        $queryParams    = $this->settingsManager->getQueryParams();
        $isActive       = $this->settingsManager->isActive();
        $webhookUrl     = $this->settingsManager->getWebhookUrl();
        $lastTestResult = $this->settingsManager->getLastTestResult();
        $formOverrides  = $this->settingsManager->getFormOverrides();

        ?>
        <div class="wrap fwi-wrap">
            <h1>Forms Webhook Integrator</h1>

            <?php if ($saved): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>
            <?php endif; ?>

            <form method="post" action="" id="fwi-settings-form">
                <?php wp_nonce_field('fwi_save_settings', 'fwi_settings_nonce'); ?>

                <!-- ── Webhook Status Toggle ───────────────────────────────── -->
                <div class="fwi-card fwi-toggle-card" id="fwi-webhook-toggle-card"<?php if (empty($webhookUrl)): ?> style="display:none"<?php endif; ?>>
                    <h2 class="fwi-card-title">Webhook Status</h2>
                    <div class="fwi-toggle-row">
                        <label class="fwi-toggle" for="fwi_active" aria-label="Toggle webhook active state">
                            <input
                                type="checkbox"
                                id="fwi_active"
                                name="fwi_active"
                                value="1"
                                <?php checked($isActive, true); ?>
                            >
                            <span class="fwi-toggle-slider" aria-hidden="true"></span>
                        </label>
                        <span class="fwi-toggle-label" id="fwi-toggle-label">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>
                </div>

                <!-- ── Webhook Settings ────────────────────────────────────── -->
                <div class="fwi-card">
                    <h2 class="fwi-card-title">Webhook Settings</h2>

                    <table class="form-table" role="presentation">

                        <!-- Webhook URL -->
                        <tr>
                            <th scope="row">
                                <label for="fwi_webhook_url">Webhook URL</label>
                            </th>
                            <td>
                                <div class="fwi-webhook-url-row">
                                    <input
                                        type="url"
                                        id="fwi_webhook_url"
                                        name="fwi_webhook_url"
                                        value="<?php echo esc_attr($webhookUrl); ?>"
                                        class="regular-text"
                                        placeholder="https://..."
                                    >
                                    <button type="button" id="fwi-test-webhook" class="button"<?php echo empty($webhookUrl) ? ' disabled' : ''; ?>>
                                        Test Webhook
                                    </button>
                                </div>
                                <div id="fwi-test-result" class="fwi-test-result" hidden></div>
                                <?php if (!empty($lastTestResult)): ?>
                                    <?php $testOk = !empty($lastTestResult['success']); ?>
                                    <div class="fwi-last-test <?php echo $testOk ? 'fwi-last-test-success' : 'fwi-last-test-error'; ?>">
                                        <strong>Last test:</strong>
                                        <?php echo esc_html($lastTestResult['message'] ?? ''); ?>
                                        <span class="fwi-last-test-meta">
                                            — <?php echo esc_html($lastTestResult['time'] ?? ''); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <!-- Global Headers -->
                        <tr>
                            <th scope="row">Global Headers</th>
                            <td>
                                <p class="description" style="margin-bottom:10px;">
                                    Custom HTTP headers sent with every webhook request.
                                </p>
                                <div class="fwi-row-add">
                                    <input
                                        type="text"
                                        id="fwi-header-key"
                                        placeholder="Header Name"
                                        class="fwi-param-input"
                                        aria-label="Header name"
                                    >
                                    <input
                                        type="text"
                                        id="fwi-header-value"
                                        placeholder="Value"
                                        class="fwi-param-input"
                                        aria-label="Header value"
                                    >
                                    <button type="button" id="fwi-add-header" class="button">Add Header</button>
                                </div>
                                <ul id="fwi-webhook-headers-list" class="fwi-list">
                                    <?php $webhookHeaders = $this->settingsManager->getWebhookHeaders(); ?>
                                    <?php if (empty($webhookHeaders)): ?>
                                        <li id="fwi-no-webhook-headers" class="fwi-empty-msg">No custom headers added.</li>
                                    <?php else: ?>
                                        <?php foreach ($webhookHeaders as $i => $header): ?>
                                            <li class="fwi-list-item">
                                                <span class="fwi-item-label">
                                                    <code><?php echo esc_html($header['key']); ?></code>
                                                    &nbsp;=&nbsp;
                                                    <code><?php echo esc_html($header['value']); ?></code>
                                                </span>
                                                <input type="hidden" name="fwi_webhook_headers[<?php echo $i; ?>][key]"   value="<?php echo esc_attr($header['key']); ?>">
                                                <input type="hidden" name="fwi_webhook_headers[<?php echo $i; ?>][value]" value="<?php echo esc_attr($header['value']); ?>">
                                                <button type="button" class="button fwi-remove-btn" aria-label="Remove header <?php echo esc_attr($header['key']); ?>">Remove</button>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </td>
                        </tr>

                        <!-- Global URL Query Parameters -->
                        <tr>
                            <th scope="row">Global URL Query Parameters</th>
                            <td>
                                <p class="description" style="margin-bottom: 10px;">
                                    These key/value pairs are appended as a query string to the webhook URL on every request.
                                </p>
                                <div class="fwi-row-add">
                                    <input
                                        type="text"
                                        id="fwi-param-key"
                                        placeholder="Key"
                                        class="fwi-param-input"
                                        aria-label="Query parameter key"
                                    >
                                    <input
                                        type="text"
                                        id="fwi-param-value"
                                        placeholder="Value"
                                        class="fwi-param-input"
                                        aria-label="Query parameter value"
                                    >
                                    <button type="button" id="fwi-add-param" class="button">Add Parameter</button>
                                </div>

                                <ul id="fwi-query-params-list" class="fwi-list">
                                    <?php if (empty($queryParams)): ?>
                                        <li id="fwi-no-query-params" class="fwi-empty-msg">No query parameters added.</li>
                                    <?php else: ?>
                                        <?php foreach ($queryParams as $i => $param): ?>
                                            <li class="fwi-list-item">
                                                <span class="fwi-item-label">
                                                    <code><?php echo esc_html($param['key']); ?></code>
                                                    &nbsp;=&nbsp;
                                                    <code><?php echo esc_html($param['value']); ?></code>
                                                </span>
                                                <input type="hidden" name="fwi_query_params[<?php echo $i; ?>][key]"   value="<?php echo esc_attr($param['key']); ?>">
                                                <input type="hidden" name="fwi_query_params[<?php echo $i; ?>][value]" value="<?php echo esc_attr($param['value']); ?>">
                                                <button type="button" class="button fwi-remove-btn" aria-label="Remove parameter <?php echo esc_attr($param['key']); ?>">Remove</button>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </ul>
                            </td>
                        </tr>

                        <!-- Client First Name -->
                        <tr>
                            <th scope="row">
                                <label for="fwi_client_first_name">Client First Name</label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="fwi_client_first_name"
                                    name="fwi_client_first_name"
                                    value="<?php echo esc_attr($this->settingsManager->getClientFirstName()); ?>"
                                    class="regular-text"
                                >
                            </td>
                        </tr>

                        <!-- Client Last Name -->
                        <tr>
                            <th scope="row">
                                <label for="fwi_client_last_name">Client Last Name</label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="fwi_client_last_name"
                                    name="fwi_client_last_name"
                                    value="<?php echo esc_attr($this->settingsManager->getClientLastName()); ?>"
                                    class="regular-text"
                                >
                            </td>
                        </tr>

                        <!-- Block Outside US -->
                        <tr>
                            <th scope="row">
                                <label for="fwi_block_outside_us">Block Submissions Outside US</label>
                            </th>
                            <td>
                                <select id="fwi_block_outside_us" name="fwi_block_outside_us">
                                    <option value="1" <?php selected($this->settingsManager->isBlockOutsideUs(), true); ?>>Yes — block non-US submissions</option>
                                    <option value="0" <?php selected($this->settingsManager->isBlockOutsideUs(), false); ?>>No — allow all submissions</option>
                                </select>
                                <p class="description">When enabled, form submissions from outside the United States are rejected to reduce spam.</p>
                            </td>
                        </tr>

                        <!-- Log Retention -->
                        <tr>
                            <th scope="row">
                                <label for="fwi_log_retention_months">Log Retention</label>
                            </th>
                            <td>
                                <?php $retention = $this->settingsManager->getLogRetentionMonths(); ?>
                                <select id="fwi_log_retention_months" name="fwi_log_retention_months">
                                    <?php foreach ([1, 3, 6, 12, 24] as $months): ?>
                                        <option value="<?php echo $months; ?>" <?php selected($retention, $months); ?>>
                                            <?php echo $months === 1 ? '1 month' : "{$months} months"; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Log entries older than this are purged automatically each day.</p>
                            </td>
                        </tr>

                    </table>
                </div>

                <!-- ── Excluded Forms ──────────────────────────────────────── -->
                <div class="fwi-card">
                    <h2 class="fwi-card-title">Excluded Forms</h2>
                    <p class="description">Select Elementor forms that should <strong>not</strong> trigger the webhook.</p>

                    <div class="fwi-row-add">
                        <select id="fwi-form-select" aria-label="Select a form to exclude">
                            <option value="">— Select a form —</option>
                            <?php foreach ($allForms as $formName): ?>
                                <option value="<?php echo esc_attr($formName); ?>">
                                    <?php echo esc_html($formName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="fwi-add-excluded-form" class="button">Add to Excluded List</button>
                    </div>

                    <ul id="fwi-excluded-forms-list" class="fwi-list">
                        <?php if (empty($excludedForms)): ?>
                            <li id="fwi-no-excluded-forms" class="fwi-empty-msg">
                                All Elementor forms are currently enabled to use the webhook.
                            </li>
                        <?php else: ?>
                            <?php foreach ($excludedForms as $formName): ?>
                                <li class="fwi-list-item">
                                    <span class="fwi-item-label"><?php echo esc_html($formName); ?></span>
                                    <input type="hidden" name="fwi_excluded_forms[]" value="<?php echo esc_attr($formName); ?>">
                                    <button type="button" class="button fwi-remove-btn" aria-label="Remove <?php echo esc_attr($formName); ?>">Remove</button>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- ── Specific Form URL Query And Headers ────────────────── -->
                <div class="fwi-card">
                    <h2 class="fwi-card-title">Specific Form URL Query And Headers</h2>
                    <p class="description">
                        Configure additional URL query parameters and request headers for individual forms.
                        These are merged on top of the global webhook settings for that form's submission only.
                    </p>

                    <?php if (empty($activeForms)): ?>
                        <p class="fwi-empty-msg" style="margin-top:10px;">
                            No active Elementor forms found. Forms appear here once they exist in Elementor and are not excluded above.
                        </p>
                    <?php else: ?>
                        <div class="fwi-form-overrides-list">
                        <?php foreach ($activeForms as $formName): ?>
                            <?php
                            $override     = $formOverrides[$formName] ?? [];
                            $formQP       = is_array($override['query_params'] ?? null) ? $override['query_params'] : [];
                            $formHeaders  = is_array($override['headers']      ?? null) ? $override['headers']      : [];
                            ?>
                            <div class="fwi-form-override">
                                <h3 class="fwi-form-override-title"><?php echo esc_html($formName); ?></h3>

                                <table class="form-table" role="presentation">

                                    <!-- Per-form URL Query Parameters -->
                                    <tr>
                                        <th scope="row">URL Query Parameters</th>
                                        <td>
                                            <p class="description" style="margin-bottom:10px;">
                                                Additional query parameters appended to the webhook URL for this form only.
                                            </p>
                                            <div class="fwi-form-override-builder"
                                                 data-fwi-builder
                                                 data-form-name="<?php echo esc_attr($formName); ?>"
                                                 data-type="query_params">
                                                <div class="fwi-row-add">
                                                    <input type="text" placeholder="Key"   class="fwi-param-input fwi-builder-key"   aria-label="Query parameter key">
                                                    <input type="text" placeholder="Value" class="fwi-param-input fwi-builder-value" aria-label="Query parameter value">
                                                    <button type="button" class="button fwi-builder-add-btn">Add Parameter</button>
                                                </div>
                                                <ul class="fwi-list fwi-builder-list">
                                                    <?php if (empty($formQP)): ?>
                                                        <li class="fwi-builder-empty-msg fwi-empty-msg">No query parameters added.</li>
                                                    <?php else: ?>
                                                        <?php foreach ($formQP as $i => $param): ?>
                                                            <li class="fwi-list-item">
                                                                <span class="fwi-item-label">
                                                                    <code><?php echo esc_html($param['key']); ?></code>
                                                                    &nbsp;=&nbsp;
                                                                    <code><?php echo esc_html($param['value']); ?></code>
                                                                </span>
                                                                <input type="hidden" name="fwi_form_overrides[<?php echo esc_attr($formName); ?>][query_params][<?php echo $i; ?>][key]"   value="<?php echo esc_attr($param['key']); ?>">
                                                                <input type="hidden" name="fwi_form_overrides[<?php echo esc_attr($formName); ?>][query_params][<?php echo $i; ?>][value]" value="<?php echo esc_attr($param['value']); ?>">
                                                                <button type="button" class="button fwi-remove-btn" aria-label="Remove parameter <?php echo esc_attr($param['key']); ?>">Remove</button>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Per-form Request Headers -->
                                    <tr>
                                        <th scope="row">Request Headers</th>
                                        <td>
                                            <p class="description" style="margin-bottom:10px;">
                                                Additional HTTP headers sent with the webhook request for this form only.
                                            </p>
                                            <div class="fwi-form-override-builder"
                                                 data-fwi-builder
                                                 data-form-name="<?php echo esc_attr($formName); ?>"
                                                 data-type="headers">
                                                <div class="fwi-row-add">
                                                    <input type="text" placeholder="Header Name" class="fwi-param-input fwi-builder-key"   aria-label="Header name">
                                                    <input type="text" placeholder="Value"        class="fwi-param-input fwi-builder-value" aria-label="Header value">
                                                    <button type="button" class="button fwi-builder-add-btn">Add Header</button>
                                                </div>
                                                <ul class="fwi-list fwi-builder-list">
                                                    <?php if (empty($formHeaders)): ?>
                                                        <li class="fwi-builder-empty-msg fwi-empty-msg">No custom headers added.</li>
                                                    <?php else: ?>
                                                        <?php foreach ($formHeaders as $i => $header): ?>
                                                            <li class="fwi-list-item">
                                                                <span class="fwi-item-label">
                                                                    <code><?php echo esc_html($header['key']); ?></code>
                                                                    &nbsp;=&nbsp;
                                                                    <code><?php echo esc_html($header['value']); ?></code>
                                                                </span>
                                                                <input type="hidden" name="fwi_form_overrides[<?php echo esc_attr($formName); ?>][headers][<?php echo $i; ?>][key]"   value="<?php echo esc_attr($header['key']); ?>">
                                                                <input type="hidden" name="fwi_form_overrides[<?php echo esc_attr($formName); ?>][headers][<?php echo $i; ?>][value]" value="<?php echo esc_attr($header['value']); ?>">
                                                                <button type="button" class="button fwi-remove-btn" aria-label="Remove header <?php echo esc_attr($header['key']); ?>">Remove</button>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>

                                </table>
                            </div>
                        <?php endforeach; ?>
                        </div><!-- /.fwi-form-overrides-list -->
                    <?php endif; ?>
                </div>

                <?php submit_button('Save Settings'); ?>

            </form>
        </div>
        <?php
    }
}
