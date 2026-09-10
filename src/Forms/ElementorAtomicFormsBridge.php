<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Forms;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Forms\Atomic\FwiWebhookAction;
use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\RetryManager;
use FormsWebhookIntegrator\Webhook\WebhookHandler;

/**
 * Bridges Elementor Pro Atomic Form submissions to the WebhookHandler.
 *
 * Atomic forms do not fire elementor_pro/forms/new_record. Instead, Elementor
 * Pro runs an ordered list of "Actions After Submit" through its
 * Action_Runner, so this bridge:
 *
 *  1. Registers a custom Atomic action ({@see self::ACTION_SLUG}) on the
 *     elementor_pro/atomic_forms/actions/register hook.
 *  2. Adds a matching "Forms Webhook Integrator" choice to the Atomic Form
 *     root element's "Actions after submit" control in the editor, via the
 *     elementor/atomic-widgets/controls filter.
 *  3. Converts an Atomic submission into a WebhookHandler::handleFormSubmission()
 *     call and translates the WebhookResponse back into the result shape
 *     Action_Runner expects.
 *
 * Elementor's own native "Webhook" action is left untouched — the two can be
 * selected together on the same form.
 *
 * Every Elementor Atomic class reference is guarded, so loading this plugin
 * without Elementor Pro (or with an Elementor version that predates Atomic
 * Forms) registers hooks that simply never fire.
 *
 * Note: handleAtomicSubmission() calls the handler directly rather than via
 * do_action('fwi_submission') because WordPress discards action callback
 * return values, and the Atomic action must report an outcome back to
 * Elementor. This mirrors {@see ElementorFormsBridge}.
 */
final class ElementorAtomicFormsBridge
{
    /**
     * Action slug saved in the Atomic Form's actions-after-submit setting.
     *
     * Deliberately distinct from Elementor's own 'webhook' action so both can
     * be selected on the same form and neither overwrites the other.
     */
    public const ACTION_SLUG = 'fwi-webhook';

    /**
     * Label shown for this action in the editor's "Actions after submit"
     * control and in the Submissions action log.
     */
    public const ACTION_LABEL = 'Forms Webhook Integrator';

    /**
     * Element type of the Atomic Form root element. Used both to gate the
     * editor control filter and to detect forms during discovery.
     */
    public const ATOMIC_FORM_ELEMENT_TYPE = 'e-form';

    /**
     * Atomic Form prop that holds the selected actions-after-submit list.
     */
    private const ACTIONS_PROP = 'actions-after-submit';

    /**
     * Elementor Pro's Atomic action base class. Our action extends it, so the
     * class must exist before FwiWebhookAction is referenced at all.
     */
    private const ACTION_BASE_CLASS = 'ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Base';

    /**
     * Elementor core's Atomic Form element class, used to read the canonical
     * element type and the form-name prop default when available.
     */
    private const ATOMIC_FORM_CLASS = 'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Form\\Atomic_Form';

    /**
     * Guards against registering the same callbacks twice if register() is
     * ever called more than once.
     *
     * @var bool
     */
    private bool $registered = false;

