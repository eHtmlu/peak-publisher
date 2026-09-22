// Checklist Component - the shared check-row list (icon + title + description).
// One visual language for verification facts: the upload result checklist and the
// access gate's fact rows render through this same component, so a fact looks the
// same wherever it appears.
const Checklist = ({ items = [], className = '' } = {}) => {
    const { createElement } = wp.element;
    const { getSvgIcon } = Pblsh.Utils;

    const checkTypes = {
        ok: { className: 'pblsh--check pblsh--check--ok', icon: 'check_bold' },
        // Positive highlight (the first-release moment) — shimmer and gold echo
        // the celebration box, so the moment is recognizable across surfaces.
        celebrate: { className: 'pblsh--check pblsh--check--celebrate', icon: 'shimmer' },
        info: { className: 'pblsh--check pblsh--check--info', icon: 'information_outline' },
        // Non-blocking caution (e.g. heuristic ownership hints) — finalize stays
        // possible. Solid triangle: rows speak the solid status-glyph language,
        // boxes (panels, notes) the outline one.
        warning: { className: 'pblsh--check pblsh--check--warn', icon: 'alert' },
        error: { className: 'pblsh--check pblsh--check--error', icon: 'close_thick' },
    };

    const normalizedItems = (Array.isArray(items) ? items : []).flat(Infinity).filter(Boolean);
    if (normalizedItems.length === 0) return null;

    return createElement('ul', { className: ['pblsh--checklist', className].filter(Boolean).join(' ') },
        normalizedItems.map((item, index) => {
            const checkType = checkTypes[item.type] || checkTypes.error;
            return createElement('li', {
                key: index,
                className: checkType.className,
            },
                createElement('span', { className: 'pblsh--check__icon' }, getSvgIcon(checkType.icon, { size: 24 })),
                createElement('span', { className: 'pblsh--check__text' },
                    item.title && createElement('span', { className: 'pblsh--check__title' }, item.title),
                    item.desc && createElement('span', { className: 'pblsh--check__desc' }, item.desc),
                ),
            );
        }),
    );
};

lodash.set(window, 'Pblsh.Components.Checklist', Checklist);
