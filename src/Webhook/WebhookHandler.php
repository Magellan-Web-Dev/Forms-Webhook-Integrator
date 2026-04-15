<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;

/**
 * Listens for form submissions via the fwi_submission action and forwards them
 * to the configured webhook.
 *
 * Hooks into the fwi_submission action only when the webhook is marked active in
 * settings. On each submission it assembles a structured JSON payload — including
 * website metadata, client info, sanitised field values, IP-based geolocation
 * data via ipapi.co, and a UTC timestamp — then POSTs it to the webhook URL.
 * All requests (successful and failed) are written to the log via WebhookLogger.
 *
 * External code can trigger a submission via:
 *   do_action('fwi_submission', $form_name, $form_fields);
 * where $form_name is a string and $form_fields is an associative array of
 * field names to field values.
 */
final class WebhookHandler
{
    /**
     * Records each webhook request and its outcome for display on the analytics page.
     *
     * @var WebhookLogger
     */
    private readonly WebhookLogger $logger;

    /**
     * Constructs the handler and its logging dependency.
     *
     * @param SettingsManager $settings Shared settings store used to read the webhook
     *                                  URL, client info, excluded forms, and the
     *                                  outside-US blocking flag.
     */
    public function __construct(
        private readonly SettingsManager $settings
    ) {
        $this->logger = new WebhookLogger();
    }

