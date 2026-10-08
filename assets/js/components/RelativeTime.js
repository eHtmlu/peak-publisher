// RelativeTime Component - a moment as "3 days ago": a <time> with the machine-readable
// moment, the dotted underline that signals the exact localized moment behind it, and
// the wording from Pblsh.Utils.formatRelativeTime — read anew exactly when it changes,
// and when the tab comes back into view, where a hidden tab's timers ran late. The exact
// moment is the native title by default: the Tooltip component's popover lands behind a
// native <dialog>'s top layer, so callers in dialogs stay with the title; outside them,
// `tooltip` puts it in the Tooltip component, reachable by keyboard. Without a valid
// value the `fallback` text renders, or nothing.
const RelativeTime = ({ value, tooltip = false, fallback = null }) => {
    const { createElement, useState, useEffect } = wp.element;
    const { Tooltip } = wp.components;
    const { formatRelativeTime, msUntilRelativeTimeChanges } = Pblsh.Utils;

    const [reading, setReading] = useState(0);
    useEffect(() => {
        const wait = msUntilRelativeTimeChanges(value);
        if (wait === null) return undefined;
        const timer = setTimeout(() => setReading((count) => count + 1), wait);
        return () => clearTimeout(timer);
    }, [value, reading]);
    useEffect(() => {
        const onVisibility = () => setReading((count) => count + 1);
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    const wording = formatRelativeTime(value);
    if (wording === null) return fallback;
    const then = new Date(value);
    const exact = then.toLocaleString();
    const time = createElement('time', {
        className: 'pblsh--relative-time',
        dateTime: then.toISOString(),
        ...(tooltip ? { tabIndex: 0 } : { title: exact }),
    }, wording);
    return tooltip ? createElement(Tooltip, { text: exact }, time) : time;
};

lodash.set(window, 'Pblsh.Components.RelativeTime', RelativeTime);
