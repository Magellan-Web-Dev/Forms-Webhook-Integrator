<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * Immutable value object representing the outcome of a webhook form submission.
 *
 * Returned by {@see WebhookHandler::handleFormSubmission()} and
 * {@see fwi_submit_form()} in place of an associative array so that callers
 * cannot accidentally mutate the result after it is produced.
 */
final class WebhookResponse
{
    /**
     * @param bool   $ok     True when the submission was accepted by the webhook
     *                       (HTTP 200/201/202/204); false on any failure.
     * @param int    $status HTTP status code returned by the webhook endpoint.
     *                       0 for early exits (inactive integration, excluded form,
     *                       missing URL, etc.) and transport-level errors.
     * @param string $msg    User-facing error description when $ok is false;
     *                       empty string on success.
     * @param mixed  $data   The webhook's response body when an HTTP response was
     *                       received — JSON-decoded if the body is valid JSON,
     *                       raw string otherwise. Null for early exits and
     *                       transport-level errors. Not intended for public display.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $status = 0,
        public readonly string $msg = '',
        public readonly mixed $data = null
    ) {}
}
