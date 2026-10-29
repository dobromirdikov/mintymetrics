(function() {
    // Respect DNT / Global Privacy Control (configurable server-side)
    if ('{{RESPECT_DNT}}' === '1' && (navigator.doNotTrack === '1' || navigator.globalPrivacyControl)) return;

    var endpoint = '{{ENDPOINT}}';
    var site = '{{SITE}}';

    // Hub mode: post back to wherever this script was loaded from. Keeps https when the
    // server sits behind a TLS-terminating proxy. (Inline drop-in snippets have no src.)
    try {
        var cs = document.currentScript;
        if (cs && cs.src) {
            var u = new URL(cs.src, location.href);
            endpoint = u.origin + u.pathname;
        }
    } catch (e) {}

    function currentPage() {
        return location.pathname + location.search;
    }

    // Send a GET-style request that survives the page unloading
    function send(url) {
        if (navigator.sendBeacon) {
            try { if (navigator.sendBeacon(url)) return; } catch (e) {}
        }
        new Image().src = url;
    }

    // ─── Custom events: window.mm('track', name, value?, props?) ───
    // Caller stub: window.mm = window.mm || function(){(window.mm.q=window.mm.q||[]).push(arguments)};
    function track(name, value, props) {
        if (!name) return;
        var url = endpoint + '?event&site=' + encodeURIComponent(site)
                + '&name=' + encodeURIComponent(name)
                + '&_v=1'
                + '&page=' + encodeURIComponent(currentPage())
                + (pageviewId ? '&pv=' + pageviewId : '');
        if (value !== undefined && value !== null) {
            url += '&value=' + encodeURIComponent(String(value));
        }
        if (props && typeof props === 'object') {
            try { url += '&p=' + encodeURIComponent(JSON.stringify(props)); } catch (e) {}
        }
        send(url);
    }

    // ─── Time on page: cumulative visible time, re-sent each time the page hides ───
    var page = null;
    var pageviewId = null;
    var visibleMs = 0;
    var visibleSince = null;

    function startTimer() {
        visibleMs = 0;
        visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    }

    function sendTime() {
        if (visibleSince !== null) {
            visibleMs += Date.now() - visibleSince;
            visibleSince = null;
        }
        var t = Math.round(visibleMs / 1000);
        if (t < 1 || !page || !navigator.sendBeacon) return;

        var fd = new FormData();
        fd.append('site', site);
        fd.append('page', page);
        fd.append('pv', pageviewId);
        fd.append('time', Math.min(t, 3600));
        fd.append('_v', '1');
        try { navigator.sendBeacon(endpoint + '?beacon', fd); } catch (e) {}
    }

    // ─── Pageviews ───
    // Random per-pageview ID so time-on-page beacons update exactly this pageview
    function newPageviewId() {
        var bytes = new Uint8Array(8);
        try { crypto.getRandomValues(bytes); } catch (e) {
            for (var i = 0; i < 8; i++) bytes[i] = Math.floor(Math.random() * 256);
        }
        var hex = '';
        for (var j = 0; j < 8; j++) hex += (bytes[j] < 16 ? '0' : '') + bytes[j].toString(16);
        return hex;
    }

    function sendHit(referrer) {
        page = currentPage();
        pageviewId = newPageviewId();
        var data = {
            site: site,
            page: page,
            ref: referrer || '',
            res: screen.width + 'x' + screen.height,
            lang: navigator.language || '',
            pv: pageviewId,
            _v: '1'
        };

        // Extract UTM parameters from current URL
        try {
            var params = new URLSearchParams(location.search);
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function(k) {
                var v = params.get(k);
                if (v) data[k] = v;
            });
        } catch (e) {}

        var qs = Object.keys(data).map(function(k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        }).join('&');

        new Image().src = endpoint + '?hit&' + qs;
        startTimer();
    }

    // Single-page apps: a pushState/replaceState/popstate that changes the URL is a new pageview
    function onNavigate() {
        if (currentPage() === page) return;
        var previous = location.origin + page;
        sendTime();
        sendHit(previous);
    }

    function init() {
        var stub = window.mm;
        window.mm = function(cmd) {
            var args = Array.prototype.slice.call(arguments, 1);
            if (cmd === 'track') track.apply(null, args);
        };

        // Pageview first, so queued events carry its pageview ID
        sendHit(document.referrer);

        // Drain calls queued before the script loaded (read now: during prerendering the
        // stub may have created its queue after this script first ran)
        var queued = (stub && stub.q) || [];
        for (var i = 0; i < queued.length; i++) {
            try { window.mm.apply(null, queued[i]); } catch (e) {}
        }

        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                sendTime();
            } else if (visibleSince === null) {
                visibleSince = Date.now();
            }
        });
        window.addEventListener('pagehide', sendTime);

        // Restored from the back/forward cache: the visitor is viewing the page again.
        // document.referrer still holds the original referrer, so do not count it twice.
        window.addEventListener('pageshow', function(e) {
            if (e.persisted) sendHit('');
        });

        try {
            ['pushState', 'replaceState'].forEach(function(method) {
                var original = history[method];
                history[method] = function() {
                    var result = original.apply(this, arguments);
                    onNavigate();
                    return result;
                };
            });
            window.addEventListener('popstate', onNavigate);
        } catch (e) {}
    }

    // Chrome may prerender pages the visitor never opens: wait until it is actually shown
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', init, { once: true });
    } else {
        init();
    }
})();
