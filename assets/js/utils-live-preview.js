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
    // The theme row's fact when the blueprint sets none, and the field that sets one: a slug or
    // the theme's wordpress.org address, empty for the default.
    const getDefaultThemeNote = () => __("Playground's default theme", 'peak-publisher');
    const getThemePlaceholder = () => __('Theme slug or wordpress.org URL…', 'peak-publisher');
    const getThemeHint = () => __("Leave empty for Playground's default theme.", 'peak-publisher');
    const getSetInJsonNote = () => __('set in the JSON', 'peak-publisher');
    const getChangeThemeLink = () => __('Change the theme…', 'peak-publisher');
    const getChangeLabel = () => __('Change', 'peak-publisher');

    // The plugins the preview installs: from the current release's Requires Plugins header — the
    // place where a plugin's needs belong —, others only through the JSON.
    const getPluginNote = (plugin, required) => plugin.required && required
        ? sprintf(__('required by version %s', 'peak-publisher'), required.version)
        : __('added in the JSON', 'peak-publisher');
    // The plugin itself at its place in the install order: last, or wherever the JSON puts it.
    const getOwnPluginNote = (last) => last ? __('your plugin, installed last', 'peak-publisher') : __('your plugin', 'peak-publisher');
    // A required plugin the blueprint does not install before the plugin: WordPress activates no
    // plugin without its active dependencies, and the whole preview stops there. The dot stays
    // with the version (no-break space): a line never starts with it.
    const getNotInstalledNote = (required) => sprintf(__('required by version %s · not installed', 'peak-publisher'), required.version);
    // A required plugin the blueprint installs, but not active before the plugin's own step —
    // after it, or without activating it: the same stop.
    const getNotActiveBeforeNote = (required) => sprintf(__('required by version %s · not active before your plugin', 'peak-publisher'), required.version);
    // A plugin the directory lacks (closed, or no such slug): Playground cannot install it, whatever else is true of it.
    const getNotOnWporgText = () => __('not on wordpress.org', 'peak-publisher');
    // A known activation redirect (WooCommerce's setup): dropped by a step right after the
    // install, or still there — then an admin landing page is never reached.
    const getRedirectSkippedNote = () => __('setup skipped', 'peak-publisher');
    const getRedirectOpenNote = () => __('its setup would open instead of your landing page', 'peak-publisher');
    const getSkipRedirectLabel = () => __('Skip it', 'peak-publisher');
    // Adding a plugin to the preview, in steps: the link, then the one question — a plugin the
    // plugin needs belongs in the Requires Plugins header, the guidance says so —, and only after
    // "no" the field for the slug or address, so nothing is typed in vain.
    const getAddPluginLink = () => __('Add a plugin…', 'peak-publisher');
    const getNeedQuestion = () => __('Does your plugin need it to work?', 'peak-publisher');
    const getNeedYes = () => __('Yes, it is necessary', 'peak-publisher');
    const getNeedNo = () => __('No, it is optional or only for the preview', 'peak-publisher');
    // The answer to yes: a heading like the question's, the text with the header's documentation linked (<a>).
    const getNeedGuidanceTitle = () => __('Then it is a hard dependency', 'peak-publisher');
    const getNeedGuidance = () => __('A hard dependency belongs in the <a>"Requires Plugins" header</a> of your plugin file. Re-upload the current release with the new header and come back here.', 'peak-publisher');
    const getRequiresPluginsHeaderUrl = () => 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/#header-fields';
    const getOkLabel = () => __('OK', 'peak-publisher');
    const getCancelLabel = () => __('Cancel', 'peak-publisher');
    const getAddPluginPlaceholder = () => __('Plugin slug or wordpress.org URL…', 'peak-publisher');
    const getAddPluginLabel = () => __('Add', 'peak-publisher');
    const getRemovePluginLabel = () => __('Remove', 'peak-publisher');
    const getMoveBeforeLabel = () => __('Move before your plugin', 'peak-publisher');
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
    // wordpress.org's guide to plugin previews: the tip's "guide", and the link under the JSON.
    const getGuideUrl = () => 'https://developer.wordpress.org/plugins/wordpress-org/previews-and-blueprints/';
    const getGuideLabel = () => __('Guide to plugin previews', 'peak-publisher');
    const getDeleteConfirmText = (onWporg) => onWporg
        ? __('Remove the Live Preview? It is deleted from wordpress.org with the next commit.', 'peak-publisher')
        : __('Remove the Live Preview? It was never committed and is lost.', 'peak-publisher');
    const getStartOverConfirmText = () => __('Start over? The blueprint.json on wordpress.org is replaced by what you set up here, with the next commit.', 'peak-publisher');
    const getDiscardConfirmText = () => __('Discard your changes to the Live Preview? It returns to its state on wordpress.org.', 'peak-publisher');
    const getUnsavedWorkingCopyText = () => __('Live Preview not saved yet', 'peak-publisher');

    // The file the JSON is, as its path in the plugin's SVN: the editor's head names it.
    const getBlueprintPath = () => 'assets/blueprints/blueprint.json';
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
        getLandingChoice, getLandingOptions, getLandingPath, getLandingError, getLandingHint, getDefaultThemeNote, getThemePlaceholder, getThemeHint, getSetInJsonNote, getChangeThemeLink, getChangeLabel,
        getPluginNote, getOwnPluginNote, getNotInstalledNote, getNotActiveBeforeNote, getNotOnWporgText, getRedirectSkippedNote, getRedirectOpenNote, getSkipRedirectLabel,
        getAddPluginLink, getNeedQuestion, getNeedYes, getNeedNo, getNeedGuidanceTitle, getNeedGuidance, getRequiresPluginsHeaderUrl, getOkLabel, getCancelLabel, getAddPluginPlaceholder, getAddPluginLabel, getRemovePluginLabel, getMoveBeforeLabel,
        getMoreText, getFieldStateText,
        getExpertLabel, getPastePlaceholder, getSaveLineText, getNotObjectText, getUndoLabel, getLoadElsewhereLabel,
        getTestLabel, getTestTooltip, getSetUpLabel, getRemoveLabel, getIntroText, getGuideUrl, getGuideLabel, getDeleteConfirmText, getStartOverConfirmText, getDiscardConfirmText, getUnsavedWorkingCopyText,
        getBlueprintPath, getOtherFilesText, getBlueprintsFolderLabel,
        getFailsText, getFailsLabel, getFailsFact, getCarryTitle, getCarryText, getFlipCarryText, getFlipCarriedText,
    };
})());
