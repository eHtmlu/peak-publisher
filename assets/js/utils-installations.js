// Pure helpers for the installations figures — self-hosted the exact count of the last
// 24 hours, wordpress.org the rounded public active-install count — and the other
// figures the daily wordpress.org fetch delivers (downloads, rating, closed state).
// The vocabulary of the states lives here, keyed by the server's installations.state,
// shared by the list cell, the editor header and the import table; no state, like
// utils-current-release.js.
lodash.set(window, 'Pblsh.InstallationsUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;
    const { formatRelativeTime } = Pblsh.Utils;

    // wordpress.org's rounded bucket. The directory writes it as "Fewer than 10", "N+
    // million", else "N+" (Template::format_active_installs_for_display()); here every
    // bucket from 10 up is the number with a plus — one numeric style for the whole
    // column, and a figure means the same in every language while a word like "million"
    // may not. The cell says "< 10" for space; the tooltip spells it out.
    function formatWporgActiveInstalls(count) {
        if (count < 10) {
            return { text: '< 10', title: __('Fewer than 10 active installations · Reported by wordpress.org', 'peak-publisher') };
        }
        return { text: count.toLocaleString() + '+', title: __('Active installations (rounded) · Reported by wordpress.org', 'peak-publisher') };
    }

    function getWporgClosedFact(closed) {
        return closed && closed.date
            ? sprintf(__('Closed on wordpress.org since %s', 'peak-publisher'), closed.date)
            : __('Closed on wordpress.org', 'peak-publisher');
    }

    // The notice above the releases of a closed plugin: wordpress.org's own closure
    // sentence verbatim — it says whether the closure is permanent or under review, names
    // the reason and that nothing is available for download. Only a closure answer without
    // a sentence gets the bare fact and the consequence.
    function getWporgClosedNotice(closed) {
        if (closed.text) return closed.text;
        return closed.date
            ? sprintf(__('This plugin has been closed on wordpress.org as of %s. Nothing is distributed while it is closed.', 'peak-publisher'), closed.date)
            : __('This plugin has been closed on wordpress.org. Nothing is distributed while it is closed.', 'peak-publisher');
    }

    // What the tooltip of every wordpress.org figure ends with: how old the daily figures
    // are, and the last failed attempt to fetch them.
    function withFiguresAge(plugin, figure) {
        const inst = plugin.installations;
        return { ...figure, title: [
            figure.title,
            inst.fetched_at && sprintf(__('Updated %s', 'peak-publisher'), formatRelativeTime(inst.fetched_at)),
            inst.last_error && sprintf(__('wordpress.org could not be reached %1$s: %2$s', 'peak-publisher'), formatRelativeTime(inst.last_error.at), inst.last_error.message),
        ].filter(Boolean).join(' · ') };
    }

    // Whether a wordpress.org plugin's first figures are on their way: a directory refresh
    // runs and the server has never tried this plugin — then its figures are due. Any other
    // refresh may leave the plugin untouched, so it must not read as "fetching".
    function isFirstFetchRunning(plugin, refreshing) {
        return refreshing && plugin.hosting_type === 'wporg'
            && plugin.installations.state === 'never' && !plugin.installations.attempted_at;
    }

    // The cell of the list and the editor header, one vocabulary for both channels — the
    // row shows the channel, the cell says what the figure is. A failed fetch does not
    // touch the cell: the figures keep their last state, the tooltip tells.
    function getInstallationsCell(plugin) {
        const inst = plugin.installations;
        if (plugin.hosting_type !== 'wporg') {
            return inst.state === 'disabled'
                ? { text: '—', title: __('Counting is disabled in Settings › Self-hosted', 'peak-publisher') }
                : { text: String(inst.count), title: sprintf(_n('%d site checked for updates in the last 24 hours', '%d sites checked for updates in the last 24 hours', inst.count, 'peak-publisher'), inst.count) };
        }
        switch (inst.state) {
            case 'ok':
                return withFiguresAge(plugin, formatWporgActiveInstalls(inst.count));
            case 'closed':
                return withFiguresAge(plugin, { text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) });
            case 'not_found':
                return withFiguresAge(plugin, { text: '—', title: __('Not listed on wordpress.org', 'peak-publisher') });
            default:
                return withFiguresAge(plugin, { text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') });
        }
    }

    // The editor's Downloads figure: the all-time total, or what stands in for it.
    function getDownloads(plugin) {
        if (plugin.installations.state === 'closed') return withFiguresAge(plugin, { text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) });
        const downloaded = plugin.wporg_stats.downloaded;
        return withFiguresAge(plugin, typeof downloaded === 'number'
            ? { text: downloaded.toLocaleString(), title: __('Total downloads · Reported by wordpress.org', 'peak-publisher') }
            : { text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') });
    }

    // The editor's Rating figure as wordpress.org shows it: five stars in half-star steps
    // (the directory rounds rating / 20 to the nearest half) with the number of ratings
    // beside them, spelled out in the tooltip. `stars` is null when there is nothing to
    // draw — then `text` stands in the value (closed, not fetched or not rated: "—").
    function getRating(plugin) {
        if (plugin.installations.state === 'closed') return withFiguresAge(plugin, { stars: null, count: null, text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) });
        const stats = plugin.wporg_stats;
        if (typeof stats.rating !== 'number') return withFiguresAge(plugin, { stars: null, count: null, text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') });
        if (!stats.num_ratings) return withFiguresAge(plugin, { stars: null, count: null, text: '—', title: __('No ratings on wordpress.org yet', 'peak-publisher') });
        const halves = Math.round(stats.rating / 10);
        const stars = Array.from({ length: 5 }, (unused, index) => {
            const filled = halves - index * 2;
            return filled >= 2 ? 'star' : (filled === 1 ? 'star_half_full' : 'star_outline');
        });
        const count = stats.num_ratings.toLocaleString();
        return withFiguresAge(plugin, {
            stars,
            count,
            text: null,
            title: sprintf(_n('%1$s out of 5 stars from %2$s rating · Reported by wordpress.org', '%1$s out of 5 stars from %2$s ratings · Reported by wordpress.org', stats.num_ratings, 'peak-publisher'),
                (stats.rating / 20).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }), count),
        });
    }

    return { formatWporgActiveInstalls, isFirstFetchRunning, getInstallationsCell, getWporgClosedFact, getWporgClosedNotice, getDownloads, getRating };
})());
