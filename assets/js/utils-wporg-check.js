// Pure helpers for how fresh the wordpress.org data on screen is — when the plugins a
// surface shows were last brought in step with wordpress.org (the server's wporg_check per
// plugin) — and for what a refresh found changed there. The wording of the status line
// (components/WporgCheckStatus.js) and of the editor's notice; no state, like
// utils-installations.js.
lodash.set(window, 'Pblsh.WporgCheckUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;
    const { formatRelativeTime } = Pblsh.Utils;

    // The check a line speaks for. Of several plugins the oldest — everything shown is at
    // least that fresh —, null when one of them was never checked; and the latest failure.
    function getCheck(plugins) {
        const checks = plugins.map((plugin) => plugin.wporg_check);
        const times = checks.map((check) => check.checked_at);
        const errors = checks.map((check) => check.error).filter(Boolean).sort((a, b) => a.at < b.at ? 1 : -1);
        return {
            checkedAt: times.includes(null) ? null : times.sort()[0],
            error: errors[0] || null,
        };
    }

    // The line itself: <time /> is the caller's RelativeTime of the moment.
    function getCheckedText(checkedAt, checking) {
        if (checking) return __('Checking wordpress.org…', 'peak-publisher');
        if (!checkedAt) return __('Not checked against wordpress.org yet', 'peak-publisher');
        return __('Checked against wordpress.org <time />', 'peak-publisher');
    }

    // The last check that did not complete, as the line's tooltip explains it: when, and the
    // reason as wordpress.org or the server gave it.
    function getCheckErrorText(error) {
        return sprintf(__('The last check failed %1$s: %2$s', 'peak-publisher'), formatRelativeTime(error.at), error.message);
    }

    // What refreshes found changed on wordpress.org (the server's `changes` of a plugin), as
    // the editor's notice says it: only the facts that apply, ' · ' separated.
    function getChangesText(changes) {
        const facts = [
            changes.releases_added && sprintf(_n('%d new release', '%d new releases', changes.releases_added, 'peak-publisher'), changes.releases_added),
            changes.releases_removed && sprintf(_n('%d release removed', '%d releases removed', changes.releases_removed, 'peak-publisher'), changes.releases_removed),
            changes.releases_updated && sprintf(_n('%d release updated', '%d releases updated', changes.releases_updated, 'peak-publisher'), changes.releases_updated),
            changes.current_release && __('current release changed', 'peak-publisher'),
            changes.assets && __('assets changed', 'peak-publisher'),
        ].filter(Boolean);
        return sprintf(__('Changed on wordpress.org: %s', 'peak-publisher'), facts.join(' · '));
    }

    return { getCheck, getCheckedText, getCheckErrorText, getChangesText };
})());
