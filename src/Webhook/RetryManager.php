<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * Schedules and executes background retries for failed webhook deliveries.
 *
 * When the failure mode setting is "retry", the ElementorFormsBridge hands the
 * failed deliveries from a submission to scheduleRetries(). Each failed
 * endpoint delivery is stored as its own non-autoloaded option row and a
 * WP-Cron single event is scheduled per RETRY_SCHEDULE — quick retries first
 * to recover from short blips, then widening gaps to ride out long outages,
 * spanning roughly 24 hours in total. A delivery is attempted at most
 * MAX_ATTEMPTS times in total (the original send plus the scheduled retries);
 * after the final failure the row is deleted and the delivery is abandoned —
 * every attempt is already recorded in the webhook log, so nothing is
 * silently lost.
 *
 * The stored request (URL, headers, JSON body) is replayed verbatim so that
 * endpoints receive exactly what was originally dispatched, even if the
 * webhook settings change between the submission and the retry. The rows
 * necessarily hold the unredacted request (it must be re-sent as-is, including
 * any auth headers); they are admin-only wp_options data and are deleted on
 * success, on give-up, and by a daily stale-row sweep.
 *
 * Note: WP-Cron fires on page traffic, so on a low-traffic site each delay
 * is a floor — the retry runs on the first page load after it is due.
 */
final class RetryManager
{
    /**
     * Cron hook fired once per pending retry, with the retry id as its argument.
     */
    public const HOOK = 'fwi_webhook_retry';

    /**
     * Option-name prefix for pending retry rows; the suffix is a UUID v4.
     */
    public const OPTION_PREFIX = 'FWI_retry_';

    /**
     * Total delivery attempts per endpoint: the original send plus one retry
     * per RETRY_SCHEDULE entry (MAX_ATTEMPTS = 1 + count(RETRY_SCHEDULE)).
     */
    public const MAX_ATTEMPTS = 6;

    /**
     * Backoff delays in seconds before each retry, indexed by attempts already
     * made (attempt 1 is the original send, so RETRY_SCHEDULE[0] is the delay
     * before attempt 2). Quick retries first recover fast from short blips;
     * the widening gaps cover an outage of up to ~24.5 hours in total.
     */
    private const RETRY_SCHEDULE = [300, 1800, 7200, 21600, 57600]; // 5m, 30m, 2h, 6h, 16h

    /**
     * Age in seconds after which a pending retry row is considered orphaned
     * (its cron events were lost) and removed by the daily sweep. Retries
     * complete within ~24.5 hours, so 48 hours is comfortably past any
     * legitimate pending window.
     */
    private const STALE_AGE = 172800;

    /**
     * Records each retry attempt and its outcome for display on the analytics page.
     *
     * @var WebhookLogger
     */
    private readonly WebhookLogger $logger;

    public function __construct()
    {
        $this->logger = new WebhookLogger();
    }

