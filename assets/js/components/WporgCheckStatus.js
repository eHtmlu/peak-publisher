// WporgCheckStatus Component - how fresh the wordpress.org data on screen is: when the
// plugins it speaks for were last brought in step with wordpress.org (the oldest of them),
// "Checking wordpress.org…" while a refresh runs, and the last failed check with its reason
// in the tooltip. In the editor header for its plugin, with the links the caller passes as
// children (Refresh, View on wordpress.org), each a fragment of its own; above the plugin
// list for every wordpress.org plugin, where the list shows it only while a check failed.
// Muted fragments, ' · ' separated, no period. Wording from Pblsh.WporgCheckUtils.
const WporgCheckStatus = ({ plugins, children = null }) => {
    const { createElement, createInterpolateElement, Children } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip } = wp.components;
    const { __ } = wp.i18n;
    const { RelativeTime } = Pblsh.Components;
    const { getCheck, getCheckedText, getCheckErrorText } = Pblsh.WporgCheckUtils;

    const checking = useSelect((select) => select('pblsh/plugins').isRefreshingWporg(), []);
    const check = getCheck(plugins);

    const fragments = [
        createInterpolateElement(getCheckedText(check.checkedAt, checking), { time: createElement(RelativeTime, { value: check.checkedAt, tooltip: true }) }),
        check.error && !checking && createElement(Tooltip, { text: getCheckErrorText(check.error) },
            createElement('span', { tabIndex: 0 }, __('Last check failed', 'peak-publisher'))),
        ...Children.toArray(children),
    ].filter(Boolean);
    return createElement('div', { className: 'pblsh--wporg-check' },
        ...fragments.flatMap((fragment, index) => index === 0 ? [ fragment ] : [ ' · ', fragment ]),
    );
};

lodash.set(window, 'Pblsh.Components.WporgCheckStatus', WporgCheckStatus);
