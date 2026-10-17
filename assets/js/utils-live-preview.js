// Pure: the vocabulary of the Live Preview (Pblsh.LivePreviewUtils) — the assets tab's section
// for assets/blueprints/blueprint.json of a wordpress.org plugin: the facts the section, the
// list, the editor and the upload dialog say about it. No state, like utils-assets.js. Users
// never read "imported": what the plugin page shows is "on the plugin page", wordpress.org is
// where a commit goes — the words of every slot's band (Pblsh.AssetsUtils).
lodash.set(window, 'Pblsh.LivePreviewUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;

    const list = (slugs) => slugs.join(', ');

    // What the band says of the Live Preview beyond the slot's facts (AssetStatusBand): a file
    // the import has read and thrown away — a fact about wordpress.org, not about the page —,
    // and the public switch.
    const getIgnoredStatusText = () => __('Committed · wordpress.org ignores it', 'peak-publisher');
    const getNotPublicText = () => __("On the plugin page, but visitors don't see it yet.", 'peak-publisher');
    // <a> is the plugin's Advanced View on wordpress.org.
    const getSwitchText = () => __("Switch it on in the plugin's <a>Advanced View</a>: The Danger Zone › Toggle Live Preview. Until then only committers see it, as Test Preview.", 'peak-publisher');
    const getPublicText = () => __('Public — visitors see the Live Preview button', 'peak-publisher');
    const getOpenLabel = () => __('Open', 'peak-publisher');
    const getCheckAgainLabel = () => __('Check again', 'peak-publisher');
    const getCheckingText = () => __('Checking wordpress.org…', 'peak-publisher');
    const getCheckFailedText = () => __('Could not check wordpress.org', 'peak-publisher');

    // wordpress.org's own blueprint.json, which its import ignores: the plugin page has no preview.
    // Over the limit the file was not read, so there is nothing to fix here — a new one replaces
    // it. <a> is the start-over link.
    function getIgnoredText(onWporg) {
        if (onWporg.ignored === 'too_large') {
            return sprintf(__('blueprint.json on wordpress.org is %s — wordpress.org ignores blueprints over 100 KB, so the plugin page has no Live Preview. Set up a new one or paste one below.', 'peak-publisher'), Pblsh.AssetsUtils.getMetaLine({ filesize: onWporg.filesize }));
        }
        return sprintf(__('wordpress.org cannot read the blueprint.json it has (%s), so the plugin page has no Live Preview. Fix it here or <a>start over</a>.', 'peak-publisher'), onWporg.problem);
    }
    const getNotDownloadableText = () => __('Testing needs your plugin to be downloadable from wordpress.org.', 'peak-publisher');

    // Where the preview opens: the presets of the landing page field — the plugins screen is
    // where wordpress.org's own generator lands — and the path behind each. A blueprint without
    // landingPage opens Playground's default, the front page.
    const LANDING_PRESETS = [
        { key: 'plugins', path: '/wp-admin/plugins.php', label: __('Plugins screen', 'peak-publisher') },
        { key: 'dashboard', path: '/wp-admin/', label: __('Dashboard', 'peak-publisher') },
        { key: 'front', path: '/', label: __('Front page', 'peak-publisher') },
    ];
    function getLandingChoice(value) {
        const preset = LANDING_PRESETS.find((p) => p.path === (value === null ? '/' : value));
        return preset ? preset.key : 'other';
    }
    const getLandingOptions = () => [ ...LANDING_PRESETS.map((p) => ({ value: p.key, label: p.label })), { value: 'other', label: __('Other page…', 'peak-publisher') } ];
    const getLandingPath = (key) => (LANDING_PRESETS.find((p) => p.key === key) || {}).path;
    // The one piece of advice at the field: the preview is only as convincing as its first page.
    const getLandingHint = () => __('Where visitors start — best a page that shows your plugin at work.', 'peak-publisher');
    const getLandingError = () => __('The landing page is a path on the site and starts with /, for example /wp-admin/admin.php?page=my-plugin.', 'peak-publisher');
    const getThemePlaceholder = () => __("Playground's default theme", 'peak-publisher');
    const getSetInJsonText = () => __('Set in the JSON.', 'peak-publisher');

    // The plugins the preview installs: from the current release's Requires Plugins header — the
    // place where a plugin's needs belong —, others only through the JSON.
    const getPluginNote = (plugin, required) => plugin.required && required
        ? sprintf(__('required by version %s', 'peak-publisher'), required.version)
        : __('added in the JSON', 'peak-publisher');
    const getOwnPluginNote = () => __('your plugin, installed last', 'peak-publisher');
    // A required plugin the blueprint does not install before the plugin: WordPress activates no
    // plugin without its active dependencies, and the whole preview stops there.
    const getNotInstalledNote = (required) => sprintf(__('required by version %s · not installed', 'peak-publisher'), required.version);
    const getAddMissingLabel = (missing) => missing.length === 1 ? sprintf(__('Add %s', 'peak-publisher'), missing[0]) : __('Add them', 'peak-publisher');
    const getPluginsSourceText = (required) => required
        ? sprintf(__('From the Requires Plugins header of version %s.', 'peak-publisher'), required.version)
        : __('From the Requires Plugins header of the current release (none yet).', 'peak-publisher');
    // What no field shows, by name: "2 runPHP, siteOptions".
    const getMoreText = (more) => sprintf(__('Also in the JSON: %s', 'peak-publisher'), more.map((item) => item.count > 1 ? item.count + ' ' + item.name : item.name).join(', '));
    // A field's state on its label line, gone again two seconds after a save.
    const getFieldStateText = (state) => ({
        saving: __('Saving…', 'peak-publisher'),
        saved: __('Saved', 'peak-publisher'),
        asking: __('Asking wordpress.org…', 'peak-publisher'),
    })[state] || '';

    // The editor and its save line: how the text there stands to the working copy and wordpress.org.
    const getExpertLabel = () => __('Expert mode', 'peak-publisher');
    const getPastePlaceholder = () => __('Paste a blueprint here', 'peak-publisher');
    function getSaveLineText(state, detail) {
        return {
            mirror: sprintf(__('As on wordpress.org (r%s)', 'peak-publisher'), detail),
            working: __('As in the working copy', 'peak-publisher'),
            none: __('No Live Preview on wordpress.org.', 'peak-publisher'),
            pending: __('Saved to the working copy a second after you stop typing.', 'peak-publisher'),
            saving: __('Saving…', 'peak-publisher'),
            saved: __('Saved in the working copy', 'peak-publisher'),
            invalid: sprintf(__('Not saved — %s', 'peak-publisher'), detail),
            refused: sprintf(__('Not saved — %s', 'peak-publisher'), detail),
            elsewhere: __('Not saved — the Live Preview was changed elsewhere since you started.', 'peak-publisher'),
        }[state] || '';
    }
    const getNotObjectText = () => __('a blueprint is a JSON object, it starts with {', 'peak-publisher');
    const getUndoLabel = () => __('Undo my edits', 'peak-publisher');
    const getLoadElsewhereLabel = () => __('Load the current version', 'peak-publisher');

    // The actions: the test in Playground — the blueprint as the plugin page will serve it, with
    // the plugin as wordpress.org distributes it —, setting up, removing.
    const getTestLabel = () => __('Test in Playground', 'peak-publisher');
    const getTestTooltip = (version) => version
        ? sprintf(__('Installs your plugin as wordpress.org distributes it (%s).', 'peak-publisher'), version)
        : __('Installs your plugin as wordpress.org distributes it.', 'peak-publisher');
    const getSetUpLabel = () => __('Set up Live Preview', 'peak-publisher');
    const getRemoveLabel = () => __('Remove Live Preview', 'peak-publisher');
    // The section's introduction, in every state: what a Live Preview does for the plugin page —
    // the how (Playground, the steps, the blueprint) is the tip's (Pblsh.Tips.livePreview).
    const getIntroText = () => __('Offers a Live Preview on the plugin page, so interested visitors can give the plugin a quick try.', 'peak-publisher');
    const getDeleteConfirmText = (onWporg) => onWporg
        ? __('Remove the Live Preview? It is deleted from wordpress.org with the next commit.', 'peak-publisher')
        : __('Remove the Live Preview? It was never committed and is lost.', 'peak-publisher');
    const getStartOverConfirmText = () => __('Start over? The blueprint.json on wordpress.org is replaced by what you set up here, with the next commit.', 'peak-publisher');
    const getDiscardConfirmText = () => __('Discard your changes to the Live Preview? It returns to its state on wordpress.org.', 'peak-publisher');
    const getUnsavedWorkingCopyText = () => __('Live Preview not saved yet', 'peak-publisher');

    // The other files of assets/blueprints/ — counted, never managed.
    const getOtherFilesText = (count) => sprintf(_n('%d other file in assets/blueprints/ is not used for the preview', '%d other files in assets/blueprints/ are not used for the preview', count, 'peak-publisher'), count);
    const getBlueprintsFolderLabel = () => __('assets/blueprints/ on wordpress.org', 'peak-publisher');

    // The committed preview fails for what visitors get: the editor's notice and the list's
    // status column (whose tooltip says what, never how).
    function getFailsText(livePreview) {
        return sprintf(_n(
            'The Live Preview on the plugin page fails: %1$s, which version %2$s requires, is not installed before the plugin.',
            'The Live Preview on the plugin page fails: %1$s, which version %2$s requires, are not installed before the plugin.',
            livePreview.missing.length, 'peak-publisher'
        ), list(livePreview.missing), livePreview.version);
    }
    const getFailsLabel = () => __('Live Preview fails', 'peak-publisher');
    function getFailsFact(livePreview) {
        return sprintf(_n('%1$s, which version %2$s requires, is not installed before the plugin.', '%1$s, which version %2$s requires, are not installed before the plugin.', livePreview.missing.length, 'peak-publisher'), list(livePreview.missing), livePreview.version);
    }

    // A commit that makes a release current brings its Requires Plugins into the preview: said
    // before (the upload's checklist, the flip's confirm) and, for the flip, after.
    const getCarryTitle = () => __('Live Preview gets the required plugins', 'peak-publisher');
    function getCarryText(missing, version) {
        return sprintf(_n(
            'Version %2$s requires %1$s: the Live Preview on wordpress.org will install it too, in the same commit.',
            'Version %2$s requires %1$s: the Live Preview on wordpress.org will install them too, in the same commit.',
            missing.length, 'peak-publisher'
        ), list(missing), version);
    }
    const getFlipCarryText = (missing) => sprintf(__('The Live Preview will install %s too, which this version requires.', 'peak-publisher'), list(missing));
    const getFlipCarriedText = (added) => sprintf(__('The Live Preview now installs %s too.', 'peak-publisher'), list(added));

    return {
        getIgnoredStatusText, getNotPublicText, getSwitchText, getPublicText, getOpenLabel, getCheckAgainLabel,
        getCheckingText, getCheckFailedText, getIgnoredText, getNotDownloadableText,
        getLandingChoice, getLandingOptions, getLandingPath, getLandingError, getLandingHint, getThemePlaceholder, getSetInJsonText,
        getPluginNote, getOwnPluginNote, getNotInstalledNote, getAddMissingLabel, getPluginsSourceText, getMoreText, getFieldStateText,
        getExpertLabel, getPastePlaceholder, getSaveLineText, getNotObjectText, getUndoLabel, getLoadElsewhereLabel,
        getTestLabel, getTestTooltip, getSetUpLabel, getRemoveLabel, getIntroText, getDeleteConfirmText, getStartOverConfirmText, getDiscardConfirmText, getUnsavedWorkingCopyText,
        getOtherFilesText, getBlueprintsFolderLabel,
        getFailsText, getFailsLabel, getFailsFact, getCarryTitle, getCarryText, getFlipCarryText, getFlipCarriedText,
    };
})());
