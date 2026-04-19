<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Database;

if (!defined('ABSPATH')) exit;

/**
 * Manages the plugin's custom database table.
 *
 * Responsible for:
 *  - Defining the table schema and providing the fully-qualified table name.
 *  - Creating or upgrading the table via dbDelta on plugin activation and on
 *    the plugins_loaded hook (for updates that change the schema version).
 *  - Purging log rows older than three months, intended to be called by a
 *    daily WP-Cron event registered in the main plugin file.
 *
 * Table schema:
 *  - id            BIGINT(20) UNSIGNED  Auto-incrementing primary key.
 *  - success       TINYINT(1)           1 = response was 200, 201, 202, or 204; 0 = anything else.
 *  - request_url   TEXT                 The fully-qualified webhook URL (base + query params)
 *                                       used for the request.
 *  - request_data  LONGTEXT             JSON payload sent to the webhook.
 *  - response_data LONGTEXT             Raw response body returned by the webhook.
 *                                       For transport-level errors this contains a
 *                                       JSON object of the form {"error": "message"}.
 *  - response_code INT                  HTTP status code; 0 means no response was
 *                                       received (transport error).
 *  - created_at    DATETIME             UTC timestamp of when the request was made.
 */
final class DatabaseManager
{
    /**
     * The un-prefixed portion of the table name. The full name is built by
     * prepending $wpdb->prefix at runtime.
     */
    private const TABLE_SUFFIX = 'FWI_webhook_logs';

    /**
     * WordPress option key used to track which schema version is installed.
     * Compared against DB_VERSION to decide whether dbDelta needs to run.
     */
    private const DB_VERSION_OPTION = 'FWI_db_version';

    /**
     * Current schema version. Increment this string whenever the table
     * definition changes so that maybeCreateTable() triggers a dbDelta run
     * for existing installations.
     */
    private const DB_VERSION = '5.0';

    /**
     * Returns the fully-qualified table name including the WordPress database prefix.
     *
     * @return string e.g. "wp_FWI_webhook_logs"
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Runs dbDelta to create or upgrade the table if the stored schema version
     * does not match DB_VERSION.
     *
     * Safe to call on every page load; the version check ensures dbDelta only
     * runs when genuinely needed. Called from Plugin::init() to handle plugin
     * updates where the activation hook does not re-fire.
     *
     * @return void
     */
    public static function maybeCreateTable(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        self::createTable();
    }

    /**
     * Unconditionally creates or upgrades the custom table using dbDelta.
     *
     * Also persists the current DB_VERSION to the options table so subsequent
     * calls to maybeCreateTable() skip the work. Called directly from the
     * plugin activation hook.
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        $tableName      = self::getTableName();
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta requires: two spaces before data type, no trailing comma on
        // last field, and "PRIMARY KEY  (col)" with two spaces before the paren.
        $sql = "CREATE TABLE {$tableName} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            success tinyint(1) NOT NULL DEFAULT 0,
            request_url text NOT NULL,
            request_data longtext NOT NULL,
            response_data longtext NOT NULL,
            response_code int NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_success (success),
            KEY idx_response_code (response_code),
            KEY idx_created_at (created_at)
            ) {$charsetCollate};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Deletes all log rows whose created_at timestamp is older than the configured
     * retention period. The retention duration is read from the FWI_log_retention_months
     * option (default 3, range 1–24 months).
     *
     * Intended to be called by a daily WP-Cron event (FWI_cleanup_old_logs).
     *
     * @return void
     */
    public static function purgeOldLogs(): void
    {
        global $wpdb;

        $months     = max(1, min(24, (int) get_option('FWI_log_retention_months', 3)));
        $tableName  = self::getTableName();
        $cutoffDate = gmdate('Y-m-d H:i:s', strtotime("-{$months} months", current_time('timestamp')));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$tableName}` WHERE created_at < %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $cutoffDate
            )
        );
    }
}
