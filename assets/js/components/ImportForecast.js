// ImportForecast Component - when wordpress.org shows the last commit: the server's forecast
// (the detail's wporg_import) at the right end of the editor's tab bar, plugin-wide, with
// wordpress.org linked to the plugin's page. It disappears three minutes after the expected
// time, when the import has likely finished; no polling.
lodash.set(window, 'Pblsh.Components.ImportForecast', ({ forecast, slug }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement, useState, useEffect } = wp.element;
    const SHOWN_AFTER = 180000;   // ms past the expected time, like the server's PBLSH_WPORG_IMPORT_SHOWN_AFTER
    const expected = forecast ? Date.parse(forecast.expected_at) : 0;
    const [now, setNow] = useState(Date.now());
    // One timer to the moment the forecast ends — the component then renders itself away.
    useEffect(() => {
        if (!forecast) return undefined;
        const timer = setTimeout(() => setNow(Date.now()), Math.max(0, expected + SHOWN_AFTER - Date.now()));
        return () => clearTimeout(timer);
    }, [forecast && forecast.expected_at]);
    if (!forecast || now >= expected + SHOWN_AFTER) return null;

    // Tags and flips are imported within seconds: no clock time, it would only read as precision.
    // The 15-minute wait is the one fact worth a fragment: it moves with every further change —
    // of assets while nothing else in trunk moved, else of whatever did.
    const time = new Date(expected).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const text = {
        tag: __('On <a>wordpress.org</a> within a few minutes', 'peak-publisher'),
        flip: __('On <a>wordpress.org</a> within a few minutes', 'peak-publisher'),
        assets: sprintf(__('On <a>wordpress.org</a> around %s · 15 minutes after the last change of assets', 'peak-publisher'), time),
        trunk: sprintf(__('On <a>wordpress.org</a> around %s · 15 minutes after the last change', 'peak-publisher'), time),
    }[forecast.reason];
    if (!text) return null;
    return createElement('span', { className: 'pblsh--import-forecast' },
        createInterpolateElement(text, { a: createElement('a', { href: 'https://wordpress.org/plugins/' + encodeURIComponent(slug) + '/', target: '_blank', rel: 'noreferrer' }) }),
    );
});
