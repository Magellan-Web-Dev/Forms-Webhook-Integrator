<?php
/**
 * Suite: the Elementor Pro Atomic Forms integration, exercised against stubs
 * that mirror the public API of the locally installed Elementor / Elementor
 * Pro 4.2.3 Atomic Form classes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../stubs/elementor.php';

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control;
use Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form;
use Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form_Field_Stub;
use ElementorPro\Modules\AtomicForm\Actions\Action_Base;
use ElementorPro\Modules\AtomicForm\Actions\Action_Runner;
use ElementorPro\Modules\AtomicForm\Actions\Action_Type;
use FormsWebhookIntegrator\Forms\ElementorAtomicFormsBridge;
use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\RetryManager;

/**
 * Finds the actions-after-submit Chips control inside a control tree.
 */
function fwi_find_actions_control(array $controls): ?Chips_Control
{
    foreach ($controls as $control) {
        if ($control instanceof Section) {
            $found = fwi_find_actions_control($control->get_items());
            if ($found) {
                return $found;
            }
            continue;
        }

        if ($control instanceof Chips_Control && $control->get_bind() === 'actions-after-submit') {
            return $control;
        }
    }

    return null;
}

/** @return array<int, string> */
function fwi_action_option_values(array $controls): array
{
    $control = fwi_find_actions_control($controls);

    if (!$control) {
        return [];
    }

    return array_map(static fn($option) => (string) $option['value'], $control->get_props()['options']);
}

/**
 * Registers the plugin's Atomic action the way Elementor Pro does, and returns
 * the resulting Action_Base instance.
 */
function fwi_register_atomic_action(ElementorAtomicFormsBridge $bridge): ?Action_Base
{
    Action_Runner::reset();
    $bridge->register();
    Action_Runner::init();

    return Action_Runner::create_action(ElementorAtomicFormsBridge::ACTION_SLUG);
}

// ─── Action registration ─────────────────────────────────────────────────────

fwi_test('registration: custom action is registered under the fwi-webhook slug', function (): void {
    fwi_configure();

    $action = fwi_register_atomic_action(fwi_atomic_bridge());

    fwi_assert($action instanceof Action_Base, 'action extends Elementor Pro Action_Base');
    fwi_assert_same('fwi-webhook', $action->get_type(), 'slug is fwi-webhook');
    fwi_assert_same('fwi-webhook', ElementorAtomicFormsBridge::ACTION_SLUG, 'slug constant matches');
});

fwi_test("registration: Elementor's native webhook action is not replaced", function (): void {
    fwi_configure();

    Action_Runner::reset();

    // Stand in for Elementor Pro's own Webhook_Action registration.
    Action_Runner::register_action(new class extends Action_Base {
        public function get_type(): string
        {
            return Action_Type::WEBHOOK;
        }

        public function execute(array $form_data, array $widget_settings, array $context): array
        {
            return $this->success('native');
        }
    });

    fwi_atomic_bridge()->register();
    do_action('elementor_pro/atomic_forms/actions/register', Action_Runner::class);

    fwi_assert(Action_Runner::has_action(Action_Type::WEBHOOK), 'native webhook action still registered');
    fwi_assert(Action_Runner::has_action(ElementorAtomicFormsBridge::ACTION_SLUG), 'FWI action registered alongside it');
    fwi_assert_same(
        'native',
        Action_Runner::create_action(Action_Type::WEBHOOK)->execute([], [], [])['message'],
        'native webhook action untouched'
    );
});

fwi_test('registration: an existing fwi-webhook action is left in place', function (): void {
    fwi_configure();

    Action_Runner::reset();
    Action_Runner::register_action(new class extends Action_Base {
        public function get_type(): string
        {
            return ElementorAtomicFormsBridge::ACTION_SLUG;
        }

        public function execute(array $form_data, array $widget_settings, array $context): array
        {
            return $this->success('pre-existing');
        }
    });

    fwi_atomic_bridge()->register();
    do_action('elementor_pro/atomic_forms/actions/register', Action_Runner::class);

    fwi_assert_same(
        'pre-existing',
        Action_Runner::create_action(ElementorAtomicFormsBridge::ACTION_SLUG)->execute([], [], [])['message'],
        'already-registered action of the same slug is not overwritten'
    );
});

// ─── Editor option ───────────────────────────────────────────────────────────

