// PublishProgress Component - how far a publish got, as the steps of its channel: done,
// running, still to come — in the upload result's body while the publish runs, in the
// checklist's rhythm (a done step has the check of a passed one). Only the step whose amount
// of work is known gets a bar — the operations of the commit; the others are named, so a wait
// without a measure (wordpress.org saving the commit) is announced before it starts. The
// phases are the server's finalize status (PBLSH_FINALIZE_STATUS_FILE): wporg prepare | read |
// send | save, self-hosted prepare | build — after its ZIP, a self-hosted publish is over in
// milliseconds, too short for a step anyone would see run.
const PublishProgress = ({ channel, phase = 'prepare', done = 0, total = 0 } = {}) => {
    const { createElement } = wp.element;
    const { __, _n, sprintf } = wp.i18n;
    const { Spinner } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;

    const steps = channel === 'wporg' ? [
        {
            phases: ['prepare', 'read'],
            label: __('Reading wordpress.org', 'peak-publisher'),
            detail: true,
            // One listing per folder: a large plugin takes a while, the count shows it going.
            count: phase === 'read' && done > 0 ? sprintf(_n('%d folder', '%d folders', done, 'peak-publisher'), done) : null,
        },
        {
            phases: ['send'],
            label: __('Sending the changes', 'peak-publisher'),
            detail: true,
            bar: total > 0,
            count: total > 0 ? sprintf(__('%1$d of %2$d', 'peak-publisher'), done, total) : null,
        },
        { phases: ['save'], label: __('Saving on wordpress.org', 'peak-publisher') },
    ] : [
        { phases: ['prepare', 'build'], label: __('Building the release ZIP', 'peak-publisher') },
    ];
    const active = Math.max(0, steps.findIndex((step) => step.phases.includes(phase)));

    return createElement('ol', { className: 'pblsh--publish-progress', 'aria-label': __('Publishing', 'peak-publisher') },
        steps.map((step, index) => {
            const state = index < active ? 'done' : (index === active ? 'active' : 'pending');
            const running = state === 'active';
            return createElement('li', {
                key: index,
                className: 'pblsh--publish-progress__step is-' + state,
                'aria-current': state === 'active' ? 'step' : undefined,
            },
                createElement('span', { className: 'pblsh--publish-progress__icon', 'aria-hidden': 'true' },
                    state === 'done' ? getSvgIcon('check_bold', { size: 24 }) : (state === 'active' ? createElement(Spinner) : null)),
                // The step name, and under it — like a check's description — what the running step
                // has done so far. A step that can have this line keeps it, empty before and after
                // it runs, so no step moves when the line fills or empties.
                createElement('span', { className: 'pblsh--publish-progress__text' },
                    createElement('span', { className: 'pblsh--publish-progress__label' }, step.label),
                    step.detail && createElement('span', { className: 'pblsh--publish-progress__detail' },
                        running && step.bar && createElement('span', {
                            className: 'pblsh--publish-progress__bar',
                            role: 'progressbar',
                            'aria-label': step.label,
                            'aria-valuemin': 0,
                            'aria-valuemax': total,
                            'aria-valuenow': done,
                            style: { '--pblsh-publish-progress': (100 * Math.min(done, total) / total) + '%' },
                        }),
                        running && step.count && createElement('span', { className: 'pblsh--publish-progress__count' }, step.count),
                    ),
                ),
            );
        }),
    );
};

lodash.set(window, 'Pblsh.Components.PublishProgress', PublishProgress);
