/* MintyMetrics Dashboard JS */

var MM = {
    site: '',
    dateFrom: '',
    dateTo: '',
    baseUrl: '',
    liveInterval: null,
    _sites: [],
    _selectedSites: [],
    _siteDebounce: null,
    chartColors: ['#2AB090','#4A90C4','#E5A53D','#D94F4F','#7B6BB5','#3DBAB0','#E88D5A','#5AAA6E'],

    init: function() {
        this.baseUrl = location.pathname;
        this.setRange('7d');
        this.bindEvents();
        this.loadSites();
        this.bindModalEvents();
        this.checkAutoOpen();
    },

    // ─── Events ──────────────────────────────────────────────────────────

    bindEvents: function() {
        var self = this;

        // Date presets
        document.querySelectorAll('.mm-date-presets button').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var range = this.getAttribute('data-range');
                if (range === 'custom') {
                    document.getElementById('customDatePicker').hidden = false;
                    return;
                }
                document.getElementById('customDatePicker').hidden = true;
                self.setRange(range);
            });
        });

        // Custom date apply
        var applyBtn = document.getElementById('applyDateRange');
        if (applyBtn) {
            applyBtn.addEventListener('click', function() {
                var from = document.getElementById('dateFrom').value;
                var to = document.getElementById('dateTo').value;
                if (from && to) self.setCustomRange(from, to);
            });
        }

        // Site selector
        var siteSelector = document.getElementById('siteSelector');
        if (siteSelector) {
            document.getElementById('siteSelectorTrigger').addEventListener('click', function(e) {
                e.stopPropagation();
                self._toggleSiteMenu();
            });
            document.getElementById('siteSelectorMenu').addEventListener('click', function(e) {
                e.stopPropagation();
                self._handleSiteClick(e);
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') self._closeSiteMenu();
            });
        }

        // Tab bars
        document.querySelectorAll('.mm-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var parent = this.closest('.mm-tab-bar');
                parent.querySelectorAll('.mm-tab').forEach(function(t) { t.classList.remove('active'); });
                this.classList.add('active');

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

        // Modal links
        document.querySelectorAll('[data-modal]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var type = this.dataset.modal;
                if (type === 'settings') {
                    self.openModal(self.baseUrl + '?settings', 'Settings', {wide: true});
                } else if (type === 'health') {
                    self.openModal(self.baseUrl + '?health', 'System Health', {narrow: true});
                } else if (type === 'help') {
                    self.openModal(self.baseUrl + '?help', 'Tracking Code');
                }
            });
        });
    },

    // ─── Date Range ──────────────────────────────────────────────────────

    setRange: function(preset) {
        var today = new Date();
        var from = new Date();

        switch (preset) {
            case 'today':
                break;
            case 'yesterday':
                from.setDate(today.getDate() - 1);
                today = new Date(from);
                break;
            case '7d':
                from.setDate(today.getDate() - 6);
                break;
            case '30d':
                from.setDate(today.getDate() - 29);
                break;
            case '90d':
                from.setDate(today.getDate() - 89);
                break;
            default:
                from.setDate(today.getDate() - 6);
        }

        this.dateFrom = this.formatDate(from);
        this.dateTo = this.formatDate(today);

        // Update active button
        document.querySelectorAll('.mm-date-presets button').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-range') === preset);
        });

        this.loadAll();
    },

    setCustomRange: function(from, to) {
        this.dateFrom = from;
        this.dateTo = to;
        document.querySelectorAll('.mm-date-presets button').forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-range') === 'custom');
        });
        this.loadAll();
    },

    // ─── Data Fetching ───────────────────────────────────────────────────

    fetch: function(action, params) {
        var url = this.baseUrl + '?api&action=' + action;
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
                    // Session expired
                    location.href = location.pathname + '?login';
                    throw new Error('Session expired');
                }
                return res.json();
            });
    },

    loadAll: function() {
        var self = this;

        this.fetch('summary').then(function(d) { self.renderSummary(d); });
        this.fetch('chart').then(function(d) { self.renderChart(d); });
        this.fetch('pages').then(function(d) { self.renderPages(d); });
        this.fetch('referrers').then(function(d) { self.renderReferrers(d); });
        this.fetch('utm', {group: 'source'}).then(function(d) { self.renderUTM(d); });
        this.fetch('devices', {group: 'type'}).then(function(d) { self.renderDevices(d); });
        this.fetch('countries').then(function(d) { self.renderCountries(d); });
        this.fetch('screens').then(function(d) { self.renderScreens(d); });
        this.fetch('languages').then(function(d) { self.renderLanguages(d); });
    },

    loadSites: function() {
        var self = this;
        this.fetch('sites').then(function(d) {
            var wrapper = document.getElementById('siteSelector');
            if (!wrapper || !d.sites || d.sites.length < 2) {
                if (wrapper) wrapper.style.display = 'none';
                if (d.sites && d.sites.length === 1) self.site = d.sites[0];
                self.startLivePolling();
                return;
            }
            self._sites = d.sites;

            // Restore saved selection (backwards-compatible: single string or comma-separated)
            var saved = '';
            try { saved = localStorage.getItem('mm_selected_site') || ''; } catch(e) {}
            var savedArr = saved ? saved.split(',').filter(function(s) {
                return d.sites.indexOf(s) !== -1;
            }) : [];

            self._selectedSites = savedArr;
            self._updateSiteMenu();
            self._updateSiteLabel();
            self._applySiteSelection();
            if (savedArr.length > 0) self.loadAll();
            self.startLivePolling();
        });
    },

    _updateSiteMenu: function() {
        var menu = document.getElementById('siteSelectorMenu');
        if (!menu || !this._sites) return;
        var self = this;
        var html = '';

        // "All Sites" item (with checkbox, same style as others)
        var allActive = this._selectedSites.length === 0;
        html += '<button class="mm-site-selector__item mm-site-selector__item--all' +
            (allActive ? ' mm-site-selector__item--active' : '') +
            '" data-site-action="all" type="button" role="option">' +
            '<span class="mm-site-selector__check' + (allActive ? ' mm-site-selector__check--checked' : '') + '"></span>' +
            '<span class="mm-site-selector__name">All Sites</span></button>';

        // Individual site items (with checkbox)
        this._sites.forEach(function(s) {
            var checked = self._selectedSites.indexOf(s) !== -1;
            html += '<button class="mm-site-selector__item' +
                (checked ? ' mm-site-selector__item--active' : '') +
                '" data-site-value="' + self.escapeHtml(s) + '" type="button" role="option">' +
                '<span class="mm-site-selector__check' + (checked ? ' mm-site-selector__check--checked' : '') + '"></span>' +
                '<span class="mm-site-selector__name" title="' + self.escapeHtml(s) + '">' + self.escapeHtml(s) + '</span></button>';
        });
        menu.innerHTML = html;
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
        var trigger = document.getElementById('siteSelectorTrigger');
        if (!menu || !trigger) return;
        var isOpen = !menu.hidden;
        menu.hidden = isOpen;
        trigger.setAttribute('aria-expanded', String(!isOpen));
    },

    _closeSiteMenu: function() {
        var menu = document.getElementById('siteSelectorMenu');
        var trigger = document.getElementById('siteSelectorTrigger');
        if (!menu || !trigger) return;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    },

    _handleSiteClick: function(e) {
        var item = e.target.closest('.mm-site-selector__item');
        if (!item) return;

        // "All Sites" clicked
        if (item.dataset.siteAction === 'all') {
            this._selectedSites = [];
            this._updateSiteMenu();
            this._updateSiteLabel();
            this._applySiteSelection();
            var self = this;
            clearTimeout(this._siteDebounce);
            this._siteDebounce = setTimeout(function() { self.loadAll(); self.pollLive(); }, 600);
            return;
        }

        var site = item.dataset.siteValue;
        if (!site) return;

        var clickedCheckbox = e.target.closest('.mm-site-selector__check');
        var idx = this._selectedSites.indexOf(site);
        if (clickedCheckbox) {
            // Checkbox click = toggle in multi-select set
            if (idx !== -1) this._selectedSites.splice(idx, 1);
            else this._selectedSites.push(site);
        } else {
            // Name click = single-select (replaces selection, menu stays open)
            this._selectedSites = [site];
        }
        this._updateSiteMenu();
        this._updateSiteLabel();
        this._applySiteSelection();
        var self = this;
        clearTimeout(this._siteDebounce);
        this._siteDebounce = setTimeout(function() { self.loadAll(); self.pollLive(); }, 600);
    },

    loadUTM: function(group) {
        var self = this;
        this.fetch('utm', {group: group}).then(function(d) { self.renderUTM(d); });
    },

    loadDevices: function(group) {
        var self = this;
        this.fetch('devices', {group: group}).then(function(d) { self.renderDevices(d); });
    },

    // ─── Render: Summary ─────────────────────────────────────────────────

    renderSummary: function(data) {
        this.setText('valPageviews', this.formatNumber(data.pageviews || 0));
        this.setText('valUniques', this.formatNumber(data.uniques || 0));
        this.setText('valBounce', data.bounce_rate !== null ? this.formatPercent(data.bounce_rate) : '—');
        this.setText('valTime', data.avg_time !== null ? this.formatDuration(data.avg_time) : '—');
    },

    // ─── Render: Line Chart ──────────────────────────────────────────────

    renderChart: function(data) {
        var container = document.getElementById('mainChart');
        if (!data.days || data.days.length === 0) {
            container.innerHTML = this.emptyState('No data for this period');
            return;
        }

        var days = data.days;
        var w = container.clientWidth || 700;
        var h = 250;
        var pad = {top: 20, right: 20, bottom: 40, left: 50};
        var cw = w - pad.left - pad.right;
        var ch = h - pad.top - pad.bottom;

        var maxVal = 0;
        days.forEach(function(d) {
            if (d.pageviews > maxVal) maxVal = d.pageviews;
            if (d.uniques > maxVal) maxVal = d.uniques;
        });
        if (maxVal === 0) maxVal = 1;

        var xStep = days.length > 1 ? cw / (days.length - 1) : cw / 2;

        function yPos(val) { return pad.top + ch - (val / maxVal * ch); }
        function xPos(i) { return pad.left + (days.length > 1 ? i * xStep : cw / 2); }

        var svg = '<svg class="mm-chart-svg" viewBox="0 0 ' + w + ' ' + h + '">';

        // Grid lines
        var gridLines = 5;
        for (var g = 0; g <= gridLines; g++) {
            var gy = pad.top + (ch / gridLines) * g;
            var gv = Math.round(maxVal - (maxVal / gridLines) * g);
            svg += '<line class="mm-chart-grid" x1="' + pad.left + '" y1="' + gy + '" x2="' + (w - pad.right) + '" y2="' + gy + '"/>';
            svg += '<text class="mm-chart-label" x="' + (pad.left - 8) + '" y="' + (gy + 4) + '" text-anchor="end">' + this.formatNumber(gv) + '</text>';
        }

        // X-axis labels
        var labelEvery = Math.max(1, Math.floor(days.length / 7));
        for (var i = 0; i < days.length; i += labelEvery) {
            svg += '<text class="mm-chart-label" x="' + xPos(i) + '" y="' + (h - 8) + '" text-anchor="middle">' + this.formatShortDate(days[i].date) + '</text>';
        }

        // Helper: build polyline points
        function buildPoints(key) {
            var pts = [];
            for (var i = 0; i < days.length; i++) {
                pts.push(xPos(i) + ',' + yPos(days[i][key]));
            }
            return pts.join(' ');
        }

        // Area fills
        var series = [{key: 'pageviews', color: this.chartColors[0]}, {key: 'uniques', color: this.chartColors[1]}];
        var self = this;

        series.forEach(function(s) {
            var pts = buildPoints(s.key);
            svg += '<polygon class="mm-chart-area" fill="' + s.color + '" points="' + xPos(0) + ',' + (pad.top + ch) + ' ' + pts + ' ' + xPos(days.length - 1) + ',' + (pad.top + ch) + '"/>';
            svg += '<polyline class="mm-chart-line" stroke="' + s.color + '" points="' + pts + '"/>';
        });

        // Data points and hit areas
        for (var i = 0; i < days.length; i++) {
            var cx = xPos(i);
            // Hit area
            svg += '<rect class="mm-chart-hitarea" x="' + (cx - xStep/2) + '" y="' + pad.top + '" width="' + xStep + '" height="' + ch + '" data-idx="' + i + '"/>';
            // Dots
            svg += '<circle class="mm-chart-dot" cx="' + cx + '" cy="' + yPos(days[i].pageviews) + '" fill="' + this.chartColors[0] + '"/>';
            svg += '<circle class="mm-chart-dot" cx="' + cx + '" cy="' + yPos(days[i].uniques) + '" fill="' + this.chartColors[1] + '"/>';
        }

        svg += '</svg>';

        // Legend
        svg += '<div style="display:flex;gap:16px;justify-content:center;padding:8px;font-size:0.8125rem;color:#5A6F66;">';
        svg += '<span><span style="display:inline-block;width:12px;height:3px;background:#2AB090;border-radius:2px;vertical-align:middle;margin-right:4px;"></span>Pageviews</span>';
        svg += '<span><span style="display:inline-block;width:12px;height:3px;background:#4A90C4;border-radius:2px;vertical-align:middle;margin-right:4px;"></span>Visitors</span>';
        svg += '</div>';

        container.innerHTML = svg;

        // Tooltip
        var tooltip = document.createElement('div');
        tooltip.className = 'mm-chart-tooltip';
        container.appendChild(tooltip);

        container.querySelectorAll('.mm-chart-hitarea').forEach(function(area) {
            area.addEventListener('mouseenter', function(e) {
                var idx = parseInt(this.dataset.idx);
                var d = days[idx];
                tooltip.innerHTML = '<strong>' + d.date + '</strong><br>Pageviews: ' + self.formatNumber(d.pageviews) + '<br>Visitors: ' + self.formatNumber(d.uniques);
                tooltip.classList.add('visible');
                // Show dots for this index
                var dots = container.querySelectorAll('.mm-chart-dot');
                dots[idx * 2].style.opacity = 1;
                dots[idx * 2 + 1].style.opacity = 1;
            });
            area.addEventListener('mousemove', function(e) {
                var rect = container.getBoundingClientRect();
                tooltip.style.left = (e.clientX - rect.left + 10) + 'px';
                tooltip.style.top = (e.clientY - rect.top - 40) + 'px';
            });
            area.addEventListener('mouseleave', function() {
                tooltip.classList.remove('visible');
                container.querySelectorAll('.mm-chart-dot').forEach(function(d) { d.style.opacity = 0; });
            });
        });
    },

    // ─── Render: Tables ──────────────────────────────────────────────────

    renderPages: function(data) {
        this.renderTable('tablePages', data.rows, [
            {key: 'page_path', label: 'Page', truncate: true},
            {key: 'views', label: 'Views', align: 'right'},
            {key: 'uniques', label: 'Uniques', align: 'right'}
        ], 'No pages tracked yet');
    },

    renderReferrers: function(data) {
        this.renderTable('tableReferrers', data.rows, [
            {key: 'domain', label: 'Source', truncate: true},
            {key: 'visitors', label: 'Visitors', align: 'right'}
        ], 'No referrer data');
    },

    renderUTM: function(data) {
        this.renderTable('tableUTM', data.rows, [
            {key: 'name', label: 'Name'},
            {key: 'visitors', label: 'Visitors', align: 'right'}
        ], 'No UTM data');
    },

    renderScreens: function(data) {
        this.renderTable('tableScreens', data.rows, [
            {key: 'resolution', label: 'Resolution'},
            {key: 'visitors', label: 'Visitors', align: 'right'}
        ], 'No screen data');
    },

    renderLanguages: function(data) {
        this.renderTable('tableLangs', data.rows, [
            {key: 'lang', label: 'Language'},
            {key: 'visitors', label: 'Visitors', align: 'right'}
        ], 'No language data');
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
                var val = row[col.key] !== undefined ? row[col.key] : '';
                var cls = [];
                if (col.truncate) cls.push('mm-truncate');
                if (col.align === 'right') cls.push('');
                var valStr = typeof val === 'number' ? MM.formatNumber(val) : MM.escapeHtml(String(val));
                html += '<td' + (cls.length ? ' class="' + cls.join(' ') + '"' : '') + ' title="' + MM.escapeHtml(String(val)) + '">' + valStr + '</td>';
            });
            html += '</tr>';
        });
        tbody.innerHTML = html;
    },

    // ─── Render: Bar Chart (Devices) ─────────────────────────────────────

    renderDevices: function(data) {
        var container = document.getElementById('chartDevices');
        if (!data.rows || data.rows.length === 0) {
            container.innerHTML = this.emptyState('No device data');
            return;
        }

        var max = 0;
        data.rows.forEach(function(r) { if (r.visitors > max) max = r.visitors; });
        if (max === 0) max = 1;
        var total = 0;
        data.rows.forEach(function(r) { total += r.visitors; });

        var html = '';
        var self = this;
        data.rows.forEach(function(r, i) {
            var pct = (r.visitors / max * 100).toFixed(1);
            var color = self.chartColors[i % self.chartColors.length];
            var label = r.name || 'Unknown';
            var pctTotal = total > 0 ? (r.visitors / total * 100).toFixed(1) + '%' : '';
            html += '<div class="mm-bar-row">';
            html += '<span class="mm-bar-label" title="' + self.escapeHtml(label) + '">' + self.escapeHtml(label) + '</span>';
            html += '<span class="mm-bar-track"><span class="mm-bar-fill" style="width:' + pct + '%;background:' + color + '"></span></span>';
            html += '<span class="mm-bar-value">' + pctTotal + '</span>';
            html += '</div>';
        });
        container.innerHTML = html;
    },

    // ─── Render: Countries ───────────────────────────────────────────────

    countryName: function(code) {
        try {
            return new Intl.DisplayNames(['en'], {type: 'region'}).of(code);
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
            {key: 'visitors', label: 'Visitors', align: 'right'}
        ], 'No country data. Enable geolocation in settings.');

        // Colorize map if loaded
        if (data.rows && data.rows.length > 0) {
            this.loadAndColorMap(data.rows);
        }
    },

    loadAndColorMap: function(rows) {
        var container = document.getElementById('worldMap');
        if (!container) return;

        // Check if map already loaded
        if (container.querySelector('svg')) {
            this.colorMap(container, rows);
            return;
        }

        // Lazy-load the map
        var self = this;
        fetch(this.baseUrl + '?asset=worldmap', {credentials: 'same-origin'})
            .then(function(res) { return res.text(); })
            .then(function(svg) {
                container.innerHTML = svg;
                self.colorMap(container, rows);
            })
            .catch(function() {
                // Map not available, skip silently
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
        if (max === 0) return;

        svg.querySelectorAll('path[id]').forEach(function(path) {
            path.style.fill = '#E8EFEB';
        });

        for (var code in countryData) {
            var path = svg.getElementById(code);
            if (path) {
                var intensity = countryData[code] / max;
                var r = Math.round(184 + (26 - 184) * intensity);
                var g = Math.round(230 + (138 - 230) * intensity);
                var b = Math.round(216 + (106 - 216) * intensity);
                path.style.fill = 'rgb(' + r + ',' + g + ',' + b + ')';
            }
        }
    },

    // ─── Live Visitors ───────────────────────────────────────────────────

    startLivePolling: function() {
        var self = this;
        this.liveFailCount = 0;
        this.pollLive();
        this.liveInterval = setInterval(function() { self.pollLive(); }, 30000);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                clearInterval(self.liveInterval);
                self.liveInterval = null;
            } else if (!self.liveInterval) {
                self.pollLive();
                self.liveInterval = setInterval(function() { self.pollLive(); }, 30000);
            }
        });
    },
    pollLive: function() {
        var self = this;
        this.fetch('live').then(function(d) {
            self.liveFailCount = 0;
            self.setText('valLive', d.count !== undefined ? self.formatNumber(d.count) : '—');
        }).catch(function() {
            self.liveFailCount = (self.liveFailCount || 0) + 1;
            if (self.liveFailCount >= 3) {
                self.setText('valLive', '—');
            }
        });
    },

    // ─── CSV Export ──────────────────────────────────────────────────────

    exportCSV: function(type) {
        var url = this.baseUrl + '?export&type=' + type;
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
            document.body.appendChild(container);
        }
        var el = document.createElement('div');
        el.className = 'mm-toast mm-toast--' + (type || 'success');
        el.textContent = message;
        container.appendChild(el);
        setTimeout(function() { el.remove(); }, 4000);
    },

    // ─── Modal ────────────────────────────────────────────────────────────

    openModal: function(url, title, options) {
        var self = this;
        var overlay = document.getElementById('mmModal');
        if (!overlay) return;
        var titleEl = document.getElementById('mmModalTitle');
        var body = document.getElementById('mmModalBody');
        var modal = overlay.querySelector('.mm-modal');

        overlay.dataset.url = url;
        overlay.dataset.modalOpts = JSON.stringify(options || {});
        titleEl.textContent = title;
        body.innerHTML = '<div class="mm-loading"><div class="mm-spinner"></div></div>';
        modal.className = 'mm-modal' + (options && options.narrow ? ' mm-modal--narrow' : '') + (options && options.wide ? ' mm-modal--wide' : '');

        overlay.hidden = false;
        document.body.style.overflow = 'hidden';

        var fetchUrl = url + (url.indexOf('?') !== -1 ? '&' : '?') + 'modal=1';
        fetch(fetchUrl, { credentials: 'same-origin' })
            .then(function(res) {
                if (res.status === 401) {
                    location.href = self.baseUrl + '?login';
                    throw new Error('Session expired');
                }
                return res.text();
            })
            .then(function(html) {
                body.innerHTML = html;
                self.bindModalForms();
                self.bindModalContent();
            })
            .catch(function() {
                body.innerHTML = '<div class="mm-alert mm-alert--error">Failed to load content.</div>';
            });
    },

    closeModal: function() {
        var overlay = document.getElementById('mmModal');
        if (!overlay) return;
        overlay.hidden = true;
        document.body.style.overflow = '';
        document.getElementById('mmModalBody').innerHTML = '';
        // Clean URL if it contains ?settings or ?health
        if (/[?&](settings|health|help)/.test(location.search)) {
            history.replaceState(null, '', this.baseUrl);
        }
    },

    refreshModal: function() {
        var overlay = document.getElementById('mmModal');
        if (!overlay || overlay.hidden) return;
        var url = overlay.dataset.url;
        var title = document.getElementById('mmModalTitle').textContent;
        var opts = {};
        try { opts = JSON.parse(overlay.dataset.modalOpts || '{}'); } catch(e) {}
        if (url) this.openModal(url, title, opts);
    },

    bindModalEvents: function() {
        var self = this;
        var overlay = document.getElementById('mmModal');
        if (!overlay) return;

        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) self.closeModal();
        });

        overlay.querySelector('.mm-modal-close').addEventListener('click', function() {
            self.closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !overlay.hidden) self.closeModal();
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
                if (btn) { btn.disabled = true; btn.textContent = 'Saving\u2026'; }

                fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers,
                    body: data
                })
                .then(function(res) { return res.json(); })
                .then(function(result) {
                    self.toast(result.message || 'Done', result.success ? 'success' : 'error');
                    if (btn) { btn.disabled = false; btn.textContent = origText; }
                    if (result.success) self.refreshModal();
                })
                .catch(function() {
                    self.toast('An error occurred.', 'error');
                    if (btn) { btn.disabled = false; btn.textContent = origText; }
                });
            });
        });
    },

    bindModalContent: function() {
        var self = this;
        var body = document.getElementById('mmModalBody');
        if (!body) return;

        // Modal links inside modal content (e.g., "Settings" links in help modal)
        body.querySelectorAll('[data-modal]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var type = this.dataset.modal;
                if (type === 'settings') {
                    self.openModal(self.baseUrl + '?settings', 'Settings', {wide: true});
                } else if (type === 'health') {
                    self.openModal(self.baseUrl + '?health', 'System Health', {narrow: true});
                } else if (type === 'help') {
                    self.openModal(self.baseUrl + '?help', 'Tracking Code');
                }
            });
        });
    },

    checkAutoOpen: function() {
        var params = new URLSearchParams(location.search);
        if (params.has('settings')) {
            this.openModal(this.baseUrl + '?settings', 'Settings', {wide: true});
        } else if (params.has('health')) {
            this.openModal(this.baseUrl + '?health', 'System Health', {narrow: true});
        } else if (params.has('help')) {
            this.openModal(this.baseUrl + '?help', 'Tracking Code');
        }
    },

    // ─── Utilities ───────────────────────────────────────────────────────

    formatNumber: function(n) {
        if (n === null || n === undefined) return '—';
        return n.toLocaleString();
    },

    formatDuration: function(seconds) {
        if (!seconds || seconds < 0) return '0s';
        seconds = Math.round(seconds);
        if (seconds < 60) return seconds + 's';
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m + 'm ' + (s > 0 ? s + 's' : '');
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

    formatShortDate: function(dateStr) {
        var parts = dateStr.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(parts[1]) - 1] + ' ' + parseInt(parts[2]);
    },

    escapeHtml: function(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
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
