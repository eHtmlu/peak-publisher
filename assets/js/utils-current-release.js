// Pure helpers for the current release — the plugin-level pointer to the release sites
// receive, on both channels. The vocabulary of its states lives here, keyed by the server's
// current_release_state, and is shared by every surface that speaks about it (the list's
// version cell, the editor header and notices, the upload's decision row on both channels);
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

    // The wording of the upload's current-release decision for the outcome the toggle
    // currently shows, keyed on the server's relation, pre-release flag and pointer state —
    // the client never compares versions. `warning` marks the outcomes that deserve a second
    // look (a pre-release or an older version becoming current, a pointer the dialog could not
    // read). wordpress.org adds the mechanics — the Stable tag line the deploy writes and what
    // happens to trunk — as `mechanics`; self-hosted has no file that carries the pointer.
    function getUploadDecisionText(facts, version, makeCurrent, replacesRelease, isWporg = false, deployMode = null) {
        const current = facts.version;
        const pre = !!facts.pre_release;
        const text = (title, desc, warning = false) => ({ title, desc, warning, mechanics: [] });
        const becomes = pre ? __('Becomes the current release (pre-release)', 'peak-publisher') : __('Becomes the current release', 'peak-publisher');
        const doesNot = pre ? __('Does not become the current release (pre-release)', 'peak-publisher') : __('Does not become the current release', 'peak-publisher');
        const offeredToEverySite = sprintf(__('Every site will be offered %s as an update.', 'peak-publisher'), version);
        // Both channels deliver with the sites' next update check; wordpress.org first has to
        // import the commit.
        const sitesWillReceive = isWporg
            ? sprintf(__('Sites will receive %s with their next update check once wordpress.org has processed the commit — usually within a few minutes.', 'peak-publisher'), version)
            : sprintf(__('Sites will receive %s with their next update check.', 'peak-publisher'), version);
        const replacedFilesNote = sprintf(__('Sites that already updated to %s will not download the replaced files.', 'peak-publisher'), version);
        const keepsTrunk = __('wordpress.org keeps distributing trunk.', 'peak-publisher');

        let result = null;
        switch (facts.relation) {
            case 'first':
            case 'no_current': {
                // No current release yet — 'first' has no releases at all, 'no_current' has
                // releases but no usable pointer (wordpress.org distributes trunk then).
                const isFirst = facts.relation === 'first';
                if (isWporg) {
                    if (!makeCurrent) {
                        result = text(doesNot, pre ? __('Pre-release versions are published as a tag only.', 'peak-publisher') + ' ' + keepsTrunk : keepsTrunk);
                    } else if (isFirst) {
                        result = text(becomes, sitesWillReceive);
                    } else if (pre) {
                        result = text(becomes, offeredToEverySite, true);
                    } else {
                        result = text(becomes, {
                            trunk: __('wordpress.org currently distributes the trunk directory (Stable tag: trunk), which wordpress.org asks authors to avoid — this release ends that.', 'peak-publisher'),
                            tag_missing: sprintf(__('The Stable tag on wordpress.org names %s, which does not exist, so wordpress.org distributes trunk — this release ends that.', 'peak-publisher'), facts.pointer),
                            none: __('wordpress.org has no Stable tag for this plugin yet and distributes trunk — this release ends that.', 'peak-publisher'),
                        }[facts.state]);
                    }
                    break;
                }
                if (makeCurrent) {
                    result = pre
                        ? text(becomes, offeredToEverySite, true)
                        : text(becomes, isFirst ? sitesWillReceive : __('This plugin has no current release yet and offers no updates — this release ends that.', 'peak-publisher'));
                } else if (pre) {
                    result = text(doesNot, __('Pre-release versions are published without becoming current. Until you make a release current, the plugin offers no updates.', 'peak-publisher'));
                } else {
                    result = text(doesNot, isFirst
                        ? sprintf(__('The release %s is published; the plugin offers no updates until you make it current from the release list.', 'peak-publisher'), version)
                        : sprintf(__('The plugin keeps offering no updates until you make %s current from the release list.', 'peak-publisher'), version));
                }
                break;
            }
            case 'unknown':
                // wordpress.org only: the dialog could not read the pointer. The label states the
                // outcome; the description says what publishing does about the uncertainty.
                result = makeCurrent
                    ? text(becomes, __('The current release on wordpress.org could not be read. Peak Publisher reads it again when publishing and stops if the outcome would differ from what you see here.', 'peak-publisher'), true)
                    : text(doesNot, __('The current release on wordpress.org could not be read and stays as it is. Peak Publisher reads it again when publishing and stops if the outcome would differ from what you see here.', 'peak-publisher'));
                break;
            case 'repairs_pointer':
                result = text(becomes, isWporg
                    ? sprintf(__('The Stable tag on wordpress.org already names %s, which does not exist yet — publishing this tag makes it valid. Until then wordpress.org distributes trunk.', 'peak-publisher'), version)
                    : sprintf(__('The current release already names %s, which does not exist yet — publishing this release makes it valid.', 'peak-publisher'), version));
                break;
            case 'equal':
                result = text(__('Stays the current release', 'peak-publisher'),
                    (isWporg
                        ? __('You are replacing the release wordpress.org currently distributes.', 'peak-publisher')
                        : __('You are replacing the release sites currently receive.', 'peak-publisher')) + ' ' + replacedFilesNote);
                break;
            case 'higher':
                if (makeCurrent) {
                    result = pre
                        ? text(becomes, offeredToEverySite, true)
                        : text(becomes, sitesWillReceive + (replacesRelease ? ' ' + replacedFilesNote : ''));
                } else if (pre) {
                    result = text(doesNot, isWporg
                        ? sprintf(__('Pre-release versions are published as a tag only. Sites keep receiving %s.', 'peak-publisher'), current)
                        : sprintf(__('Pre-release versions are published without becoming current. Sites keep receiving %s.', 'peak-publisher'), current));
                } else {
                    result = text(doesNot, isWporg
                        ? sprintf(__('The tag %1$s is published, sites keep receiving %2$s. You can make it current later from the release list.', 'peak-publisher'), version, current)
                        : sprintf(__('The release %1$s is published, sites keep receiving %2$s. You can make it current later from the release list.', 'peak-publisher'), version, current));
                }
                break;
            case 'lower':
                result = makeCurrent
                    ? text(__('Becomes the current release (rollback)', 'peak-publisher'),
                        sprintf(__('New installs and sites below %1$s will receive %1$s; sites on %2$s keep it — WordPress never offers an older version.', 'peak-publisher'), version, current), true)
                    : text(doesNot, isWporg
                        ? sprintf(__('Sites keep receiving %1$s. The tag %2$s is published for manual downloads.', 'peak-publisher'), current, version)
                        : sprintf(__('Sites keep receiving %1$s. The release %2$s is published for manual downloads.', 'peak-publisher'), current, version));
                break;
        }
        if (result && isWporg) {
            result.mechanics = getWporgMechanics(facts, version, makeCurrent, deployMode);
        }
        return result;
    }

    // The mechanics behind a wordpress.org decision, for developers who know the term: the
    // Stable tag line the deploy writes into trunk/readme.txt — the pointer as the dialog read
    // it, an arrow when the deploy changes it — and what happens to trunk. The arrow follows the
    // server's rule: the line is written when the decided value differs from the live one.
    function getWporgMechanics(facts, version, makeCurrent, deployMode) {
        const shown = facts.state === 'unknown' ? __('(unknown)', 'peak-publisher') : facts.pointer;
        const changes = makeCurrent && shown !== version;
        const stableTag = shown === ''
            ? sprintf(__('Stable tag on wordpress.org: %s', 'peak-publisher'), version)
            : (changes
                ? sprintf(__('Stable tag on wordpress.org: %1$s → %2$s', 'peak-publisher'), shown, version)
                : sprintf(__('Stable tag on wordpress.org: %s (unchanged)', 'peak-publisher'), shown));
        const trunk = deployMode === 'trunk_and_tag'
            ? __('Trunk is updated with this release.', 'peak-publisher')
            : (changes
                ? __('Trunk code stays unchanged; only the Stable tag line in trunk/readme.txt is updated.', 'peak-publisher')
                : __('Trunk stays unchanged.', 'peak-publisher'));
        return [ stableTag, trunk ];
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
        // import the commit (the same wording as the upload's decision row).
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

    return { getCurrentReleaseIssue, getUploadDecisionText, getFlipConfirmText, getFlipSuccessText };
})());
