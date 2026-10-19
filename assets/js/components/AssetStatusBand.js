// AssetStatusBand Component - the band every slot of a wordpress.org plugin's assets tab
// carries, over a slot's box and over the Live Preview: what the commit will do with the slot,
// or where its file stands on the plugin page — decided from the slot's facts alone (the
// server's wporg.slots[slot]: pending, conflict, on_wporg.imported, shown), in the order of
// urgency: a conflict (red), an open change (blue) — the two states the user can act on, the
// Discard in the line —, then, grey, committed and passed over (the page shows the SVG icon in
// a raster icon's place), committed and not read by wordpress.org's import yet (its forecast
// while it lasts), removed while the page still shows the file, on the plugin page, empty.
// Every state has its sign before the text, in the band's colour: the text carries
// the meaning, the sign lets a grid of boxes be read without reading. The colour changes with
// the state, the height never does: one line, and beneath it what the caller adds. A fact
// the slot does not carry — the Live Preview's file wordpress.org ignores, its public switch
// — the caller says through `text` and `icon`, the way out of a warning through `note`
// (under the text, within the label), what stands beneath the line through `detail` (the
// Live Preview's check, muted, under the text); `warning` marks the one state that asks the
// user to act without a Discard. The moment an
// import lands — the band turns from waiting to on the plugin page, or from removed to empty
// — it lights up once; nothing moves while it waits.
lodash.set(window, 'Pblsh.Components.AssetStatusBand', ({ slot, forecast, disabled, onDiscard, text = null, icon = null, warning = false, detail = null, note = null }) => {
    const { __ } = wp.i18n;
    const { createElement, useState, useEffect, useRef } = wp.element;
    const { Button } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;
    const { getBandText, getForecastEnd, getWaitingText, getRemovedWaitingText, getShownText, getShadowedText, getEmptyText, getUncheckedText } = Pblsh.AssetsUtils;

    const facts = slot || { pending: null, conflict: false, on_wporg: null, shown: null, shadowed: false };
    const state = facts.conflict ? 'conflict'
        : facts.pending ? 'pending'
        : facts.on_wporg && facts.shadowed ? 'shadowed'
        : facts.on_wporg && facts.on_wporg.imported === null ? 'unchecked'
        : facts.on_wporg && !facts.on_wporg.imported ? 'waiting'
        : !facts.on_wporg && facts.shown ? 'removed'
        : facts.on_wporg ? 'shown' : 'empty';

    // The waiting texts carry the forecast's time while it lasts: one timer to its end, the
    // band then says the plain fact.
    const end = getForecastEnd(forecast);
    const [, setNow] = useState(Date.now());
    useEffect(() => {
        if (end === null || (state !== 'waiting' && state !== 'removed')) return undefined;
        const timer = setTimeout(() => setNow(Date.now()), Math.max(0, end - Date.now()));
        return () => clearTimeout(timer);
    }, [end, state]);

    // The import landed: from waiting to on the page, from removed to empty — once, at the change.
    const previous = useRef(state);
    const [landed, setLanded] = useState(false);
    useEffect(() => {
        const before = previous.current;
        previous.current = state;
        if ((before === 'waiting' || before === 'removed') && (state === 'shown' || state === 'empty')) setLanded(true);
    }, [state]);

    const stateText = {
        conflict: () => getBandText('conflict'),
        pending: () => getBandText(facts.pending === 'delete' ? 'delete' : facts.on_wporg ? 'replace' : 'add'),
        shadowed: getShadowedText,
        unchecked: getUncheckedText,
        waiting: () => getWaitingText(forecast),
        removed: () => getRemovedWaitingText(forecast),
        shown: getShownText,
        empty: getEmptyText,
    }[state]();
    const stateIcon = {
        conflict: 'alert_outline',
        pending: facts.pending === 'delete' ? 'delete_forever' : 'cloud_upload',
        shadowed: 'information_outline',
        unchecked: 'clock_outline',
        waiting: 'clock_outline',
        removed: 'clock_outline',
        shown: 'check_circle',
        empty: 'circle_outline',
    }[state];
    const canDiscard = state === 'conflict' || state === 'pending';

    return createElement('div', {
        className: ['pblsh--assets-band', 'pblsh--assets-band--' + state, warning ? 'pblsh--assets-band--warning' : '', landed ? 'pblsh--assets-band--landed' : ''].filter(Boolean).join(' '),
        onAnimationEnd: () => setLanded(false),
    },
        createElement('div', { className: 'pblsh--assets-band__line' },
            createElement('span', { className: 'pblsh--assets-band__text' },
                createElement('span', { className: 'pblsh--assets-band__icon', 'aria-hidden': 'true' }, getSvgIcon(icon || (warning ? 'alert_outline' : stateIcon), { size: 16 })),
                createElement('span', { className: 'pblsh--assets-band__label' },
                    text !== null ? text : stateText,
                    note && createElement('span', { className: 'pblsh--assets-band__note' }, note),
                ),
            ),
            canDiscard && createElement(Button, { isSecondary: true, className: 'pblsh--assets-band__discard', disabled, onClick: onDiscard }, __('Discard', 'peak-publisher')),
        ),
        detail && createElement('div', { className: 'pblsh--assets-band__detail' }, detail),
    );
});
