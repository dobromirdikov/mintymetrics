(function() {
    // Respect DNT / Global Privacy Control (configurable server-side)
    if ('{{RESPECT_DNT}}' === '1' && (navigator.doNotTrack === '1' || navigator.globalPrivacyControl)) return;
    if (document.prerendering) return;

    var endpoint = '{{ENDPOINT}}';
    var site = '{{SITE}}';

    // Public API: window.mm('track', name, value?, props?)
    // Caller stub: window.mm = window.mm || function(){(window.mm.q=window.mm.q||[]).push(arguments)};
    var queued = (window.mm && window.mm.q) ? window.mm.q : [];

    function track(name, value, props) {
        if (!name) return;
        var url = endpoint + '?event&site=' + encodeURIComponent(site)
                + '&name=' + encodeURIComponent(name)
                + '&_v=1'
                + '&page=' + encodeURIComponent(location.pathname + location.search);
        if (value !== undefined && value !== null) {
            url += '&value=' + encodeURIComponent(String(value));
        }
        if (props && typeof props === 'object') {
            try { url += '&p=' + encodeURIComponent(JSON.stringify(props)); } catch (e) {}
        }
        new Image().src = url;
    }

    window.mm = function(cmd) {
        var args = Array.prototype.slice.call(arguments, 1);
        if (cmd === 'track') track.apply(null, args);
    };

    // Drain calls queued before the script loaded
    for (var i = 0; i < queued.length; i++) {
        try { window.mm.apply(null, queued[i]); } catch (e) {}
    }

    // Collect page data
    var data = {
        site: site,
        page: location.pathname + location.search,
        ref: document.referrer || '',
        res: screen.width + 'x' + screen.height,
        lang: navigator.language || '',
        _v: '1'
    };

    // Extract UTM parameters from current URL
    try {
        var params = new URLSearchParams(location.search);
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function(k) {
            var v = params.get(k);
            if (v) data[k] = v;
        });
    } catch(e) {}

    // Build query string
    var qs = Object.keys(data).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');

    // Send tracking hit via image pixel
    var img = new Image();
    img.src = endpoint + '?hit&' + qs;

    // Time-on-page tracking via beacon on page hide/unload
    var startTime = Date.now();
    var sent = false;

    function sendTime() {
        if (sent) return;
        var t = Math.round((Date.now() - startTime) / 1000);
        if (t < 1 || t > 3600) return;
        sent = true;

        var fd = new FormData();
        fd.append('site', site);
        fd.append('page', data.page);
        fd.append('time', t);
        fd.append('_v', '1');

        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint + '?beacon', fd);
        }
    }

    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden') sendTime();
    });
    window.addEventListener('pagehide', sendTime);
})();
