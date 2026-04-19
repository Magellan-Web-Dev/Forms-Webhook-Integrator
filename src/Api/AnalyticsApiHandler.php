<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Api;

if (!defined('ABSPATH')) exit;

use FormsWebhookIntegrator\Settings\SettingsManager;
use FormsWebhookIntegrator\Webhook\WebhookLogger;

/**
 * Registers and handles the read-only analytics REST API endpoint.
 *
 * Route: GET /wp-json/fwi/v1/analytics
 *
 * Query parameters:
 *   page     - 1-based page number (default: 1)
 *   per_page - results per page, max 100 (default: 25)
 *
 * Authentication: pass the API key as the Authorization request header.
 *
 *   Authorization: <api-key>
 *
 * Cross-origin requests are permitted from any origin. The following CORS
 * headers are added to every response (including OPTIONS preflight):
 *   Access-Control-Allow-Origin:   *
 *   Access-Control-Allow-Methods:  GET, OPTIONS
 *   Access-Control-Allow-Headers:  Authorization, Content-Type
 *   Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, X-FWI-Page
 *   Access-Control-Max-Age:        86400
 *
 * Pagination metadata is returned in response headers:
 *   X-WP-Total      - total number of log entries matching the query
 *   X-WP-TotalPages - total number of pages
 *   X-FWI-Page      - the current page number
 */
final class AnalyticsApiHandler
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE     = 100;

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly WebhookLogger $logger
    ) {}

    /**
     * Hooks into rest_api_init to register the route.
     *
     * Called once from Plugin::init().
     *
     * @return void
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);

        // rest_post_dispatch fires for every dispatched REST request — including
        // OPTIONS preflight — so one filter handles CORS for both cases.
        // Only 1 argument is requested so the unused $server/$request params
        // that WordPress would otherwise pass are never received.
        add_filter('rest_post_dispatch', [$this, 'addCorsHeaders'], 10, 1);
    }

    /**
     * @return void
     */
    public function registerRoutes(): void
    {
        register_rest_route('fwi/v1', '/analytics', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handleRequest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'default'           => 1,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => self::DEFAULT_PER_PAGE,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Appends CORS headers to any response destined for the /fwi/v1/analytics
     * route. Scoped via a REQUEST_URI check so other REST endpoints are unaffected.
     * Registered with add_filter(..., 10, 1) so only the response object is
     * received — no unused $server or $request parameters needed.
     *
     * @param \WP_REST_Response $response
     *
     * @return \WP_REST_Response
     */
    public function addCorsHeaders(\WP_REST_Response $response): \WP_REST_Response
    {
        if (!str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/fwi/v1/analytics')) {
            return $response;
        }

        $response->header('Access-Control-Allow-Origin',   '*');
        $response->header('Access-Control-Allow-Methods',  'GET, OPTIONS');
        $response->header('Access-Control-Allow-Headers',  'Authorization, Content-Type');
        $response->header('Access-Control-Expose-Headers', 'X-WP-Total, X-WP-TotalPages, X-FWI-Page');
        $response->header('Access-Control-Max-Age',        '86400');

        return $response;
    }

    /**
     * Authenticates the request and returns a paginated page of log entries.
     *
     * @param \WP_REST_Request $request
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!$this->settings->isAnalyticsApiActive()) {
            return new \WP_Error(
                'api_disabled',
                'The analytics API is not enabled.',
                ['status' => 403]
            );
        }

        $storedKey  = $this->settings->getAnalyticsApiKey();
        $authHeader = (string) $request->get_header('authorization');

        if (empty($storedKey) || !hash_equals($storedKey, $authHeader)) {
            return new \WP_Error(
                'unauthorized',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }

        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));

        $total      = $this->logger->getLogCount();
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Clamp page to valid range.
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $logs   = $this->logger->getLogsPaginated($page, $perPage);
        $output = [];

        foreach ($logs as $entry) {
            $requestDecoded  = json_decode((string) ($entry['request_data']  ?? '{}'), true);
            $responseDecoded = json_decode((string) ($entry['response_data'] ?? ''),   true);

            $output[] = [
                'id'            => (int)    ($entry['id']            ?? 0),
                'created_at'    => (string) ($entry['created_at']    ?? ''),
                'success'       => (int)    ($entry['success']       ?? 0) === 1,
                'form_name'     => is_array($requestDecoded) ? (string) ($requestDecoded['form_name'] ?? '') : '',
                'request_url'   => (string) ($entry['request_url']   ?? ''),
                'response_code' => (int)    ($entry['response_code'] ?? 0),
                'request_data'  => is_array($requestDecoded)  ? $requestDecoded  : [],
                'response_data' => is_array($responseDecoded) ? $responseDecoded : (string) ($entry['response_data'] ?? ''),
            ];
        }

        $response = new \WP_REST_Response($output, 200);
        $response->header('X-WP-Total',      (string) $total);
        $response->header('X-WP-TotalPages', (string) $totalPages);
        $response->header('X-FWI-Page',      (string) $page);

        return $response;
    }
}
