// Pure helpers for the current release — the plugin-level pointer to the release sites
// receive, on both channels. The vocabulary of its states lives here, keyed by the server's
// current_release_state, and is shared by every surface that speaks about it (the list's
// version cell, the editor header and notices); no state, like utils-upload-result.js.
lodash.set(window, 'Pblsh.CurrentReleaseUtils', (() => {
    const { __, sprintf } = wp.i18n;

    // The plugin's current-release problem, if any: null when the plugin has a current
    // release or nothing to say (no releases yet). wordpress.org falls back to trunk whenever
    // the pointer does not name an existing tag; a self-hosted plugin offers no updates then.
    function getCurrentReleaseIssue(plugin) {
        if (!plugin || plugin.version) return null;
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

    return { getCurrentReleaseIssue };
})());
