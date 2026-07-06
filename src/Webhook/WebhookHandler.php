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
 *   do_action('fwi_submission', $formIdentifier, $formFields);
 * where $formIdentifier is an associative array with 'form_name' (string) and
 * 'form_id' (string) keys, and $formFields is an associative array of field
 * names to field values.
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
     * Can be called directly or via do_action('fwi_submission', $formIdentifier, $fields).
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
     * @param array{form_name: string, form_id: string} $formIdentifier
     *                                              Associative array identifying the form.
     *                                              'form_name' is used for exclusion checks and
     *                                              per-form override lookups. 'form_id' is the
     *                                              native form identifier supplied by the caller;
     *                                              it is used in the payload only when no form_id
     *                                              override is configured in settings.
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
    public function handleFormSubmission(array $formIdentifier, array $fields, array $urlQuery = [], array $requestHeaders = []): WebhookResponse
    {
        $formName     = (string) ($formIdentifier['form_name'] ?? '');
        $passedFormId = (string) ($formIdentifier['form_id'] ?? '');

        if (!$this->settings->isActive()) {
            return new WebhookResponse(ok: false, msg: 'The webhook integration is not active.');
        }

        // Check if this form is excluded
        if (in_array($formName, $this->settings->getExcludedForms(), true)) {
            return new WebhookResponse(ok: false, msg: 'This form is excluded from webhook submissions by the current settings.');
        }

        $webhooks = $this->settings->getWebhooks();
        if (empty($webhooks)) {
            return new WebhookResponse(ok: false, msg: 'No webhook URL is configured.');
        }

        // ── Build payload once (shared across all webhook endpoints) ──────────

        $formData = [];

        // Add website data
        $formData['website_info'] = [
            'name'   => get_bloginfo('name'),
            'url'    => home_url(),
            'id'     => $this->settings->getWebsiteId(),
            'client' => [
                'first_name' => $this->settings->getClientFirstName(),
                'last_name'  => $this->settings->getClientLastName(),
                'id'         => $this->settings->getClientId(),
            ],
            'page' => $this->buildPageInfo(),
        ];

        // Add form name and identifier — settings override wins; caller-supplied form_id is the fallback
        $formData['form_name'] = $formName;
        $settingsFormId        = $this->settings->getFormOverride($formName)['form_id'];
        $formData['form_id']   = $settingsFormId !== '' ? $settingsFormId : $passedFormId;

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

            $ipErrorMessage  = 'Unable to get client location information from the user IP address.';
            $ipUrl           = 'https://ipapi.co/' . $ip . '/json';

            // The geolocation lookup blocks the form submission, so keep its
            // timeout short and filterable. A slow ipapi.co response should not
            // be able to stall the submission for the full default timeout.
            $ipTimeout       = (int) apply_filters('fwi_ip_lookup_timeout', 3);
            $ipLookupRequest = wp_remote_get($ipUrl, ['timeout' => $ipTimeout]);

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

        if ($jsonData === false) {
            error_log('FWI JSON encoding error: ' . json_last_error_msg());
            return new WebhookResponse(ok: false, msg: 'There was an issue compiling the submission data to send to the webhook.');
        }

        // Build shared headers — Content-Type first, then settings headers, then caller-supplied headers
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

        // ── Dispatch to each configured webhook endpoint ───────────────────────

        $overallOk        = true;
        $lastResponse     = new WebhookResponse(ok: false, msg: 'No webhook URLs with a configured URL were found.');
        $failedDeliveries = [];

        // Each endpoint POST is synchronous and blocks the submission response,
        // so the per-request timeout is filterable for site owners running slow
        // or many endpoints who need to tune the front-end wait.
        $webhookTimeout = (int) apply_filters('fwi_webhook_timeout', 10);

        foreach ($webhooks as $idx => $webhook) {
            $webhookUrl = $this->settings->buildWebhookUrlForWebhook($webhook);
            if (empty($webhookUrl)) {
                continue;
            }

            if (!empty($urlQuery)) {
                $webhookUrl = add_query_arg($urlQuery, $webhookUrl);
            }

            $label = trim((string) ($webhook['label'] ?? ''));
            if ($label === '') {
                $label = 'Webhook ' . ($idx + 1);
            }

            $response = wp_safe_remote_post($webhookUrl, [
                'body'    => $jsonData,
                'headers' => $headers,
                'timeout' => $webhookTimeout,
            ]);

            if (is_wp_error($response)) {
                $errorMessage = $response->get_error_message();
                error_log('FWI Webhook error (' . $label . '): ' . $errorMessage);

                $this->logger->log(
                    requestData: $formData,
                    requestUrl: $webhookUrl,
                    responseCode: 0,
                    responseData: (string) wp_json_encode(['error' => $errorMessage]),
                    webhookLabel: $label
                );

                $overallOk          = false;
                $lastResponse       = new WebhookResponse(ok: false, msg: 'There was an issue submitting the form data through the webhook.');
                $failedDeliveries[] = ['url' => $webhookUrl, 'headers' => $headers, 'body' => $jsonData, 'label' => $label];
                continue;
            }

            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = (int) wp_remote_retrieve_response_code($response);
            $decodedBody  = !empty($responseBody) ? (json_decode($responseBody, true) ?? $responseBody) : null;
            $okResponse   = in_array($responseCode, [200, 201, 202, 204], true);

            if (!$okResponse) {
                error_log('FWI Webhook response (' . $label . '): ' . $responseBody);
                $overallOk          = false;
                $failedDeliveries[] = ['url' => $webhookUrl, 'headers' => $headers, 'body' => $jsonData, 'label' => $label];
            }

            $this->logger->log(
                requestData: $formData,
                requestUrl: $webhookUrl,
                responseCode: $responseCode,
                responseData: $responseBody,
                webhookLabel: $label
            );

            $lastResponse = new WebhookResponse(
                ok: $okResponse,
                status: $responseCode,
                msg: $okResponse ? 'Successfully submitted form data through the webhook.' : 'There was an issue submitting the form data through the webhook.',
                data: $decodedBody
            );
        }

        if ($overallOk) {
            return new WebhookResponse(ok: true, status: $lastResponse->status, msg: 'Successfully submitted form data through all webhooks.', data: $lastResponse->data);
        }

        // ok/status/msg/data mirror $lastResponse (the final endpoint iteration)
        // for backward compatibility; $failedDeliveries carries the precise
        // per-endpoint failure data even when the last endpoint succeeded.
        return new WebhookResponse(
            ok: $lastResponse->ok,
            status: $lastResponse->status,
            msg: $lastResponse->msg,
            data: $lastResponse->data,
            failedDeliveries: $failedDeliveries
        );
    }

    /**
     * Builds page info from the HTTP referrer: the clean URL (no query string)
     * and an associative array of any query parameters that were present.
     *
     * Elementor forms submit via AJAX, so HTTP_REFERER reliably contains the
     * originating page URL. For submissions via do_action('fwi_submission')
     * the referer reflects whatever page triggered the server request.
     *
     * @return array{url: string, query: array<string, string>}
     */
    private function buildPageInfo(): array
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        if (empty($referer)) {
            return ['url' => '', 'query' => []];
        }

        $parts = wp_parse_url($referer);

        $pageUrl  = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (!empty($parts['port'])) {
            $pageUrl .= ':' . $parts['port'];
        }
        $pageUrl .= ($parts['path'] ?? '');

        $query = [];
        if (!empty($parts['query'])) {
            $rawParams = [];
            wp_parse_str($parts['query'], $rawParams);
            foreach ($rawParams as $key => $value) {
                $cleanKey = sanitize_text_field((string) $key);
                if ($cleanKey !== '') {
                    $query[$cleanKey] = sanitize_text_field((string) $value);
                }
            }
        }

        return [
            'url'   => esc_url_raw($pageUrl),
            'query' => $query,
        ];
    }
}