fwi_test('editor: option is appended once to the Atomic Form root', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    $controls = apply_filters('elementor/atomic-widgets/controls', Atomic_Form::build_controls(), new Atomic_Form());

    fwi_assert_same(
        ['email', 'collect-submissions', 'webhook', 'fwi-webhook'],
        fwi_action_option_values($controls),
        'FWI choice appended after the native choices'
    );

    $control = fwi_find_actions_control($controls);
    $options = $control->get_props()['options'];
    fwi_assert_same('Forms Webhook Integrator', $options[3]['label'], 'label is exactly "Forms Webhook Integrator"');
});

fwi_test('editor: re-running the filter does not duplicate the option', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    $element  = new Atomic_Form();
    $controls = Atomic_Form::build_controls();

    $controls = apply_filters('elementor/atomic-widgets/controls', $controls, $element);
    $controls = apply_filters('elementor/atomic-widgets/controls', $controls, $element);
    $controls = apply_filters('elementor/atomic-widgets/controls', $controls, $element);

    $values = fwi_action_option_values($controls);

    fwi_assert_same(1, count(array_keys($values, 'fwi-webhook', true)), 'option present exactly once');
    fwi_assert_same(4, count($values), 'no other options added or lost');
});

fwi_test('editor: option is not added to Atomic field elements', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    $controls = apply_filters(
        'elementor/atomic-widgets/controls',
        Atomic_Form_Field_Stub::build_controls(),
        new Atomic_Form_Field_Stub()
    );

    fwi_assert_same(['email'], fwi_action_option_values($controls), 'field element controls untouched');
});

// ─── Submission forwarding ───────────────────────────────────────────────────

fwi_test('submission: Atomic data is forwarded through the existing handler', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $action = fwi_register_atomic_action(fwi_atomic_bridge());

    $result = $action->execute(
        ['f_name' => 'John Doe', 'f_email' => 'john@example.com'],
        ['webhook_url' => 'https://elementor-native.test/hook'],
        fwi_atomic_context([
            'field_metadata' => [
                'f_name'  => ['label' => 'Full name', 'type' => 'text'],
                'f_email' => ['label' => 'Email', 'type' => 'email'],
            ],
        ])
    );

    fwi_assert_same('success', $result['status'], 'Atomic action reports success');
    fwi_assert_same(1, count(fwi_post_calls()), 'exactly one webhook POST');
    fwi_assert_same(
        'https://hooks.test/primary',
        fwi_post_calls()[0]['url'],
        "the plugin's endpoint is used, not the native webhook_url setting"
    );

    $payload = fwi_post_payload();
    fwi_assert_same('Atomic Contact Form', $payload['form_name'], 'Atomic form_name maps to form_name');
    fwi_assert_same('e1a2b3c', $payload['form_id'], 'Atomic form_id maps to the native form_id');
    fwi_assert_same('John Doe', $payload['submission_data']['Full name'], 'field value forwarded');
    fwi_assert_same('john@example.com', $payload['submission_data']['Email'], 'second field forwarded');
    fwi_assert_same('Test Site', $payload['website_info']['name'], 'existing payload shape preserved');
    fwi_assert_same('site-123', $payload['website_info']['id'], 'website id preserved');
    fwi_assert_same(1, count(fwi_log_rows()), 'request recorded by the analytics logger');
});

fwi_test('submission: field ids are used when no label is supplied', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1a' => 'value-a', 'e1b' => 'value-b'],
        fwi_atomic_context(['field_metadata' => ['e1a' => ['label' => '', 'type' => 'text']]])
    );

    $data = fwi_post_payload()['submission_data'];
    fwi_assert_same('value-a', $data['e1a'], 'blank label falls back to the field id');
    fwi_assert_same('value-b', $data['e1b'], 'missing metadata falls back to the field id');
});

fwi_test('submission: duplicate labels are disambiguated, never dropped', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1' => 'first', 'e2' => 'second', 'e3' => 'third'],
        fwi_atomic_context(['field_metadata' => [
            'e1' => ['label' => 'Name'],
            'e2' => ['label' => 'Name'],
            'e3' => ['label' => 'Name'],
        ]])
    );

    $data = fwi_post_payload()['submission_data'];
    fwi_assert_same(3, count($data), 'all three fields survive the collision');
    fwi_assert_same('first', $data['Name'], 'first field keeps the label');
    fwi_assert_same('second', $data['Name_e2'], 'second field is suffixed with its id');
    fwi_assert_same('third', $data['Name_e3'], 'third field is suffixed with its id');
});

