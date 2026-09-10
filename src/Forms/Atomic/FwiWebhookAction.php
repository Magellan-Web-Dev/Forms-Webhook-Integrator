<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Forms\Atomic;

if (!defined('ABSPATH')) exit;

use ElementorPro\Modules\AtomicForm\Actions\Action_Base;
use FormsWebhookIntegrator\Forms\ElementorAtomicFormsBridge;

/**
 * Elementor Pro Atomic Form action that forwards a submission to the plugin's
 * configured webhook endpoints.
 *
 * Registered under the {@see ElementorAtomicFormsBridge::ACTION_SLUG} slug so
 * it sits alongside — never replacing — Elementor's native 'webhook' action.
 * Site owners opt in per form by selecting "Forms Webhook Integrator" under the
 * Atomic form's "Actions after submit" control.
 *
 * This class is a thin adapter: all delivery, exclusion, override, logging, and
 * retry behaviour lives in {@see ElementorAtomicFormsBridge} (and, below it, in
 * the shared WebhookHandler), so it can be exercised without Elementor Pro
 * present. Keeping the Elementor-coupled surface this small also means the file
 * is only ever loaded once Action_Base is known to exist.
 *
 * IMPORTANT: this file must not be referenced unless
 * class_exists('ElementorPro\Modules\AtomicForm\Actions\Action_Base') is true —
 * the parent class is only available with Elementor Pro's Atomic Form module
 * active. {@see ElementorAtomicFormsBridge::registerAtomicAction()} performs
 * that check before touching this class.
 */
final class FwiWebhookAction extends Action_Base
{
    /**
     * @param ElementorAtomicFormsBridge $bridge Bridge holding the plugin's
     *                                           settings, webhook handler, and
     *                                           retry manager. Injected so this
     *                                           action reuses the plugin's
     *                                           existing services rather than
     *                                           implementing its own delivery.
     */
    public function __construct(
        private readonly ElementorAtomicFormsBridge $bridge
    ) {}

    /**
     * Returns the action slug persisted in the Atomic form's settings.
     *
     * @return string
     */
    public function get_type(): string
    {
        return ElementorAtomicFormsBridge::ACTION_SLUG;
    }

    /**
     * Runs the webhook delivery for one Atomic form submission.
     *
     * $widget_settings is intentionally unused: the endpoints, headers, query
     * parameters, and payload metadata all come from this plugin's own settings
     * rather than from per-widget Elementor fields. Elementor's native
     * 'webhook' action, which does read widget_settings['webhook_url'], is
     * unaffected and can be used at the same time.
     *
     * @param array<string, mixed> $form_data       Sanitised submitted field values keyed by field id.
     * @param array<string, mixed> $widget_settings Full resolved Atomic form settings (unused).
     * @param array<string, mixed> $context         Elementor's Atomic context: form_name, form_id,
     *                                              post_id, referrer, field_metadata, files, etc.
     *
     * @return array{status: string, message?: string, error?: string}
     *         Action_Runner's expected result shape: a success carries 'message',
     *         a failure carries 'error'.
     */
    public function execute(array $form_data, array $widget_settings, array $context): array
    {
        $outcome = $this->bridge->handleAtomicSubmission($form_data, $context);

        return $outcome['ok']
            ? $this->success($outcome['message'])
            : $this->failure($outcome['message']);
    }
}
