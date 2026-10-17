// LivePreview Component - the Live Preview section of a wordpress.org plugin's assets tab:
// assets/blueprints/blueprint.json, one more entry of the same working copy as the images,
// committed with them. Fixed zones, top down: the section's introduction with its tip, in
// every state; then the box — a box like the images' (pblsh--assets-box), there as long as
// the Live Preview has a state: the slot's band as its head (AssetStatusBand) — what the
// commit will do, or where the file stands on the plugin page, with the check of the public
// switch beneath once wordpress.org has read it —, then the notices; the fields, windows onto
// the JSON, with the JSON itself beside them in expert mode; the actions on the blueprint;
// the other files of assets/blueprints/. Set up stands outside the box, beneath it, while no
// blueprint is in effect; with nothing here, there or on the page only the introduction and
// Set up remain — the zones with nothing to say are not there. Every change goes into the working copy at once — a
// field when it is left, the editor a second after the typing stops while it is valid. The
// view is the server's (assets.blueprint, assets.wporg.slots.blueprint); the editor's draft
// and its saving are the store's (Pblsh.Controllers.BlueprintDraft), so leaving the section
// loses nothing; the field changes in flight, the expert mode and the check are the
// component's own.
lodash.set(window, 'Pblsh.Components.LivePreview', (() => {
    const CHECK_STALE_AFTER = 300000;      // ms: a stored answer older than this is checked anew when the section opens
    const ADVANCED_VIEW_WINDOW = 1200000;  // ms: after a click on the Advanced View from here, a return to the window checks
    const RETURN_DEBOUNCE = 5000;          // ms: focus and visibility arrive together — one check
    const SAVED_SHOWN_FOR = 2000;          // ms: "Saved" on a field's label line
    const EXPERT_KEY = 'pblsh.livePreview.expert';
    // Per plugin, across the section's mounts: when the Advanced View was opened from here, and
    // when the last check ran.
    const advancedViewClicks = {};
    const lastCheckAt = {};

    return ({ pluginData, assets, readOnly, showingWporgState, applyAssets, reportError, onResolve }) => {
        const { __ } = wp.i18n;
        const { createElement, createInterpolateElement, Fragment, useState, useEffect, useRef } = wp.element;
        const { useSelect } = wp.data;
        const { Button, DropdownMenu, MenuItem, SelectControl, TextControl, ToggleControl, Tooltip } = wp.components;
        const { getSvgIcon, getWporgPluginUrl } = Pblsh.Utils;
        const { getShownText } = Pblsh.AssetsUtils;
        const T = Pblsh.LivePreviewUtils;
        const { NoticeBox, BlueprintEditor, RelativeTime, TipLink, WporgRevisionLink, AssetStatusBand } = Pblsh.Components;

        const id = pluginData.id;
        const slug = pluginData.slug;
        const blueprint = assets.blueprint;
        const slot = assets.wporg.slots.blueprint || null;   // { pending, conflict, on_wporg, shown } — null: nothing there, here or on the page
        const onWporg = blueprint.on_wporg;                   // wordpress.org's file, null: none
        const committed = !!onWporg && !onWporg.ignored;      // a file the plugin page can use
        const imported = committed && slot.on_wporg.imported === true;   // wordpress.org has read it: the public switch exists there
        const preview = blueprint.preview;                    // the last check of the public switch, null: never
        const required = blueprint.required;                  // { version, slugs } of the current release, null: none
        // wordpress.org's state alone (the bar's switch): the mirror's file and fields, read-only;
        // else the working copy applied.
        const view = showingWporgState
            ? { contents: onWporg ? onWporg.contents : null, hash: null, fields: onWporg ? onWporg.fields : null, missing: onWporg ? onWporg.missing : [], warnings: [], test: { url: null, blocked: null } }
            : blueprint;
        const hasBlueprint = view.contents !== null;
        const pending = !showingWporgState && slot ? slot.pending : null;

        // Expert mode: the JSON beside the fields, remembered per browser; never switched by itself.
        // A mode of the fields: without a blueprint there is nothing to stand beside, and no editor.
        const [expert, setExpert] = useState(() => { try { return localStorage.getItem(EXPERT_KEY) === '1'; } catch (e) { return false; } });
        const toggleExpert = (on) => {
            setExpert(on);
            try { localStorage.setItem(EXPERT_KEY, on ? '1' : '0'); } catch (e) { /* a browser without storage forgets the switch */ }
        };
        // Without fields (no JSON object) the editor is the only way in: shown whatever the switch says.
        const forcedExpert = hasBlueprint && !view.fields;
        const showEditor = expert || forcedExpert;

        // The editor's draft: text null while the editor shows the view's contents. Leaving the
        // section saves a valid draft at once; an invalid one waits here for the return.
        const Draft = Pblsh.Controllers.BlueprintDraft;
        const draftState = useSelect((select) => select('pblsh/assets').getBlueprintDraft(id), [id]);
        const draft = draftState ? draftState.text : null;
        const saving = !!(draftState && draftState.saving);
        useEffect(() => () => Draft.flush(id), [id]);
        const onEditorChange = (text) => Draft.edit(id, text, blueprint.hash);

        // The field changes in flight: which field, its state on the label line, its error beneath.
        const [fieldBusy, setFieldBusy] = useState(null);     // 'landing_page' | 'theme' | 'dependencies' | 'create' | 'delete'
        const [fieldState, setFieldState] = useState({});     // field → 'saving' | 'asking' | 'saved'
        const [fieldError, setFieldError] = useState({});     // field → message
        const [otherPath, setOtherPath] = useState(null);     // the "Other page" path while typed
        const [themeInput, setThemeInput] = useState(null);   // the theme slug while typed
        const savedTimer = useRef(null);
        useEffect(() => () => clearTimeout(savedTimer.current), []);
        const showSaved = (field) => {
            setFieldState({ [field]: 'saved' });
            clearTimeout(savedTimer.current);
            savedTimer.current = setTimeout(() => setFieldState({}), SAVED_SHOWN_FOR);
        };
        const isField = (name) => name === 'landing_page' || name === 'theme';
        // A change of the Live Preview; the answer is the fresh view. A change built on a state that
        // moved meanwhile brings the fresh view along — shown, and said.
        const change = async (field, body) => {
            setFieldBusy(field);
            setFieldError({});
            if (isField(field)) setFieldState({ [field]: field === 'theme' ? 'asking' : 'saving' });
            try {
                applyAssets(await Pblsh.API.changePluginBlueprint(id, body));
                if (isField(field)) showSaved(field); else setFieldState({});
                return true;
            } catch (e) {
                setFieldState({});
                if (e && e.assets) applyAssets({ assets: e.assets });
                if (isField(field)) {
                    setFieldError({ [field]: (e && e.message) || __('The change could not be saved.', 'peak-publisher') });
                } else {
                    reportError(e, __('The change could not be saved.', 'peak-publisher'));
                }
                return false;
            } finally {
                setFieldBusy(null);
            }
        };
        // Removing and starting over are the same step: a pending delete leaves nothing in effect,
        // and setting up or pasting replaces it with a put.
        const remove = (confirmText) => { if (confirm(confirmText)) change('delete', { change: 'delete' }); };

        // Whether the Live Preview is public — asked on wordpress.org only once wordpress.org has
        // the file: when the section opens and the stored answer is older than five minutes, on
        // "Check again", and on a return to the window within twenty minutes after the Advanced
        // View was opened from here — the switch lives there, and may have moved several times.
        const [checking, setChecking] = useState(false);
        const [checkFailed, setCheckFailed] = useState(false);
        const check = async () => {
            lastCheckAt[id] = Date.now();
            setChecking(true);
            try {
                applyAssets(await Pblsh.API.checkLivePreview(id));
                setCheckFailed(false);
            } catch (e) {
                setCheckFailed(true);
            } finally {
                setChecking(false);
            }
        };
        useEffect(() => {
            if (imported && !showingWporgState && (!preview || Date.now() - preview.checked_at * 1000 > CHECK_STALE_AFTER)) check();
        }, [id, imported]);
        useEffect(() => {
            if (!imported) return undefined;
            const onReturn = () => {
                if (document.visibilityState !== 'visible') return;
                const now = Date.now();
                if (now - (advancedViewClicks[id] || 0) < ADVANCED_VIEW_WINDOW && now - (lastCheckAt[id] || 0) > RETURN_DEBOUNCE) check();
            };
            window.addEventListener('focus', onReturn);
            document.addEventListener('visibilitychange', onReturn);
            return () => {
                window.removeEventListener('focus', onReturn);
                document.removeEventListener('visibilitychange', onReturn);
            };
        }, [id, imported]);

        const external = (href, text) => createElement('a', { href, target: '_blank', rel: 'noreferrer' }, text);
        const previewUrl = getWporgPluginUrl(slug) + '?preview=1';
        const advancedUrl = getWporgPluginUrl(slug) + 'advanced/';
        const svnUrl = 'https://plugins.svn.wordpress.org/' + encodeURIComponent(slug) + '/assets/blueprints/';
        const advancedLink = createElement('a', { href: advancedUrl, target: '_blank', rel: 'noreferrer', onClick: () => { advancedViewClicks[id] = Date.now(); } });

        // The introduction, above the zones in every state: what the Live Preview does for the
        // plugin page, with the tip that explains the how — built for this plugin.
        const intro = createElement('div', { className: 'pblsh--live-preview__intro' },
            createElement('p', null, T.getIntroText()),
            createElement(TipLink, { tipKey: 'livePreview', params: { slug } }));

        // Zone 1: the slot's band — what the commit will do, with its Discard, or where the file
        // stands on the plugin page; once wordpress.org has read it, the check of the public switch
        // beneath. The check's line keeps the last answer with its age while a new check runs,
        // "Checking…" beside it — nothing jumps. Visitors not seeing what is on the page is the one
        // state that asks the user to act: the warning, with the way there. Not while wordpress.org's
        // state is shown — like the images' boxes —, and not with nothing here, there or on the page:
        // unlike an image slot, the empty Live Preview is no slot in a grid, the empty state speaks
        // for itself.
        const renderCheckLine = () => createElement(Fragment, null,
            external(previewUrl, T.getOpenLabel() + ' ↗'),
            preview && createElement(Fragment, null, ' · ', createElement(RelativeTime, { value: new Date(preview.checked_at * 1000).toISOString(), tooltip: true })),
            ' · ', createElement(Button, { isLink: true, disabled: checking, onClick: check }, T.getCheckAgainLabel()),
            checking && createElement(Fragment, null, ' · ', T.getCheckingText()),
            !checking && checkFailed && createElement(Fragment, null, ' · ', T.getCheckFailedText()),
        );
        const renderBand = () => {
            if (showingWporgState) return null;
            const band = (props, detail = null) => createElement(AssetStatusBand, {
                slot,
                forecast: pluginData.wporg_import,
                disabled: readOnly || fieldBusy !== null || draft !== null || saving,
                onDiscard: () => {
                    if (pending === 'put' && !confirm(T.getDiscardConfirmText())) return;
                    onResolve('blueprint', 'theirs');
                },
                ...props,
            }, detail);
            if (!pending && !onWporg && !(slot && slot.shown)) return null;
            if (pending || !onWporg) return band({});
            if (onWporg.ignored) return band({ text: T.getIgnoredStatusText(), icon: 'alert_outline' });
            if (!imported) return band({});
            if (!preview) return band({}, renderCheckLine());
            if (preview.public) return band({ text: getShownText() + ' · ' + T.getPublicText() }, renderCheckLine());
            return band({
                warning: true,
                text: createElement(Fragment, null, T.getNotPublicText(), ' ', createElement('span', { className: 'pblsh--assets-band__note' }, createInterpolateElement(T.getSwitchText(), { a: advancedLink }))),
            }, renderCheckLine());
        };

        // Zone 2: the notices, at one place — fact, consequence, remedy.
        const renderNotices = () => [
            onWporg && onWporg.ignored && !pending && createElement(NoticeBox, { key: 'ignored', variant: 'warning' },
                createElement('p', null, onWporg.ignored === 'too_large'
                    ? T.getIgnoredText(onWporg)
                    : createInterpolateElement(T.getIgnoredText(onWporg), { a: createElement(Button, { isLink: true, disabled: readOnly || fieldBusy !== null, onClick: () => remove(T.getStartOverConfirmText()) }) })),
            ),
            ...view.warnings.map((warning) => createElement(NoticeBox, { key: warning.code, variant: 'warning' }, createElement('p', null, warning.message))),
            hasBlueprint && view.test.blocked === 'not_downloadable' && createElement(NoticeBox, { key: 'not-downloadable', variant: 'warning' }, createElement('p', null, T.getNotDownloadableText())),
        ].filter(Boolean);

        // Zone 3: the fields — each reads its part of the JSON and writes only that; locked while
        // the editor holds something the JSON does not have yet, the save line says why.
        const locked = readOnly || draft !== null || saving || fieldBusy !== null || !view.fields;
        const renderFieldLabel = (field, text) => createElement('div', { className: 'pblsh--live-preview__field-label' },
            createElement('span', null, text),
            createElement('span', { className: 'pblsh--live-preview__field-state' }, T.getFieldStateText(fieldState[field])),
        );
        const renderLanding = () => {
            const field = view.fields.landing_page;
            const choice = otherPath !== null ? 'other' : T.getLandingChoice(field.value);
            const commitPath = (path) => {
                if (!path.startsWith('/')) {
                    setFieldError({ landing_page: T.getLandingError() });
                    return;
                }
                if (path === field.value) {
                    setOtherPath(null);
                    return;
                }
                change('landing_page', { change: 'landing_page', value: path }).then((ok) => { if (ok) setOtherPath(null); });
            };
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('landing_page', __('Landing page', 'peak-publisher')),
                !field.editable
                    ? createElement('div', { className: 'pblsh--live-preview__muted' }, T.getSetInJsonText())
                    : createElement('div', { className: 'pblsh--live-preview__landing' },
                        createElement(SelectControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('Landing page', 'peak-publisher'),
                            hideLabelFromVision: true,
                            value: choice,
                            options: T.getLandingOptions(),
                            disabled: locked,
                            onChange: (key) => {
                                if (key === 'other') { setOtherPath(field.value || ''); return; }
                                setOtherPath(null);
                                if (T.getLandingPath(key) !== field.value) change('landing_page', { change: 'landing_page', value: T.getLandingPath(key) });
                            },
                        }),
                        choice === 'other' && createElement(TextControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('Path', 'peak-publisher'),
                            hideLabelFromVision: true,
                            placeholder: '/wp-admin/admin.php?page=' + slug,
                            value: otherPath !== null ? otherPath : (field.value || ''),
                            disabled: locked && fieldBusy !== 'landing_page',
                            onChange: setOtherPath,
                            onBlur: () => { if (otherPath !== null && otherPath.trim() !== '') commitPath(otherPath.trim()); },
                            onKeyDown: (e) => {
                                if (e.key === 'Enter' && otherPath !== null) commitPath(otherPath.trim());
                                if (e.key === 'Escape') { setOtherPath(null); setFieldError({}); }
                            },
                        }),
                    ),
                field.editable && createElement('div', { className: 'pblsh--live-preview__muted' }, T.getLandingHint()),
                fieldError.landing_page && createElement('div', { className: 'pblsh--live-preview__field-error' }, fieldError.landing_page),
            );
        };
        const renderTheme = () => {
            const field = view.fields.theme;
            const value = themeInput !== null ? themeInput : (field.slug || '');
            const commitTheme = () => {
                if (themeInput === null) return;
                const next = themeInput.trim();
                if (next === (field.slug || '')) { setThemeInput(null); return; }
                change('theme', { change: 'theme', value: next }).then((ok) => { if (ok) setThemeInput(null); });
            };
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('theme', __('Theme', 'peak-publisher')),
                !field.editable
                    ? createElement('div', { className: 'pblsh--live-preview__muted' }, (field.slug ? field.slug + ' · ' : '') + T.getSetInJsonText())
                    : createElement(TextControl, {
                        __nextHasNoMarginBottom: true,
                        label: __('Theme', 'peak-publisher'),
                        hideLabelFromVision: true,
                        placeholder: T.getThemePlaceholder(),
                        value,
                        disabled: locked && fieldBusy !== 'theme',
                        onChange: (next) => { setThemeInput(next); setFieldError({}); },
                        onBlur: commitTheme,
                        onKeyDown: (e) => {
                            if (e.key === 'Enter') commitTheme();
                            if (e.key === 'Escape') { setThemeInput(null); setFieldError({}); }
                        },
                    }),
                fieldError.theme && createElement('div', { className: 'pblsh--live-preview__field-error' }, fieldError.theme),
            );
        };
        const renderPlugins = () => {
            const missing = view.missing;
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('plugins', __('Plugins', 'peak-publisher')),
                createElement('ul', { className: 'pblsh--live-preview__plugins' },
                    view.fields.plugins.map((plugin, i) => createElement('li', { key: plugin.slug + i },
                        createElement('code', null, plugin.slug), ' ', createElement('span', { className: 'pblsh--live-preview__muted' }, T.getPluginNote(plugin, required)))),
                    missing.map((name) => createElement('li', { key: 'missing-' + name, className: 'pblsh--live-preview__plugin--missing' },
                        createElement('code', null, name), ' ', createElement('span', null, T.getNotInstalledNote(required)))),
                    createElement('li', null, createElement('code', null, slug), ' ', createElement('span', { className: 'pblsh--live-preview__muted' }, T.getOwnPluginNote())),
                ),
                missing.length > 0 && required && createElement('div', { className: 'pblsh--live-preview__plugins-add' },
                    createElement(Button, { isLink: true, isBusy: fieldBusy === 'dependencies', disabled: locked, onClick: () => change('dependencies', { change: 'dependencies' }) }, T.getAddMissingLabel(missing))),
                createElement('div', { className: 'pblsh--live-preview__muted' }, T.getPluginsSourceText(required)),
            );
        };
        const renderFields = () => createElement(Fragment, null,
            renderLanding(),
            renderTheme(),
            renderPlugins(),
            view.fields.more.length > 0 && createElement('div', { className: 'pblsh--live-preview__more' }, T.getMoreText(view.fields.more)),
        );

        // The JSON: the editor with its save line — how the text there stands to the working copy
        // and wordpress.org. A draft that waits for its save, or an empty editor, says what happens.
        const editorValue = draft !== null ? draft : (view.contents || '');
        const saveLine = draftState && draftState.line ? draftState.line : null;
        const saveState = saving ? 'saving'
            : saveLine ? saveLine.state
            : showingWporgState ? (onWporg ? 'mirror' : 'none')
            : draft !== null || !hasBlueprint ? 'pending'
            : pending === 'put' ? 'working'
            : onWporg ? 'mirror' : 'working';
        const saveDetail = saveLine ? saveLine.detail : (onWporg ? onWporg.revision : null);
        const saveError = saveState === 'invalid' || saveState === 'refused' || saveState === 'elsewhere';
        const renderEditor = () => createElement('div', { className: 'pblsh--live-preview__editor' },
            createElement('div', { className: 'pblsh--live-preview__editor-box' },
                createElement(BlueprintEditor, { value: editorValue, onChange: onEditorChange, readOnly: readOnly || fieldBusy !== null, placeholder: T.getPastePlaceholder(), label: __('Blueprint JSON', 'peak-publisher') })),
            createElement('div', { className: 'pblsh--live-preview__save' + (saveError ? ' pblsh--live-preview__save--error' : '') },
                T.getSaveLineText(saveState, saveDetail),
                saveState === 'elsewhere' && createElement(Fragment, null, ' ', createElement(Button, { isLink: true, onClick: () => Draft.loadElsewhere(id) }, T.getLoadElsewhereLabel())),
                (saveState === 'invalid' || saveState === 'refused') && createElement(Fragment, null, ' ', createElement(Button, { isLink: true, onClick: () => Draft.drop(id) }, T.getUndoLabel())),
            ),
        );
        const renderMain = () => createElement(Fragment, null,
            createElement('div', { className: 'pblsh--live-preview__main-head' },
                createElement(ToggleControl, { __nextHasNoMarginBottom: true, label: T.getExpertLabel(), checked: showEditor, disabled: forcedExpert, onChange: toggleExpert })),
            createElement('div', { className: 'pblsh--live-preview__main' },
                !forcedExpert && createElement('div', { className: 'pblsh--live-preview__fields' }, renderFields()),
                showEditor && renderEditor(),
            ),
        );

        // Zone 4: the actions on the blueprint in effect — the test of the working copy, the menu.
        // Setting up is no action on it: that button stands outside the box.
        const testBlocked = draft !== null || saving || !view.test.url;
        const testButton = createElement(Button, {
            isPrimary: true,
            ...(testBlocked ? { disabled: true } : { href: view.test.url, target: '_blank', rel: 'noreferrer' }),
        }, T.getTestLabel() + ' ↗');
        const renderSetUp = () => createElement('div', { className: 'pblsh--live-preview__actions' },
            createElement(Button, { isPrimary: true, isBusy: fieldBusy === 'create', disabled: readOnly || fieldBusy !== null, onClick: () => change('create', { change: 'create' }) }, T.getSetUpLabel()));
        const renderActions = () => createElement('div', { className: 'pblsh--live-preview__actions' },
            testBlocked ? testButton : createElement(Tooltip, { text: T.getTestTooltip(required ? required.version : null) }, testButton),
            createElement(DropdownMenu, {
                icon: getSvgIcon('dots_horizontal', { size: 24 }),
                label: __('More options', 'peak-publisher'),
                children: ({ onClose }) => createElement(MenuItem, {
                    isDestructive: true,
                    disabled: readOnly || fieldBusy !== null,
                    onClick: () => { onClose(); remove(T.getDeleteConfirmText(!!onWporg)); },
                }, getSvgIcon('delete_forever', { size: 24 }), T.getRemoveLabel()),
            }),
        );

        // Zone 5: the other files of assets/blueprints/.
        const otherCount = blueprint.other_files + Math.max(0, blueprint.blueprint_files - (onWporg ? 1 : 0));
        const footer = otherCount > 0 && createElement('div', { className: 'pblsh--assets-footer' },
            T.getOtherFilesText(otherCount), ' · ', external(svnUrl, T.getBlueprintsFolderLabel() + ' ↗'));

        // The box: the band as its head, what the section says beneath; there as long as the Live
        // Preview has a state — a band, or a blueprint in effect while wordpress.org's state is shown.
        const band = renderBand();
        const box = (...body) => createElement('div', { className: 'pblsh--assets-box pblsh--live-preview__box' },
            band,
            body.some(Boolean) && createElement('div', { className: 'pblsh--live-preview__body' }, ...body),
        );

        // Changed here and on wordpress.org: both states, each with the decision that takes it.
        if (slot && slot.conflict && !showingWporgState) {
            const side = (label, contents, emptyText, meta, decision) => createElement('div', { className: 'pblsh--live-preview__compare-side' },
                createElement('div', { className: 'pblsh--live-preview__compare-label' }, label, meta),
                contents === null
                    ? createElement('div', { className: 'pblsh--live-preview__compare-empty' }, emptyText)
                    : createElement('pre', { className: 'pblsh--live-preview__json' }, contents),
                createElement('div', { className: 'pblsh--live-preview__compare-decision' }, decision),
            );
            return createElement('div', { className: 'pblsh--live-preview' },
                intro,
                box(
                    createElement('div', { className: 'pblsh--live-preview__compare' },
                        side(__('Mine', 'peak-publisher'), slot.pending === 'delete' ? null : blueprint.contents, __('Deleted here', 'peak-publisher'), null,
                            createElement(Button, { isPrimary: true, disabled: readOnly, onClick: () => onResolve('blueprint', 'mine') }, __('Keep mine', 'peak-publisher'))),
                        side(__('wordpress.org', 'peak-publisher'), onWporg ? onWporg.contents : null, __('Deleted there', 'peak-publisher'),
                            onWporg ? createElement(Fragment, null, ' ', createElement(WporgRevisionLink, { revision: onWporg.revision })) : null,
                            createElement(Button, { isSecondary: true, disabled: readOnly, onClick: () => onResolve('blueprint', 'theirs') }, __('Take theirs', 'peak-publisher'))),
                    ),
                    footer,
                ),
            );
        }

        return createElement('div', { className: 'pblsh--live-preview' },
            intro,
            (band || hasBlueprint) && box(
                ...renderNotices(),
                hasBlueprint && renderMain(),
                hasBlueprint && renderActions(),
                footer,
            ),
            !hasBlueprint && renderSetUp(),
        );
    };
})());
