// FaqLink Component - the shared help-link pattern: a small icon plus a linked
// question that opens the plugin's FAQ in a new tab. One recognizable look for
// "help lives here" across all screens. The icon is overridable for spots where
// a semantic icon carries meaning of its own (e.g. the lock on the credentials
// line); renders nothing when the FAQ key is unknown.
const FaqLink = ({ faqKey = '', icon = 'help_circle_outline', children = null } = {}) => {
    const { createElement } = wp.element;
    const { getFaqUrl, getSvgIcon } = Pblsh.Utils;

    const href = getFaqUrl(faqKey);
    if (!href) return null;

    return createElement('a', {
        className: 'pblsh--faq-link',
        href,
        target: '_blank',
        rel: 'noreferrer',
    },
        createElement('span', { className: 'pblsh--faq-link__icon', 'aria-hidden': 'true' }, getSvgIcon(icon, { size: 18 })),
        createElement('span', { className: 'pblsh--faq-link__text' }, children),
    );
};

lodash.set(window, 'Pblsh.Components.FaqLink', FaqLink);
