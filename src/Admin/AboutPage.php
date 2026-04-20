<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin;

if (!defined('ABSPATH')) exit;

final class AboutPage
{
    public function render(): void
    {
        ?>
        <div class="wrap fwi-wrap">
            <h1>About Forms Webhook Integrator</h1>

            <div class="fwi-about-intro fwi-card">
                <p>
                    <strong>Forms Webhook Integrator</strong> forwards form submissions — from Elementor Pro or any WordPress code — to a configurable webhook endpoint as a structured JSON payload.
                    It includes an admin settings UI, per-request analytics logging, a read-only REST API, and automatic updates from GitHub releases.
                </p>
                <ul class="fwi-about-requirements">
                    <li><span class="fwi-about-label">PHP</span> 8.1+</li>
                    <li><span class="fwi-about-label">WordPress</span> 6.0+</li>
                    <li><span class="fwi-about-label">Elementor Pro</span> Optional — required only for the built-in Elementor bridge; all other forms use the public action hook.</li>
                </ul>
            </div>

            <!-- Settings -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Settings</h2>
                <p>All settings live under <strong>Webhook Integrator → Settings</strong>.</p>
                <table class="fwi-about-table">
                    <thead><tr><th>Setting</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td>Webhook Status</td><td>Global enable / disable toggle. Hidden until a URL is saved. Setting to <em>Inactive</em> stops all POSTs without removing configuration.</td></tr>
                        <tr><td>Webhook URL</td><td>The full URL the plugin POSTs JSON to on every submission.</td></tr>
                        <tr><td>Test Webhook</td><td>Sends a lightweight test payload (<code>{"msg":"Webhook submission test"}</code>) to the currently-typed URL and shows the HTTP response code inline.</td></tr>
                        <tr><td>Global Headers</td><td>Key/value HTTP headers included on every request, merged after <code>Content-Type: application/json</code>.</td></tr>
                        <tr><td>Global URL Query Parameters</td><td>Key/value pairs appended as a query string to the webhook URL on every request.</td></tr>
                        <tr><td>Client First / Last Name</td><td>Embedded in the <code>website_info.client</code> block of every payload.</td></tr>
                        <tr><td>Block Outside US</td><td>Rejects submissions whose sender IP resolves to a non-US country before the webhook fires. Defaults to <em>Yes</em>.</td></tr>
                        <tr><td>Excluded Forms</td><td>Elementor form names that should never trigger the webhook. Per-form settings are preserved even while a form is excluded.</td></tr>
                        <tr><td>Per-Form Overrides</td><td>Per-form URL query parameters and request headers that are merged on top of the global values for that form only.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Webhook Payload -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Webhook Payload</h2>
                <p>Every POST uses <code>Content-Type: application/json</code>. The body shape is:</p>
                <pre class="fwi-about-code">{
  "website_info": {
    "name": "My Site",
    "url": "https://example.com",
    "client": { "first_name": "Jane", "last_name": "Smith" }
  },
  "form_name": "Contact Form",
  "submission_data": {
    "name": "John Doe",
    "email": "john@example.com",
    "message": "Hello!"
  },
  "client_location_data": {
    "city": "Chicago",
    "region": "Illinois",
    "region_code": "IL",
    "country_name": "United States",
    "postal": "60601",
    "latitude": "41.8781",
    "longitude": "-87.6298",
    "timezone": "America/Chicago",
    "ip": "203.0.113.5"
  },
  "timestamp": { "date": "2025-04-15", "time": "14:32:00" }
}</pre>
                <p>
                    <code>submission_data</code> keys are the form field IDs; values are sanitised strings.
                    <code>client_location_data</code> is populated via a live lookup to <strong>ipapi.co</strong>.
                    If the IP cannot be resolved the block contains an <code>"error"</code> key instead.
                    HTTP <code>200</code>, <code>201</code>, <code>202</code>, and <code>204</code> are treated as success.
                </p>
            </div>

            <!-- Public Action Hook -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Public Action Hook — <code>do_action('fwi_submission')</code></h2>
                <p>
                    Any WordPress code — including third-party form plugins — can trigger the webhook without depending on Elementor.
                    Call the action anywhere a form submission is processed:
                </p>
                <pre class="fwi-about-code">do_action( 'fwi_submission', $form_name, $fields );</pre>
                <table class="fwi-about-table">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$form_name</code></td><td><code>string</code></td><td>The logical name of the form — used for exclusion checks and per-form overrides.</td></tr>
                        <tr><td><code>$fields</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Associative array of field names / IDs to raw values. These become the <code>submission_data</code> keys in the payload.</td></tr>
                    </tbody>
                </table>

                <p style="margin-top:16px;">Two optional parameters allow runtime overrides for a single call:</p>
                <pre class="fwi-about-code">do_action( 'fwi_submission', $form_name, $fields, $url_query, $request_headers );</pre>
                <table class="fwi-about-table">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$url_query</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Extra query parameters merged onto the webhook URL for this call only.</td></tr>
                        <tr><td><code>$request_headers</code></td><td><code>array&lt;string, string&gt;</code></td><td>Extra headers merged after global and per-form headers for this call only.</td></tr>
                    </tbody>
                </table>

                <div class="fwi-about-note">
                    <strong>Note:</strong> WordPress discards return values from <code>do_action</code>. If you need to inspect the result (success / failure), call <code>WebhookHandler::handleFormSubmission()</code> directly the same way <code>ElementorFormsBridge</code> does.
                </div>

                <h3 class="fwi-about-subheading">Example — Custom Form Plugin</h3>
                <pre class="fwi-about-code">// After your form validates and you have the field values:
do_action(
    'fwi_submission',
    'My Custom Contact Form',            // form name
    [                                    // field data
        'first_name' => $first_name,
        'email'      => $email,
        'message'    => $message,
    ]
);</pre>

                <h3 class="fwi-about-subheading">Example — With Runtime Overrides</h3>
                <pre class="fwi-about-code">do_action(
    'fwi_submission',
    'Newsletter Signup',
    [ 'email' => $email ],
    [ 'source' => 'footer-widget' ],     // extra query param for this call only
    [ 'X-Campaign' => 'spring-2025' ]    // extra header for this call only
);</pre>
            </div>

            <!-- Analytics -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Analytics</h2>
                <p><strong>Webhook Integrator → Analytics</strong> logs every webhook request the plugin has made.</p>
                <ul class="fwi-about-list">
                    <li>Accordion sections for <em>Total Requests</em> and <em>Total Errors</em>, each sorted newest-first.</li>
                    <li>Each entry shows the timestamp, form name, HTTP response code, full webhook URL, request payload, and raw response body.</li>
                    <li>Filter by year / month and paginate (5 / 10 / 25 / 50 / 100 per page).</li>
                    <li><strong>Delete</strong> a single entry via AJAX, <strong>Clear All Logs</strong> after confirmation, or export as <strong>CSV</strong> / <strong>JSON</strong>.</li>
                    <li>A daily WP-Cron event automatically purges entries older than <strong>3 months</strong>.</li>
                </ul>
            </div>

            <!-- REST API -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Analytics REST API</h2>
                <p>A read-only REST endpoint returns the same data as <em>Export JSON</em>.</p>
                <p><strong>Route:</strong> <code>GET /wp-json/fwi/v1/analytics</code></p>
                <p>Enable the API and retrieve your key from the <strong>Analytics API</strong> card on the Analytics page. Pass the key in every request:</p>
                <pre class="fwi-about-code">Authorization: &lt;your-api-key&gt;</pre>
                <table class="fwi-about-table">
                    <thead><tr><th>Query Parameter</th><th>Default</th><th>Max</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>page</code></td><td>1</td><td>—</td><td>1-based page number, clamped to the last page.</td></tr>
                        <tr><td><code>per_page</code></td><td>25</td><td>100</td><td>Entries returned per page.</td></tr>
                    </tbody>
                </table>
                <p>Pagination metadata is returned via <code>X-WP-Total</code>, <code>X-WP-TotalPages</code>, and <code>X-FWI-Page</code> response headers. Cross-origin requests are permitted from any origin.</p>
            </div>

            <!-- Automatic Updates -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Automatic Updates</h2>
                <p>
                    The plugin checks <strong>GitHub Releases</strong> for new versions and integrates with the standard WordPress update pipeline.
                    A standard <em>"Update available"</em> notice appears on the Plugins screen when a new release is published.
                    Updates can be applied from <strong>Dashboard → Updates</strong> like any WordPress.org plugin.
                </p>
                <p>A <strong>"Check for updates"</strong> link in the plugin row clears the 12-hour release cache and forces an immediate re-check.</p>
            </div>

        </div><!-- .fwi-wrap -->
        <?php
    }
}
