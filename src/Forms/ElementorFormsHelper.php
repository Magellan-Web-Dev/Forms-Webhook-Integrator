<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Forms;

if (!defined('ABSPATH')) exit;

final class ElementorFormsHelper
{
    /**
     * Atomic Form root element type, as stored in _elementor_data's elType.
     */
    private const ATOMIC_FORM_ELEMENT_TYPE = 'e-form';

    /**
     * Atomic Form setting that holds the form name. Atomic settings are stored
     * either as typed values ({"$$type":"string","value":"Contact Form"}) or as
     * plain scalars, so both shapes are supported.
     */
    private const ATOMIC_FORM_NAME_SETTING = 'form-name';

    /**
     * Fallback for the Atomic form-name prop default, used when Elementor's
     * Atomic Form class is unavailable to report its own default.
     */
    private const ATOMIC_FORM_NAME_DEFAULT = 'Form';

    /**
     * Elementor core's Atomic Form element class, queried for the canonical
     * form-name prop default when Elementor is loaded.
     */
    private const ATOMIC_FORM_CLASS = 'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Form\\Atomic_Form';

    /**
     * Memoised Atomic form-name prop default for this request.
     *
     * @var string|null
     */
    private ?string $atomicFormNameDefault = null;

    /**
     * Retrieves all Elementor form widget names found across the entire site.
     *
     * Queries the postmeta table directly rather than using get_posts() or
     * WP_Query. This is necessary because get_posts() with post_type => 'any'
     * only searches *public* post types, silently excluding private ones such as
     * elementor_library — where template-based forms are stored. A direct postmeta
     * query has no such restriction and returns every post that carries Elementor
     * data regardless of post type, post status, or registration visibility.
     *
     * @return array<int, string>
     */
    public function getAllFormNames(): array
    {
        global $wpdb;

        $forms = [];

        /** @var string[] $postIds */
        $postIds = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_elementor_data'
            )
        );

        foreach ($postIds as $postId) {
            $rawData = get_post_meta((int) $postId, '_elementor_data', true);

            if (empty($rawData) || !is_string($rawData)) {
                continue;
            }

            $elements = json_decode($rawData, true);

            if (!is_array($elements)) {
                continue;
            }

            $this->extractFormNames($elements, $forms);
        }

        return array_values(array_unique($forms));
    }

    /**
     * Recursively walks Elementor element trees to find form widget names.
     *
     * Classic Elementor Pro forms are widgets (widgetType 'form', setting
     * 'form_name'); Atomic forms are elements (elType 'e-form', setting
     * 'form-name'). Both are collected so either kind can be excluded or given
     * per-form overrides on the settings page.
     *
     * @param array<int|string, mixed> $elements
     * @param array<int, string>       $forms
     */
    private function extractFormNames(array $elements, array &$forms): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (
                isset($element['widgetType']) &&
                $element['widgetType'] === 'form' &&
                isset($element['settings']['form_name']) &&
                is_string($element['settings']['form_name']) &&
                $element['settings']['form_name'] !== ''
            ) {
                $forms[] = $element['settings']['form_name'];
            }

            if ($this->isAtomicFormElement($element)) {
                $atomicName = $this->resolveAtomicFormName($element);

                if ($atomicName !== '') {
                    $forms[] = $atomicName;
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->extractFormNames($element['elements'], $forms);
            }
        }
    }

    /**
     * Determines whether an element node is an Atomic Form root.
     *
     * The root is stored as an element (elType 'e-form'); widgetType is also
     * checked so a future registration as a widget type is still detected.
     * Atomic *field* elements (e-form-input, e-form-checkbox, …) are widgets
     * with their own types and are intentionally not matched.
     *
     * @param array<string, mixed> $element
     */
    private function isAtomicFormElement(array $element): bool
    {
        return ($element['elType'] ?? null) === self::ATOMIC_FORM_ELEMENT_TYPE
            || ($element['widgetType'] ?? null) === self::ATOMIC_FORM_ELEMENT_TYPE;
    }

    /**
     * Resolves the name an Atomic form submits under.
     *
     * Mirrors Elementor's own resolution order so the discovered label matches
     * the form_name that actually arrives on submission:
     *
     *  1. A configured, non-empty 'form-name' setting (typed or plain).
     *  2. No setting at all — Elementor resolves the prop schema default
     *     (normally "Form"), which is what the form then posts.
     *  3. An explicitly empty name — Elementor omits data-form-name, and the
     *     Atomic controller falls back to the form's element id.
     *
     * @param array<string, mixed> $element
     *
     * @return string Discovered form name, or an empty string when nothing
     *                stable can be derived.
     */
    private function resolveAtomicFormName(array $element): string
    {
        $settings = $element['settings'] ?? null;
        $raw      = is_array($settings) ? ($settings[self::ATOMIC_FORM_NAME_SETTING] ?? null) : null;

        if ($raw === null) {
            return $this->getAtomicFormNameDefault();
        }

        $name = $this->unwrapAtomicValue($raw);

        if ($name !== '') {
            return $name;
        }

        $elementId = $element['id'] ?? null;

        return is_scalar($elementId) ? (string) $elementId : '';
    }

    /**
     * Reads a scalar out of an Atomic setting value.
     *
     * Handles both the typed shape ({"$$type":"string","value":"Contact Form"})
     * and plain scalars. Anything else — a disabled value, a dynamic-tag
     * reference, a nested structure — yields an empty string, since it cannot
     * be resolved without rendering the element.
     *
     * @param mixed $value
     */
    private function unwrapAtomicValue(mixed $value): string
    {
        if (is_array($value)) {
            if (!empty($value['disabled'])) {
                return '';
            }

            if (isset($value['$$type']) && array_key_exists('value', $value)) {
                return $this->unwrapAtomicValue($value['value']);
            }

            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Returns the Atomic form-name prop default.
     *
     * Read from Elementor's own schema where possible so translated defaults
     * still match the submitted form_name, falling back to the English literal
     * when Elementor (or the Atomic Form class) is unavailable.
     */
    private function getAtomicFormNameDefault(): string
    {
        if ($this->atomicFormNameDefault !== null) {
            return $this->atomicFormNameDefault;
        }

        $this->atomicFormNameDefault = self::ATOMIC_FORM_NAME_DEFAULT;
        $atomicFormClass             = self::ATOMIC_FORM_CLASS;

        if (!class_exists($atomicFormClass) || !is_callable([$atomicFormClass, 'get_props_schema'])) {
            return $this->atomicFormNameDefault;
        }

        try {
            $schema = $atomicFormClass::get_props_schema();
            $prop   = is_array($schema) ? ($schema[self::ATOMIC_FORM_NAME_SETTING] ?? null) : null;

            if (is_object($prop) && method_exists($prop, 'get_default')) {
                $default = $this->unwrapAtomicValue($prop->get_default());

                if ($default !== '') {
                    $this->atomicFormNameDefault = $default;
                }
            }
        } catch (\Throwable) {
            // Keep the literal fallback if Elementor's schema cannot be built.
        }

        return $this->atomicFormNameDefault;
    }
}
