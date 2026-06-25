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
     * Performs a test POST to the given URL and saves the outcome per webhook index.
     *
     * Uses any custom headers currently saved in settings. Considers HTTP 200,
     * 201, 202, and 204 as success; any transport error or other code is a failure.
     *
     * @param string|null $url          Override the URL to test. When null, uses the
     *                                  saved URL for the given webhook index.
     * @param int         $webhookIndex 0-based index into the webhooks array; used to
     *                                  persist the result to the correct slot.
     *
     * @return array{success: bool, message: string}
     */
    public function test(?string $url = null, int $webhookIndex = 0): array
    {
        if ($url === null) {
            $webhooks = $this->settings->getWebhooks();
            $webhook  = $webhooks[$webhookIndex] ?? null;
            $url      = $webhook !== null ? $this->settings->buildWebhookUrlForWebhook($webhook) : '';
        }

        if (empty($url)) {
            return ['success' => false, 'message' => 'No webhook URL configured.'];
        }

        $headers = ['Content-Type' => 'application/json'];

        foreach ($this->settings->getWebhookHeaders() as $header) {
            if (!empty($header['key'])) {
                $headers[$header['key']] = $header['value'];
            }
        }

        $response = wp_safe_remote_post($url, [
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
            $success = in_array($code, [200, 201, 202, 204], true);
            $result  = [
                'success' => $success,
                'message' => 'HTTP ' . $code . ($success ? ' — Test passed.' : ' — Test failed.'),
            ];
        }

        $this->settings->saveWebhookTestResult(
            webhookIndex: $webhookIndex,
            success: $result['success'],
            message: $result['message'],
            testedUrl: $url
        );

        return $result;
    }
}
