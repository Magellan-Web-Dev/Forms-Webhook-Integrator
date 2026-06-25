<?php
declare(strict_types=1);

namespace FormsWebhookIntegrator\Admin\Pages;

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
                    <strong>Forms Webhook Integrator</strong> forwards form submissions — from Elementor Pro or any WordPress code — to one or more configurable webhook endpoints as a structured JSON payload.
                    It includes an admin settings UI, per-request analytics logging with per-endpoint filtering, a read-only REST API, and automatic updates from GitHub releases.
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
                        <tr><td>Webhook Status</td><td>Global enable / disable toggle. Hidden until at least one webhook URL is saved. Setting to <em>Inactive</em> stops all POSTs without removing configuration.</td></tr>
                        <tr><td>Webhook Endpoints</td><td>One or more webhook URLs the plugin POSTs JSON to on every form submission. Each block has its own URL field, optional label, and <em>Test Webhook</em> button. Use <strong>+ Add Additional URL</strong> to add a second or subsequent endpoint; each additional block has a <strong>Remove</strong> button. When multiple endpoints are configured, the submission is sent to <em>all</em> of them in sequence.</td></tr>
                        <tr><td>Webhook Label</td><td>An optional human-readable name for each endpoint (e.g. <em>CRM</em>, <em>Slack</em>). Labels appear as coloured badges on Analytics log entries and power the per-webhook filter dropdown on the Analytics page.</td></tr>
                        <tr><td>Test Webhook</td><td>Each webhook block has its own <em>Test Webhook</em> button. It sends a lightweight test payload (<code>{"msg":"Webhook submission test"}</code>) to the URL currently typed in that block and shows the HTTP response code inline. The result is persisted and shown on subsequent page loads for that specific endpoint.</td></tr>
                        <tr><td>Global Headers</td><td>Key/value HTTP headers included on every request to every endpoint, merged after <code>Content-Type: application/json</code>.</td></tr>
                        <tr><td>Global URL Query Parameters</td><td>Key/value pairs appended as a query string to the webhook URL on every request.</td></tr>
                        <tr><td>Include Page URL Parameters</td><td>When enabled, any query parameters in the URL of the page where the form was submitted (e.g. <code>?utm_source=google</code>) are automatically appended to the webhook URL for every form submission. Can also be toggled per-form in the <em>Specific Form</em> section. Page params are merged after global params but before per-form params, so per-form values take the highest precedence.</td></tr>
                        <tr><td>Client First / Last Name</td><td>Embedded in the <code>website_info.client</code> block of every payload.</td></tr>
                        <tr><td>Client ID</td><td>Optional identifier sent as <code>website_info.client.id</code> in every payload. Leave blank if not needed.</td></tr>
                        <tr><td>Website ID</td><td>Optional identifier sent as <code>website_info.id</code> in every payload.</td></tr>
                        <tr><td>Block Outside US</td><td>Rejects submissions whose sender IP resolves to a non-US country before the webhook fires. Defaults to <em>Yes</em>.</td></tr>
                        <tr><td>Excluded Forms</td><td>Elementor form names that should never trigger the webhook. Per-form settings are preserved even while a form is excluded.</td></tr>
                        <tr><td>Per-Form Overrides</td><td>Per-form settings that are merged on top of the global values for that form only: an optional <em>Form ID</em> (sent as <code>form_id</code> in the payload), an <em>Include Page URL Parameters</em> checkbox, additional URL query parameters, and additional request headers.</td></tr>
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
    "id": "site-123",
    "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" },
    "page": {
      "url": "https://example.com/contact",
      "query": { "utm_source": "google", "utm_medium": "cpc" }
    }
  },
  "form_name": "Contact Form",
  "form_id": "contact-form-01",
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
                    <code>website_info.id</code> is an optional identifier configured in Webhook Settings; it is always present in the payload (empty string when not set).
                    <code>website_info.client.id</code> is an optional client identifier configured in Webhook Settings; it is always present in the payload (empty string when not set).
                    <code>website_info.page.url</code> is the clean URL of the page the form was submitted from (no query string);
                    <code>website_info.page.query</code> is an associative array of any URL parameters that were present on that page — both derived from the HTTP referrer.
                    <code>form_id</code> is an optional per-form identifier configured in the <em>Specific Form URL Query And Headers</em> section; it is always present (empty string when not set).
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
                <pre class="fwi-about-code">do_action( 'fwi_submission', $formIdentifier, $fields );</pre>
                <table class="fwi-about-table">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$formIdentifier</code></td><td><code>array{form_name: string, form_id: string}</code></td><td>Associative array identifying the form. <code>form_name</code> is used for exclusion checks and per-form overrides. <code>form_id</code> is the native form identifier (used in the payload when no <code>form_id</code> override is configured in settings).</td></tr>
                        <tr><td><code>$fields</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Associative array of field names / IDs to raw values. These become the <code>submission_data</code> keys in the payload.</td></tr>
                    </tbody>
                </table>

                <p style="margin-top:16px;">Two optional parameters allow runtime overrides for a single call:</p>
                <pre class="fwi-about-code">do_action( 'fwi_submission', $formIdentifier, $fields, $urlQuery, $requestHeaders );</pre>
                <table class="fwi-about-table">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$urlQuery</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Extra query parameters merged onto the webhook URL for this call only.</td></tr>
                        <tr><td><code>$requestHeaders</code></td><td><code>array&lt;string, string&gt;</code></td><td>Extra headers merged after global and per-form headers for this call only.</td></tr>
                    </tbody>
                </table>

                <div class="fwi-about-note">
                    <strong>Note:</strong> WordPress discards return values from <code>do_action</code>. Use <code>fwi_submit_form()</code> (see below) when you need to know whether the submission succeeded.
                </div>

                <h3 class="fwi-about-subheading">Example — Custom Form Plugin</h3>
                <pre class="fwi-about-code">// After your form validates and you have the field values:
