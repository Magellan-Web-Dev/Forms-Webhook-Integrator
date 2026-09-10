<?php
/**
 * Test bootstrap for Forms Webhook Integrator.
 *
 * The plugin has no Composer/PHPUnit tooling, so this file provides a
 * dependency-free harness: just enough WordPress API surface to load the
 * plugin's classes in isolation, a tiny assertion library, and a recording
 * HTTP layer so no test can ever reach a real webhook endpoint.
 *
 * Run the whole suite with:  php tests/run-tests.php
 */

declare(strict_types=1);

// ─── Harness state ───────────────────────────────────────────────────────────

/**
 * Everything the stubs read and record. Reset between tests with fwi_reset().
 */
$GLOBALS['FWI_T'] = [];

function fwi_reset(): void
{
    $GLOBALS['FWI_T'] = [
        'options'     => [],
        'post_queue'  => [],   // Queued wp_safe_remote_post() results (FIFO).
        'post_calls'  => [],   // Every outbound POST the code attempted.
        'geo'         => null, // wp_remote_get() result for the ipapi.co lookup.
        'cron'        => [],   // Scheduled single events.
        'log_rows'    => [],   // Rows written through $wpdb->insert().
    ];

    // Hooks are per-test too, so callback counts can be asserted exactly.
    $GLOBALS['FWI_HOOKS'] = [];

    $_SERVER['HTTP_REFERER'] = '';
    $_SERVER['REMOTE_ADDR']  = '203.0.113.5';
}

/**
 * Runs the plugin's real composition root, exactly as the plugins_loaded hook
 * would, so wiring and hook registration can be asserted end to end.
 */
function fwi_boot_plugin(): void
{
    FormsWebhookIntegrator\Plugin::getInstance()->init();
}

/**
 * Queues one wp_safe_remote_post() result. Accepts an HTTP status int, or a
 * WP_Error to simulate a transport failure.
 */
function fwi_queue_post(int|WP_Error $result, string $body = '{"ok":true}'): void
{
    $GLOBALS['FWI_T']['post_queue'][] = $result instanceof WP_Error
        ? $result
        : ['response' => ['code' => $result], 'body' => $body];
}

/** @return array<int, array{url: string, args: array}> */
function fwi_post_calls(): array
{
    return $GLOBALS['FWI_T']['post_calls'];
}

/** @return array<int, array{ts: int, hook: string, args: array}> */
function fwi_cron_events(): array
{
    return $GLOBALS['FWI_T']['cron'];
}

/** @return array<int, array<string, mixed>> */
function fwi_log_rows(): array
{
    return $GLOBALS['FWI_T']['log_rows'];
}

/**
 * Decoded JSON body of the nth recorded webhook POST.
 *
 * @return array<string, mixed>
 */
