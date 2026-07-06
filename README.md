# Forms Webhook Integrator

A WordPress plugin that forwards Elementor Pro form submissions — and any other form — to one or more configurable webhook endpoints as a structured JSON payload. Includes an admin settings UI, automatic background retries for failed deliveries, per-endpoint analytics logging with label-based filtering, a read-only REST API, and automatic updates from GitHub releases.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1 |
| WordPress | 6.3 |
| Elementor Pro | Any version that provides `elementor_pro/forms/new_record` |

Elementor Pro is required only for the built-in Elementor bridge. Other form plugins can integrate via the [public action hook](#public-action-hook).

---

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/Magellan-Web-Dev/Forms-Webhook-Integrator/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate **Forms Webhook Integrator**.
4. Go to **Webhook Integrator → Settings** to configure the endpoint.

Once at least one webhook URL is saved, the **Webhook Status** toggle appears. The webhook will not fire until the toggle is set to **Active**.

---

## Settings

All settings live under **Webhook Integrator → Settings** in the WordPress admin.

### Webhook Status

A toggle that enables or disables the webhook globally. The toggle is hidden until a webhook URL has been saved. Setting the status to **Inactive** stops all webhook POSTs without removing any configuration.

### Webhook Settings

#### Webhook Endpoints

The settings page starts with a single webhook block. Use **+ Add Additional URL** to add more endpoints; the second and subsequent blocks each have a **Remove** button. When more than one endpoint is configured, every form submission is sent to **all** of them in sequence, with each endpoint producing its own separate log entry in Analytics.

Each webhook block contains:

| Field | Description |
|---|---|
| **Webhook URL** | The full URL the plugin POSTs JSON to for this endpoint on every form submission. |
| **Label** | An optional human-readable name for this endpoint (e.g. `CRM`, `Slack`). Labels appear as coloured badges on Analytics log entries and are available as a filter on the Analytics page. |
| **Test Webhook** | Sends a lightweight test payload (`{"msg": "Webhook submission test"}`) to the URL currently typed in this block's URL field. Displays the HTTP response code inline without saving, and persists the result so it is visible on the next page load for that specific endpoint. |

#### Other Settings

| Field | Description |
|---|---|
| **Webhook Failure Mode** | What happens when a webhook delivery fails for an **Elementor form** submission. **Retry in background** (default): the visitor still sees a successful submission and the failed delivery is retried automatically in the background. **Show error to visitor**: the form displays an error message immediately. See [Failure Handling & Background Retries](#failure-handling--background-retries). |
| **Global Headers** | Custom HTTP headers included on every webhook request to every endpoint (e.g. `Authorization: Bearer …`). Added via a key/value builder; any header here is merged after `Content-Type: application/json`. |
| **Global URL Query Parameters** | Key/value pairs appended as a query string to every webhook URL on every request. Also includes an **Include Page URL Parameters** checkbox — when enabled, any query parameters present in the URL of the page where the form was submitted (e.g. `?utm_source=google&gclid=…`) are automatically appended to the webhook URL on every form submission. |
| **Client First Name** | Embedded in the `website_info.client` block of every payload. |
| **Client Last Name** | Embedded in the `website_info.client` block of every payload. |
| **Client ID** | Optional identifier sent as `website_info.client.id` in every payload. Leave blank if not needed. |
| **Website ID** | Optional identifier sent as `website_info.id` in every payload. Leave blank if not needed. |
| **Block Submissions Outside US** | When set to **Yes**, any submission where the sender's IP resolves to a country other than the United States is rejected before the webhook fires. Defaults to **Yes**. |
| **Log Retention** | How long log entries are kept before the daily purge removes them. Options: 1, 3, 6, 12, or 24 months. Defaults to 3 months. |

### Excluded Forms

A list of Elementor form names that should **not** trigger the webhook even when the webhook is active. Add a form from the dropdown selector; remove it from the list with the **Remove** button. Only forms detected in the site's Elementor data appear in the dropdown.

### Specific Form URL Query And Headers

Per-form overrides for URL query parameters and request headers. Each active (non-excluded) Elementor form is listed here with its own controls for:

- **Form ID** — an optional identifier sent as `form_id` directly alongside `form_name` in the webhook payload for that form's submissions only.
- **Include Page URL Parameters** — a checkbox that enables page URL parameter passthrough for this form only, regardless of the global setting. When enabled, query parameters from the page URL are appended to the webhook URL for that form's submissions.
- **URL Query Parameters** — appended on top of the global query parameters for that form's requests only.
- **Request Headers** — merged after the global headers for that form's requests only.

Per-form settings are preserved in the database even while a form is excluded, so the configuration is restored automatically when the form is re-enabled.

#### Page URL parameter precedence

When page URL parameters are included, they are merged in the following order (later values override earlier ones for any shared key):

1. Global URL query parameters (configured in Webhook Settings)
2. Page URL parameters (from the submitting page's URL)
3. Per-form URL query parameters (the highest priority)

---

## Webhook Payload

Every webhook POST sends `Content-Type: application/json` with the following body structure:

```json
{
  "website_info": {
    "name": "My Site",
    "url": "https://example.com",
    "id": "site-123",
    "client": {
      "first_name": "Jane",
      "last_name": "Smith",
      "id": "client-456"
    },
    "page": {
      "url": "https://example.com/contact",
      "query": {
        "utm_source": "google",
        "utm_medium": "cpc"
      }
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
  "timestamp": {
    "date": "2025-04-15",
    "time": "14:32:00"
  }
}
```

`submission_data` keys are the Elementor field IDs; values are sanitised strings. `website_info.id` is the optional identifier configured in Webhook Settings (empty string when not set). `website_info.client.id` is the optional client identifier configured in Webhook Settings (empty string when not set). `website_info.page.url` is the clean URL of the page the form was submitted from (no query string), and `website_info.page.query` is an associative array of any URL parameters that were present on that page — both derived from the HTTP referrer. `form_id` is an optional per-form identifier configured in the Specific Form URL Query And Headers section (empty string when not set). `client_location_data` is populated via a live lookup to [ipapi.co](https://ipapi.co). If the IP cannot be resolved, the block contains an `"error"` key instead of location fields.

HTTP `200`, `201`, `202`, and `204` responses are treated as success. Any other status code, or a transport-level error, is recorded as a failure.

---

## Failure Handling & Background Retries

The **Webhook Failure Mode** setting controls what an Elementor form visitor experiences when one or more webhook deliveries fail (for example, when an endpoint is temporarily down):

- **Retry in background** (default) — the form shows its normal success state, so visitors never see an error for a temporary outage and are not tempted to re-submit. Each failed delivery is retried automatically: a second attempt roughly **2 hours** after the submission, and — if that also fails — a third and final attempt roughly **2 hours** after that. After the third failure the delivery is abandoned; every attempt remains visible in Analytics.
- **Show error to visitor** — the form displays an error message immediately when any delivery fails (the original behavior).

How retries work:

- **Only the endpoints that failed are retried.** With multiple webhooks configured, endpoints that already accepted the submission are never sent a duplicate.
- **The exact original request is replayed** — same URL (including query parameters), same headers, same JSON body — even if the webhook settings change between the submission and the retry.
- **Every attempt is logged.** Retry attempts appear as their own Analytics entries with the attempt number appended to the webhook label, e.g. `CRM (retry 2/3)`, making them easy to correlate with the original failed entry.
- **Scope: Elementor submissions only.** Submissions sent through the [`fwi_submission` action hook](#public-action-hook) or [`fwi_submit_form()`](#result-aware-helper-function) are never retried automatically — those callers receive the real result and are expected to handle failures themselves.
- **Pre-dispatch rejections are never retried.** A submission blocked by the **Block Submissions Outside US** setting (or one that never dispatched because the integration is inactive, the form is excluded, or no URL is configured) still shows an error even in retry mode — there is nothing to retry.
- **Timing is a floor, not a guarantee.** Retries run on WP-Cron, which fires on page traffic. On a low-traffic site a retry executes on the first page load after its scheduled time has passed.
- **Deactivating the plugin discards pending retries.** Their earlier attempts remain in the log; nothing is rescheduled on reactivation.

---

## Public Action Hook

Any WordPress code — including third-party form plugins — can trigger the webhook without depending on Elementor:

```php
do_action('fwi_submission', $formIdentifier, $fields);
```

| Parameter | Type | Description |
|---|---|---|
| `$formIdentifier` | `array{form_name: string, form_id: string}` | Associative array identifying the form. `form_name` is used for exclusion checks and per-form overrides. `form_id` is the native form identifier supplied by the caller; it is used in the payload only when no `form_id` override is configured in settings. |
| `$fields` | `array<string, mixed>` | Associative array of field names/IDs to raw values. |

The hook also accepts two optional parameters for runtime URL query params and headers:

```php
do_action('fwi_submission', $formIdentifier, $fields, $urlQuery, $requestHeaders);
```

| Parameter | Type | Description |
|---|---|---|
| `$urlQuery` | `array<string, mixed>` | Extra query parameters merged onto the webhook URL for this call only. |
| `$requestHeaders` | `array<string, string>` | Extra headers merged after global and per-form headers for this call only. |

> **Note:** `do_action` discards return values. Use [`fwi_submit_form()`](#result-aware-helper-function) when you need to know whether the submission succeeded.

---

## Result-Aware Helper Function

When the calling code needs to inspect the outcome, use `fwi_submit_form()` instead of `do_action`. It submits the form to the webhook and returns a `WebhookResponse` object:

```php
$result = fwi_submit_form(['form_name' => $formName, 'form_id' => $formId], $fields);

if (!$result->ok) {
    // $result->msg contains a user-facing error description
}
```

`fwi_submit_form()` accepts the same four parameters as the action hook and respects the same active-state gate, exclusion list, and per-form overrides.

| Parameter | Type | Description |
|---|---|---|
| `$formIdentifier` | `array{form_name: string, form_id: string}` | Associative array with `form_name` and `form_id` keys identifying the form. |
| `$fields` | `array<string, mixed>` | Associative array of field names/IDs to raw values. |
| `$urlQuery` | `array<string, mixed>` | Optional extra query parameters for this call only. |
| `$requestHeaders` | `array<string, string>` | Optional extra headers for this call only. |

**Return value:** `WebhookResponse` — a read-only object with five properties:

| Property | Type | Description |
|---|---|---|
| `ok` | `bool` | `true` when the webhook accepted the submission (HTTP 200/201/202/204); `false` on any failure. |
| `status` | `int` | HTTP status code returned by the webhook endpoint. `0` when no HTTP response was received (early exits, transport-level errors). |
| `msg` | `string` | User-facing error description when `ok` is `false`; empty string on success. |
| `data` | `mixed` | The webhook's response body when an HTTP response was received. JSON-decoded if the body is valid JSON, raw string otherwise. `null` for early exits (inactive integration, excluded form, missing URL, etc.) and transport-level errors. Not intended for public display. |
| `failedDeliveries` | `array` | One entry per endpoint whose dispatch failed, each an array with `url`, `headers`, `body`, and `label` keys describing the exact request that was sent. Always empty for early exits where nothing was dispatched. The Elementor bridge uses this to queue [background retries](#failure-handling--background-retries); external callers may use it to implement their own retry logic. |

Properties are readonly and cannot be modified after the object is created.

> **Note:** [Background retries](#failure-handling--background-retries) apply only to Elementor form submissions. `fwi_submit_form()` and the action hook always return the real result immediately and never queue retries.

If the webhook integration is disabled in settings, `fwi_submit_form()` returns a `WebhookResponse` with `ok: false`, `msg: 'The webhook integration is not active.'`, and `data: null` immediately without sending any request.

---

## Analytics

**Webhook Integrator → Analytics** displays a log of every webhook request the plugin has made.

### Total Requests / Total Errors

Each accordion shows its entries newest-first. Per-entry data includes:

- Timestamp, form name, HTTP response code, and success/failure status
- The webhook label badge (when the endpoint has a label configured)
- The full webhook URL (including query string) that was used
- The JSON request payload that was sent
- The raw response body received

When multiple webhook endpoints are configured, each endpoint generates its **own separate log entry** per form submission, making it easy to see which endpoint succeeded or failed independently.

[Background retry](#failure-handling--background-retries) attempts also appear as their own entries, labelled with the attempt number appended to the webhook label (e.g. `CRM (retry 2/3)`), so a failed delivery and its subsequent retries can be traced together by label and timestamp.

### Filtering and Pagination

Each accordion has independent controls:

- **Year / Month** dropdowns to filter by calendar period
- **Webhook** dropdown to filter by a specific endpoint label (visible only when more than one distinct labelled endpoint exists in the log)
- **Search** field to filter by any text in the request data
- **Per page** selector: 5, 10, 25, 50, or 100 entries per page
- Page navigation with a windowed page-number selector

### Log Management

- **Delete** — removes a single log entry immediately via AJAX (no page reload)
- **Clear All Logs** — truncates the entire log table after a confirmation prompt
- **Export CSV** — downloads all logs as a UTF-8 CSV file (Excel-compatible); includes a **Webhook** column for the endpoint label
- **Export JSON** — downloads all logs as a pretty-printed JSON file; includes a `webhook_label` field per entry

### Retention

A daily WP-Cron event automatically purges log rows older than the **Log Retention** period configured in settings (default 3 months), keeping the table size manageable without manual intervention.

---

## Analytics REST API

The plugin exposes a read-only REST endpoint that returns the same data as the **Export JSON** action on the Analytics page.

**Route:** `GET /wp-json/fwi/v1/analytics`

### Enabling the API

On the **Webhook Integrator → Analytics** page, find the **Analytics API** card. Toggle the switch to **Active**. An API key is generated on first activation and displayed in the card. The endpoint returns `403` while the toggle is inactive.

### Authentication

Pass the API key as the value of the `Authorization` request header:

```
Authorization: <your-api-key>
```

The key can be regenerated at any time using the **Regenerate Key** button in the admin card. Regenerating immediately invalidates the previous key. A missing or incorrect key returns `401`.

### Query Parameters

| Parameter | Default | Maximum | Description |
|---|---|---|---|
| `page` | `1` | — | 1-based page number. Clamped to the last page if it exceeds the total. |
| `per_page` | `25` | `100` | Number of entries to return per page. |

### Response

The response body is a JSON array. Each element matches the shape produced by **Export JSON**:

```json
[
  {
    "id": 42,
    "created_at": "2025-04-15 14:32:00",
    "success": true,
    "form_name": "Contact Form",
    "request_url": "https://hooks.example.com/webhook",
    "response_code": 200,
    "request_data": { "form_name": "Contact Form", "submission_data": { ... } },
    "response_data": { ... }
  }
]
```

Pagination metadata is returned in response headers:

| Header | Description |
|---|---|
| `X-WP-Total` | Total number of log entries |
| `X-WP-TotalPages` | Total number of pages at the current `per_page` value |
| `X-FWI-Page` | The page number returned in this response |

### CORS

Cross-origin requests are permitted from any origin. The endpoint adds the following headers to every response, including `OPTIONS` preflight requests:

```
Access-Control-Allow-Origin:   *
Access-Control-Allow-Methods:  GET, OPTIONS
Access-Control-Allow-Headers:  Authorization, Content-Type
Access-Control-Expose-Headers: X-WP-Total, X-WP-TotalPages, X-FWI-Page
Access-Control-Max-Age:        86400
```

`Access-Control-Expose-Headers` ensures that browser-based clients can read the pagination headers in cross-origin contexts.

### Error Responses

| Status | Code | Condition |
|---|---|---|
| `403` | `api_disabled` | The Analytics API toggle is inactive |
| `401` | `unauthorized` | The `Authorization` header is missing or does not match the stored key |

---

## Automatic Updates

The plugin checks [GitHub Releases](https://github.com/Magellan-Web-Dev/Forms-Webhook-Integrator/releases) for new versions and integrates with the standard WordPress update pipeline.

- When a new release is published, a standard **"Update available"** notice appears on the Plugins screen and the WordPress Updates page, identical to a plugin sourced from the WordPress.org directory.
- Clicking **View version X details** opens the standard plugin-info thickbox with release metadata.
- The update can be applied from **Dashboard → Updates** like any other plugin.

### Check for Updates

A **"Check for updates"** link appears in the plugin's row on the Plugins screen. Clicking it:

1. Clears the 12-hour release cache.
2. Forces WordPress to re-evaluate all plugin updates immediately.
3. Redirects back to the Plugins screen with a notice indicating either the latest available version or confirmation that the current version is up to date.

### Folder Integrity

GitHub release archives are extracted into a version-stamped folder by default (e.g. `Forms-Webhook-Integrator-1.2.3/`). Two complementary hooks — `upgrader_package_options` and `upgrader_post_install` — ensure the plugin is always installed into the canonical `forms-webhook-integrator/` folder so it does not deactivate after an update.

---

## File Structure

```
forms-webhook-integrator/
├── forms-webhook-integrator.php   # Main plugin file; defines constants, bootstraps autoloader
├── assets/
│   ├── css/admin.css              # Admin UI styles
│   └── js/admin.js                # Admin UI behaviour (toggles, builders, pagination, AJAX)
└── src/
    ├── Autoloader.php             # PSR-4 autoloader (no Composer required)
    ├── Plugin.php                 # Composition root / singleton bootstrap
    ├── Admin/
    │   ├── AdminMenu.php          # Registers admin menu pages and AJAX handlers
    │   └── Pages/
    │       ├── SettingsPage.php   # Settings page render and form processing
    │       ├── AnalyticsPage.php  # Analytics page render, export, and log-clear
    │       └── AboutPage.php      # About / documentation page render
    ├── Api/
    │   └── AnalyticsApiHandler.php  # REST API endpoint: GET /wp-json/fwi/v1/analytics
    ├── Database/
    │   └── DatabaseManager.php    # Table creation, schema upgrades, log purge
    ├── Forms/
    │   ├── ElementorFormsBridge.php  # Bridges Elementor Pro submissions to the webhook
    │   └── ElementorFormsHelper.php  # Discovers Elementor form names from post meta
    ├── Settings/
    │   └── SettingsManager.php    # Centralised read/write layer for all plugin options
    ├── Updates/
    │   └── GitHubUpdater.php      # GitHub release checking and WordPress update integration
    └── Webhook/
        ├── RetryManager.php       # Schedules and executes background retries for failed deliveries
        ├── WebhookHandler.php     # Builds payload, performs IP lookup, POSTs to webhook
        ├── WebhookLogger.php      # Inserts and retrieves log rows from the custom DB table
        ├── WebhookResponse.php    # Readonly value object returned by handleFormSubmission()
        └── WebhookTester.php      # Sends lightweight test POST to the configured URL
```

---

## Database

The plugin creates a single custom table — `{prefix}FWI_webhook_logs` — on activation via `dbDelta`. The table is never removed on deactivation; data is preserved across deactivate/reactivate cycles.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `success` | `TINYINT(1)` | `1` for HTTP 200/201/202/204, `0` for everything else |
| `request_url` | `TEXT` | Full webhook URL including query string |
| `request_headers` | `LONGTEXT` | JSON-encoded headers sent (not currently populated) |
| `request_data` | `LONGTEXT` | JSON-encoded request payload |
| `response_data` | `LONGTEXT` | Raw response body, or error JSON on transport failure |
| `response_code` | `INT` | HTTP status code (`0` for transport errors) |
| `webhook_label` | `VARCHAR(255)` | Optional label of the webhook endpoint that produced this row (empty string when unlabelled) |
| `created_at` | `DATETIME` | UTC timestamp of the request |

---

## Changelog

### 2.1.0
- **Webhook Failure Mode setting** — a new option in Webhook Settings controlling what happens when a delivery fails for an Elementor form submission: **Retry in background** (default) shows the visitor a normal success state and retries the failed delivery automatically, or **Show error to visitor** keeps the previous behavior of surfacing the error on the form.
- **Background retries** — failed deliveries are retried up to 2 more times, roughly 2 hours apart, via WP-Cron. Only the endpoints that failed are retried, and the exact original request (URL, headers, body) is replayed. Retry attempts are logged in Analytics with a `(retry 2/3)` / `(retry 3/3)` label suffix. Pending retries are discarded on plugin deactivation.
- **`WebhookResponse::$failedDeliveries`** — new readonly property listing the exact request for each endpoint whose dispatch failed, enabling external callers of `fwi_submit_form()` to implement their own retry logic. Backward compatible; existing callers are unaffected.
- **Scope** — background retries apply to Elementor form submissions only; the `fwi_submission` action hook and `fwi_submit_form()` behave exactly as before. Submissions rejected before dispatch (e.g. by the outside-US block) always show an error and are never retried.

### 2.0.0
- **Multi-webhook support** — configure any number of webhook endpoints under a single settings page. Each additional URL is added via the **+ Add Additional URL** button; additional blocks can be removed individually.
- **Webhook labels** — each endpoint can have an optional label (e.g. `CRM`, `Slack`) that appears as a badge on Analytics log entries.
- **Per-endpoint logging** — every configured endpoint generates its own separate log entry per form submission, making success/failure visible independently for each destination.
- **Per-endpoint test button** — each webhook block has its own **Test Webhook** button; test results are stored and displayed per endpoint.
- **Analytics webhook filter** — a new dropdown on the Analytics page filters log entries by webhook endpoint label when more than one labelled endpoint exists.
- **Log Retention setting** — choose how long log entries are kept (1, 3, 6, 12, or 24 months); previously hardcoded to 3 months.
- **Database schema update** — added `webhook_label` column to `{prefix}FWI_webhook_logs`; existing rows default to an empty string. Schema version bumped to `6.0`; `dbDelta` upgrades existing installs non-destructively.
- **Backward compatible** — the legacy `FWI_webhook_url` option is read as a fallback when `FWI_webhooks` is not yet set, so existing installs upgrade seamlessly without losing the configured URL.
- **CSV / JSON export** — exports now include a `Webhook` / `webhook_label` column.

### 1.0.0
- Initial release.
