<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Forms;

if (!defined('ABSPATH')) exit;

final class ElementorFormsHelper
{
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

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->extractFormNames($element['elements'], $forms);
            }
        }
    }
}
