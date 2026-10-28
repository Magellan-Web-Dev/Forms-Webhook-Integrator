<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Forms;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\RetryManager;
use FormsWebhookIntegrator\Webhook\WebhookHandler;

/**
 * Bridges Elementor Pro form submissions to the WebhookHandler.
 *
 * Listens for the elementor_pro/forms/new_record action, extracts the form
 * name and field values from the Elementor record object, then calls
 * WebhookHandler::handleFormSubmission() directly so that the return value
 * can be inspected. When a failure is not queued for retry, a supplied Elementor
 * Ajax handler is used to surface an error message in the form UI. Webhook
 * processing does not require an Ajax handler.
 *
 * Note: handleFormSubmission() is called directly here rather than via
 * do_action('fwi_submission') because WordPress discards action callback
 * return values. The fwi_submission action remains the public API for all
 * other (non-Elementor) form integrations.
 */
final class ElementorFormsBridge
{
    /**
     * @param SettingsManager $settings      Shared settings store, used to gate
     *                                       hook registration on the active flag.
     * @param WebhookHandler  $webhookHandler Called directly so its return value
     *                                       can be used for Elementor error reporting.
     * @param RetryManager    $retryManager  Queues background retries for failed
     *                                       deliveries when the failure mode is 'retry'.
     */
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly WebhookHandler $webhookHandler,
        private readonly RetryManager $retryManager
    ) {}

    /**
     * Registers the Elementor form-submission hook when the webhook is active.
     *
     * Called once from {@see Plugin::init()}.
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->settings->isActive()) {
            add_action('elementor_pro/forms/new_record', [$this, 'handleElementorSubmission'], 10, 2);
        }
    }

    /**
     * Parses an Elementor form record and forwards it to the webhook handler.
     *
     * Extracts the form name and builds a flat associative array of field IDs
     * to raw values. Returns early without calling handleFormSubmission() if the
     * form name is empty or if the form appears on the excluded-forms list.
     * Failed deliveries are queued when retry mode is enabled. Otherwise, on
     * failure, a supplied Elementor Ajax handler with add_error_message() is
     * used to display the error and mark the submission as unsuccessful.
     * Omitting the handler or passing null skips form error reporting without
     * affecting webhook processing or retry scheduling.
     *
     * @param mixed $record  Elementor_Form_Record instance. Typed as mixed because
     *                       Elementor Pro type declarations are not available at
     *                       plugin load time.
     * @param mixed $handler Optional Elementor Ajax_Handler instance. Defaults to
     *                       null. Used to attach form error messages and mark the
     *                       submission as failed. When omitted or null, form error
     *                       reporting is skipped. Typed as mixed for the same
     *                       reason as $record.
     *
     * @return void
     */
    public function handleElementorSubmission(mixed $record, mixed $handler = null): void
    {
        $formName = $record->get_form_settings('form_name');

        if (empty($formName)) {
            return;
        }

        $rawFields = $record->get('fields');
        $fields     = [];

        foreach ($rawFields as $id => $field) {
            $fields[$id] = $field['value'];
        }

        $override   = $this->settings->getFormOverride($formName);
        $urlQuery   = array_column($override['query_params'], 'value', 'key');
        $reqHeaders = array_column($override['headers'],      'value', 'key');

        if ($this->settings->isIncludePageParams() || $override['include_page_params']) {
            $pageParams = $this->extractPageParams();
            if (!empty($pageParams)) {
                // Page params merged first so form-specific params take precedence
                $urlQuery = array_merge($pageParams, $urlQuery);
            }
        }

        if (in_array($formName, $this->settings->getExcludedForms(), true)) {
            return;
        }

        $result = $this->webhookHandler->handleFormSubmission(
            ['form_name' => $formName, 'form_id' => ''],
            $fields,
            $urlQuery,
            $reqHeaders
        );

        // Retry mode: suppress the visitor-facing error only when there are
        // actual failed dispatches to replay. Pre-dispatch rejections (geo-block,
        // inactive, excluded, no URL) have no failed deliveries and still surface
        // the error when a compatible Ajax handler is provided.
        // Keyed off failedDeliveries rather than ok because a partial
        // multi-endpoint failure whose last endpoint succeeded reports ok=true.
        if ($this->settings->getFailureMode() === 'retry' && !empty($result->failedDeliveries)) {
            $this->retryManager->scheduleRetries($result->failedDeliveries);
            return; // Elementor defaults to success — the visitor sees the normal success state.
        }

        if (!$result->ok) {
            if (is_object($handler) && method_exists($handler, 'add_error_message')) {
                $handler->add_error_message($result->msg);
                $handler->is_success = false;
            }
        }
    }

    /**
     * Parses query parameters from the HTTP referrer (the page the form is on).
     *
     * Elementor forms submit via AJAX, so HTTP_REFERER reliably contains the
     * originating page URL including any query string.
     *
     * @return array<string, string> Sanitised key-value pairs, or empty array.
     */
    private function extractPageParams(): array
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (empty($referer)) {
            return [];
        }

        $parts = wp_parse_url($referer);
        if (empty($parts['query'])) {
            return [];
        }

        $rawParams = [];
        wp_parse_str($parts['query'], $rawParams);

        $params = [];
        foreach ($rawParams as $key => $value) {
            $cleanKey = sanitize_text_field((string) $key);
            if ($cleanKey !== '') {
                $params[$cleanKey] = sanitize_text_field((string) $value);
            }
        }

        return $params;
    }
}
