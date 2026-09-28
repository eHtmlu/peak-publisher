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

    // The wording of the upload's current-release decision for the outcome the toggle
    // currently shows: keyed on the server's relation and
    // pre-release flag — the client never compares versions. `warning` marks the outcomes
    // that deserve a second look (a pre-release or an older version becoming current).
    // Self-hosted wording; the wordpress.org variant arrives with the wporg deploy step.
    function getUploadDecisionText(facts, version, makeCurrent, replacesRelease) {
        const current = facts.version;
        const pre = !!facts.pre_release;
        const text = (title, desc, warning = false) => ({ title, desc, warning });
        const becomes = pre ? __('Becomes the current release (pre-release)', 'peak-publisher') : __('Becomes the current release', 'peak-publisher');
        const doesNot = pre ? __('Does not become the current release (pre-release)', 'peak-publisher') : __('Does not become the current release', 'peak-publisher');
        const offeredToEverySite = sprintf(__('Every site will be offered %s as an update.', 'peak-publisher'), version);
        const sitesWillReceive = sprintf(__('Sites will receive %s with their next update check.', 'peak-publisher'), version);

        switch (facts.relation) {
            case 'first':
            case 'no_current': {
                // No current release yet: the plugin offers no updates until one exists —
                // 'first' has no releases at all, 'no_current' has releases but no pointer.
                const isFirst = facts.relation === 'first';
                if (makeCurrent) {
                    return pre
                        ? text(becomes, offeredToEverySite, true)
                        : text(becomes, isFirst ? sitesWillReceive : __('This plugin has no current release yet and offers no updates — this release ends that.', 'peak-publisher'));
                }
                if (pre) {
                    return text(doesNot, __('Pre-release versions are published without becoming current. Until you make a release current, the plugin offers no updates.', 'peak-publisher'));
                }
                return text(doesNot, isFirst
                    ? sprintf(__('The release %s is published; the plugin offers no updates until you make it current from the release list.', 'peak-publisher'), version)
                    : sprintf(__('The plugin keeps offering no updates until you make %s current from the release list.', 'peak-publisher'), version));
            }
            case 'repairs_pointer':
                return text(becomes, sprintf(__('The current release already names %s, which does not exist yet — publishing this release makes it valid.', 'peak-publisher'), version));
            case 'equal':
                return text(__('Stays the current release', 'peak-publisher'),
                    sprintf(__('You are replacing the release sites currently receive. Sites that already updated to %s will not download the replaced files.', 'peak-publisher'), version));
            case 'higher':
                if (makeCurrent) {
                    return pre
                        ? text(becomes, offeredToEverySite, true)
                        : text(becomes, sitesWillReceive + (replacesRelease ? ' ' + sprintf(__('Sites that already updated to %s will not download the replaced files.', 'peak-publisher'), version) : ''));
                }
                return pre
                    ? text(doesNot, sprintf(__('Pre-release versions are published without becoming current. Sites keep receiving %s.', 'peak-publisher'), current))
                    : text(doesNot, sprintf(__('The release %1$s is published, sites keep receiving %2$s. You can make it current later from the release list.', 'peak-publisher'), version, current));
            case 'lower':
                return makeCurrent
                    ? text(__('Becomes the current release (rollback)', 'peak-publisher'),
                        sprintf(__('New installs and sites below %1$s will receive %1$s; sites on %2$s keep it — WordPress never offers an older version.', 'peak-publisher'), version, current), true)
                    : text(doesNot, sprintf(__('Sites keep receiving %1$s. The release %2$s is published for manual downloads.', 'peak-publisher'), current, version));
        }
        return null;
    }

    // The confirm before making a release current, by channel and by where the release sits
    // relative to the current one: 'higher' | 'lower' | 'none' (no current release). The
    // caller reads that off the version-sorted release list — rows above the current row
    // are higher — never from a version comparison of its own.
    function getFlipConfirmText(isWporg, version, relation) {
        const question = isWporg
            ? sprintf(__('Make %s the current release on wordpress.org?', 'peak-publisher'), version)
            : sprintf(__('Make %s the current release?', 'peak-publisher'), version);
        const timing = isWporg
            ? __('wordpress.org picks this up within a few minutes.', 'peak-publisher')
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
                    ? sprintf(__('wordpress.org currently distributes trunk; afterwards it distributes %s. This is picked up within a few minutes.', 'peak-publisher'), version)
                    : sprintf(__('The plugin currently offers no updates; afterwards new installs and sites below %1$s will receive %1$s with their next update check.', 'peak-publisher'), version));
        }
    }

    // The transient notice after a successful flip.
    function getFlipSuccessText(isWporg, version, revision) {
        return isWporg
            ? sprintf(__('Stable tag set to %1$s in r%2$s — wordpress.org picks it up within a few minutes.', 'peak-publisher'), version, revision)
            : sprintf(__('%s is now the current release — sites see it with their next update check.', 'peak-publisher'), version);
    }

    return { getCurrentReleaseIssue, getUploadDecisionText, getFlipConfirmText, getFlipSuccessText };
})());
