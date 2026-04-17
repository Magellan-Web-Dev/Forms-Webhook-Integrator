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
        li.innerHTML =
            '<span class="fwi-item-label">' + escapeHtml(formName) + '</span>' +
            '<input type="hidden" name="fwi_excluded_forms[]" value="' + escapeHtml(formName) + '">' +
            '<button type="button" class="button fwi-remove-btn" aria-label="Remove ' + escapeHtml(formName) + '">Remove</button>';

        li.querySelector('.fwi-remove-btn').addEventListener('click', function () {
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

    // ── Analytics Pagination ──────────────────────────────────────────────────

    /**
     * Initialises client-side month/year filtering and pagination for every
     * accordion body that contains a .fwi-log-list on the analytics page.
     *
     * For each such body the function:
     *  1. Injects a controls bar (year filter, month filter, per-page selector)
     *     above the list.
     *  2. Injects a pagination bar (item count + page buttons) below the list.
     *  3. Hides all items not on the current page; updates on every control change.
     */
    function initAnalyticsPagination() {
        document.querySelectorAll('.fwi-accordion-body').forEach(function (body) {
            const list = body.querySelector('.fwi-log-list');
            if (!list) return;

            const items = Array.from(list.querySelectorAll('.fwi-log-item'));
            if (items.length === 0) return;

            // ── Derive unique years + months from data-date="YYYY-MM" ──────────
            const dates  = new Set(items.map(function (el) { return el.dataset.date || ''; }).filter(Boolean));
            const years  = Array.from(new Set(Array.from(dates).map(function (d) { return d.slice(0, 4); }))).sort().reverse();
            const months = Array.from(new Set(Array.from(dates).map(function (d) { return d.slice(5, 7); }))).sort();

            // ── Build and inject controls bar ─────────────────────────────────
            const controls = document.createElement('div');
            controls.className = 'fwi-acc-controls';
            controls.innerHTML = buildControlsHtml(years, months);
            list.before(controls);

            // ── Build and inject pagination container ─────────────────────────
            const paginationEl = document.createElement('div');
            paginationEl.className = 'fwi-pagination';
            list.after(paginationEl);

            // ── Per-accordion state ───────────────────────────────────────────
            const state = { page: 1, perPage: 10, year: '', month: '', search: '' };

            // ── Event listeners ───────────────────────────────────────────────
            controls.querySelector('.fwi-filter-year').addEventListener('change', function () {
                state.year  = this.value;
                state.page  = 1;
                render();
            });

            controls.querySelector('.fwi-filter-month').addEventListener('change', function () {
                state.month = this.value;
                state.page  = 1;
                render();
            });

            controls.querySelector('.fwi-per-page').addEventListener('change', function () {
                state.perPage = parseInt(this.value, 10);
                state.page    = 1;
                render();
            });

            controls.querySelector('.fwi-search-input').addEventListener('input', function () {
                state.search = this.value;
                state.page   = 1;
                render();
            });

            controls.querySelector('.fwi-search-clear').addEventListener('click', function () {
                controls.querySelector('.fwi-search-input').value = '';
                state.search = '';
                state.page   = 1;
                render();
            });

            // ── Helpers ───────────────────────────────────────────────────────

            function getFilteredItems() {
                return items.filter(function (item) {
                    const date = item.dataset.date || '';
                    if (state.year  && date.slice(0, 4) !== state.year)  return false;
                    if (state.month && date.slice(5, 7) !== state.month) return false;
                    if (state.search) {
                        const needle    = state.search.toLowerCase();
                        const haystack  = (item.dataset.requestSearch || '').toLowerCase();
                        if (!haystack.includes(needle)) return false;
                    }
                    return true;
                });
            }

            function render() {
                const filtered   = getFilteredItems();
                const total      = filtered.length;
                const totalPages = Math.max(1, Math.ceil(total / state.perPage));

                if (state.page > totalPages) state.page = totalPages;

                const start = (state.page - 1) * state.perPage;
                const end   = start + state.perPage;

                // Show only items on the current page
                items.forEach(function (item) { item.hidden = true; });
                filtered.slice(start, end).forEach(function (item) { item.hidden = false; });

                renderPagination(paginationEl, state.page, totalPages, total, state.perPage, function (p) {
                    state.page = p;
                    render();
                });
            }

            // ── Delete log entry (event delegation) ───────────────────────────
            list.addEventListener('click', function (e) {
                const btn = e.target.closest('.fwi-log-delete-btn');
                if (!btn) return;

                const li    = btn.closest('.fwi-log-item');
                const logId = li ? li.dataset.logId : null;
                if (!logId) return;

                if (!confirm('Delete this log entry? This cannot be undone.')) return;

                btn.disabled    = true;
                btn.textContent = '\u2026';

                const formData = new FormData();
                formData.append('action', 'fwi_delete_log');
                formData.append('nonce',  (typeof FWI !== 'undefined' && FWI.deleteNonce) ? FWI.deleteNonce : '');
                formData.append('log_id', logId);

                fetch((typeof FWI !== 'undefined' && FWI.ajaxUrl) ? FWI.ajaxUrl : ajaxurl, {
                    method: 'POST',
                    body:   formData,
                })
                .then(function (res) { return res.json(); })
                .then(function (response) {
                    if (response.success) {
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

            // ── Listen for deletion events (fired by any accordion) ───────────
            document.addEventListener('fwi:log-deleted', function (e) {
                const deletedId = String(e.detail.logId);
                const idx = items.findIndex(function (item) {
                    return item.dataset.logId === deletedId;
                });

                if (idx === -1) return;

                items[idx].remove();
                items.splice(idx, 1);

                render();

                // Decrement the badge in this accordion's header
                const accordion = body.closest('.fwi-accordion');
                if (accordion) {
                    const badge = accordion.querySelector('.fwi-accordion-header .fwi-badge');
                    if (badge) {
                        const count = parseInt(badge.textContent, 10);
                        if (!isNaN(count) && count > 0) {
                            badge.textContent = String(count - 1);
                        }
                    }
                }
            });

            // Initial render
            render();
        });
    }

    /**
     * Returns the HTML string for the controls bar.
     *
     * @param {string[]} years  Unique years, newest first.
     * @param {string[]} months Unique two-digit month numbers, ascending.
     * @returns {string}
     */
    function buildControlsHtml(years, months) {
        const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
                             'July', 'August', 'September', 'October', 'November', 'December'];

        var yearOptions = '<option value="">All Years</option>';
        years.forEach(function (y) {
            yearOptions += '<option value="' + y + '">' + y + '</option>';
        });

        var monthOptions = '<option value="">All Months</option>';
        months.forEach(function (m) {
            var name = MONTH_NAMES[parseInt(m, 10) - 1] || m;
            monthOptions += '<option value="' + m + '">' + name + '</option>';
        });

        return '<div class="fwi-acc-filters">' +
                   '<select class="fwi-filter-year">'  + yearOptions  + '</select>' +
                   '<select class="fwi-filter-month">' + monthOptions + '</select>' +
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
    });

})();
