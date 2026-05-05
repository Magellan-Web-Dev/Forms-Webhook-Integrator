<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Database\DatabaseManager;

/**
 * Persists and retrieves webhook request logs in the plugin's custom database table.
 *
 * Each call to log() inserts a single row. Before storing, sensitive field names
 * (passwords, tokens, etc.) in submission_data are replaced with [REDACTED], and
 * both the encoded request payload and the response body are capped at 64 KB to
 * prevent the table from ballooning on large payloads.
 *
 * Old entries are automatically pruned by the daily WP-Cron event
 * (FWI_cleanup_old_logs) registered in Plugin::init().
 *
 * @see DatabaseManager For the table schema and purge logic.
 */
final class WebhookLogger
{
    private const MAX_BODY_BYTES = 65536;

    private const SENSITIVE_FIELD_PATTERNS = [
        'password', 'pass', 'pwd', 'secret', 'token', 'api_key',
        'ssn', 'social_security', 'credit_card', 'cc_number', 'cvv', 'card_number',
    ];

    /**
     * Inserts a new log entry into the custom database table.
     *
     * success is set to 1 when responseCode is 200, 201, 202, or 204, and 0 for
     * everything else including transport errors (responseCode = 0).
     *
     * @param array<string, mixed> $requestData  The form-data payload that was POSTed.
     * @param string               $requestUrl   The fully-qualified webhook URL.
     * @param int                  $responseCode HTTP status code, or 0 for transport errors.
     * @param string               $responseData Raw response body, or JSON error object.
     *
     * @return void
     */
    public function log(
        array $requestData,
        string $requestUrl,
        int $responseCode,
        string $responseData
    ): void {
        global $wpdb;

        $logData  = $this->redactSensitiveFields($requestData);
        $encoded  = wp_json_encode($logData) ?: '{}';

        if (strlen($encoded) > self::MAX_BODY_BYTES) {
            if (isset($logData['submission_data']) && is_array($logData['submission_data'])) {
                $logData['submission_data'] = ['_truncated' => 'Request payload exceeded the 64 KB log limit.'];
            }
            $encoded = wp_json_encode($logData) ?: '{}';
        }

        if (strlen($responseData) > self::MAX_BODY_BYTES) {
            $responseData = mb_substr($responseData, 0, self::MAX_BODY_BYTES) . ' [TRUNCATED]';
        }

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            DatabaseManager::getTableName(),
            [
                'success'       => in_array($responseCode, [200, 201, 202, 204], true) ? 1 : 0,
                'request_url'   => $this->redactSensitiveUrl($requestUrl),
                'request_data'  => $encoded,
                'response_data' => $responseData,
                'response_code' => $responseCode,
                'created_at'    => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Retrieves all log entries from the custom table, ordered newest-first.
     *
     * Used by CSV/JSON export where the full dataset is needed. For the analytics
     * page UI use getLogsPaginated() instead.
     *
     * @return array<int, array<string, mixed>>
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
     * Returns a single page of log rows, newest-first, with optional filters.
     *
     * @param int    $page        1-based page number.
     * @param int    $perPage     Rows per page (clamped to 1–100 by callers).
     * @param bool   $errorsOnly  When true, only rows with success = 0 are returned.
     * @param string $filterYear  Four-digit year string, or '' for all years.
     * @param string $filterMonth Two-digit month string ('01'–'12'), or '' for all months.
     * @param string $search      Substring searched against the request_data column.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLogsPaginated(
        int $page = 1,
        int $perPage = 10,
        bool $errorsOnly = false,
        string $filterYear = '',
        string $filterMonth = '',
        string $search = ''
    ): array {
        global $wpdb;

        $table  = DatabaseManager::getTableName();
        $offset = ($page - 1) * $perPage;

        [$where, $values] = $this->buildWhereClause($errorsOnly, $filterYear, $filterMonth, $search);

        $values[] = $perPage;
        $values[] = $offset;

        $sql = "SELECT * FROM `{$table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";

        $results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare($sql, $values), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Returns the total number of rows matching the given filters.
     *
     * @param bool   $errorsOnly
     * @param string $filterYear
     * @param string $filterMonth
     * @param string $search
     *
     * @return int
     */
    public function getLogCount(
        bool $errorsOnly = false,
        string $filterYear = '',
        string $filterMonth = '',
        string $search = ''
    ): int {
        global $wpdb;

        $table = DatabaseManager::getTableName();

        [$where, $values] = $this->buildWhereClause($errorsOnly, $filterYear, $filterMonth, $search);

        $sql = "SELECT COUNT(*) FROM `{$table}` {$where}";

        $count = $values
            ? $wpdb->get_var($wpdb->prepare($sql, $values)) // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_var($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return (int) $count;
    }

    /**
     * Returns the distinct calendar years and months that have log entries,
     * for populating the analytics page filter dropdowns.
     *
     * @param bool $errorsOnly When true, only considers error rows.
     *
     * @return array{years: list<string>, months: list<string>}
     */
    public function getDistinctDates(bool $errorsOnly = false): array
    {
        global $wpdb;

        $table = DatabaseManager::getTableName();
        $where = $errorsOnly ? 'WHERE success = 0' : '';

        $rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') FROM `{$table}` {$where} ORDER BY 1 DESC"
        );

        if (!is_array($rows)) {
            return ['years' => [], 'months' => []];
        }

        $years  = [];
        $months = [];

        foreach ($rows as $ym) {
            if (!is_string($ym) || strlen($ym) < 7) continue;
            $y = substr($ym, 0, 4);
            $m = substr($ym, 5, 2);
            if (!in_array($y, $years, true))  $years[]  = $y;
            if (!in_array($m, $months, true)) $months[] = $m;
        }

        sort($months);

        return ['years' => $years, 'months' => $months];
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

    /**
     * Replaces values of known-sensitive field names in submission_data with [REDACTED].
     *
     * @param array<string, mixed> $requestData
     *
     * @return array<string, mixed>
     */
    private function redactSensitiveFields(array $requestData): array
    {
        if (!isset($requestData['submission_data']) || !is_array($requestData['submission_data'])) {
            return $requestData;
        }

        foreach ($requestData['submission_data'] as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE_FIELD_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $requestData['submission_data'][$key] = '[REDACTED]';
                    break;
                }
            }
        }

        return $requestData;
    }

    /**
     * Redacts query-string parameters whose names match sensitive field patterns
     * so tokens embedded in webhook URLs are not persisted to the log table.
     */
    private function redactSensitiveUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (empty($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);
        $redacted = false;
        foreach ($params as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE_FIELD_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern)) {
                    $params[$key] = '[REDACTED]';
                    $redacted     = true;
                    break;
                }
            }
        }

        if (!$redacted) {
            return $url;
        }

        $scheme   = isset($parts['scheme'])   ? $parts['scheme'] . '://' : '';
        $host     = $parts['host']   ?? '';
        $port     = isset($parts['port'])     ? ':' . $parts['port']     : '';
        $path     = $parts['path']   ?? '';
        $query    = '?' . http_build_query($params);
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $fragment;
    }

    /**
     * Builds a SQL WHERE clause and a corresponding ordered values array from
     * the provided filter parameters.
     *
     * @param bool   $errorsOnly
     * @param string $filterYear
     * @param string $filterMonth
     * @param string $search
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhereClause(
        bool $errorsOnly,
        string $filterYear,
        string $filterMonth,
        string $search
    ): array {
        global $wpdb;

        $conditions = [];
        $values     = [];

        if ($errorsOnly) {
            $conditions[] = 'success = 0';
        }

        if ($filterYear !== '' && ctype_digit($filterYear) && strlen($filterYear) === 4) {
            $conditions[] = 'YEAR(created_at) = %d';
            $values[]     = (int) $filterYear;
        }

        if ($filterMonth !== '' && ctype_digit($filterMonth) && (int) $filterMonth >= 1 && (int) $filterMonth <= 12) {
            $conditions[] = 'MONTH(created_at) = %d';
            $values[]     = (int) $filterMonth;
        }

        if ($search !== '') {
            $conditions[] = 'request_data LIKE %s';
            $values[]     = '%' . $wpdb->esc_like($search) . '%';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }
}
