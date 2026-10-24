// NoticeBox Component - the shared tinted message box (icon + optional block
// title + content). One visual language for inline guidance: "info" (blue),
// "warning" (orange) and "error" (red) mean the same thing wherever they appear
// — each severity with its own silhouette (circle, triangle, stop octagon).
// An optional `error` ({ code, message }) renders the standardized error
// anatomy as one unit: the human message as a leading paragraph with its
// machine code as the muted support line right beneath it; children follow
// as guidance. As a block in flowing content the box brings its own
// paragraph-like block margins; a layout container that manages spacing
// itself overrides them via className.
const NoticeBox = ({ variant = 'info', title = null, className = '', error = null, children = null } = {}) => {
    const { createElement } = wp.element;
    const { __, sprintf } = wp.i18n;
    const { getSvgIcon } = Pblsh.Utils;
    const errorMessage = error && error.message ? error.message : null;
    const errorCode = error && error.code ? error.code : null;

    const variantIcons = {
        info: 'information_outline',
        warning: 'alert_outline',
        error: 'alert_octagon_outline',
    };

    return createElement('div', {
        className: ['pblsh--notice-box', 'pblsh--notice-box--' + variant, className].filter(Boolean).join(' '),
        // Static ancillary content — deliberately not an ARIA live region.
        role: 'note',
    },
        createElement('span', { className: 'pblsh--notice-box__icon', 'aria-hidden': 'true' },
            getSvgIcon(variantIcons[variant] || variantIcons.info, { size: 20 })),
        createElement('div', { className: 'pblsh--notice-box__content' },
            title ? createElement('strong', { className: 'pblsh--notice-box__title' }, title) : null,
            errorMessage ? createElement('p', null, errorMessage) : null,
            errorCode ? createElement('p', { className: 'pblsh--notice-box__code' },
                sprintf(__('Error code: %s', 'peak-publisher'), errorCode)) : null,
            children,
        ),
    );
};

lodash.set(window, 'Pblsh.Components.NoticeBox', NoticeBox);
