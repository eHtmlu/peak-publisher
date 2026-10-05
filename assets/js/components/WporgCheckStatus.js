// WporgCheckStatus Component - how fresh the wordpress.org data on screen is: when the
// plugins it speaks for were last brought in step with wordpress.org (the oldest of them),
// "Checking wordpress.org…" while a refresh runs, and the last failed check with its reason
// in the tooltip. In the editor header for its plugin, with the Refresh link the caller
// passes as the child; above the plugin list for every wordpress.org plugin, where the list
// shows it only while a check failed. Muted fragments, ' · ' separated, no period. Wording
// from Pblsh.WporgCheckUtils.
const WporgCheckStatus = ({ plugins, children = null }) => {
    const { createElement, createInterpolateElement, useState, useEffect } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip } = wp.components;
    const { __ } = wp.i18n;
    const { getTimeTooltipProps, msUntilRelativeTimeChanges } = Pblsh.Utils;
    const { getCheck, getCheckedText, getCheckErrorText } = Pblsh.WporgCheckUtils;

    const checking = useSelect((select) => select('pblsh/plugins').isRefreshingWporg(), []);
    const check = getCheck(plugins);
    // "2 minutes ago" ages while the line stands: it is read anew exactly when its wording
    // changes — and when the tab comes back into view, where a hidden tab's timers ran late.
    const [reading, setReading] = useState(0);
    useEffect(() => {
        const wait = msUntilRelativeTimeChanges(check.checkedAt);
        if (wait === null) return undefined;
        const timer = setTimeout(() => setReading((count) => count + 1), wait);
        return () => clearTimeout(timer);
    }, [check.checkedAt, reading]);
    useEffect(() => {
        const onVisibility = () => setReading((count) => count + 1);
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    const fragments = [
        createInterpolateElement(getCheckedText(check.checkedAt, checking), { time: createElement('time', getTimeTooltipProps(check.checkedAt)) }),
        check.error && !checking && createElement(Tooltip, { text: getCheckErrorText(check.error) },
            createElement('span', { tabIndex: 0 }, __('Last check failed', 'peak-publisher'))),
        children,
    ].filter(Boolean);
    return createElement('div', { className: 'pblsh--wporg-check' },
        ...fragments.flatMap((fragment, index) => index === 0 ? [ fragment ] : [ ' · ', fragment ]),
    );
};

lodash.set(window, 'Pblsh.Components.WporgCheckStatus', WporgCheckStatus);