fwi_test('submission: multi-value fields stay arrays, never "Array"', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        [
            'checkboxes' => ['Option A', 'Option B'],
            'multi'      => ['one', ['nested-two', 'nested-three']],
            'empty'      => [],
            'single'     => 'plain',
            'flag'       => true,
            'nothing'    => null,
        ],
        fwi_atomic_context(['field_metadata' => [
            'checkboxes' => ['label' => 'Interests', 'type' => 'checkbox'],
        ]])
    );

    $data = fwi_post_payload()['submission_data'];

    fwi_assert_same(['Option A', 'Option B'], $data['Interests'], 'checkbox group stays an array');
    fwi_assert_same(['one', 'nested-two', 'nested-three'], $data['multi'], 'nested arrays are flattened, not stringified');
    fwi_assert_same([], $data['empty'], 'empty multi-value field stays an empty array');
    fwi_assert_same('plain', $data['single'], 'scalar unchanged');
    fwi_assert_same('1', $data['flag'], 'boolean coerced without notices');
    fwi_assert_same('', $data['nothing'], 'null coerced to an empty string');

    fwi_assert(
        !str_contains((string) fwi_post_calls()[0]['args']['body'], '"Array"'),
        'payload never contains the literal string "Array"'
    );
});

fwi_test('submission: uploaded-file URL arrays are handled safely', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $urls = [
        'https://example.test/wp-content/uploads/2026/09/spec.pdf',
        'https://example.test/wp-content/uploads/2026/09/photo.jpg',
    ];

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['upload1' => $urls],
        fwi_atomic_context([
            'field_metadata' => ['upload1' => ['label' => 'Attachments', 'type' => 'file']],
            // Elementor also passes local paths; they must not alter the payload.
            'files'          => ['upload1' => ['/var/www/uploads/spec.pdf', '/var/www/uploads/photo.jpg']],
        ])
    );

    fwi_assert_same($urls, fwi_post_payload()['submission_data']['Attachments'], 'file URL list forwarded intact');
});

fwi_test('submission: per-form Form ID override beats the native Atomic form id', function (): void {
    fwi_configure([SettingsManager::OPTION_FORM_OVERRIDES => [
        'Atomic Contact Form' => ['form_id' => 'crm-form-9'],
    ]]);
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context());

    fwi_assert_same('crm-form-9', fwi_post_payload()['form_id'], 'configured override wins');
});

fwi_test('submission: global and per-form headers and query params are applied', function (): void {
    fwi_configure([
        SettingsManager::OPTION_QUERY_PARAMS    => [['key' => 'source', 'value' => 'global']],
        SettingsManager::OPTION_WEBHOOK_HEADERS => [['key' => 'X-Global', 'value' => 'yes']],
        SettingsManager::OPTION_FORM_OVERRIDES  => [
            'Atomic Contact Form' => [
                'query_params' => [['key' => 'source', 'value' => 'per-form'], ['key' => 'campaign', 'value' => 'spring']],
                'headers'      => [['key' => 'X-Form', 'value' => 'atomic'], ['key' => 'X-Global', 'value' => 'overridden']],
            ],
        ],
    ]);
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context());

    $call = fwi_post_calls()[0];
    parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);

    fwi_assert_same('per-form', $query['source'], 'per-form query param takes precedence');
    fwi_assert_same('spring', $query['campaign'], 'per-form query param added');
    fwi_assert_same('atomic', $call['args']['headers']['X-Form'], 'per-form header applied');
    fwi_assert_same('overridden', $call['args']['headers']['X-Global'], 'per-form header overrides the global one');
    fwi_assert_same('application/json', $call['args']['headers']['Content-Type'], 'JSON content type preserved');

    $params = fwi_post_payload()['custom_parameters'];
    fwi_assert_same('per-form', $params['source'], 'custom_parameters mirrors the merged params');
    fwi_assert_same('spring', $params['campaign'], 'custom_parameters includes per-form params');
});

fwi_test("submission: page params prefer Atomic's sanitised referrer", function (): void {
    fwi_configure([SettingsManager::OPTION_INCLUDE_PAGE_PARAMS => true]);
    $_SERVER['HTTP_REFERER'] = 'https://example.test/wrong/?utm_source=header';
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1' => 'x'],
        fwi_atomic_context(['referrer' => 'https://example.test/contact/?utm_source=context&utm_medium=cpc'])
    );

    parse_str((string) parse_url(fwi_post_calls()[0]['url'], PHP_URL_QUERY), $query);

    fwi_assert_same('context', $query['utm_source'], 'context referrer wins over HTTP_REFERER');
    fwi_assert_same('cpc', $query['utm_medium'], 'all context referrer params forwarded');
});

