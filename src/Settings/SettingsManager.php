<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Centralised read/write layer for all plugin options.
 *
 * All option keys are defined as public constants so that other classes can
 * reference them without hard-coding strings. Every getter casts the raw
 * database value to the expected PHP type so callers always receive a
 * well-typed result regardless of what WordPress serialised internally.
 */
final class SettingsManager
{
    /**
     * WordPress option key: whether the webhook is currently active.
     */
    public const OPTION_ACTIVE = 'FWI_active';

    /**
     * WordPress option key: the base webhook endpoint URL.
     */
    public const OPTION_WEBHOOK_URL = 'FWI_webhook_url';

    /**
     * WordPress option key: an ordered list of URL query-parameter pairs to
     * append to every webhook request.
     */
    public const OPTION_QUERY_PARAMS = 'FWI_query_params';

    /**
     * WordPress option key: the client's first name included in the payload.
     */
    public const OPTION_CLIENT_FIRST_NAME = 'FWI_client_first_name';

    /**
     * WordPress option key: the client's last name included in the payload.
     */
    public const OPTION_CLIENT_LAST_NAME = 'FWI_client_last_name';

    /**
     * WordPress option key: an optional client identifier embedded in the
     * website_info.client block of every payload.
     */
    public const OPTION_CLIENT_ID = 'FWI_client_id';

    /**
     * WordPress option key: an optional website identifier embedded in the
     * website_info block of every payload.
     */
    public const OPTION_WEBSITE_ID = 'FWI_website_id';

    /**
     * WordPress option key: whether submissions from outside the US are blocked.
     */
    public const OPTION_BLOCK_OUTSIDE_US = 'FWI_block_outside_us';

    /**
     * WordPress option key: what happens when a webhook delivery fails for an
     * Elementor form submission. 'retry' (default) shows success to the visitor
     * and retries the failed deliveries in the background; 'show_error' surfaces
     * the error on the form. Submissions via the fwi_submission action or
     * fwi_submit_form() are unaffected by this setting.
     */
    public const OPTION_FAILURE_MODE = 'FWI_failure_mode';

    /**
     * WordPress option key: list of Elementor form names excluded from the webhook.
     */
    public const OPTION_EXCLUDED_FORMS = 'FWI_excluded_forms';

    /**
     * WordPress option key: custom HTTP headers sent with every webhook request.
     */
    public const OPTION_WEBHOOK_HEADERS = 'FWI_webhook_headers';

    /**
     * WordPress option key: result of the most recent webhook connectivity test.
     * @deprecated Still written/read for backward compat; prefer OPTION_WEBHOOK_TEST_RESULTS.
     */
    public const OPTION_LAST_TEST_RESULT = 'FWI_last_test_result';

    /**
     * WordPress option key: ordered array of webhook endpoint objects.
     *
     * Each element is an associative array with 'url' (string) and 'label' (string) keys.
     * When not yet set, the plugin falls back to the legacy OPTION_WEBHOOK_URL single-URL option.
     */
    public const OPTION_WEBHOOKS = 'FWI_webhooks';

    /**
     * WordPress option key: per-index connectivity test results.
     *
     * Stored as an array keyed by webhook index (0, 1, 2, …). Each value is an
     * associative array with 'success', 'message', 'tested_url', and 'time' keys.
     */
    public const OPTION_WEBHOOK_TEST_RESULTS = 'FWI_webhook_test_results';

    /**
     * WordPress option key: per-form URL query parameter and header overrides.
     *
     * Stored as an associative array keyed by form name, where each value is an
     * array with 'query_params', 'headers', and 'include_page_params' sub-keys.
     */
    public const OPTION_FORM_OVERRIDES = 'FWI_form_overrides';

    /**
     * WordPress option key: whether URL parameters from the submitting page are
     * appended to the webhook URL on every request (global default).
     */
    public const OPTION_INCLUDE_PAGE_PARAMS = 'FWI_include_page_params';

    /**
     * WordPress option key: how many months of log rows to retain before purging.
     */
    public const OPTION_LOG_RETENTION_MONTHS = 'FWI_log_retention_months';

