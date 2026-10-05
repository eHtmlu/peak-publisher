// Pure helpers for how fresh the wordpress.org data on screen is — when the plugins a
// surface shows were last brought in step with wordpress.org (the server's wporg_check per
// plugin). The wording of the status line (components/WporgCheckStatus.js); no state, like
// utils-installations.js.
lodash.set(window, 'Pblsh.WporgCheckUtils', (() => {
    const { __, sprintf } = wp.i18n;
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

    // The line itself: the moment inside <time></time> for the caller's exact-timestamp tooltip.
    function getCheckedText(checkedAt, checking) {
        if (checking) return __('Checking wordpress.org…', 'peak-publisher');
        if (!checkedAt) return __('Not checked against wordpress.org yet', 'peak-publisher');
        return sprintf(__('Checked against wordpress.org <time>%s</time>', 'peak-publisher'), formatRelativeTime(checkedAt));
    }

    // The last check that did not complete, as the line's tooltip explains it: when, and the
    // reason as wordpress.org or the server gave it.
    function getCheckErrorText(error) {
        return sprintf(__('The last check failed %1$s: %2$s', 'peak-publisher'), formatRelativeTime(error.at), error.message);
    }

    return { getCheck, getCheckedText, getCheckErrorText };
})());