function fwi_post_payload(int $index = 0): array
{
    $call = $GLOBALS['FWI_T']['post_calls'][$index] ?? null;
    if (!is_array($call)) {
        return [];
    }

    $decoded = json_decode((string) ($call['args']['body'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

// ─── Fake $wpdb ──────────────────────────────────────────────────────────────

final class FWI_Test_WPDB
{
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';

    /** @var array<int, array<string, mixed>> */
    public array $post_meta_rows = [];

    public function insert(string $table, array $data, array $format = []): int
    {
        $GLOBALS['FWI_T']['log_rows'][] = $data + ['_table' => $table];

        return 1;
    }

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $replacement = is_int($arg) || is_float($arg) ? (string) $arg : "'" . $arg . "'";
            $query       = preg_replace('/%[dsf]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /** @return array<int, string> */
    public function get_col($query = null): array
    {
        return array_map(static fn($row) => (string) $row['post_id'], $this->post_meta_rows);
    }

    public function get_results($query = null, $output = null): array
    {
        return [];
    }

    public function get_var($query = null)
    {
        return 0;
    }

    public function delete(string $table, array $where, array $format = []): int
    {
        return 1;
    }

    public function query(string $query): int
    {
        return 1;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}

$GLOBALS['wpdb'] = new FWI_Test_WPDB();

// ─── WordPress API stubs ─────────────────────────────────────────────────────

// Points at tests/wp/, which carries the one WordPress include the plugin
// requires at runtime (wp-admin/includes/upgrade.php for dbDelta).
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Keep error_log() output out of the test report.
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/fwi-tests-error.log');

// Any PHP notice, warning, or deprecation fails the test that triggered it —
// "must not cause warnings or errors" is part of the contract being verified.
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) {
        return false; // Respect @-suppression and the current error mask.
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

class WP_Error
{
    public function __construct(
        private string $code = '',
        private string $message = ''
    ) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

// Hooks ───────────────────────────────────────────────────────────────────────

$GLOBALS['FWI_HOOKS'] = [];

function add_action(string $hook, $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): bool
{
    $id = fwi_callback_id($callback);

    // Mirrors WordPress: an identical callback is only stored once per hook/priority.
    $GLOBALS['FWI_HOOKS'][$hook][$priority][$id] = ['cb' => $callback, 'args' => $args];

    return true;
}

function remove_action(string $hook, $callback, int $priority = 10): bool
{
    return remove_filter($hook, $callback, $priority);
}

function remove_filter(string $hook, $callback, int $priority = 10): bool
{
    unset($GLOBALS['FWI_HOOKS'][$hook][$priority][fwi_callback_id($callback)]);

    return true;
}

function has_action(string $hook, $callback = false)
{
    if ($callback === false) {
        return !empty($GLOBALS['FWI_HOOKS'][$hook]);
    }

    $id = fwi_callback_id($callback);

    foreach ($GLOBALS['FWI_HOOKS'][$hook] ?? [] as $priority => $callbacks) {
        if (isset($callbacks[$id])) {
            return $priority;
        }
    }

    return false;
}

function fwi_callback_id($callback): string
{
    if (is_string($callback)) {
        return $callback;
    }

    if (is_array($callback)) {
        $target = is_object($callback[0]) ? spl_object_hash($callback[0]) : (string) $callback[0];

        return $target . '::' . $callback[1];
    }

    return spl_object_hash($callback);
}

function do_action(string $hook, ...$args): void
{
    apply_filters_ref($hook, $args, false);
}

function apply_filters(string $hook, $value, ...$args)
{
    return apply_filters_ref($hook, array_merge([$value], $args), true);
}

function apply_filters_ref(string $hook, array $args, bool $isFilter)
{
    $registered = $GLOBALS['FWI_HOOKS'][$hook] ?? [];
    ksort($registered);

    $value = $args[0] ?? null;

    foreach ($registered as $callbacks) {
        foreach ($callbacks as $entry) {
            $callArgs = array_slice($isFilter ? array_merge([$value], array_slice($args, 1)) : $args, 0, max(1, $entry['args']));
            $result   = call_user_func_array($entry['cb'], $callArgs);

            if ($isFilter) {
                $value = $result;
            }
        }
    }

    return $value;
}

/** Number of callbacks registered on a hook — used to assert no duplicates. */
function fwi_hook_count(string $hook): int
{
    $total = 0;

    foreach ($GLOBALS['FWI_HOOKS'][$hook] ?? [] as $callbacks) {
        $total += count($callbacks);
    }

    return $total;
}

// Options ─────────────────────────────────────────────────────────────────────

function get_option(string $option, $default = false)
{
    return array_key_exists($option, $GLOBALS['FWI_T']['options'])
        ? $GLOBALS['FWI_T']['options'][$option]
        : $default;
}

function add_option(string $option, $value = '', string $deprecated = '', $autoload = 'yes'): bool
{
    if (array_key_exists($option, $GLOBALS['FWI_T']['options'])) {
        return false;
    }

    $GLOBALS['FWI_T']['options'][$option] = $value;

    return true;
}

function update_option(string $option, $value, $autoload = null): bool
{
    $GLOBALS['FWI_T']['options'][$option] = $value;

    return true;
}

function delete_option(string $option): bool
{
    unset($GLOBALS['FWI_T']['options'][$option]);

    return true;
}

function get_post_meta(int $postId, string $key = '', bool $single = false)
{
    foreach ($GLOBALS['wpdb']->post_meta_rows as $row) {
        if ((int) $row['post_id'] === $postId && $row['meta_key'] === $key) {
            return $single ? $row['meta_value'] : [$row['meta_value']];
        }
    }

    return $single ? '' : [];
}

// Sanitisers / escaping ───────────────────────────────────────────────────────

function sanitize_text_field($str): string
{
    $str = strip_tags((string) $str);
    $str = str_replace(["\r", "\n", "\t"], ' ', $str);

    return trim(preg_replace('/ +/', ' ', $str) ?? '');
}

function sanitize_textarea_field($str): string
{
    return trim(strip_tags((string) $str));
}

function esc_url_raw($url): string
{
    return trim((string) $url);
}

function esc_html($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_attr($text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function absint($value): int
{
    return abs((int) $value);
}

// URLs / site info ────────────────────────────────────────────────────────────

function wp_parse_url(string $url, int $component = -1)
{
    return parse_url($url, $component);
}

function wp_parse_str(string $string, &$array): void
{
    parse_str($string, $array);
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function get_site_url(): string
{
    return 'https://example.test';
}

function get_bloginfo(string $show = ''): string
{
    return $show === 'name' ? 'Test Site' : '';
}

function current_time(string $type, bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s');
}

function add_query_arg($args, string $url = '')
{
    if (!is_array($args)) {
        return $url;
    }

    $parts = parse_url($url) ?: [];
    $query = [];

    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $query = array_merge($query, $args);

    $base = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
        . ($parts['host'] ?? '')
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . ($parts['path'] ?? '');

    $queryString = http_build_query($query);

    return $queryString === '' ? $base : $base . '?' . $queryString;
}

function wp_json_encode($data, int $flags = 0)
{
    return json_encode($data, $flags);
}

function wp_http_validate_url($url)
{
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : false;
}

// HTTP ────────────────────────────────────────────────────────────────────────

function wp_remote_get(string $url, array $args = [])
{
    // Only ever used for the ipapi.co geolocation lookup.
    $geo = $GLOBALS['FWI_T']['geo'];

    if ($geo instanceof WP_Error) {
        return $geo;
    }

    if (is_array($geo)) {
        return $geo;
    }

    return ['response' => ['code' => 200], 'body' => json_encode([
        'city'         => 'Chicago',
        'region'       => 'Illinois',
        'region_code'  => 'IL',
        'country_name' => 'United States',
        'postal'       => '60601',
        'latitude'     => '41.8781',
        'longitude'    => '-87.6298',
        'timezone'     => 'America/Chicago',
    ])];
}

function wp_safe_remote_post(string $url, array $args = [])
{
    $GLOBALS['FWI_T']['post_calls'][] = ['url' => $url, 'args' => $args];

    $queued = array_shift($GLOBALS['FWI_T']['post_queue']);

    if ($queued === null) {
        return ['response' => ['code' => 200], 'body' => '{"ok":true}'];
    }

    return $queued;
}

function wp_remote_retrieve_body($response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function wp_remote_retrieve_response_code($response)
{
    return is_array($response) ? ($response['response']['code'] ?? 0) : 0;
}

// Cron / misc ─────────────────────────────────────────────────────────────────

function wp_generate_uuid4(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    $GLOBALS['FWI_T']['cron'][] = ['ts' => $timestamp, 'hook' => $hook, 'args' => $args];

    return true;
}

function wp_next_scheduled(string $hook, array $args = [])
{
    foreach ($GLOBALS['FWI_T']['cron'] as $event) {
        if ($event['hook'] === $hook && $event['args'] === $args) {
            return $event['ts'];
        }
    }

    return false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    $GLOBALS['FWI_T']['cron'][] = ['ts' => $timestamp, 'hook' => $hook, 'args' => $args];

    return true;
}

function wp_clear_scheduled_hook(string $hook, array $args = []): void {}

function wp_unschedule_hook(string $hook): void {}

function wp_cache_delete($key, string $group = ''): bool
{
    return true;
}

function register_activation_hook(string $file, $callback): void {}

function register_deactivation_hook(string $file, $callback): void {}

function plugin_dir_path(string $file): string
{
    return rtrim(dirname($file), '/') . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/forms-webhook-integrator/';
}

function wp_doing_ajax(): bool
{
    return false;
}

function is_admin(): bool
{
    return false;
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
}

function register_rest_route(string $namespace, string $route, array $args = []): bool
{
    return true;
}

// ─── Load the plugin ─────────────────────────────────────────────────────────

fwi_reset();

require_once dirname(__DIR__) . '/forms-webhook-integrator.php';
require_once __DIR__ . '/doubles.php';

// ─── Assertion library ───────────────────────────────────────────────────────

$GLOBALS['FWI_TESTS']  = [];
$GLOBALS['FWI_RESULT'] = ['pass' => 0, 'fail' => 0, 'failures' => []];

/**
 * Registers a test. Each test runs with freshly reset harness state.
 */
function fwi_test(string $name, callable $body): void
{
    $GLOBALS['FWI_TESTS'][] = ['name' => $name, 'body' => $body];
}

function fwi_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fwi_assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\n    expected: %s\n    actual:   %s",
            $message,
            fwi_dump($expected),
            fwi_dump($actual)
        ));
    }
}

function fwi_dump($value): string
{
    return str_replace("\n", ' ', var_export($value, true));
}

function fwi_run_tests(string $suiteName): int
{
    echo "\n" . $suiteName . "\n" . str_repeat('-', strlen($suiteName)) . "\n";

    foreach ($GLOBALS['FWI_TESTS'] as $test) {
        fwi_reset();

        try {
            ($test['body'])();
            $GLOBALS['FWI_RESULT']['pass']++;
            echo '  PASS  ' . $test['name'] . "\n";
        } catch (Throwable $e) {
            $GLOBALS['FWI_RESULT']['fail']++;
            $GLOBALS['FWI_RESULT']['failures'][] = $test['name'] . ': ' . $e->getMessage();
            echo '  FAIL  ' . $test['name'] . "\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        }
    }

    $result = $GLOBALS['FWI_RESULT'];
    echo sprintf("\n  %d passed, %d failed\n", $result['pass'], $result['fail']);

    return $result['fail'] === 0 ? 0 : 1;
}
