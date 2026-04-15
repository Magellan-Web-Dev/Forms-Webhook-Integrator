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
     * WordPress option key: whether submissions from outside the US are blocked.
     */
    public const OPTION_BLOCK_OUTSIDE_US = 'FWI_block_outside_us';

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
     */
    public const OPTION_LAST_TEST_RESULT = 'FWI_last_test_result';

    /**
     * WordPress option key: per-form URL query parameter and header overrides.
     *
     * Stored as an associative array keyed by form name, where each value is an
     * array with 'query_params' and 'headers' sub-arrays (each containing the
     * standard {key, value} pair format used by the global settings).
     */
    public const OPTION_FORM_OVERRIDES = 'FWI_form_overrides';

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
     * Returns the raw (un-parameterised) webhook endpoint URL.
     *
     * @return string The stored URL, or an empty string if not yet configured.
     */
    public function getWebhookUrl(): string
    {
        return (string) get_option(self::OPTION_WEBHOOK_URL, '');
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
     * Builds and returns the fully-qualified webhook URL, including any stored
     * query parameters appended as a query string.
     *
     * Returns an empty string if no webhook URL has been configured, so callers
     * should check for an empty return value before making a request.
     *
     * @return string The complete webhook URL with query string, or empty string.
     */
    public function buildWebhookUrl(): string
    {
        $url    = $this->getWebhookUrl();
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
     * Always returns a well-typed array with 'query_params' and 'headers' keys so
     * callers never need to guard against missing sub-keys.
     *
     * @param string $formName The Elementor form name to look up.
     *
     * @return array{query_params: array<int, array{key: string, value: string}>, headers: array<int, array{key: string, value: string}>}
     */
    public function getFormOverride(string $formName): array
    {
        $overrides = $this->getFormOverrides();
        $entry     = $overrides[$formName] ?? [];

        return [
            'query_params' => is_array($entry['query_params'] ?? null) ? $entry['query_params'] : [],
            'headers'      => is_array($entry['headers']      ?? null) ? $entry['headers']      : [],
        ];
    }

    /**
     * Returns the result of the most recent webhook connectivity test.
     *
     * Keys: success (bool), message (string), tested_url (string), time (string).
     *
     * @return array<string, mixed>
     */
    public function getLastTestResult(): array
    {
        $result = get_option(self::OPTION_LAST_TEST_RESULT, []);
        return is_array($result) ? $result : [];
    }

    /**
     * Persists the outcome of a webhook connectivity test to the options table.
     *
     * @param bool   $success    True when the endpoint returned 200 or 201.
     * @param string $message    Human-readable outcome description.
     * @param string $testedUrl  The full URL (including query string) that was tested.
     *
     * @return void
     */
    public function saveLastTestResult(bool $success, string $message, string $testedUrl): void
    {
        update_option(self::OPTION_LAST_TEST_RESULT, [
            'success'    => $success,
            'message'    => $message,
            'tested_url' => $testedUrl,
            'time'       => current_time('mysql'),
        ]);
    }

    /**
     * Removes any stored last-test result, used when the webhook URL changes.
     *
     * @return void
     */
    public function clearLastTestResult(): void
    {
        delete_option(self::OPTION_LAST_TEST_RESULT);
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
        update_option(self::OPTION_ACTIVE, !empty($data['fwi_active']));

        update_option(
            self::OPTION_WEBHOOK_URL,
            esc_url_raw((string) ($data['fwi_webhook_url'] ?? ''))
        );

        update_option(
            self::OPTION_CLIENT_FIRST_NAME,
            sanitize_text_field((string) ($data['fwi_client_first_name'] ?? ''))
        );

        update_option(
            self::OPTION_CLIENT_LAST_NAME,
            sanitize_text_field((string) ($data['fwi_client_last_name'] ?? ''))
        );

        update_option(
            self::OPTION_BLOCK_OUTSIDE_US,
            isset($data['fwi_block_outside_us']) && $data['fwi_block_outside_us'] === '1'
        );

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

        // Preserve existing overrides for excluded forms (not present in POST) so
        // their configuration survives while they are excluded and is restored when
        // they are re-added to the active list.
        $existingOverrides = $this->getFormOverrides();

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

                $existingOverrides[$formName] = [
                    'query_params' => $queryParams,
                    'headers'      => $headers,
                ];
            }
        }

        update_option(self::OPTION_FORM_OVERRIDES, $existingOverrides);
    }
}
