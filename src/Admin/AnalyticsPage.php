<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\WebhookLogger;

/**
 * Handles the plugin's analytics admin page.
 *
 * Responsible for four concerns:
 *  - Processing the clear-logs POST early (via admin_init / processClearLogs).
 *  - Streaming a CSV or JSON file download (via admin_init / processExport).
 *  - Rendering the analytics page HTML: two accordions whose log lists are
 *    populated client-side via the fwi_get_logs AJAX action (handleGetLogsAjax).
 *    Only row counts are fetched server-side on initial page load.
 *  - Serving paginated, filtered log data as JSON for the analytics accordions.
 *
 * Log data is read from the plugin's custom database table via WebhookLogger.
 */
final class AnalyticsPage
{
    /**
     * @var WebhookLogger
     */
    private readonly WebhookLogger $logger;

    public function __construct(
        private readonly SettingsManager $settings
    ) {
        $this->logger = new WebhookLogger();
    }

    /**
     * Clears all stored webhook logs if a valid nonce-protected POST is detected.
     *
     * @return void
     */
    public function processClearLogs(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !isset($_POST['fwi_action']) ||
            $_POST['fwi_action'] !== 'clear_logs' ||
            !isset($_POST['fwi_clear_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fwi_clear_nonce'])), 'fwi_clear_logs') ||
            !current_user_can('manage_options')
        ) {
            return;
        }

        $this->logger->clearLogs();

        wp_safe_redirect(
            add_query_arg(['page' => 'FWI-analytics', 'fwi_cleared' => '1'], admin_url('admin.php'))
        );
        exit;
    }

    /**
     * Streams a CSV or JSON file download when a valid export link is followed.
     *
     * @return void
     */
    public function processExport(): void
    {
        if (!isset($_GET['fwi_export']) || !current_user_can('manage_options')) {
            return;
        }

        $type = sanitize_key((string) ($_GET['fwi_export'] ?? ''));

        if ($type === 'csv') {
            if (
                !isset($_GET['_wpnonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'fwi_export_csv')
            ) {
                wp_die('Invalid or expired export link.');
            }

            $this->exportCsv();
        } elseif ($type === 'json') {
            if (
                !isset($_GET['_wpnonce']) ||
                !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'fwi_export_json')
            ) {
                wp_die('Invalid or expired export link.');
            }

            $this->exportJson();
        }
    }

    /**
     * Handles the fwi_get_logs AJAX action.
     *
     * Accepts page, per_page, errors_only, search, filter_year, and filter_month
     * from POST. Returns JSON with rendered log-item HTML, total row count, total
     * pages, current page, and distinct year/month arrays for the filter dropdowns.
     *
     * @return never
     */
    public function handleGetLogsAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fwi_get_logs') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $page        = max(1, (int) ($_POST['page'] ?? 1));
        $perPage     = min(100, max(5, (int) ($_POST['per_page'] ?? 10)));
        $errorsOnly  = isset($_POST['errors_only']) && $_POST['errors_only'] === '1';
        $search      = sanitize_text_field(wp_unslash($_POST['search']       ?? ''));
        $filterYear  = sanitize_text_field(wp_unslash($_POST['filter_year']  ?? ''));
        $filterMonth = sanitize_text_field(wp_unslash($_POST['filter_month'] ?? ''));

        $logs  = $this->logger->getLogsPaginated($page, $perPage, $errorsOnly, $filterYear, $filterMonth, $search);
        $total = $this->logger->getLogCount($errorsOnly, $filterYear, $filterMonth, $search);
        $dates = $this->logger->getDistinctDates($errorsOnly);

        $totalPages = max(1, (int) ceil($total / $perPage));

        $html = '';
        foreach ($logs as $entry) {
            $html .= $this->renderLogEntryHtml($entry);
        }

        wp_send_json_success([
            'html'        => $html,
            'total'       => $total,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'years'       => $dates['years'],
            'months'      => $dates['months'],
        ]);
    }

    /**
     * AJAX handler that toggles the analytics API on or off.
     *
     * When turning ON for the first time, generates an API key if none exists.
     * Returns the new active state and, when active, the key.
     *
     * @return never
     */
    public function handleApiToggleAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fwi_toggle_analytics_api') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $active = isset($_POST['active']) && $_POST['active'] === '1';
        $this->settings->setAnalyticsApiActive($active);

        $key = '';
        if ($active) {
            $key = $this->settings->getAnalyticsApiKey();
            if (empty($key)) {
                $key = $this->settings->generateAnalyticsApiKey();
            }
        }

        wp_send_json_success([
            'active' => $active,
            'key'    => $key,
        ]);
    }

    /**
     * AJAX handler that generates a new analytics API key and returns it.
     *
     * @return never
     */
    public function handleApiRegenKeyAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'fwi_regen_analytics_api_key') ||
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $key = $this->settings->generateAnalyticsApiKey();
        wp_send_json_success(['key' => $key]);
    }

    /**
     * Renders the full analytics page HTML inside the WordPress admin wrapper.
     *
     * Only row counts are fetched on page load. Log entries are loaded lazily
     * into each accordion via the fwi_get_logs AJAX action when the accordion
     * is first opened (handled by admin.js).
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cleared         = isset($_GET['fwi_cleared']) && $_GET['fwi_cleared'] === '1';
        $totalRequests   = $this->logger->getLogCount();
        $totalErrors     = $this->logger->getLogCount(errorsOnly: true);
        $retentionMonths = (int) get_option('FWI_log_retention_months', 3);
        $apiActive       = $this->settings->isAnalyticsApiActive();
        $apiKey          = $this->settings->getAnalyticsApiKey();

        ?>
        <div class="wrap fwi-wrap fwi-analytics-wrap">
            <h1>Webhook Analytics</h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p>All webhook logs have been cleared.</p></div>
            <?php endif; ?>

            <!-- ── Analytics API Card ─────────────────────────────────────── -->
            <div class="fwi-card fwi-analytics-api-card">
                <h2 class="fwi-card-title">Analytics API</h2>
                <p class="description" style="margin-bottom:12px;">
                    Enable a read-only REST endpoint that returns all log data as JSON.
                    Pass the API key as the <code>Authorization</code> header on every request.
                </p>

                <div class="fwi-toggle-row">
                    <label class="fwi-toggle" for="fwi-analytics-api-toggle" aria-label="Toggle Analytics API active state">
                        <input
                            type="checkbox"
                            id="fwi-analytics-api-toggle"
                            <?php checked($apiActive, true); ?>
                        >
                        <span class="fwi-toggle-slider" aria-hidden="true"></span>
                    </label>
                    <span class="fwi-toggle-label" id="fwi-api-toggle-label">
                        <?php echo $apiActive ? 'Active' : 'Inactive'; ?>
                    </span>
                </div>

                <div id="fwi-api-key-section"<?php echo $apiActive ? '' : ' hidden'; ?>>
                    <table class="form-table" role="presentation" style="margin-top:12px;">
                        <tr>
                            <th scope="row">Endpoint</th>
                            <td>
                                <code><?php echo esc_url(rest_url('fwi/v1/analytics')); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Key</th>
                            <td>
                                <div class="fwi-api-key-row">
                                    <code id="fwi-api-key-value"><?php echo esc_html($apiKey); ?></code>
                                    <button type="button" class="button fwi-copy-key-btn">Copy</button>
                                    <button type="button" class="button fwi-regen-key-btn">Regenerate</button>
                                </div>
                                <p class="description" style="margin-top:6px;">
                                    Include this value as the <code>Authorization</code> header in your GET request.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="notice notice-info inline fwi-retention-notice">
                <p>
                    <strong>Log retention policy:</strong>
                    Entries older than <?php echo esc_html((string) $retentionMonths); ?> month<?php echo $retentionMonths !== 1 ? 's' : ''; ?> are automatically removed daily.
                    The retention period can be changed under <strong>Settings</strong>.
                </p>
            </div>

            <div class="fwi-analytics-toolbar">
                <form method="post" action="" class="fwi-clear-form">
                    <?php wp_nonce_field('fwi_clear_logs', 'fwi_clear_nonce'); ?>
                    <input type="hidden" name="fwi_action" value="clear_logs">
                    <button
                        type="submit"
                        class="button button-secondary fwi-btn-danger"
                        onclick="return confirm('Are you sure you want to clear all webhook logs? This cannot be undone.');"
                    >
                        Clear All Logs
                    </button>
                </form>

                <?php if ($totalRequests > 0): ?>
                    <div class="fwi-export-buttons">
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'FWI-analytics', 'fwi_export' => 'csv'], admin_url('admin.php')), 'fwi_export_csv')); ?>"
                            class="button button-secondary"
                        >
                            Export All To CSV
                        </a>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => 'FWI-analytics', 'fwi_export' => 'json'], admin_url('admin.php')), 'fwi_export_json')); ?>"
                            class="button button-secondary"
                        >
                            Export All To JSON
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── All Requests Accordion ──────────────────────────────────── -->
            <div class="fwi-accordion">
                <button type="button" class="fwi-accordion-header" aria-expanded="false" aria-controls="fwi-acc-requests">
                    <span class="fwi-accordion-title">Total Requests</span>
                    <span class="fwi-badge"><?php echo esc_html((string) $totalRequests); ?></span>
                    <span class="fwi-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="fwi-accordion-body" id="fwi-acc-requests" data-errors-only="0" hidden>
                    <!-- Log list injected by admin.js via fwi_get_logs AJAX -->
                </div>
            </div>

            <!-- ── Errors Accordion ───────────────────────────────────────── -->
            <div class="fwi-accordion">
                <button type="button" class="fwi-accordion-header" aria-expanded="false" aria-controls="fwi-acc-errors">
                    <span class="fwi-accordion-title">Total Errors</span>
                    <span class="fwi-badge fwi-badge-error"><?php echo esc_html((string) $totalErrors); ?></span>
                    <span class="fwi-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="fwi-accordion-body" id="fwi-acc-errors" data-errors-only="1" hidden>
                    <!-- Log list injected by admin.js via fwi_get_logs AJAX -->
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Renders a single log row as an HTML list-item string.
     *
     * @param array<string, mixed> $entry A single database row from WebhookLogger.
     *
     * @return string
     */
    private function renderLogEntryHtml(array $entry): string
    {
        $isError      = (int) ($entry['success'] ?? 1) === 0;
        $itemClass    = $isError ? 'fwi-log-error' : 'fwi-log-success';
        $statusText   = $isError ? 'Error' : 'Success';
        $statusClass  = $isError ? 'error' : 'success';
        $timestamp    = (string) ($entry['created_at'] ?? '');
        $requestUrl   = (string) ($entry['request_url'] ?? '');
        $responseCode = (int) ($entry['response_code'] ?? 0);
        $responseData = (string) ($entry['response_data'] ?? '');
        $dateSlug     = strlen($timestamp) >= 7 ? substr($timestamp, 0, 7) : '';

        $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $requestDecoded = is_array($requestDecoded) ? $requestDecoded : [];
        $formName       = (string) ($requestDecoded['form_name'] ?? '');
        $prettyRequest  = json_encode($requestDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $responseDecoded = json_decode($responseData, true);
        $errorMessage    = is_array($responseDecoded) ? (string) ($responseDecoded['error'] ?? '') : '';

        ob_start();
        ?>
        <li class="fwi-log-item <?php echo esc_attr($itemClass); ?>" data-date="<?php echo esc_attr($dateSlug); ?>" data-log-id="<?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">

            <div class="fwi-log-meta">
                <span class="fwi-log-time"><?php echo esc_html($timestamp); ?></span>
                <?php if ($formName !== ''): ?>
                    <span class="fwi-log-form"><?php echo esc_html($formName); ?></span>
                <?php endif; ?>
                <span class="fwi-log-status <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusText); ?>
                    <?php if ($responseCode !== 0): ?>
                        (<?php echo esc_html((string) $responseCode); ?>)
                    <?php endif; ?>
                </span>
                <button type="button" class="button fwi-log-delete-btn" aria-label="Delete log entry <?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">Delete</button>
            </div>

            <?php if ($requestUrl !== ''): ?>
                <div class="fwi-log-url">
                    <strong>Request URL:</strong> <code><?php echo esc_html($requestUrl); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="fwi-log-error-msg">
                    <strong>Error:</strong> <?php echo esc_html($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="fwi-log-data">
                <strong>Request Data:</strong>
                <pre><?php echo esc_html((string) $prettyRequest); ?></pre>
            </div>

            <?php if ($responseData !== '' && $errorMessage === ''): ?>
                <div class="fwi-log-response">
                    <strong>Response:</strong>
                    <pre><?php echo esc_html($responseData); ?></pre>
                </div>
            <?php endif; ?>

        </li>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Streams all log rows as a UTF-8 CSV file and exits.
     *
     * @return never
     */
    private function exportCsv(): never
    {
        $logs     = $this->logger->getLogs();
        $filename = 'fwi-webhook-logs-' . date('Y-m-d') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['ID', 'Date/Time', 'Success', 'Form Name', 'Request URL', 'Response Code', 'Request Data', 'Response Data']);

        foreach ($logs as $entry) {
            $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
            $formName       = is_array($requestDecoded) ? (string) ($requestDecoded['form_name'] ?? '') : '';

            fputcsv($output, [
                (int) ($entry['id'] ?? 0),
                $this->escapeCsvCell((string) ($entry['created_at'] ?? '')),
                (int) ($entry['success'] ?? 0) === 1 ? 'Yes' : 'No',
                $this->escapeCsvCell($formName),
                $this->escapeCsvCell((string) ($entry['request_url'] ?? '')),
                (int) ($entry['response_code'] ?? 0),
                $this->escapeCsvCell((string) ($entry['request_data'] ?? '')),
                $this->escapeCsvCell((string) ($entry['response_data'] ?? '')),
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Prefixes spreadsheet-formula trigger characters so Excel/Sheets treat the
     * value as text rather than a formula.
     */
    private function escapeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "\t" . $value;
        }
        return $value;
    }

    /**
     * Streams all log rows as a pretty-printed JSON file and exits.
     *
     * @return never
     */
    private function exportJson(): never
    {
        $logs     = $this->logger->getLogs();
        $filename = 'fwi-webhook-logs-' . date('Y-m-d') . '.json';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = [];

        foreach ($logs as $entry) {
            $requestDecoded  = json_decode((string) ($entry['request_data'] ?? '{}'), true);
            $responseDecoded = json_decode((string) ($entry['response_data'] ?? ''), true);

            $output[] = [
                'id'            => (int) ($entry['id'] ?? 0),
                'created_at'    => (string) ($entry['created_at'] ?? ''),
                'success'       => (int) ($entry['success'] ?? 0) === 1,
                'form_name'     => is_array($requestDecoded) ? (string) ($requestDecoded['form_name'] ?? '') : '',
                'request_url'   => (string) ($entry['request_url'] ?? ''),
                'response_code' => (int) ($entry['response_code'] ?? 0),
                'request_data'  => is_array($requestDecoded) ? $requestDecoded : [],
                'response_data' => is_array($responseDecoded) ? $responseDecoded : (string) ($entry['response_data'] ?? ''),
            ];
        }

        echo wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
