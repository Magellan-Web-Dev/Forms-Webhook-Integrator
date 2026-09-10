<?php
/**
 * Shared test doubles and fixture helpers.
 *
 * Required from bootstrap.php so every suite has the same building blocks.
 */

declare(strict_types=1);

use FormsWebhookIntegrator\Forms\ElementorAtomicFormsBridge;
use FormsWebhookIntegrator\Forms\ElementorFormsBridge;
use FormsWebhookIntegrator\Forms\ElementorFormsHelper;
use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\RetryManager;
use FormsWebhookIntegrator\Webhook\WebhookHandler;

/**
 * Stands in for Elementor Pro's classic Elementor_Form_Record.
 */
final class FWI_Fake_Record
{
    /**
     * @param array<string, mixed> $settings Form settings, keyed as Elementor stores them.
     * @param array<string, array{value: mixed}> $fields Field rows keyed by field id.
     */
    public function __construct(
        private array $settings,
        private array $fields = []
    ) {}

    public function get_form_settings(string $key)
    {
        return $this->settings[$key] ?? null;
    }

    public function get(string $key)
    {
        return $key === 'fields' ? $this->fields : null;
    }
}

/**
 * Stands in for Elementor Pro's classic Ajax_Handler.
 */
final class FWI_Fake_Ajax_Handler
{
    /** @var array<int, string> */
    public array $errors = [];

    public bool $is_success = true;

    public function add_error_message(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Writes a working baseline configuration, then applies any overrides.
 *
 * @param array<string, mixed> $overrides Option name => value.
 */
function fwi_configure(array $overrides = []): void
{
    $defaults = [
        SettingsManager::OPTION_ACTIVE              => true,
        SettingsManager::OPTION_WEBHOOKS            => [['url' => 'https://hooks.test/primary', 'label' => 'Primary']],
        SettingsManager::OPTION_FAILURE_MODE        => 'retry',
        SettingsManager::OPTION_BLOCK_OUTSIDE_US    => false,
        SettingsManager::OPTION_INCLUDE_PAGE_PARAMS => false,
        SettingsManager::OPTION_EXCLUDED_FORMS      => [],
        SettingsManager::OPTION_FORM_OVERRIDES      => [],
        SettingsManager::OPTION_QUERY_PARAMS        => [],
        SettingsManager::OPTION_WEBHOOK_HEADERS     => [],
        SettingsManager::OPTION_CLIENT_FIRST_NAME   => 'Jane',
        SettingsManager::OPTION_CLIENT_LAST_NAME    => 'Smith',
        SettingsManager::OPTION_CLIENT_ID           => 'client-456',
        SettingsManager::OPTION_WEBSITE_ID          => 'site-123',
        // Pre-seeded so DatabaseManager::maybeCreateTable() is a no-op.
        'FWI_db_version'                            => '6.0',
    ];

    foreach (array_merge($defaults, $overrides) as $option => $value) {
        update_option($option, $value);
    }
}

/**
 * Builds the Atomic bridge over freshly constructed plugin services, the same
 * way Plugin's constructor wires it.
 */
function fwi_atomic_bridge(): ElementorAtomicFormsBridge
{
    $settings = new SettingsManager();

    return new ElementorAtomicFormsBridge(
        $settings,
        new WebhookHandler($settings),
        new RetryManager()
    );
}

/**
 * Builds the classic Elementor bridge over freshly constructed services.
 */
function fwi_classic_bridge(): ElementorFormsBridge
{
    $settings = new SettingsManager();

    return new ElementorFormsBridge(
        $settings,
        new WebhookHandler($settings),
        new RetryManager()
    );
}

function fwi_forms_helper(): ElementorFormsHelper
{
    return new ElementorFormsHelper();
}

/**
 * Registers a post whose _elementor_data the forms helper will discover.
 *
 * @param array<int, mixed> $elements Elementor element tree.
 */
function fwi_seed_elementor_data(int $postId, array $elements): void
{
    $GLOBALS['wpdb']->post_meta_rows[] = [
        'post_id'    => $postId,
        'meta_key'   => '_elementor_data',
        'meta_value' => json_encode($elements),
    ];
}

function fwi_clear_elementor_data(): void
{
    $GLOBALS['wpdb']->post_meta_rows = [];
}

/**
 * The Atomic action context Elementor Pro assembles in
 * Atomic_Form_Controller::ajax_send_form(), with room for overrides.
 *
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function fwi_atomic_context(array $overrides = []): array
{
    return array_merge([
        'post_id'             => 42,
        'form_id'             => 'e1a2b3c',
        'form_name'           => 'Atomic Contact Form',
        'field_metadata'      => [],
        'referer_title'       => 'Contact',
        'referrer'            => 'https://example.test/contact/',
        'cssid_map'           => [],
        'files'               => [],
        'file_field_settings' => [],
        'action_type'         => ElementorAtomicFormsBridge::ACTION_SLUG,
    ], $overrides);
}
