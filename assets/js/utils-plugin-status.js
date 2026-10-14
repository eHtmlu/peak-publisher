// Pure: what a plugin needs attention for, as the list's status column shows it — the same
// facts the editor shows, in their vocabulary (CurrentReleaseUtils, TestedUpToUtils,
// WporgCheckUtils). Items ordered by what touches the distribution first; an empty list means
// all clear. The tooltip says what is wrong, never how to fix it: that is the editor's, where
// it is done. A plugin that distributes nothing — closed on wordpress.org, a self-hosted
// draft — shows that state alone: everything else would warn for as long as it stays so,
// wanted or not. Only in this column: the editor, where one works on exactly this plugin,
// shows everything as for any other plugin. That state is a state, not a problem to fix: its
// item speaks the editor's status button — its label, its modifier as the variant (the
// button's colored dot), getPluginStatus(). No state.
lodash.set(window, 'Pblsh.PluginStatusUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;

    function getPluginStatusItems(plugin, stableBranch) {
        const items = [];
        const isWporg = plugin.hosting_type === 'wporg';
        const state = Pblsh.Utils.getPluginStatus(plugin.status);
        if (plugin.status === 'closed') {
            items.push({ key: 'closed', variant: state.modifier, label: state.label, text: __('Closed on wordpress.org. The directory distributes nothing.', 'peak-publisher') });
            // A failed check stays: the closed state is the last successful check's, maybe stale.
            const checkFailed = checkFailedItem(plugin);
            return checkFailed ? [ ...items, checkFailed ] : items;
        }
        if (plugin.status === 'draft') {
            items.push({ key: 'draft', variant: state.modifier, label: state.label, text: __('Sites receive nothing while the plugin is a draft.', 'peak-publisher') });
            return items;
        }
        const issue = Pblsh.CurrentReleaseUtils.getCurrentReleaseIssue(plugin);
        if (issue) {
            items.push({
                key: 'current_release',
                variant: issue.variant,
                // wordpress.org falls back to trunk in every state without a tag — a missing tag,
                // Stable tag trunk, no Stable tag at all; self-hosted offers nothing then.
                label: plugin.current_release_state === 'unknown'
                    ? __('Current release unknown', 'peak-publisher')
                    : (isWporg ? __('Distributes trunk', 'peak-publisher') : __('No current release', 'peak-publisher')),
                text: issue.fact,
            });
        }
        const tested = Pblsh.TestedUpToUtils.getStatusItem(plugin, stableBranch);
        if (tested) {
            items.push({ key: 'tested_up_to', variant: tested.variant, label: tested.label, text: tested.fact });
        }
        if (isWporg && plugin.assets_pending > 0) {
            items.push({
                key: 'assets_pending',
                variant: 'info',
                label: sprintf(_n('%d pending asset change', '%d pending asset changes', plugin.assets_pending, 'peak-publisher'), plugin.assets_pending),
                text: __('Asset changes not on wordpress.org yet.', 'peak-publisher'),
            });
        }
        const checkFailed = checkFailedItem(plugin);
        if (checkFailed) {
            items.push(checkFailed);
        }
        return items;
    }

    function checkFailedItem(plugin) {
        return plugin.hosting_type === 'wporg' && plugin.wporg_check && plugin.wporg_check.error
            ? { key: 'check_failed', variant: 'info', label: __('Last check failed', 'peak-publisher'), text: Pblsh.WporgCheckUtils.getCheckErrorText(plugin.wporg_check.error) }
            : null;
    }

    return { getPluginStatusItems };
})());