do_action(
    'fwi_submission',
    [ 'form_name' => 'My Custom Contact Form', 'form_id' => $formId ],
    [                                    // field data
        'first_name' => $firstName,
        'email'      => $email,
        'message'    => $message,
    ]
);</pre>

                <h3 class="fwi-about-subheading">Example — With Runtime Overrides</h3>
                <pre class="fwi-about-code">do_action(
    'fwi_submission',
    [ 'form_name' => 'Newsletter Signup', 'form_id' => $formId ],
    [ 'email' => $email ],
    [ 'source' => 'footer-widget' ],     // extra query param for this call only
    [ 'X-Campaign' => 'spring-2025' ]    // extra header for this call only
);</pre>
            </div>

            <!-- Result-Aware Helper -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Result-Aware Helper — <code>fwi_submit_form()</code></h2>
                <p>
                    When the calling code needs to inspect the outcome, use <code>fwi_submit_form()</code> instead of <code>do_action</code>.
                    It submits the form to the webhook and returns a <code>WebhookResponse</code> object with readonly <code>ok</code> and <code>msg</code> properties:
                </p>
                <pre class="fwi-about-code">$result = fwi_submit_form( [ 'form_name' => $formName, 'form_id' => $formId ], $fields );

if ( ! $result->ok ) {
    // $result->msg contains a user-facing error description
}</pre>
                <p>It accepts the same four parameters as the action hook and respects the same active-state gate, exclusion list, and per-form overrides.</p>
                <table class="fwi-about-table">
                    <thead><tr><th>Parameter</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>$formIdentifier</code></td><td><code>array{form_name: string, form_id: string}</code></td><td>Associative array with <code>form_name</code> and <code>form_id</code> keys identifying the form.</td></tr>
                        <tr><td><code>$fields</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Associative array of field names / IDs to raw values.</td></tr>
                        <tr><td><code>$urlQuery</code></td><td><code>array&lt;string, mixed&gt;</code></td><td>Optional extra query parameters for this call only.</td></tr>
                        <tr><td><code>$requestHeaders</code></td><td><code>array&lt;string, string&gt;</code></td><td>Optional extra headers for this call only.</td></tr>
                    </tbody>
                </table>
                <p style="margin-top:12px;"><strong>Return value:</strong> <code>WebhookResponse</code> — a read-only object. Properties are readonly and cannot be modified after the object is created.</p>
                <table class="fwi-about-table">
                    <thead><tr><th>Property</th><th>Type</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>ok</code></td><td><code>bool</code></td><td><code>true</code> when the webhook accepted the submission (HTTP 200/201/202/204); <code>false</code> on any failure.</td></tr>
                        <tr><td><code>status</code></td><td><code>int</code></td><td>HTTP status code returned by the webhook endpoint. <code>0</code> when no HTTP response was received (early exits, transport-level errors).</td></tr>
                        <tr><td><code>msg</code></td><td><code>string</code></td><td>User-facing error description when <code>ok</code> is <code>false</code>; empty string on success.</td></tr>
                        <tr><td><code>data</code></td><td><code>mixed</code></td><td>The webhook's response body when an HTTP response was received — JSON-decoded if valid JSON, raw string otherwise. <code>null</code> for early exits and transport-level errors. Not intended for public display.</td></tr>
                    </tbody>
                </table>
                <div class="fwi-about-note">
                    If the webhook integration is disabled in settings, <code>fwi_submit_form()</code> returns immediately with <code>ok: false</code>, <code>msg</code> set to an error description, and <code>data: null</code> — no request is sent.
                </div>
            </div>

            <!-- Analytics -->
            <div class="fwi-card">
                <h2 class="fwi-card-title">Analytics</h2>
                <p><strong>Webhook Integrator → Analytics</strong> logs every webhook request the plugin has made.</p>
                <ul class="fwi-about-list">
                    <li>Accordion sections for <em>Total Requests</em> and <em>Total Errors</em>, each sorted newest-first.</li>
                    <li>Each entry shows the timestamp, form name, HTTP response code, full webhook URL, request payload, and raw response body.</li>
                    <li>When multiple webhook endpoints are configured, each endpoint generates its <strong>own separate log entry</strong> per form submission. Entries for labelled endpoints display a coloured badge showing the webhook label.</li>
                    <li>Filter by <strong>year / month</strong>, by <strong>webhook endpoint</strong> (when more than one labelled endpoint exists), or by free-text search. Paginate at 5 / 10 / 25 / 50 / 100 entries per page.</li>
                    <li><strong>Delete</strong> a single entry via AJAX, <strong>Clear All Logs</strong> after confirmation, or export as <strong>CSV</strong> / <strong>JSON</strong> (both include the webhook label column).</li>
                    <li>A daily WP-Cron event automatically purges entries older than the configured <strong>Log Retention</strong> period (default 3 months).</li>
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
