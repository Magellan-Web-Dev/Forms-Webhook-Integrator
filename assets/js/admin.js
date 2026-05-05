/**
 * Forms Webhook Integrator — Admin JS
 */
(function () {
    'use strict';

    // ── Utility ──────────────────────────────────────────────────────────────

    /**
     * Safely escapes a string for insertion into HTML.
     *
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        const node = document.createElement('span');
        node.appendChild(document.createTextNode(String(text)));
        return node.innerHTML;
    }

    // ── Toggle Label ─────────────────────────────────────────────────────────

    function initToggleLabel() {
        const toggle = document.getElementById('fwi_active');
        const label  = document.getElementById('fwi-toggle-label');

        if (!toggle || !label) return;

        toggle.addEventListener('change', function () {
            label.textContent = this.checked ? 'Active' : 'Inactive';
        });
    }

    // ── Excluded Forms ────────────────────────────────────────────────────────

    function initExcludedForms() {
        const addBtn = document.getElementById('fwi-add-excluded-form');
        const select = document.getElementById('fwi-form-select');
        const list   = document.getElementById('fwi-excluded-forms-list');

        if (!addBtn || !select || !list) return;

        // Attach remove handlers to any server-rendered items
        list.querySelectorAll('.fwi-remove-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.closest('li').remove();
                checkExcludedFormsEmpty(list);
            });
        });

        addBtn.addEventListener('click', function () {
            const formName = select.value.trim();
            if (!formName) return;

            // Prevent duplicates
            const existing = list.querySelectorAll('input[name="fwi_excluded_forms[]"]');
            for (const input of existing) {
                if (input.value === formName) return;
            }

            appendExcludedForm(formName, list);
        });
    }

    /**
     * @param {string}      formName
     * @param {HTMLElement} list
     */
    function appendExcludedForm(formName, list) {
        const emptyMsg = document.getElementById('fwi-no-excluded-forms');
        if (emptyMsg) emptyMsg.remove();

        const li = document.createElement('li');
        li.className = 'fwi-list-item';

        const span = document.createElement('span');
        span.className   = 'fwi-item-label';
        span.textContent = formName;

        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = 'fwi_excluded_forms[]';
        input.value = formName;

        const button = document.createElement('button');
        button.type      = 'button';
        button.className = 'button fwi-remove-btn';
        button.setAttribute('aria-label', 'Remove ' + formName);
        button.textContent = 'Remove';

        li.appendChild(span);
        li.appendChild(input);
        li.appendChild(button);

        button.addEventListener('click', function () {
            li.remove();
            checkExcludedFormsEmpty(list);
        });

        list.appendChild(li);
    }

    /**
     * @param {HTMLElement} list
     */
    function checkExcludedFormsEmpty(list) {
        if (list.querySelectorAll('.fwi-list-item').length === 0) {
            const li = document.createElement('li');
            li.id        = 'fwi-no-excluded-forms';
            li.className = 'fwi-empty-msg';
            li.textContent = 'All Elementor forms are currently enabled to use the webhook.';
            list.appendChild(li);
        }
    }

    // ── Query Parameters ──────────────────────────────────────────────────────

    function initQueryParams() {
        const addBtn     = document.getElementById('fwi-add-param');
        const keyInput   = document.getElementById('fwi-param-key');
        const valueInput = document.getElementById('fwi-param-value');
        const list       = document.getElementById('fwi-query-params-list');

        if (!addBtn || !keyInput || !valueInput || !list) return;

        // Attach remove handlers to any server-rendered items
        list.querySelectorAll('.fwi-remove-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.closest('li').remove();
                reindexQueryParams(list);
                checkQueryParamsEmpty(list);
            });
        });

        addBtn.addEventListener('click', function () {
            const key   = keyInput.value.trim();
            const value = valueInput.value.trim();

            if (!key) {
                keyInput.focus();
                return;
            }

            appendQueryParam(key, value, list);
            keyInput.value   = '';
            valueInput.value = '';
            keyInput.focus();
        });

        // Allow pressing Enter in either input to trigger Add
        [keyInput, valueInput].forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addBtn.click();
                }
            });
        });
    }

    /**
     * @param {string}      key
     * @param {string}      value
     * @param {HTMLElement} list
     */
    function appendQueryParam(key, value, list) {
        const emptyMsg = document.getElementById('fwi-no-query-params');
        if (emptyMsg) emptyMsg.remove();

        const index = list.querySelectorAll('.fwi-list-item').length;

        const li = document.createElement('li');
        li.className = 'fwi-list-item';
        li.innerHTML =
            '<span class="fwi-item-label"><code>' + escapeHtml(key) + '</code> &nbsp;=&nbsp; <code>' + escapeHtml(value) + '</code></span>' +
            '<input type="hidden" name="fwi_query_params[' + index + '][key]"   value="' + escapeHtml(key) + '">' +
            '<input type="hidden" name="fwi_query_params[' + index + '][value]" value="' + escapeHtml(value) + '">' +
            '<button type="button" class="button fwi-remove-btn" aria-label="Remove parameter ' + escapeHtml(key) + '">Remove</button>';

        li.querySelector('.fwi-remove-btn').addEventListener('click', function () {
            li.remove();
            reindexQueryParams(list);
            checkQueryParamsEmpty(list);
        });

        list.appendChild(li);
    }

    /**
     * Re-indexes the name attributes after an item is removed to keep indices sequential.
     *
     * @param {HTMLElement} list
     */
    function reindexQueryParams(list) {
        list.querySelectorAll('.fwi-list-item').forEach(function (li, idx) {
            const hiddenInputs = li.querySelectorAll('input[type="hidden"]');
            if (hiddenInputs[0]) hiddenInputs[0].name = 'fwi_query_params[' + idx + '][key]';
            if (hiddenInputs[1]) hiddenInputs[1].name = 'fwi_query_params[' + idx + '][value]';
        });
    }

    /**
     * @param {HTMLElement} list
     */
    function checkQueryParamsEmpty(list) {
        if (list.querySelectorAll('.fwi-list-item').length === 0) {
            const li = document.createElement('li');
            li.id        = 'fwi-no-query-params';
            li.className = 'fwi-empty-msg';
            li.textContent = 'No query parameters added.';
            list.appendChild(li);
        }
    }

    // ── Accordions ────────────────────────────────────────────────────────────

    function initAccordions() {
        document.querySelectorAll('.fwi-accordion-header').forEach(function (header) {
            header.addEventListener('click', function () {
                const isExpanded = header.getAttribute('aria-expanded') === 'true';
                const bodyId     = header.getAttribute('aria-controls');
                const body       = bodyId ? document.getElementById(bodyId) : header.nextElementSibling;

                if (!body) return;

                if (isExpanded) {
                    header.setAttribute('aria-expanded', 'false');
                    body.hidden = true;
                } else {
                    header.setAttribute('aria-expanded', 'true');
                    body.hidden = false;
                }
            });
        });
    }

    // ── Webhook URL Watcher ───────────────────────────────────────────────────

    /**
     * Shows the Webhook Status toggle card only when the URL field has a value,
     * and enables/disables the Test Webhook button to match.
     */
    function initWebhookUrlWatcher() {
        const urlInput   = document.getElementById('fwi_webhook_url');
        const toggleCard = document.getElementById('fwi-webhook-toggle-card');
        const testBtn    = document.getElementById('fwi-test-webhook');

        if (!urlInput) return;

        function update() {
            const hasUrl = urlInput.value.trim() !== '';

            if (toggleCard) {
                toggleCard.style.display = hasUrl ? '' : 'none';
                const checkbox = toggleCard.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.disabled = !hasUrl;
                }
            }

            if (testBtn) {
                testBtn.disabled = !hasUrl;
            }
        }

        urlInput.addEventListener('input', update);
        update();
    }

    // ── Test Webhook Button ───────────────────────────────────────────────────

    function initTestWebhookButton() {
        const testBtn = document.getElementById('fwi-test-webhook');
        if (!testBtn) return;

        testBtn.addEventListener('click', function () {
            const urlInput = document.getElementById('fwi_webhook_url');
            const url      = urlInput ? urlInput.value.trim() : '';

            if (!url) {
                showTestResult(false, 'Please enter a webhook URL first.');
                return;
            }

            testBtn.disabled    = true;
            testBtn.textContent = 'Testing\u2026';

            const body = new FormData();
            body.append('action', 'fwi_test_webhook');
            body.append('nonce',  (typeof FWI !== 'undefined' && FWI.testNonce) ? FWI.testNonce : '');
            body.append('url',    url);

            fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                method: 'POST',
                body:   body,
            })
            .then(function (res) { return res.json(); })
            .then(function (response) {
                testBtn.disabled    = false;
                testBtn.textContent = 'Test Webhook';
                showTestResult(response.success, response.success ? response.data.message : response.data.message);
            })
            .catch(function (err) {
                testBtn.disabled    = false;
                testBtn.textContent = 'Test Webhook';
                showTestResult(false, 'Request failed: ' + err.message);
            });
        });
    }

    /**
     * @param {boolean} success
     * @param {string}  message
     */
    function showTestResult(success, message) {
        const el = document.getElementById('fwi-test-result');
        if (!el) return;

        el.className  = 'fwi-test-result ' + (success ? 'fwi-test-success' : 'fwi-test-error');
        el.textContent = message;
        el.hidden      = false;
    }

    // ── Webhook Headers ───────────────────────────────────────────────────────

    function initWebhookHeaders() {
        const addBtn     = document.getElementById('fwi-add-header');
        const keyInput   = document.getElementById('fwi-header-key');
        const valueInput = document.getElementById('fwi-header-value');
        const list       = document.getElementById('fwi-webhook-headers-list');

        if (!addBtn || !keyInput || !valueInput || !list) return;

        list.querySelectorAll('.fwi-remove-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                btn.closest('li').remove();
                reindexWebhookHeaders(list);
                checkWebhookHeadersEmpty(list);
            });
        });

        addBtn.addEventListener('click', function () {
            const key   = keyInput.value.trim();
            const value = valueInput.value.trim();

            if (!key) {
                keyInput.focus();
                return;
            }

            appendWebhookHeader(key, value, list);
            keyInput.value   = '';
            valueInput.value = '';
            keyInput.focus();
        });

        [keyInput, valueInput].forEach(function (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addBtn.click();
                }
            });
        });
    }

    /**
     * @param {string}      key
     * @param {string}      value
     * @param {HTMLElement} list
     */
    function appendWebhookHeader(key, value, list) {
        const emptyMsg = document.getElementById('fwi-no-webhook-headers');
        if (emptyMsg) emptyMsg.remove();

        const index = list.querySelectorAll('.fwi-list-item').length;

        const li = document.createElement('li');
        li.className = 'fwi-list-item';
        li.innerHTML =
            '<span class="fwi-item-label"><code>' + escapeHtml(key) + '</code> &nbsp;=&nbsp; <code>' + escapeHtml(value) + '</code></span>' +
            '<input type="hidden" name="fwi_webhook_headers[' + index + '][key]"   value="' + escapeHtml(key) + '">' +
            '<input type="hidden" name="fwi_webhook_headers[' + index + '][value]" value="' + escapeHtml(value) + '">' +
            '<button type="button" class="button fwi-remove-btn" aria-label="Remove header ' + escapeHtml(key) + '">Remove</button>';

        li.querySelector('.fwi-remove-btn').addEventListener('click', function () {
            li.remove();
            reindexWebhookHeaders(list);
            checkWebhookHeadersEmpty(list);
        });

        list.appendChild(li);
    }

    /**
     * @param {HTMLElement} list
     */
    function reindexWebhookHeaders(list) {
        list.querySelectorAll('.fwi-list-item').forEach(function (li, idx) {
            const inputs = li.querySelectorAll('input[type="hidden"]');
            if (inputs[0]) inputs[0].name = 'fwi_webhook_headers[' + idx + '][key]';
            if (inputs[1]) inputs[1].name = 'fwi_webhook_headers[' + idx + '][value]';
        });
    }

    /**
     * @param {HTMLElement} list
     */
    function checkWebhookHeadersEmpty(list) {
        if (list.querySelectorAll('.fwi-list-item').length === 0) {
            const li       = document.createElement('li');
            li.id          = 'fwi-no-webhook-headers';
            li.className   = 'fwi-empty-msg';
            li.textContent = 'No custom headers added.';
            list.appendChild(li);
        }
    }

    // ── Per-form Override Builders ────────────────────────────────────────────

    /**
     * Initialises every [data-fwi-builder] container found on the page.
     *
     * Each container carries two data attributes:
     *  - data-form-name: the Elementor form name (used in the hidden input names)
     *  - data-type:      either "query_params" or "headers"
     *
     * Input names follow the pattern:
     *   fwi_form_overrides[{formName}][{type}][{index}][key|value]
     */
    function initFormOverrideBuilders() {
        document.querySelectorAll('[data-fwi-builder]').forEach(function (container) {
            var formName   = container.dataset.formName || '';
            var type       = container.dataset.type     || '';
            var keyInput   = container.querySelector('.fwi-builder-key');
            var valueInput = container.querySelector('.fwi-builder-value');
            var addBtn     = container.querySelector('.fwi-builder-add-btn');
            var list       = container.querySelector('.fwi-builder-list');

            if (!keyInput || !valueInput || !addBtn || !list) return;

            // Wire up remove handlers for server-rendered items
            list.querySelectorAll('.fwi-remove-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btn.closest('li').remove();
                    reindexBuilderList(list, formName, type);
                    checkBuilderEmpty(list);
                });
            });

            addBtn.addEventListener('click', function () {
                var key   = keyInput.value.trim();
                var value = valueInput.value.trim();
                if (!key) { keyInput.focus(); return; }
                appendBuilderItem(key, value, list, formName, type);
                keyInput.value   = '';
                valueInput.value = '';
                keyInput.focus();
            });

            [keyInput, valueInput].forEach(function (input) {
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
                });
            });
        });
    }

    /**
     * Appends a new key/value item to a builder list.
     *
     * @param {string}      key
     * @param {string}      value
     * @param {HTMLElement} list
     * @param {string}      formName
     * @param {string}      type
     */
    function appendBuilderItem(key, value, list, formName, type) {
        var emptyMsg = list.querySelector('.fwi-builder-empty-msg');
        if (emptyMsg) emptyMsg.remove();

        var index  = list.querySelectorAll('.fwi-list-item').length;
        var prefix = 'fwi_form_overrides[' + escapeHtml(formName) + '][' + type + '][' + index + ']';

        var li = document.createElement('li');
        li.className = 'fwi-list-item';
        li.innerHTML =
            '<span class="fwi-item-label"><code>' + escapeHtml(key) + '</code> &nbsp;=&nbsp; <code>' + escapeHtml(value) + '</code></span>' +
            '<input type="hidden" name="' + prefix + '[key]"   value="' + escapeHtml(key)   + '">' +
            '<input type="hidden" name="' + prefix + '[value]" value="' + escapeHtml(value) + '">' +
            '<button type="button" class="button fwi-remove-btn" aria-label="Remove ' + escapeHtml(key) + '">Remove</button>';

        li.querySelector('.fwi-remove-btn').addEventListener('click', function () {
            li.remove();
            reindexBuilderList(list, formName, type);
            checkBuilderEmpty(list);
        });

        list.appendChild(li);
    }

    /**
     * Re-indexes the hidden input names after an item is removed.
     *
     * @param {HTMLElement} list
     * @param {string}      formName
     * @param {string}      type
     */
    function reindexBuilderList(list, formName, type) {
        list.querySelectorAll('.fwi-list-item').forEach(function (li, idx) {
            var prefix = 'fwi_form_overrides[' + formName + '][' + type + '][' + idx + ']';
            var inputs = li.querySelectorAll('input[type="hidden"]');
            if (inputs[0]) inputs[0].name = prefix + '[key]';
            if (inputs[1]) inputs[1].name = prefix + '[value]';
        });
    }

    /**
     * Shows the empty-state message when a builder list has no items.
     *
     * @param {HTMLElement} list
     */
    function checkBuilderEmpty(list) {
        if (list.querySelectorAll('.fwi-list-item').length === 0) {
            var li       = document.createElement('li');
            li.className = 'fwi-builder-empty-msg fwi-empty-msg';
            li.textContent = 'None added.';
            list.appendChild(li);
        }
    }

    // ── Analytics Pagination (AJAX) ──────────────────────────────────────────

    /**
     * Wires each analytics accordion to load its log list on demand via the
     * fwi_get_logs AJAX action.
     *
     * On first open, the controls bar and list are injected and an AJAX fetch
     * retrieves the first page. Filter and page changes trigger subsequent fetches.
     * Delete buttons inside the list are handled via event delegation and trigger
     * a re-fetch after a successful delete.
     */
    function initAnalyticsPagination() {
        document.querySelectorAll('.fwi-accordion').forEach(function (accordion) {
            var header = accordion.querySelector('.fwi-accordion-header');
            var body   = accordion.querySelector('.fwi-accordion-body');
            if (!header || !body) return;

            var errorsOnly  = body.dataset.errorsOnly === '1';
            var initialized = false;
            var dirty       = false;
            var state       = { page: 1, perPage: 10, search: '', year: '', month: '' };

            // Inject controls bar, list, and pagination container once.
            var controls = document.createElement('div');
            controls.className = 'fwi-acc-controls';
            controls.innerHTML = buildControlsHtml([], []);
            body.appendChild(controls);

            var list = document.createElement('ul');
            list.className = 'fwi-log-list';
            body.appendChild(list);

            var paginationEl = document.createElement('div');
            paginationEl.className = 'fwi-pagination';
            body.appendChild(paginationEl);

            // ── Controls wiring ───────────────────────────────────────────────
            controls.querySelector('.fwi-filter-year').addEventListener('change', function () {
                state.year = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.fwi-filter-month').addEventListener('change', function () {
                state.month = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.fwi-per-page').addEventListener('change', function () {
                state.perPage = parseInt(this.value, 10); state.page = 1; fetchLogs();
            });
            controls.querySelector('.fwi-search-input').addEventListener('input', function () {
                state.search = this.value; state.page = 1; fetchLogs();
            });
            controls.querySelector('.fwi-search-clear').addEventListener('click', function () {
                controls.querySelector('.fwi-search-input').value = '';
                state.search = ''; state.page = 1; fetchLogs();
            });

            // ── Fetch on accordion open ───────────────────────────────────────
            // initAccordions() fires first (registered earlier), so aria-expanded
            // is already updated when this listener runs.
            header.addEventListener('click', function () {
                var isOpen = header.getAttribute('aria-expanded') === 'true';
                if (isOpen && (!initialized || dirty)) {
                    fetchLogs();
                }
            });

            // ── Delete via event delegation ───────────────────────────────────
            list.addEventListener('click', function (e) {
                var btn   = e.target.closest('.fwi-log-delete-btn');
                if (!btn) return;
                var li    = btn.closest('.fwi-log-item');
                var logId = li ? li.dataset.logId : null;
                if (!logId) return;

                if (!confirm('Delete this log entry? This cannot be undone.')) return;

                btn.disabled    = true;
                btn.textContent = '\u2026';

                var fd = new FormData();
                fd.append('action', 'fwi_delete_log');
                fd.append('nonce',  (typeof FWI !== 'undefined' && FWI.deleteNonce) ? FWI.deleteNonce : '');
                fd.append('log_id', logId);

                fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                    method: 'POST',
                    body:   fd,
                })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (response.success) {
                        var badge = accordion.querySelector('.fwi-accordion-header .fwi-badge');
                        if (badge) {
                            var count = parseInt(badge.textContent, 10);
                            if (!isNaN(count) && count > 0) badge.textContent = String(count - 1);
                        }
                        // Re-fetch current page; mark all other accordions dirty.
                        fetchLogs();
                        document.dispatchEvent(new CustomEvent('fwi:log-deleted', { detail: { logId: logId } }));
                    } else {
                        btn.disabled    = false;
                        btn.textContent = 'Delete';
                    }
                })
                .catch(function () {
                    btn.disabled    = false;
                    btn.textContent = 'Delete';
                });
            });

            // Re-fetch if open when another accordion deletes an entry.
            document.addEventListener('fwi:log-deleted', function () {
                dirty = true;
                if (header.getAttribute('aria-expanded') === 'true') fetchLogs();
            });

            // ── Core fetch ────────────────────────────────────────────────────
            function fetchLogs() {
                list.innerHTML        = '<li class="fwi-empty-msg">Loading\u2026</li>';
                paginationEl.innerHTML = '';

                var fd = new FormData();
                fd.append('action',       'fwi_get_logs');
                fd.append('nonce',        (typeof FWI !== 'undefined' && FWI.logsNonce)  ? FWI.logsNonce  : '');
                fd.append('page',         state.page);
                fd.append('per_page',     state.perPage);
                fd.append('search',       state.search);
                fd.append('filter_year',  state.year);
                fd.append('filter_month', state.month);
                fd.append('errors_only',  errorsOnly ? '1' : '0');

                fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                    method: 'POST',
                    body:   fd,
                })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        list.innerHTML = '<li class="fwi-empty-msg">Failed to load logs.</li>';
                        return;
                    }
                    var data = resp.data;

                    if (!initialized) {
                        updateFilterOptions(controls, data.years || [], data.months || []);
                        initialized = true;
                    }
                    dirty = false;

                    list.innerHTML = data.html !== ''
                        ? data.html
                        : '<li class="fwi-empty-msg">' +
                          (errorsOnly ? 'No errors recorded.' : 'No webhook requests have been made yet.') +
                          '</li>';

                    renderPagination(paginationEl, data.currentPage, data.totalPages, data.total, state.perPage, function (p) {
                        state.page = p;
                        fetchLogs();
                    });
                })
                .catch(function () {
                    list.innerHTML = '<li class="fwi-empty-msg">Failed to load logs.</li>';
                });
            }
        });
    }

    /**
     * Populates the year and month filter dropdowns from server-returned arrays.
     *
     * @param {HTMLElement} controls
     * @param {string[]}    years    Unique years, newest first.
     * @param {string[]}    months   Unique two-digit month strings, ascending.
     */
    function updateFilterOptions(controls, years, months) {
        var MONTH_NAMES = ['January','February','March','April','May','June',
                           'July','August','September','October','November','December'];

        var yearSelect  = controls.querySelector('.fwi-filter-year');
        var monthSelect = controls.querySelector('.fwi-filter-month');

        yearSelect.innerHTML = '<option value="">All Years</option>';
        years.forEach(function (y) {
            yearSelect.innerHTML += '<option value="' + escapeHtml(y) + '">' + escapeHtml(y) + '</option>';
        });

        monthSelect.innerHTML = '<option value="">All Months</option>';
        months.forEach(function (m) {
            var name = MONTH_NAMES[parseInt(m, 10) - 1] || m;
            monthSelect.innerHTML += '<option value="' + escapeHtml(m) + '">' + escapeHtml(name) + '</option>';
        });
    }

    /**
     * Returns the HTML string for the controls bar.
     * Year/month options start empty; updateFilterOptions() fills them after
     * the first AJAX response.
     *
     * @returns {string}
     */
    function buildControlsHtml() {
        return '<div class="fwi-acc-filters">' +
                   '<select class="fwi-filter-year"><option value="">All Years</option></select>' +
                   '<select class="fwi-filter-month"><option value="">All Months</option></select>' +
                   '<div class="fwi-acc-search">' +
                       '<input type="text" class="fwi-search-input" placeholder="Search request data\u2026" />' +
                       '<button type="button" class="fwi-search-clear" aria-label="Clear search">\u2715</button>' +
                   '</div>' +
               '</div>' +
               '<div class="fwi-acc-perpage">' +
                   '<label>Per page: <select class="fwi-per-page">' +
                       '<option value="5">5</option>' +
                       '<option value="10" selected>10</option>' +
                       '<option value="25">25</option>' +
                       '<option value="50">50</option>' +
                       '<option value="100">100</option>' +
                   '</select></label>' +
               '</div>';
    }

    /**
     * Renders the pagination bar into the given container element.
     *
     * @param {HTMLElement} container
     * @param {number}      currentPage
     * @param {number}      totalPages
     * @param {number}      totalItems
     * @param {number}      perPage
     * @param {function}    onPageChange  Called with the new page number.
     */
    function renderPagination(container, currentPage, totalPages, totalItems, perPage, onPageChange) {
        if (totalItems === 0) {
            container.innerHTML = '<span class="fwi-page-info">No results match the selected filter.</span>';
            return;
        }

        var start = (currentPage - 1) * perPage + 1;
        var end   = Math.min(currentPage * perPage, totalItems);

        var html = '<span class="fwi-page-info">Showing ' + start + '–' + end + ' of ' + totalItems + '</span>';

        if (totalPages > 1) {
            html += '<div class="fwi-page-buttons">';

            if (currentPage > 1) {
                html += '<button class="fwi-page-btn" data-page="' + (currentPage - 1) + '" aria-label="Previous page">&#8249;</button>';
            }

            getPageNumbers(currentPage, totalPages).forEach(function (p) {
                if (p === '...') {
                    html += '<span class="fwi-page-ellipsis">&#8230;</span>';
                } else {
                    var activeClass = p === currentPage ? ' fwi-page-btn-active' : '';
                    html += '<button class="fwi-page-btn' + activeClass + '" data-page="' + p + '" aria-label="Page ' + p + '">' + p + '</button>';
                }
            });

            if (currentPage < totalPages) {
                html += '<button class="fwi-page-btn" data-page="' + (currentPage + 1) + '" aria-label="Next page">&#8250;</button>';
            }

            html += '</div>';
        }

        container.innerHTML = html;

        container.querySelectorAll('.fwi-page-btn[data-page]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                onPageChange(parseInt(this.dataset.page, 10));
            });
        });
    }

    /**
     * Returns an array of page numbers (and '...' sentinels) for a windowed
     * page selector. Always shows first, last, and up to two neighbours of
     * the current page; inserts '...' for gaps larger than one.
     *
     * @param {number} currentPage
     * @param {number} totalPages
     * @returns {Array<number|string>}
     */
    function getPageNumbers(currentPage, totalPages) {
        if (totalPages <= 7) {
            return Array.from({ length: totalPages }, function (_, i) { return i + 1; });
        }

        var pages = [1];

        if (currentPage > 3) pages.push('...');

        var rangeStart = Math.max(2, currentPage - 1);
        var rangeEnd   = Math.min(totalPages - 1, currentPage + 1);

        for (var i = rangeStart; i <= rangeEnd; i++) {
            pages.push(i);
        }

        if (currentPage < totalPages - 2) pages.push('...');

        pages.push(totalPages);

        return pages;
    }

    // ── Analytics API Toggle ─────────────────────────────────────────────────

    /**
     * Wires up the Analytics API toggle switch, copy-key button, and
     * regenerate-key button on the analytics page.
     */
    function initAnalyticsApiToggle() {
        var toggle   = document.getElementById('fwi-analytics-api-toggle');
        var label    = document.getElementById('fwi-api-toggle-label');
        var section  = document.getElementById('fwi-api-key-section');
        var keyValue = document.getElementById('fwi-api-key-value');

        if (!toggle) return;

        // ── Toggle on/off ─────────────────────────────────────────────────
        toggle.addEventListener('change', function () {
            var active = toggle.checked;
            toggle.disabled = true;

            var fd = new FormData();
            fd.append('action', 'fwi_toggle_analytics_api');
            fd.append('nonce',  (typeof FWI !== 'undefined' && FWI.apiToggleNonce) ? FWI.apiToggleNonce : '');
            fd.append('active', active ? '1' : '0');

            fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                method: 'POST',
                body:   fd,
            })
            .then(function (res) { return res.json(); })
            .then(function (resp) {
                toggle.disabled = false;
                if (resp.success) {
                    if (label)   label.textContent = resp.data.active ? 'Active' : 'Inactive';
                    if (section) section.hidden    = !resp.data.active;
                    if (keyValue && resp.data.key) keyValue.textContent = resp.data.key;
                } else {
                    toggle.checked = !active; // revert on failure
                }
            })
            .catch(function () {
                toggle.disabled = false;
                toggle.checked  = !active;
            });
        });

        // ── Copy key ──────────────────────────────────────────────────────
        var copyBtn = document.querySelector('.fwi-copy-key-btn');
        if (copyBtn && keyValue) {
            copyBtn.addEventListener('click', function () {
                var key = keyValue.textContent.trim();
                if (!key) return;

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(key).then(function () {
                        var orig = copyBtn.textContent;
                        copyBtn.textContent = 'Copied!';
                        setTimeout(function () { copyBtn.textContent = orig; }, 2000);
                    }).catch(function () { fallbackCopy(key, copyBtn); });
                } else {
                    fallbackCopy(key, copyBtn);
                }
            });
        }

        // ── Regenerate key ────────────────────────────────────────────────
        var regenBtn = document.querySelector('.fwi-regen-key-btn');
        if (regenBtn) {
            regenBtn.addEventListener('click', function () {
                if (!confirm('Regenerate the API key? Any existing integrations using the current key will stop working until updated.')) return;

                regenBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'fwi_regen_analytics_api_key');
                fd.append('nonce',  (typeof FWI !== 'undefined' && FWI.apiRegenNonce) ? FWI.apiRegenNonce : '');

                fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                    method: 'POST',
                    body:   fd,
                })
                .then(function (res) { return res.json(); })
                .then(function (resp) {
                    regenBtn.disabled = false;
                    if (resp.success && keyValue) {
                        keyValue.textContent = resp.data.key;
                    }
                })
                .catch(function () {
                    regenBtn.disabled = false;
                });
            });
        }
    }

    /**
     * Fallback clipboard copy using a temporary textarea.
     *
     * @param {string}      text
     * @param {HTMLElement} btn  Button whose label is temporarily replaced.
     */
    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0;';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try {
            document.execCommand('copy');
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 2000);
        } catch (_) {}
        document.body.removeChild(ta);
    }

    // ── Boot ─────────────────────────────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        initToggleLabel();
        initExcludedForms();
        initQueryParams();
        initAccordions();
        initWebhookUrlWatcher();
        initTestWebhookButton();
        initWebhookHeaders();
        initFormOverrideBuilders();
        initAnalyticsPagination();
        initAnalyticsApiToggle();
    });

})();
