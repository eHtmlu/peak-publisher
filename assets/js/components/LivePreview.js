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
// preset as it is chosen, a typed path a second after the typing stops or when its field is
// left, the theme and a plugin on their button, the editor a second after the typing stops
// while it is valid. The
// view is the server's (assets.blueprint, assets.wporg.slots.blueprint); the editor's draft
// and its saving are the store's (Pblsh.Controllers.BlueprintDraft), so leaving the section
// loses nothing; the field changes in flight, the expert mode and the check are the
// component's own.
lodash.set(window, 'Pblsh.Components.LivePreview', (() => {
    const CHECK_STALE_AFTER = 300000;      // ms: a stored answer older than this is checked anew when the section opens
    const ADVANCED_VIEW_WINDOW = 1200000;  // ms: after a click on the Advanced View from here, a return to the window checks
    const RETURN_DEBOUNCE = 5000;          // ms: focus and visibility arrive together — one check
    const SAVED_SHOWN_FOR = 2000;          // ms: "Saved" on a field's label line
    const PATH_SAVE_DELAY = 1000;          // ms: a typed landing page saves this long after the typing stops, like the editor's draft
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
        const { getSvgIcon, getWporgPluginUrl, getWporgThemeUrl, wporgSlugFromInput } = Pblsh.Utils;
        const { getShownText } = Pblsh.AssetsUtils;
        const T = Pblsh.LivePreviewUtils;
        const { NoticeBox, BlueprintEditor, RelativeTime, TipLink, WporgRevisionLink, AssetStatusBand, PluginIdentity } = Pblsh.Components;

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
            ? { contents: onWporg ? onWporg.contents : null, hash: null, fields: onWporg ? onWporg.fields : null, theme: onWporg ? onWporg.theme : null, missing: onWporg ? onWporg.missing : [], listings: blueprint.listings, warnings: [], test: { url: null, blocked: null } }
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
        // Beside a fact only the JSON can change — a field that cannot read its part, what no
        // field shows —, the switch as a link while the editor is hidden. Not while wordpress.org's
        // state is shown: nothing is edited there, like the fields' own actions.
        const editInExpertLink = () => !showEditor && !showingWporgState && createElement(Fragment, null, ' · ',
            createElement(Button, { isLink: true, onClick: () => toggleExpert(true) }, T.getEditInExpertLink()));

        // The editor's draft: text null while the editor shows the view's contents. Leaving the
        // section saves a valid draft at once; an invalid one waits here for the return.
        const Draft = Pblsh.Controllers.BlueprintDraft;
        const draftState = useSelect((select) => select('pblsh/assets').getBlueprintDraft(id), [id]);
        const draft = draftState ? draftState.text : null;
        const saving = !!(draftState && draftState.saving);
        useEffect(() => () => Draft.flush(id), [id]);
        const onEditorChange = (text) => Draft.edit(id, text, blueprint.hash);

        // The field changes in flight: which field, its state on the label line, its error beneath.
        const [fieldBusy, setFieldBusy] = useState(null);     // the field or action under way: 'create' | 'delete' | 'landing_page' | 'plugins' | 'redirect_skip' | 'theme'
        const [fieldState, setFieldState] = useState({});     // field → 'saving' | 'asking' | 'saved'
        const [fieldError, setFieldError] = useState({});     // field → message
        const [landing, setLanding] = useState(null);         // the landing page chosen or typed but not applied yet: { choice, path }; null: the page set
        const [themeInput, setThemeInput] = useState(null);   // the theme slug while typed
        const [addStep, setAddStep] = useState(null);         // adding a plugin: null (the link) | 'ask' (the question) | 'guide' (the answer to yes) | 'slug' (the field)
        const [addSlug, setAddSlug] = useState('');           // the plugin typed, slug or address
        const savedTimer = useRef(null);
        const pathTimer = useRef(null);                       // the typed path's pending save
        // The landing page typed but not saved yet: the path field shows something other than the
        // page set. It holds the commit and the test until its save has landed.
        const landingCurrent = view.fields ? (view.fields.landing_page.value === null ? '/' : view.fields.landing_page.value) : null;   // without landingPage Playground opens the front page
        const landingTyped = landing && landing.choice === 'other' ? landing.path.trim() : null;
        const landingDirty = landingTyped !== null && landingCurrent !== null && landingTyped !== landingCurrent;
        const latest = useRef({});                            // the last render's state, for the path's save when it runs
        latest.current = { landing, landingCurrent, fieldBusy };
        // A field that appears on a choice — the path on "Other page…", the theme's slug on Change,
        // the plugin to add after "no" — takes the focus with its text selected: the focus follows
        // the render that mounts it.
        const fieldInputs = useRef({});
        const focusField = useRef(null);
        useEffect(() => {
            const input = focusField.current ? fieldInputs.current[focusField.current] : null;
            if (input) {
                focusField.current = null;
                input.focus();
                if (typeof input.select === 'function') input.select();
            }
        });
        // A field holding something not saved yet, or being saved, told to the store: the commit
        // waits for it like for the draft (PluginAssets). The path field holds while it differs
        // from the page set; the theme's and the plugins' fields save only on Enter or their
        // button, so typing there holds nothing. Gone with the section.
        useEffect(() => {
            wp.data.dispatch('pblsh/assets').setBlueprintFieldState(id, fieldBusy !== null ? 'saving' : landingDirty ? 'editing' : null);
        }, [id, fieldBusy, landingDirty]);
        useEffect(() => () => wp.data.dispatch('pblsh/assets').setBlueprintFieldState(id, null), [id]);
        useEffect(() => () => { clearTimeout(savedTimer.current); clearTimeout(pathTimer.current); }, []);
        const showSaved = (field) => {
            setFieldState({ [field]: 'saved' });
            clearTimeout(savedTimer.current);
            savedTimer.current = setTimeout(() => setFieldState({}), SAVED_SHOWN_FOR);
        };
        const isField = (name) => name === 'landing_page' || name === 'theme' || name === 'plugins';
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
        // The typed path's save: a second after the typing stops, on Enter, and when the field is
        // left — whichever comes first. It reads the last render's state, not the keystroke's: the
        // timer is set anew with every keystroke, so what it finds is what the field shows. A save
        // under way is not doubled — what is typed meanwhile is saved when the answer is in —, and
        // the field returns to the page set only when nothing was typed meanwhile.
        const cancelPathSave = () => { clearTimeout(pathTimer.current); pathTimer.current = null; };
        const savePath = () => {
            cancelPathSave();
            const { landing: now, landingCurrent: current, fieldBusy: busy } = latest.current;
            const value = now && now.choice === 'other' ? now.path.trim() : null;
            if (value === null || value === current || busy !== null) return;
            if (!value.startsWith('/')) {
                setFieldError({ landing_page: T.getLandingError() });
                return;
            }
            change('landing_page', { change: 'landing_page', value }).then((ok) => {
                const after = latest.current.landing;
                const typed = after && after.choice === 'other' ? after.path.trim() : null;
                if (typed === value) {
                    if (ok) setLanding(null);
                } else if (ok && typed !== null) {
                    schedulePathSave();
                }
            });
        };
        const schedulePathSave = () => { cancelPathSave(); pathTimer.current = setTimeout(savePath, PATH_SAVE_DELAY); };

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
        const advancedUrl = getWporgPluginUrl(slug) + 'advanced/';
        const svnUrl = 'https://plugins.svn.wordpress.org/' + encodeURIComponent(slug) + '/assets/blueprints/';
        const advancedLink = createElement('a', { href: advancedUrl, target: '_blank', rel: 'noreferrer', onClick: () => { advancedViewClicks[id] = Date.now(); } });

        // The introduction, above the zones in every state: one muted line, like the screenshots'
        // captions line — what the Live Preview does for the plugin page, and the tip that explains
        // the how, built for this plugin.
        const intro = createElement('div', { className: 'pblsh--live-preview__intro' },
            T.getIntroText(), ' · ', createElement(TipLink, { tipKey: 'livePreview', params: { slug } }));

        // Zone 1: the slot's band — what the commit will do, with its Discard, or where the file
        // stands on the plugin page; once wordpress.org has read it, the check of the public switch
        // beneath. The check keeps the last answer with its age while a new check runs,
        // "Checking…" in the link's place — nothing moves. Visitors not seeing what is on the page is the one
        // state that asks the user to act: the warning, the way there as its note; the public state
        // carries the way back the same way. Not while wordpress.org's
        // state is shown — like the images' boxes —, and not with nothing here, there or on the page:
        // unlike an image slot, the empty Live Preview is no slot in a grid, the empty state speaks
        // for itself.
        const renderCheck = () => {
            const parts = [
                preview && createInterpolateElement(T.getCheckedText(), { time: createElement(RelativeTime, { value: new Date(preview.checked_at * 1000).toISOString(), tooltip: true }) }),
                checking ? T.getCheckingText() : createElement(Button, { isLink: true, onClick: check }, T.getCheckAgainLabel()),
                !checking && checkFailed ? T.getCheckFailedText() : null,
            ].filter(Boolean);
            return createElement(Fragment, null, ...parts.flatMap((part, i) => i === 0 ? [part] : [' · ', part]));
        };
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
                detail,
                ...props,
            });
            if (!pending && !onWporg && !(slot && slot.shown)) return null;
            if (pending || !onWporg) return band({});
            if (onWporg.ignored) return band({ text: T.getIgnoredStatusText(), icon: 'alert_outline' });
            if (!imported) return band({});
            if (!preview) return band({}, renderCheck());
            if (preview.public) return band({ text: getShownText() + ' · ' + T.getPublicText(), note: createInterpolateElement(T.getSwitchOffText(), { a: advancedLink }) }, renderCheck());
            return band({
                warning: true,
                text: T.getNotPublicText(),
                note: createInterpolateElement(T.getSwitchText(), { a: advancedLink }),
            }, renderCheck());
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
        // The landing page as its fields in the box, always at hand, the one piece of advice above
        // them: the choice, and for a page of the plugin's own the path. A preset applies as it is
        // chosen; "Other page…" opens the path field on the page set, selected, and a typed path
        // saves a second after the typing stops, on Enter or when the field is left — whichever
        // comes first —, Escape returns to the page set. When the JSON sets the page in a form the
        // fields cannot read, the box says so, with the way to the editor.
        const renderLanding = () => {
            const field = view.fields.landing_page;
            const current = landingCurrent;
            const choice = landing ? landing.choice : T.getLandingChoice(field.value);
            const path = landing ? landing.path : (choice === 'other' ? current : '');
            const choose = (key) => {
                cancelPathSave();
                setFieldError({});
                if (key === 'other') {
                    focusField.current = 'path';
                    setLanding({ choice: 'other', path: current });
                    return;
                }
                if (T.getLandingPath(key) === current) {
                    setLanding(null);
                    return;
                }
                setLanding({ choice: key, path: '' });   // shown as chosen while it saves; back to the page set either way after
                change('landing_page', { change: 'landing_page', value: T.getLandingPath(key) }).then(() => setLanding(null));
            };
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('landing_page', __('Landing page', 'peak-publisher')),
                createElement('div', { className: 'pblsh--framed-box pblsh--live-preview__landing' },
                    createElement('div', { className: 'pblsh--live-preview__landing-lead' }, T.getLandingHint()),
                    !field.editable && createElement('div', { className: 'pblsh--live-preview__landing-fact' }, T.getSetInJsonNote(), editInExpertLink()),
                    field.editable && createElement(Fragment, null,
                        createElement('div', { className: 'pblsh--live-preview__controls' },
                            createElement(SelectControl, {
                                className: 'pblsh--live-preview__landing-choice',
                                __nextHasNoMarginBottom: true,
                                label: __('Landing page', 'peak-publisher'),
                                hideLabelFromVision: true,
                                value: choice,
                                options: T.getLandingOptions(),
                                disabled: locked,
                                onChange: choose,
                            }),
                            choice === 'other' && createElement(TextControl, {
                                ref: (el) => { fieldInputs.current.path = el; },
                                className: 'pblsh--live-preview__landing-path',
                                __nextHasNoMarginBottom: true,
                                label: __('Path', 'peak-publisher'),
                                hideLabelFromVision: true,
                                value: path,
                                disabled: locked && fieldBusy !== 'landing_page',
                                onChange: (typed) => { setLanding({ choice: 'other', path: typed }); setFieldError({}); if (fieldBusy === null) schedulePathSave(); },
                                onBlur: savePath,
                                onKeyDown: (e) => {
                                    if (e.key === 'Enter') { e.preventDefault(); savePath(); }
                                    if (e.key === 'Escape') { cancelPathSave(); setLanding(null); setFieldError({}); }
                                },
                            }),
                        ),
                        fieldError.landing_page && createElement('div', { className: 'pblsh--live-preview__field-error' }, fieldError.landing_page),
                    ),
                ),
            );
        };
        // The theme as a row — screenshot, name and slug as the directory lists it (view.theme: the
        // blueprint's wordpress.org theme, or Playground's default when it sets none), linked to
        // its page where there is one —, the fact beside it: the default, set in the JSON (with the
        // way to the editor), red when the directory lacks it; nothing to name for a theme from elsewhere or an unknown
        // default. Change opens the field beneath, focused with the slug selected — a slug, or the
        // theme's wordpress.org address (wporgSlugFromInput()); Enter or leaving saves, Escape
        // closes, an empty field returns to the default.
        const renderTheme = () => {
            const field = view.fields.theme;
            const theme = view.theme;
            const listing = theme && theme.listing ? theme.listing : null;
            const absent = listing !== null && listing.state !== 'ok';
            const editing = themeInput !== null;
            const next = editing ? (themeInput.trim() === '' ? '' : wporgSlugFromInput(themeInput, 'themes').slug) : null;
            const unchanged = next === (field.slug || '');
            const closeTheme = () => { setThemeInput(null); setFieldError({}); };
            const commitTheme = () => {
                if (!editing || unchanged) return;
                change('theme', { change: 'theme', value: next }).then((ok) => { if (ok) setThemeInput(null); });
            };
            const facts = [ absent ? T.getNotOnWporgText() : null, theme && theme.default ? T.getDefaultThemeNote() : null, !field.editable ? T.getSetInJsonNote() : null ].filter(Boolean);
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('theme', __('Theme', 'peak-publisher')),
                createElement('div', { className: 'pblsh--framed-box pblsh--live-preview__theme' },
                    createElement('table', { className: 'pblsh--framed-table pblsh--live-preview__theme-table' },
                        createElement('tbody', null,
                            createElement('tr', null,
                                theme && theme.slug && createElement('td', null, createElement(PluginIdentity, { kind: 'theme', image: listing ? listing.screenshot : null, name: listing ? listing.name || '' : '', slug: theme.slug, href: listing && listing.state === 'ok' ? getWporgThemeUrl(theme.slug) : null })),
                                createElement('td', { className: 'pblsh--live-preview__row-fact' + (absent ? ' pblsh--live-preview__row-fact--missing' : ''), colSpan: theme && theme.slug ? 1 : 2 }, facts.join(' · '), !field.editable && editInExpertLink()),
                            ),
                        ),
                        // The foot: the link, then the field with Change and Cancel, the hint and the server's
                        // answer under them. Enter changes, Escape cancels; leaving the field alone does nothing,
                        // so Cancel stays a cancel.
                        field.editable && !showingWporgState && createElement('tfoot', null,
                            createElement('tr', null,
                                createElement('td', { colSpan: 2, className: 'pblsh--live-preview__foot' },
                                    !editing && createElement(Button, { isLink: true, disabled: locked, onClick: () => { focusField.current = 'theme'; setThemeInput(field.slug || ''); } }, T.getChangeThemeLink()),
                                    editing && createElement(Fragment, null,
                                        createElement('div', { className: 'pblsh--live-preview__controls' },
                                            createElement(TextControl, {
                                                ref: (el) => { fieldInputs.current.theme = el; },
                                                __nextHasNoMarginBottom: true,
                                                label: __('Theme', 'peak-publisher'),
                                                hideLabelFromVision: true,
                                                placeholder: T.getThemePlaceholder(),
                                                value: themeInput,
                                                disabled: locked && fieldBusy !== 'theme',
                                                onChange: (value) => { setThemeInput(value); setFieldError({}); },
                                                onKeyDown: (e) => {
                                                    if (e.key === 'Enter') { e.preventDefault(); commitTheme(); }
                                                    if (e.key === 'Escape') closeTheme();
                                                },
                                            }),
                                            createElement(Button, { isSecondary: true, isBusy: fieldBusy === 'theme', disabled: locked || unchanged, onClick: commitTheme }, T.getChangeLabel()),
                                            createElement(Button, { isSecondary: true, disabled: fieldBusy === 'theme', onClick: closeTheme }, T.getCancelLabel()),
                                        ),
                                        createElement('div', { className: 'pblsh--live-preview__foot-hint' }, T.getThemeHint()),
                                    ),
                                    fieldError.theme && createElement('div', { className: 'pblsh--live-preview__field-error' }, fieldError.theme),
                                ),
                            ),
                        ),
                    ),
                ),
            );
        };
        // The plugins as rows in the install order, the plugin itself among them at its place:
        // icon, name and slug as the directory lists them (view.listings; the slug alone without a
        // listing), linked to the page where there is one, the fact beside them — a required
        // plugin the blueprint has but not active before the plugin's own step reads as that, one
        // it lacks altogether stands right before the plugin's own row as not installed — where
        // Add would put it —, another plugin the directory lacks as that, whatever else is true
        // of it; the plugin itself keeps its fact (the test button says when its page is not
        // there yet). A plugin with a known activation redirect says whether a step drops it —
        // open, it keeps an admin landing page from showing: the warning with the step's link,
        // only while the landing page is one. A required plugin the JSON lacks is added from its row,
        // one it installs only after the plugin moved before it, a plugin the JSON adds alone taken
        // out. The table's foot adds one, in steps: the link, the one question — a plugin the plugin needs
        // belongs in the Requires Plugins header, the guidance says so —, and only after "no" the
        // field for the slug or address; the plugin is added before the plugin itself.
        const renderPlugins = () => {
            const missing = view.missing;
            const entries = view.fields.plugins;
            const absent = missing.filter((name) => !entries.some((plugin) => plugin.slug === name));
            const adminLanding = typeof view.fields.landing_page.value === 'string' && view.fields.landing_page.value.startsWith('/wp-admin');
            const fact = (plugin, index) => plugin.own
                ? T.getOwnPluginNote(index === entries.length - 1)
                : missing.includes(plugin.slug) ? T.getNotActiveBeforeNote(required) : T.getPluginNote(plugin, required);
            const listings = view.listings || {};
            const closeAdd = () => { setAddStep(null); setAddSlug(''); setFieldError({}); };
            const addPlugin = () => {
                const { slug } = wporgSlugFromInput(addSlug, 'plugins');
                if (slug === '') return;
                change('plugins', { change: 'plugin_add', value: slug }).then((ok) => { if (ok) closeAdd(); });
            };
            // One row: the identity, the fact beside it, and the step out of a stop where there is one
            // — Add (a required plugin the JSON lacks), Move before your plugin (one it installs only
            // after the plugin, or without activating it), Remove (one the JSON adds alone). Playground
            // installs nothing the directory lacks: such a plugin is only ever taken out.
            const row = (key, name, fact, { stops = false, own = false, redirect = null, fix = null }) => {
                const listing = listings[name] || null;
                const absent = !own && listing !== null && listing.state !== 'ok';
                const step = {
                    add: absent ? null : [ T.getAddPluginLabel(), 'plugin_add' ],
                    move: absent ? null : [ T.getMoveBeforeLabel(), 'plugin_add' ],
                    remove: [ T.getRemovePluginLabel(), 'plugin_remove' ],
                }[fix] || null;
                return createElement('tr', { key },
                    createElement('td', null, createElement(PluginIdentity, { image: listing ? listing.icon : null, name: listing ? listing.name || '' : '', slug: name, href: listing && listing.state === 'ok' ? getWporgPluginUrl(name) : null })),
                    createElement('td', { className: 'pblsh--live-preview__row-fact' + (stops || absent ? ' pblsh--live-preview__row-fact--missing' : '') },
                        absent ? T.getNotOnWporgText() : fact,
                        !absent && redirect === 'skipped' && createElement(Fragment, null, ' · ', T.getRedirectSkippedNote()),
                        step && !showingWporgState && createElement(Fragment, null, ' · ',
                            createElement(Button, { isLink: true, disabled: locked, onClick: () => change('plugins', { change: step[1], value: name }) }, step[0])),
                        !absent && redirect === 'open' && adminLanding && createElement('div', { className: 'pblsh--live-preview__row-fact-warning' },
                            T.getRedirectOpenNote(), ' ',
                            createElement(Button, { isLink: true, isBusy: fieldBusy === 'redirect_skip', disabled: locked, onClick: () => change('redirect_skip', { change: 'redirect_skip', value: name }) }, T.getSkipRedirectLabel()),
                        ),
                    ),
                );
            };
            return createElement('div', { className: 'pblsh--live-preview__field' },
                renderFieldLabel('plugins', __('Plugins', 'peak-publisher')),
                createElement('div', { className: 'pblsh--framed-box pblsh--live-preview__plugins' },
                    createElement('table', { className: 'pblsh--framed-table pblsh--live-preview__plugins-table' },
                        createElement('tbody', null,
                            ...entries.flatMap((plugin, i) => [
                                ...(plugin.own ? absent.map((name) => row('missing-' + name, name, T.getNotInstalledNote(required), { stops: true, fix: 'add' })) : []),
                                row(plugin.slug + i, plugin.slug, fact(plugin, i), { stops: !plugin.own && missing.includes(plugin.slug), own: plugin.own, redirect: plugin.redirect,
                                    fix: plugin.own ? null : missing.includes(plugin.slug) ? 'move' : !plugin.required ? 'remove' : null }),
                            ]),
                        ),
                        !showingWporgState && createElement('tfoot', null,
                            createElement('tr', null,
                                createElement('td', { colSpan: 2, className: 'pblsh--live-preview__foot' },
                                    addStep === null && createElement(Button, { isLink: true, disabled: locked, onClick: () => setAddStep('ask') }, T.getAddPluginLink()),
                                    addStep === 'ask' && createElement(Fragment, null,
                                        createElement('div', { className: 'pblsh--live-preview__foot-question' }, T.getNeedQuestion()),
                                        createElement('div', { className: 'pblsh--live-preview__foot-actions' },
                                            createElement(Button, { isSecondary: true, onClick: () => setAddStep('guide') }, T.getNeedYes()),
                                            createElement(Button, { isSecondary: true, onClick: () => { focusField.current = 'add'; setAddStep('slug'); } }, T.getNeedNo()),
                                            createElement(Button, { isLink: true, onClick: closeAdd }, T.getCancelLabel()),
                                        ),
                                    ),
                                    addStep === 'guide' && createElement(Fragment, null,
                                        createElement('div', { className: 'pblsh--live-preview__foot-question' }, T.getNeedGuidanceTitle()),
                                        createElement('div', { className: 'pblsh--live-preview__foot-text' },
                                            createInterpolateElement(T.getNeedGuidance(), { a: createElement('a', { href: T.getRequiresPluginsHeaderUrl(), target: '_blank', rel: 'noreferrer' }) })),
                                        createElement('div', { className: 'pblsh--live-preview__foot-actions' },
                                            createElement(Button, { isSecondary: true, onClick: closeAdd }, T.getOkLabel())),
                                    ),
                                    addStep === 'slug' && createElement('div', { className: 'pblsh--live-preview__controls' },
                                        createElement(TextControl, {
                                            ref: (el) => { fieldInputs.current.add = el; },
                                            __nextHasNoMarginBottom: true,
                                            label: T.getAddPluginPlaceholder(),
                                            hideLabelFromVision: true,
                                            placeholder: T.getAddPluginPlaceholder(),
                                            value: addSlug,
                                            disabled: locked && fieldBusy !== 'plugins',
                                            onChange: (value) => { setAddSlug(value); setFieldError({}); },
                                            onKeyDown: (e) => {
                                                if (e.key === 'Enter') { e.preventDefault(); addPlugin(); }
                                                if (e.key === 'Escape') closeAdd();
                                            },
                                        }),
                                        createElement(Button, { isSecondary: true, isBusy: fieldBusy === 'plugins', disabled: locked || wporgSlugFromInput(addSlug, 'plugins').slug === '', onClick: addPlugin }, T.getAddPluginLabel()),
                                        createElement(Button, { isSecondary: true, disabled: fieldBusy === 'plugins', onClick: closeAdd }, T.getCancelLabel()),
                                    ),
                                    // The answer to an add or a remove the server refused, under the foot's controls.
                                    fieldError.plugins && createElement('div', { className: 'pblsh--live-preview__field-error' }, fieldError.plugins),
                                ),
                            ),
                        ),
                    ),
                ),
            );
        };
        // What the preview installs first — the theme, the plugins —, then where visitors start,
        // and beneath what no field shows, with the way to the editor.
        const renderFields = () => createElement(Fragment, null,
            renderTheme(),
            renderPlugins(),
            renderLanding(),
            view.fields.more.length > 0 && createElement('div', { className: 'pblsh--live-preview__more' }, T.getMoreText(view.fields.more), editInExpertLink()),
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
        // The box: its head names the file by its path in SVN, the editor fills the body. Under the
        // box the save line at the left, and at the right wordpress.org's guide — the one the tip links to.
        const renderEditor = () => createElement('div', { className: 'pblsh--live-preview__editor' },
            createElement('div', { className: 'pblsh--live-preview__editor-box' },
                createElement('div', { className: 'pblsh--live-preview__editor-head' }, createElement('code', null, T.getBlueprintPath())),
                createElement('div', { className: 'pblsh--live-preview__editor-body' },
                    createElement(BlueprintEditor, { value: editorValue, onChange: onEditorChange, readOnly: readOnly || fieldBusy !== null, placeholder: T.getPastePlaceholder(), label: __('Blueprint JSON', 'peak-publisher') })),
            ),
            createElement('div', { className: 'pblsh--live-preview__editor-foot' },
                createElement('div', { className: 'pblsh--live-preview__save' + (saveError ? ' pblsh--live-preview__save--error' : '') },
                    T.getSaveLineText(saveState, saveDetail),
                    saveState === 'elsewhere' && createElement(Fragment, null, ' ', createElement(Button, { isLink: true, onClick: () => Draft.loadElsewhere(id) }, T.getLoadElsewhereLabel())),
                    (saveState === 'invalid' || saveState === 'refused') && createElement(Fragment, null, ' ', createElement(Button, { isLink: true, onClick: () => Draft.drop(id) }, T.getUndoLabel())),
                ),
                external(T.getGuideUrl(), T.getGuideLabel() + ' ↗'),
            ),
        );
        // The main row: the fields' column, headed by the switch at its right end, and in expert
        // mode the JSON in a second column of the same width, from the switch's line down. Without
        // fields (no JSON object) the editor stands alone beneath the switch.
        const renderMain = () => createElement('div', { className: 'pblsh--live-preview__main' + (showEditor && !forcedExpert ? ' pblsh--live-preview__main--two-columns' : '') },
            createElement('div', { className: 'pblsh--live-preview__fields' },
                createElement('div', { className: 'pblsh--live-preview__main-head' },
                    createElement(ToggleControl, { __nextHasNoMarginBottom: true, label: T.getExpertLabel(), checked: showEditor, disabled: forcedExpert, onChange: toggleExpert })),
                !forcedExpert && renderFields(),
            ),
            showEditor && renderEditor(),
        );

        // Zone 4: the actions on the blueprint in effect — the test of the working copy, the menu.
        // Setting up is no action on it: that button stands outside the box. Nothing unsaved is
        // tested: not while the JSON draft is being edited, a path typed or a field saved — the
        // button stays locked until the fresh test link is here; the field's label line says so
        // while it saves. The theme's and the plugins' fields save only on Enter or their button:
        // typing there holds nothing.
        const testBlocked = draft !== null || saving || fieldBusy !== null || landingDirty || !view.test.url;
        const testButton = createElement(Button, {
            isPrimary: true,
            ...(testBlocked ? { disabled: true } : { href: view.test.url, target: '_blank', rel: 'noreferrer' }),
        }, T.getTestLabel() + ' ↗');
        const renderSetUp = () => createElement('div', { className: 'pblsh--live-preview__setup' },
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
        // Preview has a state — a band, or a blueprint in effect while wordpress.org's state is
        // shown. Half the section's width with one column, the whole width with two: the JSON
        // beside the fields, or the conflict's two files.
        const band = renderBand();
        const box = (wide, ...body) => createElement('div', { className: 'pblsh--assets-box pblsh--live-preview__box' + (wide ? ' pblsh--live-preview__box--wide' : '') },
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
                box(true,
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
            (band || hasBlueprint) && box(hasBlueprint && showEditor,
                ...renderNotices(),
                hasBlueprint && renderMain(),
                hasBlueprint && renderActions(),
                footer,
            ),
            !hasBlueprint && renderSetUp(),
        );
    };
})());
