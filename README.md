# Forms Webhook Integrator

A WordPress plugin that forwards Elementor Pro form submissions — and any other form — to a configurable webhook endpoint as a structured JSON payload. Includes an admin settings UI, per-request analytics logging, and automatic updates from GitHub releases.

---

## Requirements

| Requirement | Minimum |
|---|---|
| PHP | 8.1 |
| WordPress | 6.0+ (recommended) |
| Elementor Pro | Any version that provides `elementor_pro/forms/new_record` |

Elementor Pro is required only for the built-in Elementor bridge. Other form plugins can integrate via the [public action hook](#public-action-hook).

---

## Installation

1. Download the latest release ZIP from [GitHub Releases](https://github.com/Magellan-Web-Dev/Forms-Webhook-Integrator/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate **Forms Webhook Integrator**.
4. Go to **Webhook Integrator → Settings** to configure the endpoint.

Once a webhook URL is saved, the **Webhook Status** toggle appears. The webhook will not fire until the toggle is set to **Active**.

---

## Settings

All settings live under **Webhook Integrator → Settings** in the WordPress admin.

### Webhook Status

A toggle that enables or disables the webhook globally. The toggle is hidden until a webhook URL has been saved. Setting the status to **Inactive** stops all webhook POSTs without removing any configuration.

### Webhook Settings

| Field | Description |
|---|---|
| **Webhook URL** | The full URL the plugin POSTs JSON to on every form submission. |
| **Test Webhook** | Sends a lightweight test payload (`{"msg": "Webhook submission test"}`) to the currently-typed URL without saving, and displays the HTTP response code inline. The result is also persisted and shown on every subsequent page load. |
| **Global Headers** | Custom HTTP headers included on every webhook request (e.g. `Authorization: Bearer …`). Added via a key/value builder; any header here is merged after `Content-Type: application/json`. |
| **Global URL Query Parameters** | Key/value pairs appended as a query string to the webhook URL on every request. |
| **Client First Name** | Embedded in the `website_info.client` block of every payload. |
| **Client Last Name** | Embedded in the `website_info.client` block of every payload. |
| **Block Submissions Outside US** | When set to **Yes**, any submission where the sender's IP resolves to a country other than the United States is rejected before the webhook fires. Defaults to **Yes**. |

### Excluded Forms

A list of Elementor form names that should **not** trigger the webhook even when the webhook is active. Add a form from the dropdown selector; remove it from the list with the **Remove** button. Only forms detected in the site's Elementor data appear in the dropdown.

### Specific Form URL Query And Headers

Per-form overrides for URL query parameters and request headers. Each active (non-excluded) Elementor form is listed here with its own key/value builder for:

- **URL Query Parameters** — appended on top of the global query parameters for that form's requests only.
- **Request Headers** — merged after the global headers for that form's requests only.

Per-form settings are preserved in the database even while a form is excluded, so the configuration is restored automatically when the form is re-enabled.

---

## Webhook Payload

Every webhook POST sends `Content-Type: application/json` with the following body structure:

```json
{
  "website_info": {
    "name": "My Site",
    "url": "https://example.com",
    "client": {
      "first_name": "Jane",
      "last_name": "Smith"
    }
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
  "timestamp": {
    "date": "2025-04-15",
    "time": "14:32:00"
  }
}
```

`submission_data` keys are the Elementor field IDs; values are sanitised strings. `client_location_data` is populated via a live lookup to [ipapi.co](https://ipapi.co). If the IP cannot be resolved, the block contains an `"error"` key instead of location fields.

HTTP `200`, `201`, `202`, and `204` responses are treated as success. Any other status code, or a transport-level error, is recorded as a failure.

---

## Public Action Hook

Any WordPress code — including third-party form plugins — can trigger the webhook without depending on Elementor:

```php
do_action('fwi_submission', $form_name, $fields);
```

| Parameter | Type | Description |
|---|---|---|
| `$form_name` | `string` | The logical name of the form (used for exclusion checks and per-form overrides). |
| `$fields` | `array<string, mixed>` | Associative array of field names/IDs to raw values. |

The hook also accepts two optional parameters for runtime URL query params and headers:

```php
do_action('fwi_submission', $form_name, $fields, $url_query, $request_headers);
```

| Parameter | Type | Description |
|---|---|---|
| `$url_query` | `array<string, mixed>` | Extra query parameters merged onto the webhook URL for this call only. |
| `$request_headers` | `array<string, string>` | Extra headers merged after global and per-form headers for this call only. |

> **Note:** WordPress discards return values from `do_action`. If you need to inspect the result (success/failure), call `WebhookHandler::handleFormSubmission()` directly the same way `ElementorFormsBridge` does.

---

## Analytics

**Webhook Integrator → Analytics** displays a log of every webhook request the plugin has made.

### Total Requests / Total Errors

Each accordion shows its entries newest-first. Per-entry data includes:

- Timestamp, form name, HTTP response code, and success/failure status
- The full webhook URL (including query string) that was used
- The JSON request payload that was sent
- The raw response body received

### Filtering and Pagination

Each accordion has independent controls:

- **Year / Month** dropdowns to filter by calendar period
- **Per page** selector: 5, 10, 25, 50, or 100 entries per page
- Page navigation with a windowed page-number selector

### Log Management

- **Delete** — removes a single log entry immediately via AJAX (no page reload)
- **Clear All Logs** — truncates the entire log table after a confirmation prompt
- **Export CSV** — downloads all logs as a UTF-8 CSV file (Excel-compatible)
- **Export JSON** — downloads all logs as a pretty-printed JSON file

### Retention

A daily WP-Cron event automatically purges log rows older than **3 months**, keeping the table size manageable without manual intervention.

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
    │   ├── SettingsPage.php       # Settings page render and form processing
    │   └── AnalyticsPage.php      # Analytics page render, export, and log-clear
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
        ├── WebhookHandler.php     # Builds payload, performs IP lookup, POSTs to webhook
        ├── WebhookLogger.php      # Inserts and retrieves log rows from the custom DB table
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
| `created_at` | `DATETIME` | UTC timestamp of the request |

---

## Changelog

### 1.0.0
- Initial release.