    /**
     * WordPress option key: whether the read-only analytics REST API is active.
     */
    public const OPTION_ANALYTICS_API_ACTIVE = 'FWI_analytics_api_active';

    /**
     * WordPress option key: the bearer token required by the analytics REST API.
     */
    public const OPTION_ANALYTICS_API_KEY = 'FWI_analytics_api_key';

    /**
     * Returns whether the webhook integration is currently active.
     *
     * @return bool True when the webhook hook is registered; false when disabled.
     */
    public function isActive(): bool
    {
        return (bool) get_option(self::OPTION_ACTIVE, false);
    }

    /**
     * Returns all configured webhook endpoint objects.
     *
     * Each element is an associative array with 'url' (string) and 'label' (string) keys.
     * Falls back to the legacy single-URL option when the new option is not yet saved,
     * so existing installations continue to work without any settings re-save.
     *
     * @return array<int, array{url: string, label: string}>
     */
    public function getWebhooks(): array
    {
        $webhooks = get_option(self::OPTION_WEBHOOKS, null);

        if (is_array($webhooks)) {
            return array_values(array_filter($webhooks, fn($w) => is_array($w)));
        }

        // Legacy fallback: migrate single URL to a one-element array (in memory only).
        $legacyUrl = (string) get_option(self::OPTION_WEBHOOK_URL, '');
        if ($legacyUrl !== '') {
            return [['url' => $legacyUrl, 'label' => '']];
        }

        return [];
    }

    /**
     * Returns the raw (un-parameterised) webhook endpoint URL.
     *
     * Returns the first webhook's URL for backward compatibility with code that
     * still calls this method. Prefer getWebhooks() for new code.
     *
     * @return string The stored URL, or an empty string if not yet configured.
     */
    public function getWebhookUrl(): string
    {
        $webhooks = $this->getWebhooks();
        return (string) ($webhooks[0]['url'] ?? '');
    }

    /**
     * Builds and returns the fully-qualified URL for a specific webhook object,
     * including the stored global query parameters as a query string.
     *
     * @param array{url: string, label: string} $webhook A single webhook element from getWebhooks().
     *
     * @return string The complete URL, or empty string if the webhook URL is blank.
     */
    public function buildWebhookUrlForWebhook(array $webhook): string
    {
        $url    = (string) ($webhook['url'] ?? '');
        $params = $this->getQueryParams();

        if (empty($url)) {
            return '';
        }

        if (!empty($params)) {
            $queryArray = array_column($params, 'value', 'key');
            $url        = add_query_arg($queryArray, $url);
        }

        return $url;
    }

    /**
     * Returns the list of URL query-parameter pairs configured by the admin.
     *
     * Each element is an associative array with 'key' and 'value' keys.
     *
     * @return array<int, array{key: string, value: string}>
     */
    public function getQueryParams(): array
    {
        $params = get_option(self::OPTION_QUERY_PARAMS, []);
        return is_array($params) ? $params : [];
    }

    /**
     * Builds and returns the fully-qualified URL for the first webhook, including
     * any stored query parameters. Kept for backward compatibility; prefer
     * buildWebhookUrlForWebhook() with a specific webhook element.
     *
     * @return string The complete URL with query string, or empty string.
     */
    public function buildWebhookUrl(): string
    {
        $webhooks = $this->getWebhooks();
        if (empty($webhooks)) {
            return '';
        }
        return $this->buildWebhookUrlForWebhook($webhooks[0]);
    }

    /**
     * Returns the client's first name to be embedded in every webhook payload.
     *
     * @return string The stored first name, or an empty string if not configured.
     */
    public function getClientFirstName(): string
    {
        return (string) get_option(self::OPTION_CLIENT_FIRST_NAME, '');
    }

    /**
     * Returns the client's last name to be embedded in every webhook payload.
     *
     * @return string The stored last name, or an empty string if not configured.
     */
    public function getClientLastName(): string
    {
        return (string) get_option(self::OPTION_CLIENT_LAST_NAME, '');
    }

