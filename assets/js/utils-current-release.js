// Pure helpers for the current release — the plugin-level pointer to the release sites
// receive, on both channels. The vocabulary of its states lives here, keyed by the server's
// current_release_state, and is shared by every surface that speaks about it (the list's
// version cell, the editor header and notices, the flip's confirm and success notice);
// no state, like utils-upload-result.js.
lodash.set(window, 'Pblsh.CurrentReleaseUtils', (() => {
    const { __, sprintf } = wp.i18n;

    // The plugin's current-release problem, if any: null when the plugin has a current
    // release or nothing to say (no releases yet). wordpress.org falls back to trunk whenever
    // the pointer does not name an existing tag; a self-hosted plugin offers no updates then.
    function getCurrentReleaseIssue(plugin) {
        if (!plugin || plugin.version) return null;
        // Closed on wordpress.org: nothing is distributed whatever the pointer says — the
        // closed notice is the one message; the pointer's issues return with the reopening.
        if (plugin.wporg_stats && plugin.wporg_stats.closed) return null;
        const isWporg = plugin.hosting_type === 'wporg';
        const hasReleases = (Number(plugin.count_of_releases) || 0) > 0;

        // One shape for every state: the fact (the list's tooltip, the notice's first line)
        // and the remedy the notice adds as an emphasized line — the release list it points
        // to sits right below; null when there is nothing to do.
        const issue = (variant, fact, remedy = null) => ({ variant, fact, remedy });
        const remedy = isWporg
            ? __('Publishing your next release fixes it — or make one of the releases below current.', 'peak-publisher')
            : __('Make one of the releases below current to fix it.', 'peak-publisher');

        switch (plugin.current_release_state) {
            case 'unknown':
                return issue('info',
                    __('The current release on wordpress.org could not be read (trunk/readme.txt). Peak Publisher will retry with the next refresh.', 'peak-publisher'),
                );
            case 'trunk':
                return issue('warning',
                    __('wordpress.org distributes the trunk directory (Stable tag: trunk), which wordpress.org asks authors to avoid so that plugins can be rolled back.', 'peak-publisher'),
                    remedy,
                );
            case 'tag_missing':
                return issue('warning',
                    isWporg
                        ? sprintf(__('The Stable tag on wordpress.org names %s, which does not exist — wordpress.org falls back to distributing trunk.', 'peak-publisher'), plugin.pointer)
                        : sprintf(__('The current release %s does not exist, so the plugin offers no updates.', 'peak-publisher'), plugin.pointer),
                    remedy,
                );
            case 'none':
                if (!hasReleases) return null;
                return issue('warning',
                    isWporg
                        ? __('wordpress.org has no Stable tag for this plugin and distributes trunk.', 'peak-publisher')
                        : __('This plugin has no current release, so it offers no updates.', 'peak-publisher'),
                    remedy,
                );
        }
        return null;
    }

    // The confirm before making a release current, by channel and by where the release sits
    // relative to the current one: 'higher' | 'lower' | 'none' (no current release). The
    // caller reads that off the version-sorted release list — rows above the current row
    // are higher — never from a version comparison of its own. `closed` is the plugin's
    // closed state on wordpress.org (wporg_stats.closed): the commit succeeds there, the
    // distribution does not — so the confirm says only that, no matter the relation.
    function getFlipConfirmText(isWporg, version, relation, closed = null) {
        const question = isWporg
            ? sprintf(__('Make %s the current release on wordpress.org?', 'peak-publisher'), version)
            : sprintf(__('Make %s the current release?', 'peak-publisher'), version);
        if (isWporg && closed) {
            return question + '\n' + __('The plugin is closed on wordpress.org — the change is committed, but nothing is distributed while it is closed.', 'peak-publisher');
        }
        // Both channels deliver with the sites' next update check; wordpress.org first has to
        // import the commit.
        const timing = isWporg
            ? __('Sites see this with their next update check once wordpress.org has processed the commit — usually within a few minutes.', 'peak-publisher')
            : __('Sites see this with their next update check.', 'peak-publisher');
        switch (relation) {
            case 'lower':
                return question + '\n'
                    + sprintf(__('Sites already on a higher version keep it — WordPress never offers an older version. New installs and sites below %1$s will receive %1$s.', 'peak-publisher'), version)
                    + '\n' + timing;
            case 'higher':
                return question + '\n'
                    + sprintf(__('Every site will be offered %s as an update.', 'peak-publisher'), version) + ' ' + timing;
            default:
                return question + '\n' + (isWporg
                    ? sprintf(__('wordpress.org currently distributes trunk; afterwards it distributes %s.', 'peak-publisher'), version) + ' ' + timing
                    : sprintf(__('The plugin currently offers no updates; afterwards new installs and sites below %1$s will receive %1$s with their next update check.', 'peak-publisher'), version));
        }
    }

    // The transient notice after a successful flip; on wordpress.org with the commit as
    // <revision />, the WporgRevisionLink's slot (createInterpolateElement).
    function getFlipSuccessText(isWporg, version) {
        return isWporg
            ? sprintf(__('Stable tag set to %s in <revision /> — sites see it with their next update check once wordpress.org has processed the commit, usually within a few minutes.', 'peak-publisher'), version)
            : sprintf(__('%s is now the current release — sites see it with their next update check.', 'peak-publisher'), version);
    }

    return { getCurrentReleaseIssue, getFlipConfirmText, getFlipSuccessText };
})());