    /**
     * Registers the fwi_submission action hook when the webhook is active.
     *
     * Called once from {@see Plugin::init()}. When the webhook is disabled in
     * settings the hook is simply not registered, so no submission processing occurs.
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->settings->isActive()) {
            add_action('fwi_submission', [$this, 'handleFormSubmission'], 10, 4);
        }
    }

    /**
     * Processes a form submission and POSTs it to the webhook.
     *
     * Can be called directly or via do_action('fwi_submission', $form_name, $fields).
     * When called directly the return value indicates whether the webhook was sent
     * successfully, allowing callers (e.g. ElementorFormsBridge) to surface errors
     * back to the user. When invoked through do_action WordPress discards the return
     * value, which is fine for integrations that do not need feedback.
     *
     * Execution flow:
     *  1. Verify the form is not excluded.
     *  2. Build the full webhook URL (base URL + configured query params).
     *  3. Assemble the payload: website info, client data, sanitised fields,
     *     IP geolocation via ipapi.co, and a timestamp.
     *  4. If outside-US blocking is enabled and the submitter's country is not
     *     "United States", abort and return a failure result.
     *  5. JSON-encode the payload and POST it to the webhook.
     *  6. Log the outcome (success or failure) via WebhookLogger.
     *
     * @param string               $form_name       The name of the submitted form.
     * @param array<string, mixed> $fields          Associative array of field names to values.
     * @param array<string, mixed> $url_query       Optional extra query parameters to append to
     *                                              the webhook URL (merged after the base URL and
     *                                              settings-configured params are applied).
     * @param array<string, string> $request_headers Optional extra request headers (key → value).
     *                                              Merged after the settings-configured headers;
     *                                              caller-supplied values override settings values
     *                                              for any shared keys.
     *
     * @return array{ok: bool, msg: string} 'ok' is true on success, false on any
     *                                       failure. 'msg' contains a user-facing
     *                                       error description when 'ok' is false,
     *                                       and an empty string on success.
     */
    public function handleFormSubmission(string $form_name, array $fields, array $url_query = [], array $request_headers = []): array
    {
        // Check if this form is excluded
        if (in_array($form_name, $this->settings->getExcludedForms(), true)) {
            return ['ok' => true, 'msg' => ''];
        }

        // Build webhook URL (base URL + query params from settings, then caller-supplied params)
        $webhookUrl = $this->settings->buildWebhookUrl();
        if (empty($webhookUrl)) {
            return ['ok' => true, 'msg' => ''];
        }

        if (!empty($url_query)) {
            $webhookUrl = add_query_arg($url_query, $webhookUrl);
        }

        // Initialize form data array
        $form_data = [];

        // Add website data
        $form_data['website_info'] = [
            'name' => get_bloginfo('name'),
            'url'  => home_url(),
            'client' => [
                'first_name' => $this->settings->getClientFirstName(),
                'last_name'  => $this->settings->getClientLastName(),
            ],
        ];

        // Add form name
        $form_data['form_name'] = $form_name;

        // Sanitize and store submission fields
        foreach ($fields as $key => $value) {
            $form_data['submission_data'][$key] = sanitize_text_field((string) $value);
        }

        // Attempt to get user IP address
        $ip = null;

        if (isset($_SERVER['HTTP_CLIENT_IP']) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP']) && !empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['REMOTE_ADDR']) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Add client location data
        $client_key_name = 'client_location_data';

        if ($ip) {
            // Handle multiple IP addresses (proxies) — take the first one
            $ip = trim(explode(',', $ip)[0]);

            $ip_error_message = 'Unable to get client location information from the user IP address.';
            $ip_url           = 'https://ipapi.co/' . $ip . '/json';

            $ip_lookup_request = wp_remote_get($ip_url);

            if (!is_wp_error($ip_lookup_request)) {
                $ip_data    = wp_remote_retrieve_body($ip_lookup_request);
                $ip_decoded = json_decode($ip_data, true);

                if (isset($ip_decoded['error'])) {
                    $form_data[$client_key_name]['error'] = $ip_error_message;
                } else {
                    $ip_keys_to_parse = ['city', 'region', 'region_code', 'country_name', 'postal', 'latitude', 'longitude', 'timezone'];

                    foreach ($ip_keys_to_parse as $key) {
                        if (isset($ip_decoded[$key])) {
                            // Block submissions outside the US if the setting is enabled
                            if (
                                $this->settings->isBlockOutsideUs() &&
                                $key === 'country_name' &&
                                $ip_decoded[$key] !== 'United States'
                            ) {
                                return ['ok' => false, 'msg' => 'Form submissions cannot be made from a location outside of the United States.'];
                            }

                            $form_data[$client_key_name][$key] = sanitize_text_field($ip_decoded[$key]);
                        }
                    }
                }
            } else {
                $form_data[$client_key_name]['error'] = $ip_error_message;
            }
        } else {
            $ip = 'Unknown';
        }

        // Assign IP address field — value is 'Unknown' if it could not be acquired
        $form_data[$client_key_name]['ip'] = $ip;

        // Set timestamp
        $form_data['timestamp'] = [
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
        ];

        // Convert form data to JSON
        $json_data = json_encode($form_data);

        // Check for JSON encoding errors
        if ($json_data === false) {
            error_log('FWI JSON encoding error: ' . json_last_error_msg());
            return ['ok' => false, 'msg' => 'There was an issue compiling the submission data to send to the webhook.'];
        }

        // Build headers — Content-Type first, then settings headers, then caller-supplied headers
        $headers = ['Content-Type' => 'application/json'];

        foreach ($this->settings->getWebhookHeaders() as $customHeader) {
            if (!empty($customHeader['key'])) {
                $headers[$customHeader['key']] = $customHeader['value'];
            }
        }

        foreach ($request_headers as $key => $value) {
            if (!empty($key)) {
                $headers[$key] = $value;
            }
        }

        // Setup POST request arguments
        $args = [
            'body'    => $json_data,
            'headers' => $headers,
            'timeout' => 10,
        ];

        // Make HTTP POST request to the webhook
        $response = wp_remote_post($webhookUrl, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('FWI Webhook error: ' . $error_message);

            $this->logger->log(
                requestData: $form_data,
                requestUrl: $webhookUrl,
                requestHeaders: $headers,
                responseCode: 0,
                responseData: (string) wp_json_encode(['error' => $error_message])
            );

            return ['ok' => false, 'msg' => 'There was an issue submitting the form data through the webhook.'];
        }

        // Check for successful response
        $response_body = wp_remote_retrieve_body($response);

        // Log the response body for non-successful responses to aid debugging, but not for successful ones to avoid log clutter. The full response is still logged in all cases.
        $response_code = (int) wp_remote_retrieve_response_code($response);

        // Consider 200 and 201 as successful responses; anything else (including 202, 204, etc.) is a failure for our purposes since it likely means the webhook did not process the data as intended. Transport-level errors are also failures (response_code = 0).
        $ok_response = $response_code === 200 || $response_code === 201 || $response_code === 202 || $response_code === 204;

        // Log non-successful responses for debugging
        if (!$ok_response) {
            error_log('FWI Webhook response: ' . $response_body);

            $this->logger->log(
                requestData: $form_data,
                requestUrl: $webhookUrl,
                requestHeaders: $headers,
                responseCode: $response_code,
                responseData: $response_body
            );

            return ['ok' => false, 'msg' => 'There was an issue submitting the form data through the webhook.'];
        }

        // Log successful request
        $this->logger->log(
            requestData: $form_data,
            requestUrl: $webhookUrl,
            requestHeaders: $headers,
            responseCode: $response_code,
            responseData: $response_body
        );

        // Return success
        return ['ok' => true, 'msg' => 'Successfully submitted form data through the webhook.'];
    }
}
