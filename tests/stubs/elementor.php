<?php
/**
 * Minimal stand-ins for the Elementor / Elementor Pro Atomic Form classes the
 * plugin interacts with, mirroring the public API of the locally installed
 * Elementor 4.2.3 and Elementor Pro 4.2.3.
 *
 * Loaded only by the suites that exercise the Atomic integration; the other
 * suites deliberately run without these classes to prove the plugin degrades
 * gracefully when Elementor Pro or Atomic Forms are unavailable.
 */

declare(strict_types=1);

namespace ElementorPro\Modules\AtomicForm\Actions;

/**
 * Mirrors ElementorPro\Modules\AtomicForm\Actions\Action_Base.
 *
 * @see wp-content/plugins/elementor-pro/modules/atomic-form/actions/action-base.php
 */
abstract class Action_Base
{
    abstract public function get_type(): string;

    abstract public function execute(array $form_data, array $widget_settings, array $context): array;

    protected function validate_settings(array $widget_settings)
    {
        return true;
    }

    protected function success(string $message, array $additional_data = []): array
    {
        return array_merge(['status' => 'success', 'message' => $message], $additional_data);
    }

    protected function failure(string $error, array $additional_data = []): array
    {
        return array_merge(['status' => 'failed', 'error' => $error], $additional_data);
    }
}

/**
 * Mirrors ElementorPro\Modules\AtomicForm\Actions\Action_Type.
 */
class Action_Type
{
    const EMAIL = 'email';
    const COLLECT_SUBMISSIONS = 'collect-submissions';
    const WEBHOOK = 'webhook';

    public static function normalize_email_actions(string $type): string
    {
        return preg_match('/^' . preg_quote(self::EMAIL, '/') . '_\d+$/', $type) ? self::EMAIL : $type;
    }
}

/**
 * Mirrors ElementorPro\Modules\AtomicForm\Actions\Action_Runner, including the
 * `elementor_pro/atomic_forms/actions/register` hook contract (Elementor Pro
 * passes the runner *class name*, not an instance) and the execute_actions()
 * result shape.
 *
 * @see wp-content/plugins/elementor-pro/modules/atomic-form/actions/action-runner.php
 */
class Action_Runner
{
    /** @var array<string, Action_Base> */
    private static array $actions = [];

    public static function reset(): void
    {
        self::$actions = [];
    }

    public static function register_action(Action_Base $action): void
    {
        self::$actions[$action->get_type()] = $action;
    }

    public static function create_action(string $type): ?Action_Base
    {
        return self::$actions[$type] ?? null;
    }

    /** @return array<string, Action_Base> */
    public static function get_registered_actions(): array
    {
        return self::$actions;
    }

    public static function has_action(string $type): bool
    {
        return isset(self::$actions[$type]);
    }

    public static function execute_actions(array $actions, array $form_data, array $widget_settings, array $context): array
    {
        $action_results = [];
        $failed_actions = [];

        foreach ($actions as $action_type) {
            $normalized = Action_Type::normalize_email_actions($action_type);

            if (!self::has_action($normalized)) {
                $error            = sprintf('Invalid action type: %s', $action_type);
                $action_results[] = ['type' => $action_type, 'status' => 'failed', 'error' => $error];
                $failed_actions[] = $action_type;
                continue;
            }

            try {
                $action = self::create_action($normalized);
                $result = $action->execute($form_data, $widget_settings, array_merge($context, ['action_type' => $action_type]));

                $action_results[] = array_merge(['type' => $action_type], $result);

                // Note: Elementor Pro 4.2.3 does NOT add returned failures to
                // failedActions — only unregistered types and thrown exceptions.
                // Reproduced faithfully so the tests document real behaviour.
                $success = 'success' === $result['status'];
                if (!$success) {
                    $unused = $result['error'];
                }
            } catch (\Exception $e) {
                $action_results[] = ['type' => $action_type, 'status' => 'failed', 'error' => $e->getMessage()];
                $failed_actions[] = $action_type;
            }
        }

        return [
            'actionResults'       => $action_results,
            'allActionsSucceeded' => empty($failed_actions),
            'failedActions'       => $failed_actions,
        ];
    }

    /**
     * Fires the registration hook exactly as Elementor Pro's Action_Runner::init()
     * does — passing __CLASS__ as a class-string.
     */
    public static function init(): void
    {
        do_action('elementor_pro/atomic_forms/actions/register', __CLASS__);
    }
}

namespace Elementor\Modules\AtomicWidgets\Controls\Base;

/**
 * Mirrors Elementor\Modules\AtomicWidgets\Controls\Base\Atomic_Control_Base.
 *
 * @see wp-content/plugins/elementor/modules/atomic-widgets/controls/base/atomic-control-base.php
 */
abstract class Atomic_Control_Base
{
    private string $bind;
    private $label = null;
    private $meta = null;

    abstract public function get_type(): string;

    abstract public function get_props(): array;

    public static function bind_to(string $prop_name)
    {
        return new static($prop_name);
    }

    protected function __construct(string $prop_name)
    {
        $this->bind = $prop_name;
    }

    public function get_bind()
    {
        return $this->bind;
    }

    public function set_label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function set_meta($meta): self
    {
        $this->meta = $meta;

        return $this;
    }
}

namespace Elementor\Modules\AtomicWidgets\Controls\Types;

