// Figure Component - a figure whose tooltip says what it is: the shared anatomy of the
// installations cell, the editor's Downloads and Rating and every other value that
// explains itself on hover or focus. The children are the figure as shown; the title is
// what a screen reader gets instead.
const Figure = ({ title, className = '', children }) => {
    const { createElement } = wp.element;
    const { Tooltip } = wp.components;

    return createElement(Tooltip, { text: title },
        createElement('span', {
            className: ['pblsh--figure', className].filter(Boolean).join(' '),
            tabIndex: 0,
            'aria-label': title,
        }, children),
    );
};

lodash.set(window, 'Pblsh.Components.Figure', Figure);