    /**
     * Registers the retry cron callback and the daily stale-row sweep.
     *
     * Called unconditionally from {@see Plugin::init()} — not gated on the
     * active flag — so retries queued before the integration was toggled off
     * still complete.
     *
     * @return void
     */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'processRetry']);

        // Piggyback the orphaned-row sweep on the existing daily cleanup event.
        add_action('FWI_cleanup_old_logs', [$this, 'purgeStaleRetries']);
    }

    /**
     * Queues a background retry for each failed delivery of a submission.
     *
     * Each delivery gets its own option row (autoload off — payloads can be
     * large) and its own single cron event carrying only the row id, keeping
     * the serialized cron option small. Unique ids also sidestep WP-Cron's
     * duplicate-event suppression for near-simultaneous submissions.
     *
     * @param array<int, array{url: string, headers: array<string, string>, body: string, label: string}> $failedDeliveries
     *        Failed deliveries as collected by WebhookHandler, replayed verbatim.
     *
     * @return void
     */
    public function scheduleRetries(array $failedDeliveries): void
    {
        foreach ($failedDeliveries as $delivery) {
            if (empty($delivery['url']) || !isset($delivery['body'])) {
                continue;
            }

            $id = wp_generate_uuid4();

            add_option(self::OPTION_PREFIX . $id, [
                'url'        => (string) $delivery['url'],
                'headers'    => (array) ($delivery['headers'] ?? []),
                'body'       => (string) $delivery['body'],
                'label'      => (string) ($delivery['label'] ?? ''),
                'attempts'   => 1,
                'created_at' => time(),
            ], '', false);

            $this->scheduleEvent($id, self::retryDelay(1));
        }
    }

    /**
     * Returns the backoff delay in seconds before the next retry.
     *
     * The schedule is filterable (fwi_retry_schedule) so individual sites can
     * tune the delays without code changes. The retry count itself is fixed at
     * MAX_ATTEMPTS; a filtered schedule shorter than the default reuses its
     * last delay for any remaining attempts.
     *
     * @param int $attemptsMade Delivery attempts already made (>= 1; the
     *                          original send counts as attempt 1).
     *
     * @return int Seconds to wait before the next attempt (minimum 60).
     */
    private static function retryDelay(int $attemptsMade): int
    {
        $schedule = (array) apply_filters('fwi_retry_schedule', self::RETRY_SCHEDULE);
        $delay    = $schedule[$attemptsMade - 1] ?? end($schedule);

        return max(60, (int) $delay);
    }

    /**
     * Schedules the retry cron event for a pending row, then verifies the write
     * actually persisted and re-writes it once if not.
     *
     * WP-Cron stores its whole schedule in one option, so a concurrently
     * finishing cron process can overwrite a freshly added event (lost-update
     * race). The verification re-read closes the common case where the
     * overwrite has already landed; anything that slips past is picked up by
     * the daily {@see purgeStaleRetries()} reconcile sweep.
     *
     * @param string $retryId UUID suffix of the pending retry's option row.
     * @param int    $delay   Seconds from now to fire the retry.
     *
     * @return void
     */
    private function scheduleEvent(string $retryId, int $delay): void
    {
        wp_schedule_single_event(time() + $delay, self::HOOK, [$retryId]);

        // Force an uncached re-read of the cron option before verifying.
        wp_cache_delete('cron', 'options');
        wp_cache_delete('alloptions', 'options');

        if (wp_next_scheduled(self::HOOK, [$retryId]) === false) {
            wp_schedule_single_event(time() + $delay, self::HOOK, [$retryId]);
        }
    }

    /**
     * Cron callback: re-sends one stored delivery and reschedules or gives up.
     *
     * Idempotent against a missing row (already completed, cleared on
     * deactivation, or swept), so a stray duplicate cron event is harmless.
     * Every attempt is logged with a "(retry N/3)" label suffix so retries are
     * identifiable on the analytics page alongside the original failure.
     *
     * @param string $retryId UUID suffix of the pending retry's option row.
     *
     * @return void
     */
    public function processRetry(string $retryId): void
    {
        $optionName = self::OPTION_PREFIX . $retryId;
        $row        = get_option($optionName);

        if (!is_array($row) || empty($row['url'])) {
            return;
        }

        $attempt = ((int) ($row['attempts'] ?? 1)) + 1;
        $label   = (string) ($row['label'] ?? '');
        $logLabel = trim($label . ' (retry ' . $attempt . '/' . self::MAX_ATTEMPTS . ')');

        $response = wp_safe_remote_post((string) $row['url'], [
            'body'    => (string) $row['body'],
            'headers' => (array) ($row['headers'] ?? []),
            'timeout' => (int) apply_filters('fwi_webhook_timeout', 10),
        ]);

        // The logger re-applies sensitive-field redaction, so decoding the
        // stored (unredacted) body here does not leak into the log table.
        $decodedBody = json_decode((string) $row['body'], true);
        $requestData = is_array($decodedBody) ? $decodedBody : [];

        if (is_wp_error($response)) {
            $errorMessage = $response->get_error_message();
            error_log('FWI Webhook retry error (' . $logLabel . '): ' . $errorMessage);

            $this->logger->log(
                requestData: $requestData,
                requestUrl: (string) $row['url'],
                responseCode: 0,
                responseData: (string) wp_json_encode(['error' => $errorMessage]),
                webhookLabel: $logLabel
            );

            $succeeded = false;
        } else {
            $responseBody = wp_remote_retrieve_body($response);
            $responseCode = (int) wp_remote_retrieve_response_code($response);
            $succeeded    = in_array($responseCode, [200, 201, 202, 204], true);

            if (!$succeeded) {
                error_log('FWI Webhook retry response (' . $logLabel . '): ' . $responseBody);
            }

            $this->logger->log(
                requestData: $requestData,
                requestUrl: (string) $row['url'],
                responseCode: $responseCode,
                responseData: $responseBody,
                webhookLabel: $logLabel
            );
        }

        if ($succeeded || $attempt >= self::MAX_ATTEMPTS) {
            // Done — delivered, or out of attempts (all attempts are logged).
            delete_option($optionName);
            return;
        }

        $row['attempts'] = $attempt;
        update_option($optionName, $row, false);
        $this->scheduleEvent($retryId, self::retryDelay($attempt));
    }

    /**
     * Daily sweep reconciling pending retry rows with the cron schedule.
     *
     * WP-Cron's option-based storage has a known lost-update race: a concurrent
     * cron run can rewrite the cron option over a freshly scheduled event. Rows
     * younger than STALE_AGE whose event went missing are re-scheduled; rows
     * older than STALE_AGE are removed (retries complete within ~24.5 hours, so
     * anything that old is beyond recovery and its attempts are already logged).
     *
     * Safety net only: under normal operation processRetry() deletes each row
     * on success or final failure well within the STALE_AGE window.
     *
     * @return void
     */
    public function purgeStaleRetries(): void
    {
        foreach (self::getPendingOptionNames() as $optionName) {
            $row = get_option($optionName);

            $createdAt = is_array($row) ? (int) ($row['created_at'] ?? 0) : 0;
            if ($createdAt < time() - self::STALE_AGE) {
                delete_option($optionName);
                continue;
            }

            $retryId = substr($optionName, strlen(self::OPTION_PREFIX));
            if (wp_next_scheduled(self::HOOK, [$retryId]) === false) {
                // The lost event was already overdue, so re-run soon rather
                // than waiting out the row's nominal backoff slot again.
                $this->scheduleEvent($retryId, self::retryDelay(1));
            }
        }
    }

    /**
     * Removes all pending retries: unschedules every retry cron event and
     * deletes every pending row.
     *
     * Called from the deactivation hook. Discarded retries are not restored on
     * reactivation; their attempts remain visible in the webhook log.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        wp_unschedule_hook(self::HOOK);

        foreach (self::getPendingOptionNames() as $optionName) {
            delete_option($optionName);
        }
    }

    /**
     * Returns the option names of all pending retry rows.
     *
     * @return list<string>
     */
    private static function getPendingOptionNames(): array
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::OPTION_PREFIX) . '%'
        ));

        return is_array($names) ? $names : [];
    }
}
