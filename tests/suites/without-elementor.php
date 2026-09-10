<?php
/**
 * Suite: classic Elementor bridge behaviour, the plugin's public API, and
 * graceful degradation when Elementor Pro / Atomic Forms are NOT available.
 *
 * This suite deliberately loads no Elementor stubs, so every Atomic code path
 * runs against genuinely missing classes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use FormsWebhookIntegrator\Forms\Atomic\FwiWebhookAction;
use FormsWebhookIntegrator\Forms\ElementorAtomicFormsBridge;
use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\RetryManager;

// ─── Classic Elementor bridge — unchanged behaviour ──────────────────────────

fwi_test('classic: successful submission posts the existing payload shape', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $handler = new FWI_Fake_Ajax_Handler();
    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => 'Contact Form'], [
            'name'  => ['value' => 'John Doe'],
            'email' => ['value' => 'john@example.com'],
        ]),
        $handler
    );

    fwi_assert_same(1, count(fwi_post_calls()), 'exactly one webhook POST');

    $payload = fwi_post_payload();
    fwi_assert_same('Contact Form', $payload['form_name'], 'form_name in payload');
    fwi_assert_same('', $payload['form_id'], 'form_id empty when no override');
    fwi_assert_same('John Doe', $payload['submission_data']['name'], 'field forwarded');
    fwi_assert_same([], $handler->errors, 'no visitor-facing error');
    fwi_assert_same(true, $handler->is_success, 'submission stays successful');
    fwi_assert_same(1, count(fwi_log_rows()), 'request written to the analytics log');
});

fwi_test('classic: excluded form sends nothing and shows no error', function (): void {
    fwi_configure([SettingsManager::OPTION_EXCLUDED_FORMS => ['Contact Form']]);

    $handler = new FWI_Fake_Ajax_Handler();
    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => 'Contact Form'], ['name' => ['value' => 'John']]),
        $handler
    );

    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
    fwi_assert_same([], $handler->errors, 'no error surfaced');
    fwi_assert_same(true, $handler->is_success, 'submission left successful');
});

fwi_test('classic: empty form name returns before dispatch', function (): void {
    fwi_configure();

    $handler = new FWI_Fake_Ajax_Handler();
    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => ''], ['name' => ['value' => 'John']]),
        $handler
    );

    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
});

fwi_test('classic: retry mode queues failures and hides the error', function (): void {
    fwi_configure([SettingsManager::OPTION_FAILURE_MODE => 'retry']);
    fwi_queue_post(500, 'server error');

    $handler = new FWI_Fake_Ajax_Handler();
    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => 'Contact Form'], ['name' => ['value' => 'John']]),
        $handler
    );

    $retries = array_values(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK));
    fwi_assert_same(1, count($retries), 'one retry scheduled');
    fwi_assert_same([], $handler->errors, 'visitor sees no error in retry mode');
    fwi_assert_same(true, $handler->is_success, 'submission reported successful');
});

fwi_test('classic: show_error mode surfaces the failure on the form', function (): void {
    fwi_configure([SettingsManager::OPTION_FAILURE_MODE => 'show_error']);
    fwi_queue_post(500, 'server error');

    $handler = new FWI_Fake_Ajax_Handler();
    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => 'Contact Form'], ['name' => ['value' => 'John']]),
        $handler
    );

    fwi_assert_same(1, count($handler->errors), 'one error message added');
    fwi_assert_same(false, $handler->is_success, 'submission marked failed');
    fwi_assert_same(0, count(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK)), 'no retry queued');
});

fwi_test('classic: page params come from HTTP_REFERER, per-form params win', function (): void {
    fwi_configure([
        SettingsManager::OPTION_INCLUDE_PAGE_PARAMS => true,
        SettingsManager::OPTION_FORM_OVERRIDES      => [
            'Contact Form' => [
                'query_params' => [['key' => 'utm_source', 'value' => 'per-form']],
                'headers'      => [['key' => 'X-Form', 'value' => 'contact']],
            ],
        ],
    ]);
    $_SERVER['HTTP_REFERER'] = 'https://example.test/contact/?utm_source=google&utm_medium=cpc';
    fwi_queue_post(200);

    fwi_classic_bridge()->handleElementorSubmission(
        new FWI_Fake_Record(['form_name' => 'Contact Form'], ['name' => ['value' => 'John']]),
        new FWI_Fake_Ajax_Handler()
    );

    $call = fwi_post_calls()[0];
    parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);

    fwi_assert_same('per-form', $query['utm_source'], 'per-form param overrides the page param');
    fwi_assert_same('cpc', $query['utm_medium'], 'other page params passed through');
    fwi_assert_same('contact', $call['args']['headers']['X-Form'], 'per-form header applied');
});

fwi_test('classic: hook registration is still gated on the active flag', function (): void {
    fwi_configure([SettingsManager::OPTION_ACTIVE => false]);
    fwi_classic_bridge()->register();
    fwi_assert_same(0, fwi_hook_count('elementor_pro/forms/new_record'), 'not registered while inactive');

    fwi_reset();
    fwi_configure([SettingsManager::OPTION_ACTIVE => true]);
    fwi_classic_bridge()->register();
    fwi_assert_same(1, fwi_hook_count('elementor_pro/forms/new_record'), 'registered once when active');
});

// ─── Public API preserved ────────────────────────────────────────────────────

fwi_test('plugin wiring: one boot registers the classic, Atomic, and public hooks', function (): void {
    fwi_configure();

    // Plugin is a singleton whose init() runs once per request, so this is the
    // only test that boots it; everything the boot must wire is asserted here.
    fwi_boot_plugin();
    fwi_queue_post(200);

    fwi_assert(function_exists('fwi_submit_form'), 'fwi_submit_form() is defined');
    fwi_assert_same(1, fwi_hook_count('fwi_submission'), 'fwi_submission listener registered exactly once');
    fwi_assert_same(1, fwi_hook_count('elementor_pro/forms/new_record'), 'classic Elementor hook registered exactly once');
    fwi_assert_same(1, fwi_hook_count('elementor_pro/atomic_forms/actions/register'), 'Atomic action registration hook added once');
    fwi_assert_same(1, fwi_hook_count('elementor/atomic-widgets/controls'), 'Atomic editor controls filter added once');
    fwi_assert_same(1, fwi_hook_count('elementor_pro/atomic_forms/action_log_label'), 'Atomic action log label filter added once');
    fwi_assert_same(1, fwi_hook_count(FormsWebhookIntegrator\Webhook\RetryManager::HOOK), 'retry cron callback still registered');

    $result = fwi_submit_form(['form_name' => 'API Form', 'form_id' => 'api-1'], ['email' => 'a@b.test']);

    fwi_assert_same(true, $result->ok, 'fwi_submit_form reports success');
    fwi_assert_same('api-1', fwi_post_payload()['form_id'], 'caller form_id used as the payload fallback');

    fwi_queue_post(200);
    do_action('fwi_submission', ['form_name' => 'Hook Form', 'form_id' => 'hook-1'], ['email' => 'c@d.test']);
    fwi_assert_same(2, count(fwi_post_calls()), 'action hook dispatched a second submission');
});

// ─── Graceful degradation without Elementor Pro / Atomic Forms ───────────────

fwi_test('no Elementor: repeated register() does not duplicate callbacks', function (): void {
    fwi_configure();

    $bridge = fwi_atomic_bridge();
    $bridge->register();
    $bridge->register();

    fwi_assert_same(1, fwi_hook_count('elementor_pro/atomic_forms/actions/register'), 'no duplicate action hook');
    fwi_assert_same(1, fwi_hook_count('elementor/atomic-widgets/controls'), 'no duplicate controls filter');
});

fwi_test('no Elementor: action registration is skipped without fataling', function (): void {
    fwi_configure();

    $bridge = fwi_atomic_bridge();
    $bridge->register();

    // Fire the hook exactly as Elementor Pro would, plus a few hostile shapes.
    do_action('elementor_pro/atomic_forms/actions/register', 'ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Runner');
    do_action('elementor_pro/atomic_forms/actions/register', null);
    do_action('elementor_pro/atomic_forms/actions/register', 12345);
    do_action('elementor_pro/atomic_forms/actions/register', new stdClass());
    do_action('elementor_pro/atomic_forms/actions/register');

    // The action class must not even be loaded — its parent class is missing,
    // so autoloading it would be a fatal error.
    fwi_assert(
        !in_array(FwiWebhookAction::class, get_declared_classes(), true),
        'FwiWebhookAction was not autoloaded while Action_Base is unavailable'
    );
});

fwi_test('no Elementor: controls filter is a pass-through', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    $controls = ['unchanged'];
    fwi_assert_same($controls, apply_filters('elementor/atomic-widgets/controls', $controls, null), 'null element ignored');
    fwi_assert_same($controls, apply_filters('elementor/atomic-widgets/controls', $controls, new stdClass()), 'unknown element ignored');
    fwi_assert_same('not-an-array', apply_filters('elementor/atomic-widgets/controls', 'not-an-array', new stdClass()), 'non-array value returned as-is');
});

fwi_test('no Elementor: action log label filter only claims its own slug', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    fwi_assert_same(
        'Forms Webhook Integrator',
        apply_filters('elementor_pro/atomic_forms/action_log_label', 'Fwi Webhook', ElementorAtomicFormsBridge::ACTION_SLUG),
        'own slug relabelled'
    );
    fwi_assert_same(
        'Webhook',
        apply_filters('elementor_pro/atomic_forms/action_log_label', 'Webhook', 'webhook'),
        "Elementor's native action label untouched"
    );
});

fwi_test('no Elementor: submission forwarding still works through the bridge', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $result = fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1' => 'John'],
        fwi_atomic_context(['field_metadata' => ['e1' => ['label' => 'Name', 'type' => 'text']]])
    );

    fwi_assert_same(true, $result['ok'], 'delivery succeeded');
    fwi_assert_same('John', fwi_post_payload()['submission_data']['Name'], 'label used as the submission key');
});

// ─── Form discovery without Elementor loaded ─────────────────────────────────

fwi_test('discovery: finds classic and Atomic forms, with Atomic fallbacks', function (): void {
    fwi_configure();
    fwi_clear_elementor_data();

    fwi_seed_elementor_data(1, [
        [
            'elType'   => 'container',
            'elements' => [
                ['elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_name' => 'Classic Contact']],
                ['id' => 'aaa111', 'elType' => 'e-form', 'settings' => ['form-name' => ['$$type' => 'string', 'value' => 'Typed Atomic']], 'elements' => []],
                ['id' => 'bbb222', 'elType' => 'e-form', 'settings' => ['form-name' => 'Plain Atomic'], 'elements' => []],
                // No form-name at all: Elementor resolves the prop default.
                ['id' => 'ccc333', 'elType' => 'e-form', 'settings' => [], 'elements' => []],
                // Explicitly blank: Elementor falls back to the element id.
                ['id' => 'ddd444', 'elType' => 'e-form', 'settings' => ['form-name' => ['$$type' => 'string', 'value' => '']], 'elements' => []],
            ],
        ],
    ]);

    $forms = fwi_forms_helper()->getAllFormNames();

    foreach (['Classic Contact', 'Typed Atomic', 'Plain Atomic', 'Form', 'ddd444'] as $expected) {
        fwi_assert(in_array($expected, $forms, true), 'discovered "' . $expected . '" (got: ' . implode(', ', $forms) . ')');
    }

    fwi_assert_same(5, count($forms), 'no extra or duplicate entries');
});

fwi_test('discovery: Atomic field widgets are never listed as forms', function (): void {
    fwi_configure();
    fwi_clear_elementor_data();

    fwi_seed_elementor_data(2, [
        [
            'id'       => 'form1',
            'elType'   => 'e-form',
            'settings' => ['form-name' => 'Root Only'],
            'elements' => [
                ['id' => 'in1', 'elType' => 'widget', 'widgetType' => 'e-form-input', 'settings' => ['placeholder' => 'Name']],
                ['id' => 'cb1', 'elType' => 'widget', 'widgetType' => 'e-form-checkbox', 'settings' => []],
            ],
        ],
    ]);

    fwi_assert_same(['Root Only'], fwi_forms_helper()->getAllFormNames(), 'only the form root is listed');
});

fwi_test('discovery: malformed and non-form data is skipped safely', function (): void {
    fwi_configure();
    fwi_clear_elementor_data();

    fwi_seed_elementor_data(3, [
        'not-an-element',
        ['elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_name' => '']],
        ['elType' => 'e-form', 'settings' => ['form-name' => ['$$type' => 'string', 'value' => '']]], // no id either
        ['elType' => 'e-form', 'settings' => ['form-name' => ['$$type' => 'dynamic', 'value' => ['name' => 'post-title']]], 'id' => 'dyn1'],
    ]);

    fwi_assert_same(['dyn1'], fwi_forms_helper()->getAllFormNames(), 'unresolvable names fall back to the element id');
});

exit(fwi_run_tests('Suite: classic bridge, public API, and no-Elementor fallbacks'));
