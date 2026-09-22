// TipLink + TipDialog - the tip system's trigger and its dialog. TipLink is the
// in-app sibling of FaqLink: the same help-link look (icon + bold text), but it
// announces a tip to open instead of leading to the plugin FAQ. The dialog is
// deliberately NOT rendered where the link sits — a dialog node does not belong
// inside flowing content — but once at the app root as TipDialog, which listens
// for the 'pblsh:open-tip' window event. Content comes from the Pblsh.Tips
// registry; without children, the tip's title doubles as the link text. Both
// render nothing when the tip key is unknown.

// The tip system's mark — the lightbulb split into two paths so the glass can
// glow over a metal-gray base. A deliberate one-off outside getSvgIcon's
// single-color contract, owned by the tip system; the colors live in CSS on the
// two path classes.
const renderTipBulbIcon = (size) => {
    const { createElement } = wp.element;
    return createElement('svg', {
        'data-icon': 'tip-bulb',
        width: size,
        height: size,
        viewBox: '0 0 24 24',
    },
        // Cut at y=16: the glass reaches into the straight socket part, so the
        // visible gray socket bar matches the bottom cap's height.
        createElement('path', {
            className: 'pblsh--tip-bulb__glass',
            d: 'M12,2A7,7 0 0,0 5,9C5,11.38 6.19,13.47 8,14.74V16H16V14.74C17.81,13.47 19,11.38 19,9A7,7 0 0,0 12,2Z',
        }),
        // The base starts slightly above the glass edge so no antialiasing
        // seam shows between the two fills.
        createElement('path', {
            className: 'pblsh--tip-bulb__base',
            d: 'M8,15.75V17A1,1 0 0,0 9,18H15A1,1 0 0,0 16,17V15.75ZM9,21A1,1 0 0,0 10,22H14A1,1 0 0,0 15,21V20H9V21Z',
        }),
    );
};

const TipLink = ({ tipKey = '', children = null } = {}) => {
    const { createElement } = wp.element;

    const tip = Pblsh.Tips[tipKey] ? Pblsh.Tips[tipKey]() : null;
    if (!tip) return null;

    return createElement('button', {
        type: 'button',
        className: 'pblsh--tip-link',
        onClick: () => window.dispatchEvent(new CustomEvent('pblsh:open-tip', { detail: { tipKey } })),
    },
        createElement('span', { className: 'pblsh--tip-link__icon', 'aria-hidden': 'true' }, renderTipBulbIcon(18)),
        createElement('span', { className: 'pblsh--tip-link__text' }, children || tip.title),
    );
};

// The app's single tip dialog host — mounted once at the app root. The dialog
// exists only while a tip is open: showModal needs the mounted node, and the
// freshly mounted content still needs its highlight.js pass so snippets in
// tips get the same code styling as everywhere else.
const TipDialog = () => {
    const { __ } = wp.i18n;
    const { createElement, useEffect, useRef, useState } = wp.element;
    const { getSvgIcon } = Pblsh.Utils;

    const [tipKey, setTipKey] = useState(null);
    const dialogRef = useRef(null);

    useEffect(() => {
        const handleOpen = (event) => setTipKey(event.detail?.tipKey || null);
        window.addEventListener('pblsh:open-tip', handleOpen);
        return () => window.removeEventListener('pblsh:open-tip', handleOpen);
    }, []);

    useEffect(() => {
        if (!tipKey) return;
        const dialogEl = dialogRef.current;
        if (!dialogEl) return;
        if (typeof dialogEl.showModal === 'function' && !dialogEl.open) {
            dialogEl.showModal();
        }
        if (window.hljs && typeof window.hljs.highlightElement === 'function') {
            dialogEl.querySelectorAll('pre code').forEach((code) => window.hljs.highlightElement(code));
        }
    }, [tipKey]);

    const tip = tipKey && Pblsh.Tips[tipKey] ? Pblsh.Tips[tipKey]() : null;
    if (!tip) return null;

    // While open, showModal makes the rest of the page inert — the dialog IS
    // the navigable document, so its title is an h1 and tip sections are h2.
    return createElement('dialog', {
        className: 'pblsh--modal pblsh--modal--tip',
        ref: dialogRef,
        'aria-labelledby': 'pblsh-tip-dialog-title',
        onClose: () => setTipKey(null),
        onClick: (e) => { if (e.target === e.currentTarget) e.currentTarget.close(); },
    },
        createElement('div', { className: 'pblsh--tip-dialog' },
            createElement('header', { className: 'pblsh--tip-dialog__header' },
                createElement('span', { className: 'pblsh--tip-dialog__icon', 'aria-hidden': 'true' }, renderTipBulbIcon(32)),
                createElement('h1', { className: 'pblsh--tip-dialog__title', id: 'pblsh-tip-dialog-title' }, tip.title),
                createElement('button', {
                    type: 'button',
                    className: 'pblsh--tip-dialog__close',
                    onClick: () => dialogRef.current && dialogRef.current.close(),
                    'aria-label': __('Close', 'peak-publisher'),
                }, getSvgIcon('close_thick', { size: 20 })),
            ),
            createElement('div', { className: 'pblsh--tip-dialog__body' }, tip.content),
        ),
    );
};

lodash.set(window, 'Pblsh.Components.TipLink', TipLink);
lodash.set(window, 'Pblsh.Components.TipDialog', TipDialog);