fwi_test('submission: page params fall back to HTTP_REFERER', function (): void {
    fwi_configure([SettingsManager::OPTION_INCLUDE_PAGE_PARAMS => true]);
    $_SERVER['HTTP_REFERER'] = 'https://example.test/contact/?utm_source=header';
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context(['referrer' => '']));

    parse_str((string) parse_url(fwi_post_calls()[0]['url'], PHP_URL_QUERY), $query);

    fwi_assert_same('header', $query['utm_source'], 'HTTP_REFERER used when the context referrer is empty');
});

fwi_test('submission: per-form page-param toggle works without the global flag', function (): void {
    fwi_configure([
        SettingsManager::OPTION_INCLUDE_PAGE_PARAMS => false,
        SettingsManager::OPTION_FORM_OVERRIDES      => [
            'Atomic Contact Form' => ['include_page_params' => true],
        ],
    ]);
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1' => 'x'],
        fwi_atomic_context(['referrer' => 'https://example.test/contact/?utm_source=per-form'])
    );

    parse_str((string) parse_url(fwi_post_calls()[0]['url'], PHP_URL_QUERY), $query);
    fwi_assert_same('per-form', $query['utm_source'], 'per-form toggle enables page params');
});

fwi_test('submission: page params are omitted when both flags are off', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    fwi_atomic_bridge()->handleAtomicSubmission(
        ['e1' => 'x'],
        fwi_atomic_context(['referrer' => 'https://example.test/contact/?utm_source=ignored'])
    );

    fwi_assert_same(
        'https://hooks.test/primary',
        fwi_post_calls()[0]['url'],
        'no page params appended to the webhook URL'
    );
});

// ─── Skipped / disabled paths ────────────────────────────────────────────────

