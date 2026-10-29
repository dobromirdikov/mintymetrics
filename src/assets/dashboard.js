/* MintyMetrics Dashboard JS */

var MM = {
    site: '',
    dateFrom: '',
    dateTo: '',
    baseUrl: '',
    today: '',
    timezone: '',
    maxDays: 1830,
    _preset: '7d',
    _clockSkew: 0,
    liveInterval: null,
    liveFailCount: 0,
    _sites: [],
    _selectedSites: [],
    _siteDebounce: null,
    _tokens: {},
    _saveQueue: null,
    // One "session" lasts from the user opening a modal until they close or reopen it.
    // Automatic refreshes after a save keep the session, so saves still pending from
    // the same dialog are not invalidated.
    _modalSession: 0,
    _modalPending: 0,
    _modalNeedsRefresh: false,
    _redirecting: false,
    _chartData: null,
    _mapRows: null,
    _mapLoading: null,
    _modalReturnFocus: null,

    init: function() {
        var main = document.querySelector('.mm-main');
        this.baseUrl = location.pathname;
        // "Today" comes from the server so presets follow the dashboard timezone, not the browser's
        this.today = (main && /^\d{4}-\d{2}-\d{2}$/.test(main.dataset.today || '')) ? main.dataset.today : this.formatDate(new Date());
        this.maxDays = parseInt(main && main.dataset.maxDays, 10) || 1830;
        this.timezone = (main && main.dataset.timezone) || '';
        var serverNow = parseInt(main && main.dataset.now, 10);
        if (serverNow) this._clockSkew = serverNow * 1000 - Date.now();

        this.setRange('7d', true);
        this.bindEvents();
        this.bindModalEvents();
        this.checkAutoOpen();
        this.startLivePolling();
        this.loadSites();
    },

    // ─── Events ──────────────────────────────────────────────────────────

    bindEvents: function() {
        var self = this;

        // Date presets
        document.querySelectorAll('.mm-date-presets button').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var range = this.getAttribute('data-range');
                var picker = document.getElementById('customDatePicker');
                if (range === 'custom') {
                    self._openCustomPicker();
                    return;
                }
                if (picker) picker.hidden = true;
                self.setRange(range);
            });
        });

        // Custom date apply
        var applyBtn = document.getElementById('applyDateRange');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() { self._applyCustomRange(); });
            ['dateFrom', 'dateTo'].forEach(function(id) {
                var input = document.getElementById(id);
                if (input) input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') self._applyCustomRange();
                });
            });
        }

        // Site selector
        var siteSelector = document.getElementById('siteSelector');
        if (siteSelector) {
            var trigger = document.getElementById('siteSelectorTrigger');
            var menu = document.getElementById('siteSelectorMenu');
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                self._toggleSiteMenu();
            });
            trigger.addEventListener('keydown', function(e) {
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    self._openSiteMenu();
                    self._focusSiteItem(e.key === 'ArrowDown' ? 0 : -1);
                } else if (e.key === 'Escape') {
                    self._closeSiteMenu();
                }
            });
            menu.addEventListener('click', function(e) {
                e.stopPropagation();
                self._handleSiteClick(e.target, !!e.target.closest('.mm-site-selector__check'));
            });
            menu.addEventListener('keydown', function(e) { self._handleSiteKey(e); });
            // Close when clicking or focusing anywhere else
            document.addEventListener('click', function(e) {
                if (!siteSelector.contains(e.target)) self._closeSiteMenu();
            });
            siteSelector.addEventListener('focusout', function(e) {
                if (e.relatedTarget && !siteSelector.contains(e.relatedTarget)) self._closeSiteMenu();
            });
        }

        // Tab bars
        document.querySelectorAll('.mm-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var parent = this.closest('.mm-tab-bar');
                parent.querySelectorAll('.mm-tab').forEach(function(t) {
                    t.classList.remove('active');
                    t.setAttribute('aria-pressed', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-pressed', 'true');

                if (this.dataset.utm) {
                    self.loadUTM(this.dataset.utm);
                } else if (this.dataset.device) {
                    self.loadDevices(this.dataset.device);
                }
            });
        });

        // Export buttons
        document.querySelectorAll('.mm-export-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                self.exportCSV(this.dataset.export);
            });
        });

        // Events drilldown — clicking an event name loads its values
        var eventsTable = document.getElementById('tableEvents');
        if (eventsTable) {
            eventsTable.addEventListener('click', function(e) {
                var link = e.target.closest('.mm-event-link');
                if (!link) return;
                e.preventDefault();
                var row = link.closest('tr');
                var name = row && row.dataset.eventName;
                if (name) self.loadEventValues(name);
            });
        }
        var eventsBack = document.getElementById('eventsBack');
        if (eventsBack) {
            eventsBack.addEventListener('click', function() { self.loadEvents(); });
        }

        // Modal links
        document.querySelectorAll('[data-modal]').forEach(function(link) {
            self._bindModalLink(link);
        });

        // Redraw the chart at the new width; recolor the map when the color scheme changes
        var resizeTimer = null;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                if (self._chartData) self.renderChart(self._chartData);
            }, 150);
        });
        if (window.matchMedia) {
            var scheme = window.matchMedia('(prefers-color-scheme: dark)');
            var recolor = function() {
                var map = document.getElementById('worldMap');
                if (map && self._mapRows) self.colorMap(map, self._mapRows);
            };
            if (scheme.addEventListener) scheme.addEventListener('change', recolor);
            else if (scheme.addListener) scheme.addListener(recolor);
        }
    },

    _bindModalLink: function(link) {
        var self = this;
        link.addEventListener('click', function(e) {
            e.preventDefault();
            self.openNamedModal(this.dataset.modal);
        });
    },

    // ─── Date Range ──────────────────────────────────────────────────────

    setRange: function(preset, silent) {
        var days = {today: 0, yesterday: 0, '7d': 6, '30d': 29, '90d': 89};
        if (!(preset in days)) preset = '7d';

        this.today = this.currentToday();
        var to = preset === 'yesterday' ? this.addDays(this.today, -1) : this.today;
        this.dateFrom = this.addDays(to, -days[preset]);
        this.dateTo = to;
        this._preset = preset;
        this._markPreset(preset);

        if (!silent) this.loadAll();
    },

    setCustomRange: function(from, to) {
        this.dateFrom = from;
        this.dateTo = to;
        this._preset = 'custom';
        this._markPreset('custom');
        this.loadAll();
    },

    _markPreset: function(preset) {
        document.querySelectorAll('.mm-date-presets button').forEach(function(btn) {
            var active = btn.getAttribute('data-range') === preset;
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', String(active));
        });
    },

    _openCustomPicker: function() {
        var picker = document.getElementById('customDatePicker');
        var from = document.getElementById('dateFrom');
        var to = document.getElementById('dateTo');
        if (!picker || !from || !to) return;
        this.today = this.currentToday();
        from.value = this.dateFrom;
        to.value = this.dateTo;
        from.max = to.max = this.today;
        picker.hidden = false;
        from.focus();
    },

    _applyCustomRange: function() {
        var fromEl = document.getElementById('dateFrom');
        var toEl = document.getElementById('dateTo');
        var from = fromEl.value;
        var to = toEl.value;
        var error = this.validateRange(from, to);

        fromEl.removeAttribute('aria-invalid');
        toEl.removeAttribute('aria-invalid');
        if (error) {
            var bad = (error.field === 'to') ? toEl : fromEl;
            bad.setAttribute('aria-invalid', 'true');
            bad.focus();
            this.toast(error.message, 'error');
            return;
        }
        this.setCustomRange(from, to);
    },

    /**
     * Returns null for a usable range, or {field, message}.
     */
    validateRange: function(from, to) {
        if (!this.parseDate(from)) return {field: 'from', message: 'Enter a valid start date.'};
        if (!this.parseDate(to)) return {field: 'to', message: 'Enter a valid end date.'};
        if (from > to) return {field: 'from', message: 'The start date must be on or before the end date.'};
        var span = Math.round((this.parseDate(to) - this.parseDate(from)) / 86400000) + 1;
        if (span > this.maxDays) {
            return {field: 'from', message: 'Choose a range of at most ' + this.formatNumber(this.maxDays) + ' days.'};
        }
        return null;
    },

    // ─── Data Fetching ───────────────────────────────────────────────────

    fetch: function(action, params) {
        var self = this;
        var url = this.baseUrl + '?api&action=' + encodeURIComponent(action);
        url += '&from=' + this.dateFrom + '&to=' + this.dateTo;
        if (this.site) url += '&site=' + encodeURIComponent(this.site);
        if (params) {
            for (var k in params) {
                url += '&' + k + '=' + encodeURIComponent(params[k]);
            }
        }

        return fetch(url, { credentials: 'same-origin' })
            .then(function(res) {
                if (res.status === 401) {
                    self._sessionExpired();
                    throw new Error('Session expired');
                }
                if (!res.ok) {
                    return res.json().catch(function() { return {}; }).then(function(body) {
                        throw new Error(body.message || body.error || 'Request failed (' + res.status + ')');
                    });
                }
                return res.json();
            });
    },

    _sessionExpired: function() {
        if (this._redirecting) return;
        this._redirecting = true;
        location.href = this.baseUrl + '?login';
    },

    /**
     * Fetch data for one dashboard panel. Only the newest request per panel may render,
     * so a slow response for an old date range or site never overwrites a newer one.
     */
    load: function(panel, action, params, render) {
        var self = this;
        var token = (this._tokens[panel] || 0) + 1;
        this._tokens[panel] = token;
        return this.fetch(action, params)
            .then(function(d) {
                if (self._tokens[panel] === token) render.call(self, d);
            })
            .catch(function(err) {
                if (self._tokens[panel] === token && !self._redirecting) self.renderError(panel, err);
            });
    },

    loadAll: function() {
        if (this._redirecting) return;
        // Rolling presets ("Today", "7 days", ...) move with the clock: after midnight, or a
        // timezone change, they cover the new days
        if (this._preset !== 'custom') this.setRange(this._preset, true);
        var utmTab = document.querySelector('.mm-tab.active[data-utm]');
        var deviceTab = document.querySelector('.mm-tab.active[data-device]');

        this.load('summary', 'summary', null, this.renderSummary);
        this.load('chart', 'chart', null, this.renderChart);
        this.load('pages', 'pages', null, this.renderPages);
        this.load('referrers', 'referrers', null, this.renderReferrers);
        this.loadUTM(utmTab ? utmTab.dataset.utm : 'source');
        this.loadDevices(deviceTab ? deviceTab.dataset.device : 'type');
        this.load('countries', 'countries', null, this.renderCountries);
        this.load('screens', 'screens', null, this.renderScreens);
        this.load('languages', 'languages', null, this.renderLanguages);
        if (this._eventDrilldown) this.loadEventValues(this._eventDrilldown);
        else this.loadEvents();
    },

    loadEvents: function() {
        this._eventDrilldown = null;
        this._toggleEventsBack(false);
        this.load('events', 'events', null, this.renderEvents);
    },

    loadEventValues: function(name) {
        this._eventDrilldown = name;
        this._toggleEventsBack(true);
        this.load('events', 'event_values', { name: name }, this.renderEventValues);
    },

    _toggleEventsBack: function(show) {
        var heading = document.getElementById('eventsHeading');
        var back = document.getElementById('eventsBack');
        if (heading) heading.textContent = show ? 'Event: ' + this._eventDrilldown : 'Top Events';
        if (back) back.hidden = !show;
    },

    loadSites: function() {
        var self = this;
        this.fetch('sites').then(function(d) {
            var sites = (d && d.sites) || [];
            var wrapper = document.getElementById('siteSelector');
            if (!wrapper || sites.length < 2) {
                if (wrapper) wrapper.hidden = true;
                self._sites = sites;
                self._selectedSites = [];
                self.site = sites.length === 1 ? sites[0] : '';
                return;
            }
            self._sites = sites;

            // Restore saved selection (backwards-compatible: single string or comma-separated)
            var saved = '';
            try { saved = localStorage.getItem('mm_selected_site') || ''; } catch(e) {}
            self._selectedSites = saved ? saved.split(',').filter(function(s) {
                return sites.indexOf(s) !== -1;
            }) : [];
            self._updateSiteMenu();
            self._updateSiteLabel();
            self._applySiteSelection();
        }).catch(function() {
            // Site list unavailable: fall back to all sites
        }).then(function() {
            // First data load waits for the saved site selection, so it happens exactly once
            self.loadAll();
            self.pollLive();
        });
    },

    _updateSiteMenu: function() {
        var menu = document.getElementById('siteSelectorMenu');
        if (!menu || !this._sites) return;
        var self = this;
        var focused = document.activeElement && menu.contains(document.activeElement)
            ? Array.prototype.indexOf.call(menu.children, document.activeElement) : -1;
        var html = '';

        // "All Sites" item (with checkbox, same style as others)
        var allActive = this._selectedSites.length === 0;
        html += '<button class="mm-site-selector__item mm-site-selector__item--all' +
            (allActive ? ' mm-site-selector__item--active' : '') +
            '" data-site-action="all" type="button" role="option" aria-selected="' + allActive + '" tabindex="-1">' +
            '<span class="mm-site-selector__check' + (allActive ? ' mm-site-selector__check--checked' : '') + '" aria-hidden="true"></span>' +
            '<span class="mm-site-selector__name">All Sites</span></button>';

        // Individual site items (with checkbox)
        this._sites.forEach(function(s) {
            var checked = self._selectedSites.indexOf(s) !== -1;
            html += '<button class="mm-site-selector__item' +
                (checked ? ' mm-site-selector__item--active' : '') +
                '" data-site-value="' + self.escapeHtml(s) + '" type="button" role="option" aria-selected="' + checked + '" tabindex="-1">' +
                '<span class="mm-site-selector__check' + (checked ? ' mm-site-selector__check--checked' : '') + '" aria-hidden="true"></span>' +
                '<span class="mm-site-selector__name" title="' + self.escapeHtml(s) + '">' + self.escapeHtml(s) + '</span></button>';
        });
        menu.innerHTML = html;
        menu.setAttribute('aria-multiselectable', 'true');
        // Keep keyboard focus on the same item after re-rendering
        if (focused !== -1) this._focusSiteItem(focused);
    },

    _updateSiteLabel: function() {
        var label = document.getElementById('siteSelectorLabel');
        if (!label) return;
        var count = this._selectedSites.length;
        if (count === 0) label.textContent = 'All Sites';
        else if (count === 1) label.textContent = this._selectedSites[0];
        else label.textContent = count + ' sites';
    },

    _applySiteSelection: function() {
        if (this._selectedSites.length === 1) this.site = this._selectedSites[0];
        else if (this._selectedSites.length > 1) this.site = this._selectedSites.join(',');
        else this.site = '';
        try { localStorage.setItem('mm_selected_site', this._selectedSites.join(',')); } catch(e) {}
    },

    _toggleSiteMenu: function() {
        var menu = document.getElementById('siteSelectorMenu');
        if (!menu) return;
        if (menu.hidden) this._openSiteMenu();
        else this._closeSiteMenu();
    },

    _openSiteMenu: function() {
        var menu = document.getElementById('siteSelectorMenu');
        var trigger = document.getElementById('siteSelectorTrigger');
        if (!menu || !trigger) return;
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
    },

    _closeSiteMenu: function(returnFocus) {
        var menu = document.getElementById('siteSelectorMenu');
        var trigger = document.getElementById('siteSelectorTrigger');
        if (!menu || !trigger || menu.hidden) return;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) trigger.focus();
    },

    _focusSiteItem: function(index) {
        var items = document.querySelectorAll('#siteSelectorMenu .mm-site-selector__item');
        if (!items.length) return;
        if (index < 0) index = items.length - 1;
        if (index >= items.length) index = 0;
        items[index].focus();
    },

    _handleSiteKey: function(e) {
        var items = Array.prototype.slice.call(document.querySelectorAll('#siteSelectorMenu .mm-site-selector__item'));
        var idx = items.indexOf(document.activeElement);
        switch (e.key) {
            case 'ArrowDown': e.preventDefault(); this._focusSiteItem(idx + 1); break;
            case 'ArrowUp': e.preventDefault(); this._focusSiteItem(idx - 1); break;
            case 'Home': e.preventDefault(); this._focusSiteItem(0); break;
            case 'End': e.preventDefault(); this._focusSiteItem(-1); break;
            case 'Escape': e.preventDefault(); e.stopPropagation(); this._closeSiteMenu(true); break;
            case 'Tab': this._closeSiteMenu(); break;
            case ' ':
                // Space toggles a site in the multi-selection (like clicking its checkbox); Enter selects only it
                if (idx !== -1) {
                    e.preventDefault();
                    this._handleSiteClick(items[idx], true);
                }
                break;
        }
    },

    _handleSiteClick: function(target, toggle) {
        var item = target.closest('.mm-site-selector__item');
        if (!item) return;

        if (item.dataset.siteAction === 'all') {
            this._selectedSites = [];
        } else {
            var site = item.dataset.siteValue;
            if (!site) return;
            var idx = this._selectedSites.indexOf(site);
            if (toggle) {
                // Checkbox = toggle in multi-select set
                if (idx !== -1) this._selectedSites.splice(idx, 1);
                else this._selectedSites.push(site);
            } else {
                // Name = single-select (replaces selection, menu stays open)
                this._selectedSites = [site];
            }
        }
        this._updateSiteMenu();
        this._updateSiteLabel();
        this._applySiteSelection();
        var self = this;
        clearTimeout(this._siteDebounce);
        this._siteDebounce = setTimeout(function() { self.loadAll(); self.pollLive(); }, 600);
    },

    loadUTM: function(group) {
        this.load('utm', 'utm', {group: group}, this.renderUTM);
    },

    loadDevices: function(group) {
        this.load('devices', 'devices', {group: group}, this.renderDevices);
    },

    // ─── Render: Errors ──────────────────────────────────────────────────

    _panels: {
        chart: 'mainChart', pages: 'tablePages', referrers: 'tableReferrers', utm: 'tableUTM',
        devices: 'chartDevices', countries: 'tableCountries', screens: 'tableScreens',
        languages: 'tableLangs', events: 'tableEvents'
    },

    renderError: function(panel, err) {
        var msg = 'Could not load data' + (err && err.message ? ': ' + err.message : '.');
        if (panel === 'summary') {
            ['valPageviews', 'valUniques', 'valBounce', 'valTime'].forEach(function(id) { MM.setText(id, '—'); });
            this.toast(msg, 'error');
            return;
        }
        var el = document.getElementById(this._panels[panel]);
        if (!el) return;
        var html = '<div class="mm-empty" role="alert"><div class="mm-empty__text">' + this.escapeHtml(msg) + '</div></div>';
        if (panel === 'countries') {
            // Do not leave the previous range's results on the map
            this._mapRows = null;
            var map = document.getElementById('worldMap');
            if (map) this.colorMap(map, []);
        }
        if (panel === 'chart') {
            this._chartData = null;
            el.removeAttribute('aria-labelledby');
            el.setAttribute('aria-label', msg);
        }
        if (el.tagName === 'TABLE') {
            var cols = el.querySelectorAll('thead th').length || 1;
            el.querySelector('tbody').innerHTML = '<tr><td colspan="' + cols + '">' + html + '</td></tr>';
        } else {
            el.innerHTML = html;
        }
    },

    // ─── Render: Summary ─────────────────────────────────────────────────

    renderSummary: function(data) {
        this.setText('valPageviews', this.formatNumber(data.pageviews || 0));
        this.setText('valUniques', this.formatNumber(data.uniques || 0));
        this.setText('valBounce', data.bounce_rate != null ? this.formatPercent(data.bounce_rate) : '—');
        this.setText('valTime', data.avg_time != null ? this.formatDuration(data.avg_time) : '—');
    },

    // ─── Render: Line Chart ──────────────────────────────────────────────

    /**
     * Round axis maximum and step (1, 2 or 5 × 10^n) so the y-axis shows readable numbers.
     */
    niceScale: function(maxVal, targetTicks) {
        maxVal = Math.max(maxVal, 1);
        var raw = maxVal / targetTicks;
        var mag = Math.pow(10, Math.floor(Math.log(raw) / Math.LN10));
        var norm = raw / mag;
        var step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10) * mag;
        step = Math.max(1, step); // counts are whole numbers
        var top = Math.ceil(maxVal / step) * step;
        return {max: top, step: step, ticks: Math.round(top / step)};
    },

    renderChart: function(data) {
        var container = document.getElementById('mainChart');
        if (!container) return;
        this._chartData = data;
        if (!data.days || data.days.length === 0) {
            container.innerHTML = this.emptyState('No data for this period');
            container.setAttribute('aria-label', 'No data for this period');
            return;
        }

        var self = this;
        var days = data.days;
        var w = container.clientWidth || 700;
        var h = 250;
        var pad = {top: 20, right: 20, bottom: 40, left: 50};
        var cw = w - pad.left - pad.right;
        var ch = h - pad.top - pad.bottom;

        var dataMax = 0, totalViews = 0, totalUniques = 0;
        days.forEach(function(d) {
            dataMax = Math.max(dataMax, d.pageviews, d.uniques);
            totalViews += d.pageviews;
            totalUniques += d.uniques;
        });
        var scale = this.niceScale(dataMax, 5);
        var maxVal = scale.max;

        var xStep = days.length > 1 ? cw / (days.length - 1) : cw / 2;

        function yPos(val) { return pad.top + ch - (val / maxVal * ch); }
        function xPos(i) { return pad.left + (days.length > 1 ? i * xStep : cw / 2); }

        var svg = '<svg class="mm-chart-svg" viewBox="0 0 ' + w + ' ' + h + '" aria-hidden="true" focusable="false">';

        // Grid lines at round values
        for (var g = 0; g <= scale.ticks; g++) {
            var gv = g * scale.step;
            var gy = yPos(gv);
            svg += '<line class="mm-chart-grid" x1="' + pad.left + '" y1="' + gy + '" x2="' + (w - pad.right) + '" y2="' + gy + '"/>';
            svg += '<text class="mm-chart-label" x="' + (pad.left - 8) + '" y="' + (gy + 4) + '" text-anchor="end">' + this.formatNumber(gv) + '</text>';
        }

        // X-axis labels
        var labelEvery = Math.max(1, Math.ceil(days.length / 7));
        for (var i = 0; i < days.length; i += labelEvery) {
            svg += '<text class="mm-chart-label" x="' + xPos(i) + '" y="' + (h - 8) + '" text-anchor="middle">' + this.formatShortDate(days[i].date) + '</text>';
        }

        function buildPoints(key) {
            var pts = [];
            for (var i = 0; i < days.length; i++) {
                pts.push(xPos(i) + ',' + yPos(days[i][key]));
            }
            return pts.join(' ');
        }

        // Area fills and lines (colors come from CSS, so they follow light/dark mode)
        [{key: 'pageviews', n: 1}, {key: 'uniques', n: 2}].forEach(function(s) {
            var pts = buildPoints(s.key);
            svg += '<polygon class="mm-chart-area mm-chart-area--' + s.n + '" points="' + xPos(0) + ',' + (pad.top + ch) + ' ' + pts + ' ' + xPos(days.length - 1) + ',' + (pad.top + ch) + '"/>';
            svg += '<polyline class="mm-chart-line mm-chart-line--' + s.n + '" points="' + pts + '"/>';
        });

        // Data points and hit areas
        for (var j = 0; j < days.length; j++) {
            var cx = xPos(j);
            svg += '<rect class="mm-chart-hitarea" x="' + (cx - xStep / 2) + '" y="' + pad.top + '" width="' + xStep + '" height="' + ch + '" data-idx="' + j + '"/>';
            svg += '<circle class="mm-chart-dot mm-chart-dot--1" cx="' + cx + '" cy="' + yPos(days[j].pageviews) + '"/>';
            svg += '<circle class="mm-chart-dot mm-chart-dot--2" cx="' + cx + '" cy="' + yPos(days[j].uniques) + '"/>';
        }

        svg += '</svg>';

        svg += '<div class="mm-chart-legend" aria-hidden="true">'
            + '<span><span class="mm-chart-legend__swatch mm-chart-legend__swatch--1"></span>Pageviews</span>'
            + '<span><span class="mm-chart-legend__swatch mm-chart-legend__swatch--2"></span>Visitors</span>'
            + '</div>';

        container.innerHTML = svg;
        container.removeAttribute('aria-labelledby');
        container.setAttribute('aria-label', 'Daily pageviews and visitors from ' + days[0].date + ' to ' + days[days.length - 1].date
            + ': ' + this.formatNumber(totalViews) + ' pageviews in total, busiest day ' + this.formatNumber(dataMax) + '.');

        // Tooltip
        var tooltip = document.createElement('div');
        tooltip.className = 'mm-chart-tooltip';
        container.appendChild(tooltip);
        var dots = container.querySelectorAll('.mm-chart-dot');

        container.querySelectorAll('.mm-chart-hitarea').forEach(function(area) {
            area.addEventListener('mouseenter', function() {
                var idx = parseInt(this.dataset.idx, 10);
                var d = days[idx];
                tooltip.innerHTML = '<strong>' + self.escapeHtml(d.date) + '</strong><br>Pageviews: ' + self.formatNumber(d.pageviews) + '<br>Visitors: ' + self.formatNumber(d.uniques);
                tooltip.classList.add('visible');
                dots[idx * 2].classList.add('visible');
                dots[idx * 2 + 1].classList.add('visible');
            });
            area.addEventListener('mousemove', function(e) {
                var rect = container.getBoundingClientRect();
                tooltip.style.left = (e.clientX - rect.left + 10) + 'px';
                tooltip.style.top = (e.clientY - rect.top - 40) + 'px';
            });
            area.addEventListener('mouseleave', function() {
                tooltip.classList.remove('visible');
                dots.forEach(function(d) { d.classList.remove('visible'); });
            });
        });
    },

    // ─── Render: Tables ──────────────────────────────────────────────────

    renderPages: function(data) {
        this.renderTable('tablePages', data.rows, [
            {key: 'page_path', label: 'Page', truncate: true},
            {key: 'views', label: 'Views'},
            {key: 'uniques', label: 'Uniques'}
        ], 'No pages tracked yet');
    },

    renderReferrers: function(data) {
        this.renderTable('tableReferrers', data.rows, [
            {key: 'domain', label: 'Source', truncate: true},
            {key: 'visitors', label: 'Visitors'}
        ], 'No referrer data');
    },

    renderUTM: function(data) {
        this.renderTable('tableUTM', data.rows, [
            {key: 'name', label: 'Name'},
            {key: 'visitors', label: 'Visitors'}
        ], 'No UTM data');
    },

    renderScreens: function(data) {
        this.renderTable('tableScreens', data.rows, [
            {key: 'resolution', label: 'Resolution'},
            {key: 'visitors', label: 'Visitors'}
        ], 'No screen data');
    },

    renderLanguages: function(data) {
        this.renderTable('tableLangs', data.rows, [
            {key: 'lang', label: 'Language'},
            {key: 'visitors', label: 'Visitors'}
        ], 'No language data');
    },

    renderEvents: function(data) {
        var table = document.getElementById('tableEvents');
        if (!table) return;
        table.querySelector('thead').innerHTML = '<tr><th>Event</th><th>Count</th><th>Users</th></tr>';
        var tbody = table.querySelector('tbody');
        var rows = (data && data.rows) || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="3">' + this.emptyState('No events tracked yet — call mm(\'track\', \'name\') from your site') + '</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(r) {
            var name = MM.escapeHtml(String(r.name));
            html += '<tr class="mm-row-clickable" data-event-name="' + name + '">'
                  + '<td><a href="#" class="mm-event-link">' + name + '</a></td>'
                  + '<td>' + MM.formatNumber(r.count) + '</td>'
                  + '<td>' + MM.formatNumber(r.uniques) + '</td>'
                  + '</tr>';
        });
        tbody.innerHTML = html;
    },

    renderEventValues: function(data) {
        var table = document.getElementById('tableEvents');
        if (!table) return;
        table.querySelector('thead').innerHTML = '<tr><th>Value</th><th>Count</th><th>Users</th></tr>';
        var tbody = table.querySelector('tbody');
        var rows = (data && data.rows) || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="3">' + this.emptyState('No values for this event') + '</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(r) {
            html += '<tr><td>' + MM.escapeHtml(String(r.value)) + '</td>'
                  + '<td>' + MM.formatNumber(r.count) + '</td>'
                  + '<td>' + MM.formatNumber(r.uniques) + '</td></tr>';
        });
        tbody.innerHTML = html;
    },

    renderTable: function(tableId, rows, columns, emptyMsg) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="' + columns.length + '">' + this.emptyState(emptyMsg || 'No data') + '</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(row) {
            html += '<tr>';
            columns.forEach(function(col) {
                var val = row[col.key] !== undefined && row[col.key] !== null ? row[col.key] : '';
                var valStr = typeof val === 'number' ? MM.formatNumber(val) : MM.escapeHtml(String(val));
                html += '<td' + (col.truncate ? ' class="mm-truncate"' : '') + ' title="' + MM.escapeHtml(String(val)) + '">' + valStr + '</td>';
            });
            html += '</tr>';
        });
        tbody.innerHTML = html;
    },

    // ─── Render: Bar Chart (Devices) ─────────────────────────────────────

    renderDevices: function(data) {
        var container = document.getElementById('chartDevices');
        if (!container) return;
        var rows = data.rows || [];
        if (rows.length === 0) {
            container.innerHTML = this.emptyState('No device data');
            return;
        }

        var max = 0, total = 0;
        rows.forEach(function(r) {
            if (r.visitors > max) max = r.visitors;
            total += r.visitors;
        });
        if (max === 0) max = 1;

        // Built with DOM + CSSOM: the CSP blocks inline style attributes
        container.textContent = '';
        rows.forEach(function(r, i) {
            var label = r.name || 'Unknown';
            var row = document.createElement('div');
            row.className = 'mm-bar-row';

            var labelEl = document.createElement('span');
            labelEl.className = 'mm-bar-label';
            labelEl.title = label;
            labelEl.textContent = label;

            var track = document.createElement('span');
            track.className = 'mm-bar-track';
            var fill = document.createElement('span');
            fill.className = 'mm-bar-fill';
            fill.style.width = (r.visitors / max * 100).toFixed(1) + '%';
            fill.style.background = 'var(--mm-chart-' + (i % 8 + 1) + ')';
            track.appendChild(fill);

            var value = document.createElement('span');
            value.className = 'mm-bar-value';
            value.textContent = total > 0 ? (r.visitors / total * 100).toFixed(1) + '%' : '';

            row.appendChild(labelEl);
            row.appendChild(track);
            row.appendChild(value);
            container.appendChild(row);
        });
    },

    // ─── Render: Countries ───────────────────────────────────────────────

    countryName: function(code) {
        try {
            if (!this._regionNames) this._regionNames = new Intl.DisplayNames(['en'], {type: 'region'});
            return this._regionNames.of(code) || code;
        } catch (e) {
            return code;
        }
    },

    renderCountries: function(data) {
        var self = this;
        var rows = (data.rows || []).map(function(r) {
            return {name: self.countryName(r.code) + ' (' + r.code + ')', code: r.code, visitors: r.visitors};
        });
        this.renderTable('tableCountries', rows, [
            {key: 'name', label: 'Country', truncate: true},
            {key: 'visitors', label: 'Visitors'}
        ], 'No country data. Enable geolocation in settings.');

        this._mapRows = data.rows || [];
        var container = document.getElementById('worldMap');
        if (!container) return;
        if (container.querySelector('svg')) {
            this.colorMap(container, this._mapRows);
        } else if (this._mapRows.length > 0) {
            this.loadMap(container);
        }
    },

    loadMap: function(container) {
        if (this._mapLoading) return;
        var self = this;
        this._mapLoading = fetch(this.baseUrl + '?asset=worldmap', {credentials: 'same-origin'})
            .then(function(res) {
                if (!res.ok) throw new Error('Map unavailable');
                return res.text();
            })
            .then(function(svg) {
                if (svg.indexOf('<svg') === -1) throw new Error('Map unavailable');
                container.innerHTML = svg;
                var map = container.querySelector('svg');
                if (map) {
                    map.setAttribute('role', 'img');
                    map.setAttribute('aria-label', 'Map of visitors by country');
                }
                self.colorMap(container, self._mapRows || []);
            })
            .catch(function() {
                self._mapLoading = null; // Map not available: skip silently, retry on next load
            });
    },

    colorMap: function(container, rows) {
        var svg = container.querySelector('svg');
        if (!svg) return;

        var max = 0;
        var countryData = {};
        rows.forEach(function(r) {
            countryData[r.code] = r.visitors;
            if (r.visitors > max) max = r.visitors;
        });

        // Reset previous colors; uncolored countries use the CSS fill
        svg.querySelectorAll('path[id]').forEach(function(path) { path.style.fill = ''; });
        if (max === 0) return;

        var css = getComputedStyle(document.documentElement);
        var low = this.parseHex(css.getPropertyValue('--mm-map-low')) || [184, 230, 216];
        var high = this.parseHex(css.getPropertyValue('--mm-map-high')) || [26, 138, 106];

        for (var code in countryData) {
            var path = svg.getElementById ? svg.getElementById(code) : svg.querySelector('#' + code);
            if (!path) continue;
            var t = countryData[code] / max;
            path.style.fill = 'rgb(' + low.map(function(c, k) { return Math.round(c + (high[k] - c) * t); }).join(',') + ')';
        }
    },

    // ─── Live Visitors ───────────────────────────────────────────────────

    startLivePolling: function() {
        var self = this;
        var start = function() {
            clearInterval(self.liveInterval);
            self.liveInterval = setInterval(function() { self.pollLive(); }, 30000);
        };
        start();
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                clearInterval(self.liveInterval);
                self.liveInterval = null;
            } else if (!self.liveInterval) {
                self.pollLive();
                start();
            }
        });
    },

    pollLive: function() {
        var self = this;
        var token = (this._tokens.live || 0) + 1;
        this._tokens.live = token;
        this.fetch('live').then(function(d) {
            if (self._tokens.live !== token) return;
            self.liveFailCount = 0;
            self.setText('valLive', d.count !== undefined ? self.formatNumber(d.count) : '—');
        }).catch(function() {
            if (self._tokens.live !== token) return;
            self.liveFailCount++;
            if (self.liveFailCount >= 3) self.setText('valLive', '—');
        });
    },

    // ─── CSV Export ──────────────────────────────────────────────────────

    exportCSV: function(type) {
        var url = this.baseUrl + '?export&type=' + encodeURIComponent(type);
        url += '&from=' + this.dateFrom + '&to=' + this.dateTo;
        if (this.site) url += '&site=' + encodeURIComponent(this.site);
        location.href = url;
    },

    // ─── Toast Notifications ─────────────────────────────────────────────

    toast: function(message, type) {
        var container = document.getElementById('mmToasts');
        if (!container) {
            container = document.createElement('div');
            container.id = 'mmToasts';
            container.className = 'mm-toast-container';
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            document.body.appendChild(container);
        }
        var el = document.createElement('div');
        el.className = 'mm-toast mm-toast--' + (type || 'success');
        el.textContent = message;
        container.appendChild(el);
        setTimeout(function() { el.remove(); }, 4000);
    },

    // ─── Modal ────────────────────────────────────────────────────────────

    _modals: {
        settings: {path: '?settings', title: 'Settings', options: {wide: true}},
        health: {path: '?health', title: 'System Health', options: {narrow: true}},
        help: {path: '?help', title: 'Tracking Code', options: {}}
    },

    openNamedModal: function(name) {
        var m = this._modals[name];
        if (m) this.openModal(this.baseUrl + m.path, m.title, m.options);
    },

    openModal: function(url, title, options, isRefresh) {
        var self = this;
        var overlay = document.getElementById('mmModal');
        if (!overlay) return;
        if (!isRefresh) this._startModalSession();
        var titleEl = document.getElementById('mmModalTitle');
        var body = document.getElementById('mmModalBody');
        var modal = overlay.querySelector('.mm-modal');

        // Remember where focus was, to return it when the modal closes
        if (overlay.hidden) {
            var active = document.activeElement;
            this._modalReturnFocus = (active && active !== document.body) ? active : null;
        }

        overlay.dataset.url = url;
        overlay.dataset.modalOpts = JSON.stringify(options || {});
        titleEl.textContent = title;
        body.innerHTML = '<div class="mm-loading"><div class="mm-spinner"></div></div>';
        body.setAttribute('aria-busy', 'true');
        modal.className = 'mm-modal' + (options && options.narrow ? ' mm-modal--narrow' : '') + (options && options.wide ? ' mm-modal--wide' : '');

        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        modal.focus();

        var token = (this._tokens.modal || 0) + 1;
        this._tokens.modal = token;
        var fetchUrl = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'modal=1';
        fetch(fetchUrl, { credentials: 'same-origin' })
            .then(function(res) {
                if (res.status === 401) {
                    self._sessionExpired();
                    throw new Error('Session expired');
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function(html) {
                if (self._tokens.modal !== token || overlay.hidden) return;
                body.innerHTML = html;
                body.removeAttribute('aria-busy');
                self.bindModalForms();
                self.bindModalContent();
            })
            .catch(function() {
                if (self._tokens.modal !== token) return;
                body.removeAttribute('aria-busy');
                body.innerHTML = '<div class="mm-alert mm-alert--error" role="alert">Failed to load content.</div>';
            });
    },

    closeModal: function() {
        var overlay = document.getElementById('mmModal');
        if (!overlay || overlay.hidden) return;
        this._tokens.modal = (this._tokens.modal || 0) + 1;
        this._startModalSession();
        overlay.hidden = true;
        document.body.style.overflow = '';
        document.getElementById('mmModalBody').innerHTML = '';
        // Clean URL if it contains ?settings or ?health
        if (/[?&](settings|health|help)/.test(location.search)) {
            history.replaceState(null, '', this.baseUrl);
        }
        var back = this._modalReturnFocus;
        this._modalReturnFocus = null;
        if (back && document.contains(back)) back.focus();
    },

    refreshModal: function() {
        var overlay = document.getElementById('mmModal');
        if (!overlay || overlay.hidden) return;
        var url = overlay.dataset.url;
        var title = document.getElementById('mmModalTitle').textContent;
        var opts = {};
        try { opts = JSON.parse(overlay.dataset.modalOpts || '{}'); } catch(e) {}
        if (url) this.openModal(url, title, opts, true);
    },

    _startModalSession: function() {
        this._modalSession++;
        this._modalPending = 0;
        this._modalNeedsRefresh = false;
    },

    // A save from the current dialog finished: refresh its content once nothing else
    // from that dialog is still pending (refreshing earlier would discard those saves' views)
    _modalSaveDone: function(session, success) {
        if (session !== this._modalSession) return false;
        this._modalPending = Math.max(0, this._modalPending - 1);
        if (success) this._modalNeedsRefresh = true;
        if (this._modalPending === 0 && this._modalNeedsRefresh) {
            this._modalNeedsRefresh = false;
            this.refreshModal();
        }
        return true;
    },

    _focusable: function(root) {
        var sel = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        return Array.prototype.filter.call(root.querySelectorAll(sel), function(el) {
            return el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0;
        });
    },

    bindModalEvents: function() {
        var self = this;
        var overlay = document.getElementById('mmModal');
        if (!overlay) return;
        var modal = overlay.querySelector('.mm-modal');

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) self.closeModal();
        });

        overlay.querySelector('.mm-modal-close').addEventListener('click', function() {
            self.closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (overlay.hidden) return;
            if (e.key === 'Escape') {
                self.closeModal();
                return;
            }
            // Keep Tab focus inside the dialog
            if (e.key === 'Tab') {
                var items = self._focusable(modal);
                if (!items.length) {
                    e.preventDefault();
                    modal.focus();
                    return;
                }
                var first = items[0];
                var last = items[items.length - 1];
                var inside = modal.contains(document.activeElement);
                if (e.shiftKey && (document.activeElement === first || document.activeElement === modal || !inside)) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && (document.activeElement === last || !inside)) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
    },

    bindModalForms: function() {
        var self = this;
        var body = document.getElementById('mmModalBody');
        if (!body) return;

        body.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                var isFileUpload = form.enctype === 'multipart/form-data';
                var data = isFileUpload ? new FormData(form) : new URLSearchParams(new FormData(form));
                var headers = { 'X-Requested-With': 'XMLHttpRequest' };
                if (!isFileUpload) headers['Content-Type'] = 'application/x-www-form-urlencoded';

                var btn = e.submitter || form.querySelector('button[type="submit"]');
                var origText = btn ? btn.textContent : '';
                if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
                var restore = function() {
                    if (btn) { btn.disabled = false; btn.textContent = origText; }
                };
                // Capture what was submitted and which dialog session sent it: the response may
                // arrive after the modal was closed, reopened or edited
                var submittedTz = data.get('timezone');
                var session = self._modalSession;
                self._modalPending++;
                // Saves run one at a time, in the order they were made, and each result is
                // applied before the next save is sent. So the server stores them in that
                // order, and a successful save's timezone is applied in the same order (a
                // later failed save changes nothing).
                var send = function() {
                    return fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: headers,
                        body: data
                    })
                    .then(function(res) {
                        if (res.status === 401) {
                            self._sessionExpired();
                            throw new Error('Session expired');
                        }
                        return res.json().catch(function() {
                            return {success: false, message: 'Unexpected response from the server (' + res.status + ').'};
                        });
                    })
                    .then(function(result) {
                        if (result.success) self._applySavedTimezone(submittedTz);
                        return result;
                    });
                };
                var request = (self._saveQueue || Promise.resolve()).then(send, send);
                self._saveQueue = request.catch(function() {});

                request
                .then(function(result) {
                    self.toast(result.message || (result.success ? 'Done' : 'Something went wrong.'), result.success ? 'success' : 'error');
                    // Only the dialog session that sent the form may be updated
                    if (session !== self._modalSession) return;
                    restore();
                    self._modalSaveDone(session, result.success);
                })
                .catch(function() {
                    if (!self._redirecting) self.toast('An error occurred.', 'error');
                    if (session !== self._modalSession) return;
                    restore();
                    self._modalSaveDone(session, false);
                });
            });
        });
    },

    // A saved timezone changes what "today" is: recompute the range and reload the data
    _applySavedTimezone: function(timezone) {
        if (!timezone || timezone === this.timezone) return;
        this.timezone = timezone;
        this.loadAll();
    },

    bindModalContent: function() {
        var self = this;
        var body = document.getElementById('mmModalBody');
        if (!body) return;

        // Modal links inside modal content (e.g., "Settings" links in help modal)
        body.querySelectorAll('[data-modal]').forEach(function(link) {
            self._bindModalLink(link);
        });

        var first = this._focusable(body)[0];
        if (first && first.tagName !== 'A') first.focus();
    },

    checkAutoOpen: function() {
        var params = new URLSearchParams(location.search);
        for (var name in this._modals) {
            if (params.has(name)) {
                this.openNamedModal(name);
                return;
            }
        }
    },

    // ─── Utilities ───────────────────────────────────────────────────────

    formatNumber: function(n) {
        if (n === null || n === undefined) return '—';
        return Number(n).toLocaleString();
    },

    formatDuration: function(seconds) {
        if (!seconds || seconds < 0) return '0s';
        seconds = Math.round(seconds);
        if (seconds < 60) return seconds + 's';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return s > 0 ? m + 'm ' + s + 's' : m + 'm';
    },

    formatPercent: function(ratio) {
        if (ratio === null || ratio === undefined) return '—';
        return (ratio * 100).toFixed(1) + '%';
    },

    formatDate: function(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    },

    /**
     * Parse YYYY-MM-DD as a UTC date (null if invalid). Date math is done in UTC so
     * daylight-saving changes in the browser's timezone can never skip or repeat a day.
     */
    parseDate: function(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(str || '');
        if (!m) return null;
        var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        return (d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3]) ? d : null;
    },

    addDays: function(str, n) {
        var d = this.parseDate(str);
        d.setUTCDate(d.getUTCDate() + n);
        return d.getUTCFullYear() + '-' + String(d.getUTCMonth() + 1).padStart(2, '0') + '-' + String(d.getUTCDate()).padStart(2, '0');
    },

    formatShortDate: function(dateStr) {
        var parts = dateStr.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10);
    },

    /**
     * Today's date in the dashboard timezone, from the (skew-corrected) current time,
     * so presets stay right after midnight and after a timezone change.
     */
    currentToday: function() {
        if (this.timezone) {
            try {
                var parts = {};
                new Intl.DateTimeFormat('en-US', {timeZone: this.timezone, year: 'numeric', month: '2-digit', day: '2-digit'})
                    .formatToParts(new Date(Date.now() + this._clockSkew))
                    .forEach(function(p) { parts[p.type] = p.value; });
                var date = parts.year + '-' + parts.month + '-' + parts.day;
                if (this.parseDate(date)) return date;
            } catch (e) {}
        }
        return this.today || this.formatDate(new Date());
    },

    parseHex: function(value) {
        var m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec((value || '').trim());
        return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : null;
    },

    escapeHtml: function(str) {
        return String(str).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    },

    setText: function(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    },

    emptyState: function(msg) {
        return '<div class="mm-empty"><div class="mm-empty__text">' + this.escapeHtml(msg) + '</div></div>';
    }
};

document.addEventListener('DOMContentLoaded', function() { MM.init(); });
