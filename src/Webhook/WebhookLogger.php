<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Database\DatabaseManager;

/**
 * Persists and retrieves webhook request logs in the plugin's custom database table.
 *
 * Each call to log() inserts a single row containing a success/failure flag,
 * the JSON request payload, the raw webhook response, the HTTP status code,
 * and a creation timestamp. Old entries are automatically pruned by the daily
 * WP-Cron event (FWI_cleanup_old_logs) registered in the main plugin file.
 *
 * @see DatabaseManager For the table schema and purge logic.
 */
final class WebhookLogger
{
    /**
     * Inserts a new log entry into the custom database table.
     *
     * success is set to 1 when responseCode is 200 or 201, and 0 for everything
     * else including transport errors (responseCode = 0). For transport errors,
     * responseData should be a JSON string of the form {"error": "message"}.
     *
     * @param array<string, mixed> $requestData    The form-data payload that was JSON-encoded and POSTed.
     * @param string               $requestUrl     The fully-qualified webhook URL (base + query params).
     * @param array<string, mixed> $requestHeaders HTTP headers sent with the request.
     * @param int                  $responseCode   HTTP status code returned by the webhook, or 0 when
     *                                             a transport-level error prevented any response.
     * @param string               $responseData   Raw response body returned by the webhook, or a
     *                                             JSON-encoded error object for transport failures.
     *
     * @return void
     */
    public function log(
        array $requestData,
        string $requestUrl,
        array $requestHeaders,
        int $responseCode,
        string $responseData
    ): void {
        global $wpdb;

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            DatabaseManager::getTableName(),
            [
                'success'         => ($responseCode === 200 || $responseCode === 201) ? 1 : 0,
                'request_url'     => $requestUrl,
                'request_headers' => wp_json_encode($requestHeaders) ?: '{}',
                'request_data'    => wp_json_encode($requestData) ?: '{}',
                'response_data'   => $responseData,
                'response_code'   => $responseCode,
                'created_at'      => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Retrieves all log entries from the custom table, ordered newest-first.
     *
     * Each row is returned as an associative array with keys: id, success,
     * request_data (JSON string), response_data, response_code, and created_at.
     *
     * @return array<int, array<string, mixed>> All stored log rows, newest first.
     */
    public function getLogs(): array
    {
        global $wpdb;

        $table   = DatabaseManager::getTableName();
        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT * FROM `{$table}` ORDER BY created_at DESC",
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Deletes a single log row by its primary key.
     *
     * @param int $id The row ID to delete.
     *
     * @return void
     */
    public function deleteLog(int $id): void
    {
        global $wpdb;

        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            DatabaseManager::getTableName(),
            ['id' => $id],
            ['%d']
        );
    }

    /**
     * Removes all rows from the custom log table.
     *
     * Uses TRUNCATE for efficiency; this also resets the auto-increment counter.
     *
     * @return void
     */
    public function clearLogs(): void
    {
        global $wpdb;

        $table = DatabaseManager::getTableName();
        $wpdb->query("TRUNCATE TABLE `{$table}`"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
