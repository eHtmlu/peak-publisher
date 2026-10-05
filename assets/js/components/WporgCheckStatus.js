// WporgCheckStatus Component - how fresh the wordpress.org data on screen is: when the
// plugins it speaks for were last brought in step with wordpress.org (the oldest of them),
// "Checking wordpress.org…" while a refresh runs, and the last failed check with its reason
// in the tooltip. Above the plugin list for every wordpress.org plugin, in the editor header
// for the one — there with the Refresh link, which the caller passes as the child. Muted
// fragments, ' · ' separated, no period. Wording from Pblsh.WporgCheckUtils.
const WporgCheckStatus = ({ plugins, children = null }) => {
    const { createElement, createInterpolateElement, useState, useEffect } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip } = wp.components;
    const { __ } = wp.i18n;
    const { getTimeTooltipProps } = Pblsh.Utils;
    const { getCheck, getCheckedText, getCheckErrorText } = Pblsh.WporgCheckUtils;

    const checking = useSelect((select) => select('pblsh/plugins').isRefreshingWporg(), []);
    // "2 minutes ago" ages while the line stands: it is read anew once a minute.
    const [, setMinute] = useState(0);
    useEffect(() => {
        const timer = setInterval(() => setMinute((minute) => minute + 1), 60000);
        return () => clearInterval(timer);
    }, []);

    const check = getCheck(plugins);
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