    /**
     * Returns the optional client identifier embedded in the website_info.client
     * block of every payload.
     *
     * @return string The stored client ID, or an empty string if not configured.
     */
    public function getClientId(): string
    {
        return (string) get_option(self::OPTION_CLIENT_ID, '');
    }

    /**
     * Returns the optional website identifier embedded in the website_info
     * block of every payload.
     *
     * @return string The stored website ID, or an empty string if not configured.
     */
    public function getWebsiteId(): string
    {
        return (string) get_option(self::OPTION_WEBSITE_ID, '');
    }

    /**
     * Returns whether form submissions originating from outside the United States
     * should be blocked at the geolocation check.
     *
     * Defaults to true (blocking enabled) when the option has not yet been saved.
     *
     * @return bool True to block non-US submissions; false to allow all.
     */
    public function isBlockOutsideUs(): bool
    {
        return (bool) get_option(self::OPTION_BLOCK_OUTSIDE_US, true);
    }

    /**
     * Returns the failure mode for Elementor form submissions.
     *
     * Whitelisted to the two known values: any unset or corrupt stored value
     * degrades to the default 'retry'.
     *
     * @return string Either 'retry' or 'show_error'.
     */
    public function getFailureMode(): string
    {
        $mode = (string) get_option(self::OPTION_FAILURE_MODE, 'retry');
        return $mode === 'show_error' ? 'show_error' : 'retry';
    }

    /**
     * Returns the list of Elementor form names that are excluded from the webhook.
     *
     * Forms in this list will not trigger a webhook POST even when the integration
     * is active.
     *
     * @return array<int, string> Sanitised form name strings.
     */
    public function getExcludedForms(): array
    {
        $forms = get_option(self::OPTION_EXCLUDED_FORMS, []);
        return is_array($forms) ? $forms : [];
    }

    /**
     * Returns the list of custom HTTP header pairs sent with every webhook request.
     *
     * Each element is an associative array with 'key' and 'value' keys.
     *
     * @return array<int, array{key: string, value: string}>
     */
    public function getWebhookHeaders(): array
    {
        $headers = get_option(self::OPTION_WEBHOOK_HEADERS, []);
        return is_array($headers) ? $headers : [];
    }

    /**
     * Returns whether URL parameters from the submitting page should be appended
     * to the webhook URL globally (applies to all forms).
     *
     * @return bool
     */
    public function isIncludePageParams(): bool
    {
        return (bool) get_option(self::OPTION_INCLUDE_PAGE_PARAMS, false);
    }

