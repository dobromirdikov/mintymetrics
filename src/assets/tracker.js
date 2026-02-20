(function() {
    // Respect DNT / Global Privacy Control (configurable server-side)
    if ('{{RESPECT_DNT}}' === '1' && (navigator.doNotTrack === '1' || navigator.globalPrivacyControl)) return;
    if (document.prerendering) return;

    var endpoint = '{{ENDPOINT}}';
    var site = '{{SITE}}';

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
