<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Webhook\WebhookLogger;

/**
 * Handles the plugin's analytics admin page.
 *
 * Responsible for three concerns:
 *  - Processing the clear-logs POST early (via admin_init / processClearLogs) so
 *    that a post-redirect-get pattern can be applied without output already sent.
 *  - Streaming a CSV or JSON file download when an export link is followed
 *    (via admin_init / processExport), before any page output occurs.
 *  - Rendering the analytics page HTML, which displays two accordions: one listing
 *    all webhook requests (newest first) and one listing only error entries.
 *    Each accordion body has a month/year filter, per-page selector, the log list,
 *    and page-number navigation. Export buttons are shown when logs exist.
 *
 * Log data is read from the plugin's custom database table via WebhookLogger.
 * Entries older than three months are purged automatically by a daily WP-Cron
 * event (fwi_cleanup_old_logs) — this is noted on the page itself.
 */
final class AnalyticsPage
{
    /**
     * Provides access to stored webhook request logs in the custom DB table.
     *
     * @var WebhookLogger
     */
    private readonly WebhookLogger $logger;

    /**
     * Constructs the analytics page and its logging dependency.
     */
    public function __construct()
    {
        $this->logger = new WebhookLogger();
    }

    /**
     * Clears all stored webhook logs if a valid nonce-protected POST is detected.
     *
     * Runs on admin_init — before any page output — so wp_safe_redirect() can be
     * called to implement a post-redirect-get flow. Exits early if the request is
     * not a POST targeting this action, the nonce is invalid, or the current user
     * lacks the manage_options capability.
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
     * Runs on admin_init — before any page output — so HTTP headers can be sent.
     * Exits early when the fwi_export query param is absent, the nonce is invalid,
     * or the current user lacks the manage_options capability.
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
     * Streams all log rows as a UTF-8 CSV file and exits.
     *
     * Columns: ID, Date/Time, Success, Form Name, Response Code,
     *          Request Data (JSON string), Response Data.
     * A UTF-8 BOM is prepended so Excel opens the file correctly.
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

        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        fputcsv($output, ['ID', 'Date/Time', 'Success', 'Form Name', 'Request URL', 'Request Headers', 'Response Code', 'Request Data', 'Response Data']);

        foreach ($logs as $entry) {
            $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
            $formName       = is_array($requestDecoded) ? (string) ($requestDecoded['form_name'] ?? '') : '';

            fputcsv($output, [
                (int) ($entry['id'] ?? 0),
                (string) ($entry['created_at'] ?? ''),
                (int) ($entry['success'] ?? 0) === 1 ? 'Yes' : 'No',
                $formName,
                (string) ($entry['request_url'] ?? ''),
                (string) ($entry['request_headers'] ?? ''),
                (int) ($entry['response_code'] ?? 0),
                (string) ($entry['request_data'] ?? ''),
                (string) ($entry['response_data'] ?? ''),
            ]);
        }

        fclose($output);
        exit;
    }

    /**
     * Streams all log rows as a pretty-printed JSON file and exits.
     *
     * request_data and response_data are decoded from their stored JSON strings
     * so the output contains nested objects rather than escaped strings.
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

            $headersDecoded = json_decode((string) ($entry['request_headers'] ?? '{}'), true);

            $output[] = [
                'id'              => (int) ($entry['id'] ?? 0),
                'created_at'      => (string) ($entry['created_at'] ?? ''),
                'success'         => (int) ($entry['success'] ?? 0) === 1,
                'form_name'       => is_array($requestDecoded) ? (string) ($requestDecoded['form_name'] ?? '') : '',
                'request_url'     => (string) ($entry['request_url'] ?? ''),
                'request_headers' => is_array($headersDecoded) ? $headersDecoded : [],
                'response_code'   => (int) ($entry['response_code'] ?? 0),
                'request_data'    => is_array($requestDecoded) ? $requestDecoded : [],
                'response_data'   => is_array($responseDecoded) ? $responseDecoded : (string) ($entry['response_data'] ?? ''),
            ];
        }

        echo wp_json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Renders the full analytics page HTML inside the WordPress admin wrapper.
     *
     * Outputs a "Clear All Logs" form, a retention-policy notice, and two
     * collapsible accordions. The first accordion shows total request count in
     * its heading and, when expanded, lists every logged request (newest first).
     * The second accordion shows total error count and lists only error entries.
     * Each accordion body has a month/year filter, a per-page selector, the log
     * list, and page-number navigation — all driven client-side via admin.js.
     *
     * Each log row is read from the custom DB table. The request_data column is
     * decoded from JSON to extract the form name and submission fields; response_data
     * and response_code are read directly from their dedicated columns.
     *
     * Exits immediately if the current user does not have manage_options capability.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $cleared = isset($_GET['fwi_cleared']) && $_GET['fwi_cleared'] === '1';
        $logs    = $this->logger->getLogs();

        $errorLogs = array_values(
            array_filter($logs, static fn(array $entry): bool => (int) ($entry['success'] ?? 1) === 0)
        );

        $totalRequests = count($logs);
        $totalErrors   = count($errorLogs);

        ?>
        <div class="wrap fwi-wrap fwi-analytics-wrap">
            <h1>Webhook Analytics</h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p>All webhook logs have been cleared.</p></div>
            <?php endif; ?>

            <div class="notice notice-info inline fwi-retention-notice">
                <p>
                    <strong>Log retention policy:</strong>
                    Entries older than 3 months are automatically removed daily to keep the database size manageable.
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
                <div class="fwi-accordion-body" id="fwi-acc-requests" hidden>
                    <?php if (empty($logs)): ?>
                        <p class="fwi-empty-msg">No webhook requests have been made yet.</p>
                    <?php else: ?>
                        <ul class="fwi-log-list">
                            <?php foreach ($logs as $entry): ?>
                                <?php $this->renderLogEntry($entry); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Errors Accordion ───────────────────────────────────────── -->
            <div class="fwi-accordion">
                <button type="button" class="fwi-accordion-header" aria-expanded="false" aria-controls="fwi-acc-errors">
                    <span class="fwi-accordion-title">Total Errors</span>
                    <span class="fwi-badge fwi-badge-error"><?php echo esc_html((string) $totalErrors); ?></span>
                    <span class="fwi-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="fwi-accordion-body" id="fwi-acc-errors" hidden>
                    <?php if (empty($errorLogs)): ?>
                        <p class="fwi-empty-msg">No errors recorded.</p>
                    <?php else: ?>
                        <ul class="fwi-log-list">
                            <?php foreach ($errorLogs as $entry): ?>
                                <?php $this->renderLogEntry($entry); ?>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Renders a single log row as an HTML list item.
     *
     * Reads the dedicated request_data, response_data, response_code, and success
     * columns directly. request_data is decoded from JSON to extract the form name
     * and submission fields for display.
     *
     * @param array<string, mixed> $entry A single database row as returned by WebhookLogger::getLogs().
     *                                    Expected keys: id, success, request_data (JSON string),
     *                                    response_data, response_code, created_at.
     *
     * @return void
     */
    private function renderLogEntry(array $entry): void
    {
        $isError      = (int) ($entry['success'] ?? 1) === 0;
        $itemClass    = $isError ? 'fwi-log-error' : 'fwi-log-success';
        $statusText   = $isError ? 'Error' : 'Success';
        $statusClass  = $isError ? 'error' : 'success';
        $timestamp    = (string) ($entry['created_at'] ?? '');
        $requestUrl     = (string) ($entry['request_url'] ?? '');
        $requestHeaders = (string) ($entry['request_headers'] ?? '');
        $responseCode   = (int) ($entry['response_code'] ?? 0);
        $responseData = (string) ($entry['response_data'] ?? '');

        // YYYY-MM slice used by JS for month/year filtering
        $dateSlug = strlen($timestamp) >= 7 ? substr($timestamp, 0, 7) : '';

        $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $requestDecoded = is_array($requestDecoded) ? $requestDecoded : [];

        $formName = (string) ($requestDecoded['form_name'] ?? '');

        $prettyRequest = json_encode($requestDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // For transport errors, response_data holds {"error": "..."} — decode it for display
        $responseDecoded = json_decode($responseData, true);
        $errorMessage    = is_array($responseDecoded) ? (string) ($responseDecoded['error'] ?? '') : '';
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

            <?php if ($requestHeaders !== '' && $requestHeaders !== '{}'): ?>
                <div class="fwi-log-data">
                    <strong>Request Headers:</strong>
                    <pre><?php echo esc_html((string) json_encode(json_decode($requestHeaders, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
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
    }
}
