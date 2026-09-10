<?php
/**
 * Minimal stand-in for WordPress's wp-admin/includes/upgrade.php.
 *
 * DatabaseManager::createTable() requires this file before calling dbDelta().
 * The test harness only needs the call to succeed — no schema is created.
 */

declare(strict_types=1);

if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true): array
    {
        return [];
    }
}