    /**
     * Returns all per-form URL query parameter and header overrides.
     *
     * The array is keyed by form name. Each value is an associative array with
     * 'query_params' and 'headers' keys, both holding arrays of {key, value} pairs.
     *
     * @return array<string, array{query_params: array<int, array{key: string, value: string}>, headers: array<int, array{key: string, value: string}>}>
     */
    public function getFormOverrides(): array
    {
        $overrides = get_option(self::OPTION_FORM_OVERRIDES, []);
        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Returns the URL query parameter and header overrides for a single form.
     *
     * Always returns a well-typed array with 'query_params', 'headers',
     * 'include_page_params', and 'form_id' keys so callers never need to guard
     * against missing sub-keys.
     *
     * @param string $formName The Elementor form name to look up.
     *
     * @return array{query_params: array<int, array{key: string, value: string}>, headers: array<int, array{key: string, value: string}>, include_page_params: bool, form_id: string}
     */
    public function getFormOverride(string $formName): array
    {
        $overrides = $this->getFormOverrides();
        $entry     = $overrides[$formName] ?? [];

        return [
            'query_params'        => is_array($entry['query_params'] ?? null) ? $entry['query_params'] : [],
            'headers'             => is_array($entry['headers']      ?? null) ? $entry['headers']      : [],
            'include_page_params' => (bool) ($entry['include_page_params'] ?? false),
            'form_id'             => (string) ($entry['form_id'] ?? ''),
        ];
    }

    /**
     * Returns the connectivity test result for a specific webhook by index.
     *
     * Keys: success (bool), message (string), tested_url (string), time (string).
     *
     * @param int $webhookIndex 0-based index into the webhooks array.
     *
     * @return array<string, mixed>
     */
    public function getWebhookTestResult(int $webhookIndex): array
    {
        $all = get_option(self::OPTION_WEBHOOK_TEST_RESULTS, []);
        $all = is_array($all) ? $all : [];
        return is_array($all[$webhookIndex] ?? null) ? $all[$webhookIndex] : [];
    }

    /**
     * Returns the result of the most recent connectivity test for the first webhook.
     *
     * Preserved for backward compatibility. Keys: success, message, tested_url, time.
     *
     * @return array<string, mixed>
     */
    public function getLastTestResult(): array
    {
        return $this->getWebhookTestResult(0);
    }

    /**
     * Returns whether the read-only analytics REST API is currently active.
     *
     * @return bool
     */
    public function isAnalyticsApiActive(): bool
    {
        return (bool) get_option(self::OPTION_ANALYTICS_API_ACTIVE, false);
    }

    /**
     * Persists the analytics API active state.
     *
     * @param bool $active
     *
     * @return void
     */
    public function setAnalyticsApiActive(bool $active): void
    {
        update_option(self::OPTION_ANALYTICS_API_ACTIVE, $active);
    }

    /**
     * Returns the stored analytics API key, or an empty string if none has been
     * generated yet.
     *
     * @return string
     */
    public function getAnalyticsApiKey(): string
    {
        return (string) get_option(self::OPTION_ANALYTICS_API_KEY, '');
    }

    /**
     * Generates a new 40-character alphanumeric API key, persists it, and
     * returns it.
     *
     * @return string The newly-generated key.
     */
    public function generateAnalyticsApiKey(): string
    {
        $key = wp_generate_password(40, false);
        update_option(self::OPTION_ANALYTICS_API_KEY, $key);
        return $key;
    }

    /**
     * Returns the number of months log rows are retained before being purged.
     *
     * Clamped to the range 1–24. Defaults to 3 when unset.
     *
     * @return int
     */
    public function getLogRetentionMonths(): int
    {
        $value = (int) get_option(self::OPTION_LOG_RETENTION_MONTHS, 3);
        return max(1, min(24, $value));
    }

    /**
     * Persists the outcome of a connectivity test for a specific webhook index.
     *
     * @param int    $webhookIndex 0-based index into the webhooks array.
     * @param bool   $success      True when the endpoint returned 200/201/202/204.
     * @param string $message      Human-readable outcome description.
     * @param string $testedUrl    The full URL (including query string) that was tested.
     *
     * @return void
     */
    public function saveWebhookTestResult(int $webhookIndex, bool $success, string $message, string $testedUrl): void
    {
        $all = get_option(self::OPTION_WEBHOOK_TEST_RESULTS, []);
        $all = is_array($all) ? $all : [];

        $all[$webhookIndex] = [
            'success'    => $success,
            'message'    => $message,
            'tested_url' => $testedUrl,
            'time'       => current_time('mysql'),
        ];

        update_option(self::OPTION_WEBHOOK_TEST_RESULTS, $all);
    }

    /**
     * Persists the outcome of a connectivity test for the first webhook.
     *
     * Preserved for backward compatibility.
     *
     * @param bool   $success
     * @param string $message
     * @param string $testedUrl
     *
     * @return void
     */
    public function saveLastTestResult(bool $success, string $message, string $testedUrl): void
    {
        $this->saveWebhookTestResult(0, $success, $message, $testedUrl);
    }

    /**
     * Removes any stored test result for the first webhook, used when its URL changes.
     *
     * @return void
     */
    public function clearLastTestResult(): void
    {
        $all = get_option(self::OPTION_WEBHOOK_TEST_RESULTS, []);
        $all = is_array($all) ? $all : [];
        unset($all[0]);
        update_option(self::OPTION_WEBHOOK_TEST_RESULTS, $all);
    }

    /**
     * Validates, sanitises, and persists all plugin settings from a form POST.
     *
     * Each value is sanitised with the appropriate WordPress helper before being
     * written to the options table. Unknown keys in $data are ignored.
     *
     * @param array<string, mixed> $data Raw POST data, typically the contents of $_POST.
     *
     * @return void
     */
    public function save(array $data): void
    {
        // ── Multi-webhook endpoints ───────────────────────────────────────────
        $oldWebhooks = $this->getWebhooks();

        $newWebhooks = [];
        if (!empty($data['fwi_webhooks']) && is_array($data['fwi_webhooks'])) {
            foreach (array_values($data['fwi_webhooks']) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $url = esc_url_raw((string) ($entry['url'] ?? ''));
                if (!empty($url) && !wp_http_validate_url($url)) {
                    $url = '';
                }
                $newWebhooks[] = [
                    'url'   => $url,
                    'label' => sanitize_text_field((string) ($entry['label'] ?? '')),
                ];
            }
        }

        // Ensure at least one element (may be empty-URL placeholder).
        if (empty($newWebhooks)) {
            $newWebhooks = [['url' => '', 'label' => '']];
        }

        update_option(self::OPTION_WEBHOOKS, $newWebhooks);

        // Keep legacy single-URL option in sync with the first webhook for
        // any external code that still reads FWI_webhook_url directly.
        $firstUrl = (string) ($newWebhooks[0]['url'] ?? '');
        update_option(self::OPTION_WEBHOOK_URL, $firstUrl);

        // Clear test results for webhooks whose URL changed or that no longer exist.
        $testResults = get_option(self::OPTION_WEBHOOK_TEST_RESULTS, []);
        $testResults = is_array($testResults) ? $testResults : [];

        foreach ($newWebhooks as $idx => $webhook) {
            $oldUrl = (string) ($oldWebhooks[$idx]['url'] ?? '');
            if ($webhook['url'] !== $oldUrl && isset($testResults[$idx])) {
                unset($testResults[$idx]);
            }
        }
        foreach (array_keys($testResults) as $idx) {
            if (!isset($newWebhooks[$idx])) {
                unset($testResults[$idx]);
            }
        }
        update_option(self::OPTION_WEBHOOK_TEST_RESULTS, $testResults);

        // Cannot be active without at least one URL — prevent silent data loss.
        $hasAnyUrl = !empty(array_filter(array_column($newWebhooks, 'url')));
        update_option(self::OPTION_ACTIVE, !empty($data['fwi_active']) && $hasAnyUrl);

        update_option(
            self::OPTION_CLIENT_FIRST_NAME,
            sanitize_text_field((string) ($data['fwi_client_first_name'] ?? ''))
        );

        update_option(
            self::OPTION_CLIENT_LAST_NAME,
            sanitize_text_field((string) ($data['fwi_client_last_name'] ?? ''))
        );

        update_option(
            self::OPTION_CLIENT_ID,
            sanitize_text_field((string) ($data['fwi_client_id'] ?? ''))
        );

        update_option(
            self::OPTION_WEBSITE_ID,
            sanitize_text_field((string) ($data['fwi_website_id'] ?? ''))
        );

        update_option(
            self::OPTION_BLOCK_OUTSIDE_US,
            isset($data['fwi_block_outside_us']) && $data['fwi_block_outside_us'] === '1'
        );

        update_option(
            self::OPTION_INCLUDE_PAGE_PARAMS,
            isset($data['fwi_include_page_params']) && $data['fwi_include_page_params'] === '1'
        );

        update_option(
            self::OPTION_FAILURE_MODE,
            (($data['fwi_failure_mode'] ?? '') === 'show_error') ? 'show_error' : 'retry'
        );

        $retentionMonths = isset($data['fwi_log_retention_months']) ? (int) $data['fwi_log_retention_months'] : 3;
        update_option(self::OPTION_LOG_RETENTION_MONTHS, max(1, min(24, $retentionMonths)));

        $queryParams = [];
        if (!empty($data['fwi_query_params']) && is_array($data['fwi_query_params'])) {
            foreach ($data['fwi_query_params'] as $param) {
                if (!empty($param['key'])) {
                    $queryParams[] = [
                        'key'   => sanitize_text_field((string) $param['key']),
                        'value' => sanitize_text_field((string) ($param['value'] ?? '')),
                    ];
                }
            }
        }
        update_option(self::OPTION_QUERY_PARAMS, $queryParams);

        $webhookHeaders = [];
        if (!empty($data['fwi_webhook_headers']) && is_array($data['fwi_webhook_headers'])) {
            foreach ($data['fwi_webhook_headers'] as $header) {
                if (!empty($header['key'])) {
                    $webhookHeaders[] = [
                        'key'   => sanitize_text_field((string) $header['key']),
                        'value' => sanitize_text_field((string) ($header['value'] ?? '')),
                    ];
                }
            }
        }
        update_option(self::OPTION_WEBHOOK_HEADERS, $webhookHeaders);

        $excludedForms = [];
        if (!empty($data['fwi_excluded_forms']) && is_array($data['fwi_excluded_forms'])) {
            foreach ($data['fwi_excluded_forms'] as $form) {
                $sanitized = sanitize_text_field((string) $form);
                if ($sanitized !== '') {
                    $excludedForms[] = $sanitized;
                }
            }
        }
        update_option(self::OPTION_EXCLUDED_FORMS, $excludedForms);

        // Track which form override blocks were actually rendered on the page so
        // we can distinguish "intentionally cleared" from "excluded/not rendered."
        $renderedForms = [];
        if (!empty($data['fwi_rendered_form_overrides']) && is_array($data['fwi_rendered_form_overrides'])) {
            foreach ($data['fwi_rendered_form_overrides'] as $name) {
                $sanitized = sanitize_text_field((string) $name);
                if ($sanitized !== '') {
                    $renderedForms[$sanitized] = true;
                }
            }
        }

        // Parse submitted per-form overrides.
        $postOverrides = [];
        if (!empty($data['fwi_form_overrides']) && is_array($data['fwi_form_overrides'])) {
            foreach ($data['fwi_form_overrides'] as $rawName => $override) {
                $formName = sanitize_text_field((string) $rawName);
                if ($formName === '') {
                    continue;
                }

                $queryParams = [];
                if (!empty($override['query_params']) && is_array($override['query_params'])) {
                    foreach ($override['query_params'] as $param) {
                        if (!empty($param['key'])) {
                            $queryParams[] = [
                                'key'   => sanitize_text_field((string) $param['key']),
                                'value' => sanitize_text_field((string) ($param['value'] ?? '')),
                            ];
                        }
                    }
                }

                $headers = [];
                if (!empty($override['headers']) && is_array($override['headers'])) {
                    foreach ($override['headers'] as $header) {
                        if (!empty($header['key'])) {
                            $headers[] = [
                                'key'   => sanitize_text_field((string) $header['key']),
                                'value' => sanitize_text_field((string) ($header['value'] ?? '')),
                            ];
                        }
                    }
                }

                $postOverrides[$formName] = [
                    'query_params'        => $queryParams,
                    'headers'             => $headers,
                    'include_page_params' => isset($override['include_page_params']) && $override['include_page_params'] === '1',
                    'form_id'             => sanitize_text_field((string) ($override['form_id'] ?? '')),
                ];
            }
        }

        // Start from existing overrides to preserve excluded/non-rendered forms,
        // then apply rendered forms from POST (empty arrays clear them correctly).
        $mergedOverrides = $this->getFormOverrides();
        foreach ($renderedForms as $formName => $_) {
            $mergedOverrides[$formName] = $postOverrides[$formName] ?? [
                'query_params'        => [],
                'headers'             => [],
                'include_page_params' => false,
                'form_id'             => '',
            ];
        }

        update_option(self::OPTION_FORM_OVERRIDES, $mergedOverrides);
    }
}