use Elementor\Modules\AtomicWidgets\Controls\Base\Atomic_Control_Base;

/**
 * Mirrors Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control —
 * private $options with a public setter and a public get_props() reader, and
 * no dedicated getter. That shape is what the plugin's editor filter relies on.
 *
 * @see wp-content/plugins/elementor/modules/atomic-widgets/controls/types/chips-control.php
 */
class Chips_Control extends Atomic_Control_Base
{
    private array $options = [];
    private bool $free_chips = false;

    public function get_type(): string
    {
        return 'chips';
    }

    public function set_options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function get_props(): array
    {
        return ['options' => $this->options, 'freeChips' => $this->free_chips];
    }

    public function set_free_chips(bool $free_chips): self
    {
        $this->free_chips = $free_chips;

        return $this;
    }
}

/**
 * Mirrors Elementor\Modules\AtomicWidgets\Controls\Types\Text_Control.
 */
class Text_Control extends Atomic_Control_Base
{
    public function get_type(): string
    {
        return 'text';
    }

    public function get_props(): array
    {
        return [];
    }
}

namespace Elementor\Modules\AtomicWidgets\Controls;

/**
 * Mirrors Elementor\Modules\AtomicWidgets\Controls\Section.
 *
 * @see wp-content/plugins/elementor/modules/atomic-widgets/controls/section.php
 */
class Section
{
    private ?string $id = null;
    private $label = null;
    private array $items = [];

    public static function make(): self
    {
        return new static();
    }

    public function set_id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function get_id()
    {
        return $this->id;
    }

    public function set_label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function get_label(): ?string
    {
        return $this->label;
    }

    public function set_items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    public function add_item($item): self
    {
        $this->items[] = $item;

        return $this;
    }

    public function get_items()
    {
        return $this->items;
    }
}

namespace Elementor\Modules\AtomicWidgets\PropTypes\Primitives;

/**
 * Enough of String_Prop_Type for the form-name prop default lookup: the typed
 * {"$$type":"string","value":"…"} shape returned by get_default().
 */
class String_Prop_Type
{
    private ?array $default = null;

    public static function make(): self
    {
        return new static();
    }

    public static function generate($value, $disable = false): array
    {
        return ['$$type' => 'string', 'value' => $value];
    }

    public function default($value): self
    {
        $this->default = static::generate($value);

        return $this;
    }

    public function get_default()
    {
        return $this->default;
    }
}

namespace Elementor\Modules\AtomicWidgets\Elements\Atomic_Form;

use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control;
use Elementor\Modules\AtomicWidgets\Controls\Types\Text_Control;
use Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type;

/**
 * Mirrors the parts of Elementor\Modules\AtomicWidgets\Elements\Atomic_Form
 * the plugin touches: the element type, the form-name prop default, and the
 * hardcoded "Actions after submit" chips control nested inside the Content
 * section (as produced by define_atomic_controls()).
 *
 * @see wp-content/plugins/elementor/modules/atomic-widgets/elements/atomic-form/atomic-form.php
 */
class Atomic_Form
{
    const ACTION_EMAIL = 'email';
    const ACTION_COLLECT_SUBMISSIONS = 'collect-submissions';
    const ACTION_WEBHOOK = 'webhook';

    public static function get_type(): string
    {
        return 'e-form';
    }

    public static function get_element_type(): string
    {
        return self::get_type();
    }

    public function get_name(): string
    {
        return static::get_element_type();
    }

    public static function get_props_schema(): array
    {
        return [
            'form-name' => String_Prop_Type::make()->default('Form'),
        ];
    }

    /**
     * Rebuilds Elementor's control tree shape: a Content Section whose items
     * include the actions-after-submit Chips control, plus sibling Sections.
     *
     * @return array<int, mixed>
     */
    public static function build_controls(): array
    {
        return [
            Section::make()
                ->set_label('Content')
                ->set_id('content')
                ->set_items([
                    Text_Control::bind_to('form-name')->set_label('Form name'),
                    Chips_Control::bind_to('actions-after-submit')
                        ->set_options([
                            ['label' => 'Email', 'value' => self::ACTION_EMAIL],
                            ['label' => 'Collect submissions', 'value' => self::ACTION_COLLECT_SUBMISSIONS],
                            ['label' => 'Webhook', 'value' => self::ACTION_WEBHOOK],
                        ])
                        ->set_label('Actions after submit'),
                ]),
            Section::make()
                ->set_label('Webhook')
                ->set_items([
                    Text_Control::bind_to('webhook_url')->set_label('Webhook URL'),
                ]),
        ];
    }
}

/**
 * Stands in for an Atomic form *field* element (e.g. e-form-input), which must
 * never receive the plugin's action option.
 */
class Atomic_Form_Field_Stub
{
    public function get_name(): string
    {
        return 'e-form-input';
    }

    /** @return array<int, mixed> */
    public static function build_controls(): array
    {
        return [
            \Elementor\Modules\AtomicWidgets\Controls\Section::make()
                ->set_label('Content')
                ->set_items([
                    // A field element with a same-named control would still be
                    // skipped, because the filter is gated on the element type.
                    \Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control::bind_to('actions-after-submit')
                        ->set_options([['label' => 'Email', 'value' => 'email']]),
                ]),
        ];
    }
}
