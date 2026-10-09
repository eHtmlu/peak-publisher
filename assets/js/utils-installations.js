// Pure helpers for the installations figures — self-hosted the exact count of the last
// 24 hours, wordpress.org the rounded public active-install count — and the other
// figures the wordpress.org check brings along (downloads, rating, closed state).
// The vocabulary of the states lives here, keyed by the server's installations.state,
// shared by the list cell, the editor header and the import table; no state, like
// utils-current-release.js.
lodash.set(window, 'Pblsh.InstallationsUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;

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

    // Whether a wordpress.org plugin's first figures are on their way: a refresh runs — it
    // reads the listing of every plugin it looks at — and this plugin has none yet.
    function isFirstFetchRunning(plugin, refreshing) {
        return refreshing && plugin.hosting_type === 'wporg' && plugin.installations.state === 'never';
    }

    // The cell of the list and the editor header, one vocabulary for both channels — the
    // row shows the channel, the cell says what the figure is. A failed check does not
    // touch the cell: the figures keep their last state, the freshness line tells.
    function getInstallationsCell(plugin) {
        const inst = plugin.installations;
        if (plugin.hosting_type !== 'wporg') {
            return inst.state === 'disabled'
                ? { text: '—', title: __('Counting is disabled in Settings › Self-hosted', 'peak-publisher') }
                : { text: String(inst.count), title: sprintf(_n('%d site checked for updates in the last 24 hours', '%d sites checked for updates in the last 24 hours', inst.count, 'peak-publisher'), inst.count) };
        }
        switch (inst.state) {
            case 'ok':
                return formatWporgActiveInstalls(inst.count);
            case 'closed':
                return { text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) };
            case 'not_found':
                return { text: '—', title: __('Not listed on wordpress.org', 'peak-publisher') };
            default:
                return { text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') };
        }
    }

    // The editor's Downloads figure: the all-time total, or what stands in for it — a
    // self-hosted plugin's counted here, the recent windows in the tooltip; a wordpress.org
    // plugin's as the directory reports it.
    function getDownloads(plugin) {
        if (plugin.hosting_type === 'self_hosted') {
            const { total, last_7_days, last_30_days } = plugin.downloads;
            return {
                text: total.toLocaleString(),
                title: sprintf(
                    /* translators: 1: downloads of the last 7 days, 2: downloads of the last 30 days */
                    __('Total downloads · Counted by Peak Publisher · %1$s in the last 7 days · %2$s in the last 30 days', 'peak-publisher'),
                    last_7_days.toLocaleString(),
                    last_30_days.toLocaleString(),
                ),
            };
        }
        if (plugin.installations.state === 'closed') return { text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) };
        const downloaded = plugin.wporg_stats.downloaded;
        return typeof downloaded === 'number'
            ? { text: downloaded.toLocaleString(), title: __('Total downloads · Reported by wordpress.org', 'peak-publisher') }
            : { text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') };
    }

    // The editor's Rating figure as wordpress.org shows it: five stars in half-star steps
    // (the directory rounds rating / 20 to the nearest half) with the number of ratings
    // beside them, spelled out in the tooltip. `stars` is null when there is nothing to
    // draw — then `text` stands in the value (closed, not fetched or not rated: "—").
    function getRating(plugin) {
        if (plugin.installations.state === 'closed') return { stars: null, count: null, text: '—', title: getWporgClosedFact(plugin.wporg_stats.closed) };
        const stats = plugin.wporg_stats;
        if (typeof stats.rating !== 'number') return { stars: null, count: null, text: '—', title: __('Not fetched from wordpress.org yet', 'peak-publisher') };
        if (!stats.num_ratings) return { stars: null, count: null, text: '—', title: __('No ratings on wordpress.org yet', 'peak-publisher') };
        const halves = Math.round(stats.rating / 10);
        const stars = Array.from({ length: 5 }, (unused, index) => {
            const filled = halves - index * 2;
            return filled >= 2 ? 'star' : (filled === 1 ? 'star_half_full' : 'star_outline');
        });
        const count = stats.num_ratings.toLocaleString();
        return {
            stars,
            count,
            text: null,
            title: sprintf(_n('%1$s out of 5 stars from %2$s rating · Reported by wordpress.org', '%1$s out of 5 stars from %2$s ratings · Reported by wordpress.org', stats.num_ratings, 'peak-publisher'),
                (stats.rating / 20).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }), count),
        };
    }

    return { formatWporgActiveInstalls, isFirstFetchRunning, getInstallationsCell, getWporgClosedFact, getWporgClosedNotice, getDownloads, getRating };
})());
