// CurrentReleaseSwitch Component - the upload's "Set as current" switch above the checklist,
// track first like the checklist's own marks. A role="switch" button whose knob is the release
// list's current ring: a plain disc
// while the release does not become current, the ring with its dot when it does. The label
// never changes; track and knob carry the state (is-on). Locked where the server's facts
// leave no choice: aria-disabled rather than disabled keeps the state announced and the look
// stable, a small lock on the track shows the state as a fact rather than a choice, and the
// checklist's current-release row says why. `disabled` is the short-lived processing state.
lodash.set(window, 'Pblsh.Components.CurrentReleaseSwitch', ({ checked, locked = false, disabled = false, onChange }) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;
    const { getSvgIcon } = Pblsh.Utils;

    return createElement('button', {
        type: 'button',
        role: 'switch',
        'aria-checked': checked,
        'aria-disabled': locked || undefined,
        disabled,
        className: 'pblsh--current-release-switch' + (checked ? ' is-on' : '') + (locked ? ' is-locked' : ''),
        onClick: () => { if (!locked) onChange(!checked); },
    },
        createElement('span', { className: 'pblsh--current-release-switch__track', 'aria-hidden': 'true' },
            // Every forced default is "on", so the knob sits right and the lock takes the free half.
            locked && createElement('span', { className: 'pblsh--current-release-switch__lock' }, getSvgIcon('lock', { size: 12 })),
            createElement('span', { className: 'pblsh--current-ring' }),
        ),
        createElement('span', { className: 'pblsh--current-release-switch__label' }, __('Set as current', 'peak-publisher')),
    );
});
