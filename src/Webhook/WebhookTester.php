<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;

/**
 * Sends a lightweight connectivity test POST to the configured webhook endpoint.
 *
 * The test payload is {"msg": "Webhook submission test"}. Custom HTTP headers
 * from settings are included. The outcome is persisted via SettingsManager so
 * the settings page can display the last-known test status on every visit.
 */
final class WebhookTester
{
    /**
     * @param SettingsManager $settings Used to read the URL, query params,
     *                                  and custom headers, and to persist the result.
     */
    public function __construct(
        private readonly SettingsManager $settings
    ) {}

    /**
     * Performs the test request and saves the result.
     *
     * Uses the fully-qualified webhook URL (base URL + query params) and any
     * custom headers currently saved in settings. Considers HTTP 200 and 201
     * as success; any transport error or other status code is a failure.
     *
     * @param string|null $url Override the saved URL (e.g. when testing an
     *                         unsaved value from the settings form). When null
     *                         the saved URL is used.
     *
     * @return array{success: bool, message: string}
     */
    public function test(?string $url = null): array
    {
        $url = $url ?? $this->settings->buildWebhookUrl();

        if (empty($url)) {
            return ['success' => false, 'message' => 'No webhook URL configured.'];
        }

        $headers = ['Content-Type' => 'application/json'];

        foreach ($this->settings->getWebhookHeaders() as $header) {
            if (!empty($header['key'])) {
                $headers[$header['key']] = $header['value'];
            }
        }

        $response = wp_remote_post($url, [
            'body'    => (string) wp_json_encode(['msg' => 'Webhook submission test', 'url' => home_url()]),
            'headers' => $headers,
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            $result = [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        } else {
            $code    = (int) wp_remote_retrieve_response_code($response);
            $success = ($code === 200 || $code === 201);
            $result  = [
                'success' => $success,
                'message' => 'HTTP ' . $code . ($success ? ' — Test passed.' : ' — Test failed.'),
            ];
        }

        $this->settings->saveLastTestResult(
            success: $result['success'],
            message: $result['message'],
            testedUrl: $url
        );

        return $result;
    }
}
