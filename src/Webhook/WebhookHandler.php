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
 *   do_action('fwi_submission', $formName, $formFields);
 * where $formName is a string and $formFields is an associative array of
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
        add_action('fwi_submission', [$this, 'handleFormSubmission'], 10, 4);
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
     *  1. Verify the webhook integration is active.
     *  2. Verify the form is not excluded.
     *  3. Build the full webhook URL (base URL + configured query params).
     *  4. Assemble the payload: website info, client data, sanitised fields,
     *     IP geolocation via ipapi.co, and a timestamp.
     *  5. If outside-US blocking is enabled and the submitter's country is not
     *     "United States", abort and return a failure result.
     *  6. JSON-encode the payload and POST it to the webhook.
     *  7. Log the outcome (success or failure) via WebhookLogger.
     *
     * @param string               $formName        The name of the submitted form.
     * @param array<string, mixed> $fields          Associative array of field names to values.
     * @param array<string, mixed> $urlQuery        Optional extra query parameters to append to
     *                                              the webhook URL (merged after the base URL and
     *                                              settings-configured params are applied).
     * @param array<string, string> $requestHeaders  Optional extra request headers (key → value).
     *                                              Merged after the settings-configured headers;
     *                                              caller-supplied values override settings values
     *                                              for any shared keys.
     *
     * @return WebhookResponse 'ok' is true on success, false on any failure.
     *                          'status' is the HTTP response code (0 when no HTTP
     *                          response was received). 'msg' contains a user-facing
     *                          error description when 'ok' is false, and an empty
     *                          string on success. 'data' holds the JSON-decoded (or
     *                          raw) response body when an HTTP response was received;
     *                          null otherwise.
     */
    public function handleFormSubmission(string $formName, array $fields, array $urlQuery = [], array $requestHeaders = []): WebhookResponse
    {
        if (!$this->settings->isActive()) {
            return new WebhookResponse(ok: false, msg: 'The webhook integration is not active.');
        }

        // Check if this form is excluded
        if (in_array($formName, $this->settings->getExcludedForms(), true)) {
            return new WebhookResponse(ok: false, msg: 'This form is excluded from webhook submissions by the current settings.');
        }

        // Build webhook URL (base URL + query params from settings, then caller-supplied params)
        $webhookUrl = $this->settings->buildWebhookUrl();
        if (empty($webhookUrl)) {
            return new WebhookResponse(ok: false, msg: 'No webhook URL is configured.');
        }

        if (!empty($urlQuery)) {
            $webhookUrl = add_query_arg($urlQuery, $webhookUrl);
        }

        // Initialize form data array
        $formData = [];

        // Add website data
        $formData['website_info'] = [
            'name' => get_bloginfo('name'),
            'url'  => home_url(),
            'client' => [
                'first_name' => $this->settings->getClientFirstName(),
                'last_name'  => $this->settings->getClientLastName(),
            ],
        ];

        // Add form name
        $formData['form_name'] = $formName;

        // Sanitize and store submission fields, preserving array structure for
        // multi-value fields such as checkbox groups.
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $formData['submission_data'][$key] = array_map('sanitize_text_field', array_map('strval', $value));
            } else {
                $formData['submission_data'][$key] = sanitize_text_field((string) $value);
            }
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
        $clientKeyName = 'client_location_data';

        if ($ip) {
            // Handle multiple IP addresses (proxies) — take the first one
            $ip = trim(explode(',', $ip)[0]);

            $ipErrorMessage = 'Unable to get client location information from the user IP address.';
            $ipUrl          = 'https://ipapi.co/' . $ip . '/json';

            $ipLookupRequest = wp_remote_get($ipUrl);

            if (!is_wp_error($ipLookupRequest)) {
                $ipData    = wp_remote_retrieve_body($ipLookupRequest);
                $ipDecoded = json_decode($ipData, true);

                if (isset($ipDecoded['error'])) {
                    $formData[$clientKeyName]['error'] = $ipErrorMessage;
                } else {
                    $ipKeysToParse = ['city', 'region', 'region_code', 'country_name', 'postal', 'latitude', 'longitude', 'timezone'];

                    foreach ($ipKeysToParse as $key) {
                        if (isset($ipDecoded[$key])) {
                            // Block submissions outside the US if the setting is enabled
                            if (
                                $this->settings->isBlockOutsideUs() &&
                                $key === 'country_name' &&
                                $ipDecoded[$key] !== 'United States'
                            ) {
                                return new WebhookResponse(ok: false, msg: 'Form submissions cannot be made from a location outside of the United States.');
                            }

                            $formData[$clientKeyName][$key] = sanitize_text_field($ipDecoded[$key]);
                        }
                    }
                }
            } else {
                $formData[$clientKeyName]['error'] = $ipErrorMessage;
            }
        } else {
            $ip = 'Unknown';
        }

        // Assign IP address field — value is 'Unknown' if it could not be acquired
        $formData[$clientKeyName]['ip'] = $ip;

        // Set timestamp
        $formData['timestamp'] = [
            'date' => gmdate('Y-m-d'),
            'time' => gmdate('H:i:s'),
        ];

        // Convert form data to JSON
        $jsonData = json_encode($formData);

        // Check for JSON encoding errors
        if ($jsonData === false) {
            error_log('FWI JSON encoding error: ' . json_last_error_msg());
            return new WebhookResponse(ok: false, msg: 'There was an issue compiling the submission data to send to the webhook.');
        }

        // Build headers — Content-Type first, then settings headers, then caller-supplied headers
        $headers = ['Content-Type' => 'application/json'];

        foreach ($this->settings->getWebhookHeaders() as $customHeader) {
            if (!empty($customHeader['key'])) {
                $headers[$customHeader['key']] = $customHeader['value'];
            }
        }

        foreach ($requestHeaders as $key => $value) {
            if (!empty($key)) {
                $headers[$key] = $value;
            }
        }

        // Setup POST request arguments
        $args = [
            'body'    => $jsonData,
            'headers' => $headers,
            'timeout' => 10,
        ];

        // Make HTTP POST request to the webhook
        $response = wp_safe_remote_post($webhookUrl, $args);

        if (is_wp_error($response)) {
            $errorMessage = $response->get_error_message();
            error_log('FWI Webhook error: ' . $errorMessage);

            $this->logger->log(
                requestData: $formData,
                requestUrl: $webhookUrl,
                responseCode: 0,
                responseData: (string) wp_json_encode(['error' => $errorMessage])
            );

            return new WebhookResponse(ok: false, msg: 'There was an issue submitting the form data through the webhook.');
        }

        // Check for successful response
        $responseBody = wp_remote_retrieve_body($response);

        // Log the response body for non-successful responses to aid debugging, but not for successful ones to avoid log clutter. The full response is still logged in all cases.
        $responseCode = (int) wp_remote_retrieve_response_code($response);

        // Decode the response body once; fall back to raw string if not valid JSON.
        $decodedBody = !empty($responseBody) ? (json_decode($responseBody, true) ?? $responseBody) : null;

        // 200, 201, 202, and 204 are all treated as success; anything else is a failure.
        // Transport-level errors are also failures (responseCode = 0).
        $okResponse = $responseCode === 200 || $responseCode === 201 || $responseCode === 202 || $responseCode === 204;

        // Log non-successful responses for debugging
        if (!$okResponse) {
            error_log('FWI Webhook response: ' . $responseBody);

            $this->logger->log(
                requestData: $formData,
                requestUrl: $webhookUrl,
                responseCode: $responseCode,
                responseData: $responseBody
            );

            return new WebhookResponse(ok: false, status: $responseCode, msg: 'There was an issue submitting the form data through the webhook.', data: $decodedBody);
        }

        // Log successful request
        $this->logger->log(
            requestData: $formData,
            requestUrl: $webhookUrl,
            responseCode: $responseCode,
            responseData: $responseBody
        );

        // Return success
        return new WebhookResponse(ok: true, status: $responseCode, msg: 'Successfully submitted form data through the webhook.', data: $decodedBody);
    }
}