    /**
     * @param SettingsManager $settings       Shared settings store: active flag,
     *                                        exclusions, per-form overrides,
     *                                        page-parameter flags, failure mode.
     * @param WebhookHandler  $webhookHandler Reused verbatim so Atomic submissions
     *                                        produce the same payload, logging,
     *                                        overrides, and multi-endpoint dispatch
     *                                        as classic Elementor submissions.
     * @param RetryManager    $retryManager   Queues background retries for failed
     *                                        deliveries when the failure mode is 'retry'.
     */
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly WebhookHandler $webhookHandler,
        private readonly RetryManager $retryManager
    ) {}

    /**
     * Registers the Atomic action, the editor control option, and the
     * Submissions action-log label.
     *
     * Called once from {@see \FormsWebhookIntegrator\Plugin::init()}.
     *
     * Deliberately *not* gated on SettingsManager::isActive(): a form that has
     * already saved the fwi-webhook action would otherwise be told "Invalid
     * action type: fwi-webhook" by Action_Runner and fail the visitor's
     * submission whenever the integration is switched off. The action stays
     * registered and reports a successful no-op instead
     * (see {@see self::handleAtomicSubmission()}).
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_action('elementor_pro/atomic_forms/actions/register', [$this, 'registerAtomicAction'], 10, 1);
        add_filter('elementor/atomic-widgets/controls', [$this, 'addAtomicEditorAction'], 10, 2);
        add_filter('elementor_pro/atomic_forms/action_log_label', [$this, 'filterActionLogLabel'], 10, 2);
    }

    /**
     * Registers the custom action with Elementor Pro's Atomic Action_Runner.
     *
     * Elementor Pro passes the Action_Runner class name (not an instance) to
     * this hook, so both a class-string and an object are accepted. Registration
     * is skipped when the Atomic action base class is unavailable, when the
     * runner does not expose the expected static API, or when an action with
     * this slug is already registered.
     *
     * @param mixed $runner Action_Runner class-string or instance supplied by Elementor Pro.
     *
     * @return void
     */
    public function registerAtomicAction(mixed $runner = null): void
    {
        if (!class_exists(self::ACTION_BASE_CLASS)) {
            return;
        }

        $runnerClass = match (true) {
            is_object($runner) => $runner::class,
            is_string($runner) => $runner,
            default            => '',
        };

        if ($runnerClass === '' || !class_exists($runnerClass)) {
            return;
        }

        if (!is_callable([$runnerClass, 'register_action']) || !is_callable([$runnerClass, 'has_action'])) {
            return;
        }

        // Never replace an already-registered action of the same slug.
        if ($runnerClass::has_action(self::ACTION_SLUG)) {
            return;
        }

        $runnerClass::register_action(new FwiWebhookAction($this));
    }

    /**
     * Adds the "Forms Webhook Integrator" choice to the Atomic Form root
     * element's "Actions after submit" control.
     *
     * Elementor hardcodes the Email / Collect submissions / Webhook choices in
     * Atomic_Form::define_atomic_controls(), and exposes the assembled control
     * objects through the elementor/atomic-widgets/controls filter. The Chips
     * control's current options are read back through its public get_props()
     * accessor and re-set with our choice appended — no reflection and no
     * access to Elementor internals.
     *
     * Only the Atomic Form root (e-form) is touched; individual field elements
     * are left alone. The choice is not appended twice if the filter runs more
     * than once over the same control.
     *
     * @param mixed $controls Array of Section / Atomic_Control_Base objects.
     * @param mixed $element  The element whose controls are being built.
     *
     * @return mixed The (possibly mutated) controls, unchanged in shape.
     */
    public function addAtomicEditorAction(mixed $controls, mixed $element = null): mixed
    {
        if (!is_array($controls) || !$this->isAtomicFormRoot($element)) {
            return $controls;
        }

        $this->injectActionOption($controls);

        return $controls;
    }

    /**
     * Supplies a readable label for this action in Elementor's Submissions
     * action log, which otherwise derives "Fwi Webhook" from the slug.
     *
     * @param mixed $label      Default label computed by Elementor Pro.
     * @param mixed $actionType Action slug being labelled.
     *
     * @return mixed
     */
    public function filterActionLogLabel(mixed $label, mixed $actionType = null): mixed
    {
        return $actionType === self::ACTION_SLUG ? self::ACTION_LABEL : $label;
    }

    /**
     * Forwards one Atomic Form submission to the webhook and reports the outcome.
     *
     * Behaviour deliberately mirrors {@see ElementorFormsBridge::handleElementorSubmission()}:
     * the same exclusion check, the same per-form override merge, the same
     * page-parameter precedence, and the same failure-mode handling. The form_id
     * override itself is resolved inside WebhookHandler, so Elementor's native
     * Atomic form id is passed through as the fallback rather than being
     * re-resolved here.
     *
     * @param array<string, mixed> $formData Sanitised Atomic field values keyed by field id.
     * @param array<string, mixed> $context  Elementor's Atomic context: form_name, form_id,
     *                                       post_id, referrer, field_metadata, files, etc.
     *
     * @return array{ok: bool, message: string} Outcome for the calling Atomic action.
     */
    public function handleAtomicSubmission(array $formData, array $context): array
    {
        // Integration switched off: the saved action must not fail the form.
        if (!$this->settings->isActive()) {
            return $this->skipped('Forms Webhook Integrator is inactive — submission skipped.');
        }

        $formName = $this->resolveFormName($context);

        // Elementor falls back to the form's element id, so an empty name means
        // the context was malformed. Skip rather than fail the visitor's form.
        if ($formName === '') {
            return $this->skipped('No Atomic form name was supplied — submission skipped.');
        }

        if (in_array($formName, $this->settings->getExcludedForms(), true)) {
            return $this->skipped('This form is excluded from webhook submissions by the current settings.');
        }

        $override   = $this->settings->getFormOverride($formName);
        $urlQuery   = array_column($override['query_params'], 'value', 'key');
        $reqHeaders = array_column($override['headers'],      'value', 'key');

        if ($this->settings->isIncludePageParams() || $override['include_page_params']) {
            $pageParams = self::extractPageParams($context['referrer'] ?? null);
            if (!empty($pageParams)) {
                // Page params merged first so form-specific params take precedence
                $urlQuery = array_merge($pageParams, $urlQuery);
            }
        }

        $fieldMetadata = isset($context['field_metadata']) && is_array($context['field_metadata'])
            ? $context['field_metadata']
            : [];

        $result = $this->webhookHandler->handleFormSubmission(
            ['form_name' => $formName, 'form_id' => $this->resolveNativeFormId($context)],
            $this->buildFields($formData, $fieldMetadata),
            $urlQuery,
            $reqHeaders
        );

        // Retry mode: suppress the visitor-facing error only when there are
        // actual failed dispatches to replay. Pre-dispatch rejections (geo-block,
        // inactive, excluded, no URL) have no failed deliveries and still surface
        // the error. Keyed off failedDeliveries rather than ok because a partial
        // multi-endpoint failure whose last endpoint succeeded reports ok=true.
        if ($this->settings->getFailureMode() === 'retry' && !empty($result->failedDeliveries)) {
            $this->retryManager->scheduleRetries($result->failedDeliveries);

            return ['ok' => true, 'message' => 'Webhook delivery failed and was queued for a background retry.'];
        }

        // A non-empty failedDeliveries list is a delivery failure even when the
        // legacy ok flag reflects only the final endpoint of a multi-endpoint run.
        if (!empty($result->failedDeliveries) || !$result->ok) {
            $message = $result->msg !== ''
                ? $result->msg
                : 'There was an issue submitting the form data through the webhook.';

            return ['ok' => false, 'message' => $message];
        }

        return ['ok' => true, 'message' => $result->msg];
    }

    /**
     * Builds a successful no-op outcome, used when nothing was dispatched but
     * the visitor's submission must not be failed.
     *
     * @param string $message Human-readable reason, surfaced to Elementor.
     *
     * @return array{ok: bool, message: string}
     */
    private function skipped(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /**
     * Extracts the Atomic form name used for exclusions, per-form overrides,
     * and the payload's form_name field.
     *
     * @param array<string, mixed> $context Elementor's Atomic action context.
     *
     * @return string Sanitised form name, or an empty string when absent.
     */
    private function resolveFormName(array $context): string
    {
        $formName = $context['form_name'] ?? '';

        if (!is_scalar($formName)) {
            return '';
        }

        return trim(sanitize_text_field((string) $formName));
    }

    /**
     * Extracts Elementor's native Atomic form id, passed to WebhookHandler as
     * the payload's form_id fallback. A configured per-form Form ID override
     * still takes precedence — that decision stays inside WebhookHandler.
     *
     * @param array<string, mixed> $context Elementor's Atomic action context.
     *
     * @return string
     */
    private function resolveNativeFormId(array $context): string
    {
        $formId = $context['form_id'] ?? '';

        return is_scalar($formId) ? sanitize_text_field((string) $formId) : '';
    }

    /**
     * Converts Atomic form data into the flat field map WebhookHandler expects.
     *
     * Atomic field ids are opaque element ids (e.g. "e4f2a1b"), so each key is
     * resolved to the field's editor label when Elementor supplied one — the
     * same label-with-id-fallback strategy Elementor's own Atomic webhook
     * action uses, keeping submission_data readable and stable. Multi-value
     * fields (checkbox groups, multi-selects, uploaded-file URL lists) stay
     * arrays of strings; they are never flattened to the literal "Array".
     *
     * @param array<string, mixed>            $formData      Atomic field values keyed by field id.
     * @param array<string, mixed>            $fieldMetadata Elementor's per-field metadata (label, type, options).
     *
     * @return array<string, string|array<int, string>>
     */
    private function buildFields(array $formData, array $fieldMetadata): array
    {
        $fields   = [];
        $usedKeys = [];

        foreach ($formData as $fieldId => $value) {
            $key = $this->resolveFieldKey((string) $fieldId, $fieldMetadata, $usedKeys);

            $fields[$key] = is_array($value)
                ? self::flattenValues($value)
                : self::stringifyValue($value);
        }

        return $fields;
    }

    /**
     * Resolves the submission_data key for one Atomic field.
     *
     * Prefers the field's label; falls back to the raw field id. Duplicate
     * labels are disambiguated with the field id suffix, then with the bare
     * field id, so no field is ever silently dropped by a key collision.
     *
     * @param string                        $fieldId       Atomic field (element) id.
     * @param array<string, mixed>          $fieldMetadata Elementor's per-field metadata.
     * @param array<string, bool>           $usedKeys      Keys already taken, updated in place.
     *
     * @return string
     */
    private function resolveFieldKey(string $fieldId, array $fieldMetadata, array &$usedKeys): string
    {
        $meta     = $fieldMetadata[$fieldId] ?? null;
        $rawLabel = is_array($meta) ? ($meta['label'] ?? null) : null;
        $label    = is_scalar($rawLabel) ? trim((string) $rawLabel) : '';

        $key = $label !== '' ? $label : $fieldId;

        if (!isset($usedKeys[$key])) {
            $usedKeys[$key] = true;
            return $key;
        }

        $suffixed = $key . '_' . $fieldId;

        if (!isset($usedKeys[$suffixed])) {
            $usedKeys[$suffixed] = true;
            return $suffixed;
        }

        $usedKeys[$fieldId] = true;

        return $fieldId;
    }

    /**
     * Flattens a multi-value Atomic field into a list of strings.
     *
     * Nested arrays are flattened rather than stringified so a checkbox group,
     * a multi-select, or an uploaded-file URL list never becomes "Array".
     *
     * @param array<int|string, mixed> $value
     *
     * @return array<int, string>
     */
    private static function flattenValues(array $value): array
    {
        $flat = [];

        array_walk_recursive($value, static function ($item) use (&$flat): void {
            $flat[] = self::stringifyValue($item);
        });

        return $flat;
    }

    /**
     * Coerces a single Atomic field value to a string without emitting
     * conversion notices for non-scalar input.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Parses query parameters from the page the Atomic form was submitted on.
     *
     * Elementor sanitises and posts the submitting page URL as context.referrer,
     * which is preferred over HTTP_REFERER; the server header remains the
     * fallback for requests that did not carry one.
     *
     * Intentionally kept local to this class rather than shared with
     * {@see ElementorFormsBridge}, so the classic bridge's behaviour is
     * untouched by this integration.
     *
     * @param mixed $contextReferrer Elementor's sanitised referrer, when supplied.
     *
     * @return array<string, string> Sanitised key-value pairs, or empty array.
     */
    private static function extractPageParams(mixed $contextReferrer): array
    {
        $referer = is_string($contextReferrer) ? trim($contextReferrer) : '';

        if ($referer === '') {
            $headerReferer = $_SERVER['HTTP_REFERER'] ?? '';
            $referer       = is_string($headerReferer) ? $headerReferer : '';
        }

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
                $params[$cleanKey] = sanitize_text_field(is_scalar($value) ? (string) $value : '');
            }
        }

        return $params;
    }

    /**
     * Determines whether the filtered element is the Atomic Form root element.
     *
     * @param mixed $element Element instance supplied by the controls filter.
     *
     * @return bool
     */
    private function isAtomicFormRoot(mixed $element): bool
    {
        if (!is_object($element) || !method_exists($element, 'get_name')) {
            return false;
        }

        return $element->get_name() === self::atomicFormElementType();
    }

    /**
     * Returns Elementor's Atomic Form element type, falling back to the known
     * slug when the Atomic Form class is unavailable.
     *
     * @return string
     */
    public static function atomicFormElementType(): string
    {
        $atomicFormClass = self::ATOMIC_FORM_CLASS;

        if (class_exists($atomicFormClass) && is_callable([$atomicFormClass, 'get_element_type'])) {
            $type = $atomicFormClass::get_element_type();

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return self::ATOMIC_FORM_ELEMENT_TYPE;
    }

    /**
     * Walks a control tree and appends this plugin's choice to the
     * actions-after-submit Chips control.
     *
     * Control objects are mutated in place, so nested Sections need no
     * write-back; the array itself is never restructured.
     *
     * @param array<int, mixed> $controls Section / control objects.
     *
     * @return void
     */
    private function injectActionOption(array $controls): void
    {
        foreach ($controls as $control) {
            if (!is_object($control)) {
                continue;
            }

            if (method_exists($control, 'get_items')) {
                $items = $control->get_items();

                if (is_array($items)) {
                    $this->injectActionOption($items);
                }

                continue;
            }

            $this->maybeAddOptionToControl($control);
        }
    }

    /**
     * Appends the "Forms Webhook Integrator" choice to a single control when it
     * is the actions-after-submit Chips control and does not already carry it.
     *
     * @param object $control Candidate control object.
     *
     * @return void
     */
    private function maybeAddOptionToControl(object $control): void
    {
        if (
            !method_exists($control, 'get_bind') ||
            !method_exists($control, 'get_props') ||
            !method_exists($control, 'set_options')
        ) {
            return;
        }

        if ($control->get_bind() !== self::ACTIONS_PROP) {
            return;
        }

        $props   = $control->get_props();
        $options = is_array($props) && isset($props['options']) && is_array($props['options'])
            ? $props['options']
            : [];

        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === self::ACTION_SLUG) {
                return; // Already present — the filter has run over this control before.
            }
        }

        $options[] = [
            'label' => self::ACTION_LABEL,
            'value' => self::ACTION_SLUG,
        ];

        $control->set_options($options);
    }
}
