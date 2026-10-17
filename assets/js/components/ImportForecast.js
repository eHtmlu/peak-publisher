// ImportForecast Component - when the plugin page shows the last commit: the server's forecast
// (the detail's wporg_import) at the right end of the editor's tab bar, plugin-wide, with the
// plugin page linked. It disappears when the forecast ends (Pblsh.AssetsUtils.getForecastEnd());
// the import watch (admin.js) reloads the plugin once the import landed, and the forecast with it.
lodash.set(window, 'Pblsh.Components.ImportForecast', ({ forecast, slug }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement, useState, useEffect } = wp.element;
    const end = Pblsh.AssetsUtils.getForecastEnd(forecast);
    const [now, setNow] = useState(Date.now());
    // One timer to the moment the forecast ends — the component then renders itself away.
    useEffect(() => {
        if (end === null) return undefined;
        const timer = setTimeout(() => setNow(Date.now()), Math.max(0, end - Date.now()));
        return () => clearTimeout(timer);
    }, [end]);
    if (end === null || now >= end) return null;

    // Tags and flips are imported within seconds: no clock time, it would only read as precision.
    // The 15-minute wait is the one fact worth a fragment: it moves with every further change —
    // of assets while nothing else in trunk moved, else of whatever did.
    const time = new Date(Date.parse(forecast.expected_at)).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const text = {
        tag: __('On <a>the plugin page</a> within a few minutes', 'peak-publisher'),
        flip: __('On <a>the plugin page</a> within a few minutes', 'peak-publisher'),
        assets: sprintf(__('On <a>the plugin page</a> around %s · 15 minutes after the last change of assets', 'peak-publisher'), time),
        trunk: sprintf(__('On <a>the plugin page</a> around %s · 15 minutes after the last change', 'peak-publisher'), time),
    }[forecast.reason];
    if (!text) return null;
    return createElement('span', { className: 'pblsh--import-forecast' },
        createInterpolateElement(text, { a: createElement('a', { href: Pblsh.Utils.getWporgPluginUrl(slug), target: '_blank', rel: 'noreferrer' }) }),
    );
});