fwi_test('skip: excluded forms send nothing and do not fail the form', function (): void {
    fwi_configure([SettingsManager::OPTION_EXCLUDED_FORMS => ['Atomic Contact Form']]);

    $action = fwi_register_atomic_action(fwi_atomic_bridge());
    $result = $action->execute(['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same('success', $result['status'], 'Atomic action still succeeds');
    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
    fwi_assert_same(0, count(fwi_log_rows()), 'nothing logged');
});

fwi_test('skip: disabled integration yields a successful no-op, not an invalid action', function (): void {
    fwi_configure([SettingsManager::OPTION_ACTIVE => false]);

    $bridge = fwi_atomic_bridge();
    fwi_register_atomic_action($bridge);

    $results = Action_Runner::execute_actions(
        [ElementorAtomicFormsBridge::ACTION_SLUG],
        ['e1' => 'x'],
        [],
        fwi_atomic_context()
    );

    fwi_assert_same(true, $results['allActionsSucceeded'], 'the visitor submission is not failed');
    fwi_assert_same([], $results['failedActions'], 'no failed actions');
    fwi_assert_same('success', $results['actionResults'][0]['status'], 'action reports a successful skip');
    fwi_assert(
        !str_contains((string) ($results['actionResults'][0]['error'] ?? ''), 'Invalid action type'),
        'no "Invalid action type" error'
    );
    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched while inactive');
});

fwi_test('skip: an unregistered slug is what actually breaks a form (control case)', function (): void {
    fwi_configure();
    Action_Runner::reset();

    $results = Action_Runner::execute_actions([ElementorAtomicFormsBridge::ACTION_SLUG], ['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same(false, $results['allActionsSucceeded'], 'unregistered slug fails the submission');
    fwi_assert(
        str_contains($results['actionResults'][0]['error'], 'Invalid action type'),
        'confirms why the action must stay registered'
    );
});

fwi_test('skip: a blank Atomic form name is skipped rather than failed', function (): void {
    fwi_configure();

    $result = fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context(['form_name' => '']));

    fwi_assert_same(true, $result['ok'], 'submission not failed');
    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
});

// ─── Failure handling ────────────────────────────────────────────────────────

fwi_test('failure: show_error mode reports a failed Atomic action', function (): void {
    fwi_configure([SettingsManager::OPTION_FAILURE_MODE => 'show_error']);
    fwi_queue_post(500, 'boom');

    $action = fwi_register_atomic_action(fwi_atomic_bridge());
    $result = $action->execute(['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same('failed', $result['status'], 'status is failed');
    fwi_assert(!empty($result['error']), 'an error message is supplied');
    fwi_assert_same(0, count(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK)), 'no retry queued');
});

fwi_test('failure: Elementor Pro 4.2.3 does not surface a returned failure to the visitor', function (): void {
    // Documents a real Action_Runner limitation in Elementor Pro 4.2.3: a result
    // of ['status' => 'failed'] is recorded in actionResults (and the submissions
    // action log) but is never added to failedActions, so allActionsSucceeded
    // stays true. Elementor's own native Webhook action is affected identically.
    // Only unregistered action types and thrown exceptions flip the form state.
    fwi_configure([SettingsManager::OPTION_FAILURE_MODE => 'show_error']);
    fwi_queue_post(500, 'boom');

    fwi_register_atomic_action(fwi_atomic_bridge());

    $results = Action_Runner::execute_actions(
        [ElementorAtomicFormsBridge::ACTION_SLUG],
        ['e1' => 'x'],
        [],
        fwi_atomic_context()
    );

    fwi_assert_same('failed', $results['actionResults'][0]['status'], 'the failure is reported to Elementor');
    fwi_assert(!empty($results['actionResults'][0]['error']), 'the failure carries an error message');
    fwi_assert_same(true, $results['allActionsSucceeded'], 'Elementor Pro 4.2.3 still reports the run as succeeded');
});

fwi_test('failure: retry mode schedules every failed delivery and reports success', function (): void {
    fwi_configure([
        SettingsManager::OPTION_FAILURE_MODE => 'retry',
        SettingsManager::OPTION_WEBHOOKS     => [
            ['url' => 'https://hooks.test/one', 'label' => 'One'],
            ['url' => 'https://hooks.test/two', 'label' => 'Two'],
        ],
    ]);
    fwi_queue_post(500, 'down');
    fwi_queue_post(new WP_Error('http_request_failed', 'Connection timed out'));

    $action = fwi_register_atomic_action(fwi_atomic_bridge());
    $result = $action->execute(['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same('success', $result['status'], 'visitor sees the normal success state');
    fwi_assert_same(
        2,
        count(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK)),
        'one retry scheduled per failed endpoint'
    );
    fwi_assert_same(2, count(fwi_log_rows()), 'both attempts logged');
});

fwi_test('failure: a partial multi-endpoint failure is still a failure in show_error mode', function (): void {
    fwi_configure([
        SettingsManager::OPTION_FAILURE_MODE => 'show_error',
        SettingsManager::OPTION_WEBHOOKS     => [
            ['url' => 'https://hooks.test/one', 'label' => 'One'],
            ['url' => 'https://hooks.test/two', 'label' => 'Two'],
        ],
    ]);
    // First endpoint fails, last endpoint succeeds — legacy `ok` reports true.
    fwi_queue_post(500, 'down');
    fwi_queue_post(200);

    $action = fwi_register_atomic_action(fwi_atomic_bridge());
    $result = $action->execute(['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same('failed', $result['status'], 'non-empty failedDeliveries is treated as a failure');
});

fwi_test('failure: a partial multi-endpoint failure retries only the failed endpoint', function (): void {
    fwi_configure([
        SettingsManager::OPTION_FAILURE_MODE => 'retry',
        SettingsManager::OPTION_WEBHOOKS     => [
            ['url' => 'https://hooks.test/one', 'label' => 'One'],
            ['url' => 'https://hooks.test/two', 'label' => 'Two'],
        ],
    ]);
    fwi_queue_post(500, 'down');
    fwi_queue_post(200);

    $result = fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context());

    fwi_assert_same(true, $result['ok'], 'reported as success to Elementor');

    $retries = array_values(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK));
    fwi_assert_same(1, count($retries), 'only the failed endpoint is queued');

    $stored = get_option(RetryManager::OPTION_PREFIX . $retries[0]['args'][0]);
    fwi_assert_same('https://hooks.test/one', $stored['url'], 'the queued retry targets the failed endpoint');
});

fwi_test('failure: geolocation rejection stays a failure even in retry mode', function (): void {
    fwi_configure([
        SettingsManager::OPTION_FAILURE_MODE     => 'retry',
        SettingsManager::OPTION_BLOCK_OUTSIDE_US => true,
    ]);
    $GLOBALS['FWI_T']['geo'] = ['response' => ['code' => 200], 'body' => json_encode(['country_name' => 'Canada'])];

    $action = fwi_register_atomic_action(fwi_atomic_bridge());
    $result = $action->execute(['e1' => 'x'], [], fwi_atomic_context());

    fwi_assert_same('failed', $result['status'], 'pre-dispatch rejection is not retried away');
    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
    fwi_assert_same(0, count(array_filter(fwi_cron_events(), fn($e) => $e['hook'] === RetryManager::HOOK)), 'no retry queued');
});

fwi_test('failure: a missing webhook URL stays a failure in retry mode', function (): void {
    fwi_configure([
        SettingsManager::OPTION_FAILURE_MODE => 'retry',
        SettingsManager::OPTION_WEBHOOKS     => [],
    ]);

    $result = fwi_atomic_bridge()->handleAtomicSubmission(['e1' => 'x'], fwi_atomic_context());

    fwi_assert_same(false, $result['ok'], 'missing configuration is reported as a failure');
    fwi_assert_same(0, count(fwi_post_calls()), 'nothing dispatched');
});

// ─── Coexistence ─────────────────────────────────────────────────────────────

fwi_test('coexistence: FWI runs alongside native actions without duplicate delivery', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $bridge = fwi_atomic_bridge();
    fwi_register_atomic_action($bridge);

    $nativeRuns = 0;
    Action_Runner::register_action(new class($nativeRuns) extends Action_Base {
        public function __construct(private int &$runs) {}

        public function get_type(): string
        {
            return Action_Type::EMAIL;
        }

        public function execute(array $form_data, array $widget_settings, array $context): array
        {
            $this->runs++;

            return $this->success('sent');
        }
    });

    $results = Action_Runner::execute_actions(
        [Action_Type::EMAIL, ElementorAtomicFormsBridge::ACTION_SLUG],
        ['e1' => 'x'],
        [],
        fwi_atomic_context()
    );

    fwi_assert_same(true, $results['allActionsSucceeded'], 'both actions succeed');
    fwi_assert_same(1, $nativeRuns, 'native action ran once');
    fwi_assert_same(1, count(fwi_post_calls()), 'exactly one FWI delivery');
});

fwi_test('coexistence: a classic submission does not also run the Atomic action', function (): void {
    fwi_configure();
    fwi_queue_post(200);

    $atomic  = fwi_atomic_bridge();
    fwi_register_atomic_action($atomic);
    fwi_classic_bridge()->register();

    do_action(
        'elementor_pro/forms/new_record',
        new FWI_Fake_Record(['form_name' => 'Classic Contact'], ['name' => ['value' => 'John']]),
        new FWI_Fake_Ajax_Handler()
    );

    fwi_assert_same(1, count(fwi_post_calls()), 'classic submission delivered exactly once');
    fwi_assert_same('Classic Contact', fwi_post_payload()['form_name'], 'classic form name used');
});

// ─── Editor / logging integration details ────────────────────────────────────

fwi_test('logging: the action log label is humanised', function (): void {
    fwi_configure();
    fwi_atomic_bridge()->register();

    fwi_assert_same(
        'Forms Webhook Integrator',
        apply_filters('elementor_pro/atomic_forms/action_log_label', 'Fwi Webhook', ElementorAtomicFormsBridge::ACTION_SLUG),
        'submissions action log shows the plugin name'
    );
});

fwi_test('discovery: Atomic form names resolve against Elementor\'s prop default', function (): void {
    fwi_configure();
    fwi_clear_elementor_data();

    fwi_seed_elementor_data(7, [
        ['id' => 'a1', 'elType' => 'e-form', 'settings' => ['form-name' => ['$$type' => 'string', 'value' => 'Typed Atomic']]],
        ['id' => 'a2', 'elType' => 'e-form', 'settings' => []],
    ]);

    $forms = fwi_forms_helper()->getAllFormNames();

    fwi_assert(in_array('Typed Atomic', $forms, true), 'typed name discovered');
    fwi_assert(
        in_array(Atomic_Form::get_props_schema()['form-name']->get_default()['value'], $forms, true),
        "missing name resolves to Elementor's own prop default"
    );
});

fwi_test('element type is read from Elementor when available', function (): void {
    fwi_assert_same(
        Atomic_Form::get_element_type(),
        ElementorAtomicFormsBridge::atomicFormElementType(),
        "bridge agrees with Elementor's element type"
    );
});

exit(fwi_run_tests('Suite: Elementor Pro Atomic Forms integration'));
