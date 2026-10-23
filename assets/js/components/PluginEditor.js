// PluginEditor Component (simplified overview + releases list)
lodash.set(window, 'Pblsh.Components.PluginEditor', ({ pluginData, refreshPlugin, refreshWporg, onTogglePluginStatus, pendingPluginStatus, initialTab, onTabChange, initialSection, onSectionChange }) => {
    const { __, _n, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip, Button, Spinner, TextControl } = wp.components;
    const { getSvgIcon, getPluginStatus } = Pblsh.Utils;
    const { getCurrentReleaseIssue, getFlipConfirmText, getFlipSuccessText } = Pblsh.CurrentReleaseUtils;
    const { getFieldError, getFieldHint, describeSource, describeMissingValue, getStatusItem, isLower } = Pblsh.TestedUpToUtils;
    const stableBranch = Pblsh.Utils.getStableBranch();
    const { getDownloads, getRating, getWporgClosedNotice } = Pblsh.InstallationsUtils;
    const { getChangesText } = Pblsh.WporgCheckUtils;
    const { NoticeBox, CurrentVersion, InstallationsCount, Figure, WporgCheckStatus, WporgRevisionLink, RelativeTime } = Pblsh.Components;

    const safe = (val) => (val === undefined || val === null) ? '' : val;
    const isWporg = pluginData && pluginData.hosting_type === 'wporg';
    // The releases table has per-version figures only self-hosted, and only while the
    // counting is on — read off the plugin's installations state, never the setting.
    const showReleaseInstallations = !!pluginData && !isWporg && pluginData.installations.state === 'ok';
    // The one gate for every wordpress.org write action (flip, delete): a usable account
    // (wporg_account.can_write; the list payload has no account, so undefined until the
    // detail is loaded — off then too, without a reason to show).
    const wporgAccount = isWporg && pluginData ? pluginData.wporg_account : null;
    const wporgWriteBlocked = isWporg && !(wporgAccount && wporgAccount.can_write);
    const wporgWriteBlockedText = wporgAccount === undefined ? null : __('No usable wordpress.org account — connect one under Settings › wordpress.org.', 'peak-publisher');

    const [downloadingReleaseId, setDownloadingReleaseId] = useState(null);
    const downloadingReleaseIdRef = useRef(null);
    // The flip in progress (its ring shows the spinner, every ring is locked) and the last
    // flip's outcome { version, revision } behind the transient success notice, which the
    // next plugin change clears.
    const [flippingReleaseId, setFlippingReleaseId] = useState(null);
    const [flipOutcome, setFlipOutcome] = useState(null);
    useEffect(() => { setFlipOutcome(null); }, [pluginData && pluginData.id]);
    // Tested up to, edited in its row: which release's field is open with its value, which
    // release is saving, and the last edit's outcome behind the transient notice — cleared
    // with the next plugin like the flip's. The field's error follows the value.
    const [editingReleaseId, setEditingReleaseId] = useState(null);
    const [editingValue, setEditingValue] = useState('');
    const [savingReleaseId, setSavingReleaseId] = useState(null);
    const [testedOutcome, setTestedOutcome] = useState(null);
    useEffect(() => { setTestedOutcome(null); setEditingReleaseId(null); }, [pluginData && pluginData.id]);
    // An empty field is not wrong yet — the format sentence comes with the first character.
    const editingError = editingReleaseId !== null && editingValue.trim() !== '' ? getFieldError(editingValue.trim(), stableBranch) : null;
    // The manual refresh from wordpress.org: while it runs, its link is busy and the status
    // line says so — for everything it brings, figures, releases and assets alike; what is
    // shown stays until the answer replaces it. Its outcome shows through the data: the status
    // line reads "just now", a check that failed stands beside it, and what changed on
    // wordpress.org is told in the notice.
    const [refreshingWporg, setRefreshingWporg] = useState(false);
    const wporgChanges = useSelect(
        (select) => pluginData && pluginData.id ? select('pblsh/plugins').getWporgChanges(pluginData.id) : null,
        [pluginData && pluginData.id]
    );
    const validTabs = ['releases', 'assets'];
    const [activeTab, setActiveTab] = useState(initialTab && validTabs.includes(initialTab) ? initialTab : 'releases');

    // The assets tab's section (Pblsh.AssetsUtils.getAssetSections()) — held here, so a notice
    // above the tabs can lead into one. Which sections the plugin has (self-hosted: no Live
    // Preview) is known once the plugin is, and the editor mounts before it is loaded: a deep
    // link to a section this plugin has not falls back to the first at that moment, and the URL
    // follows.
    const [assetsSection, setAssetsSection] = useState(initialSection || 'icons');
    const switchToTab = (tab) => {
        if (!validTabs.includes(tab)) tab = 'releases';
        setActiveTab(tab);
        if (typeof onTabChange === 'function') onTabChange(tab);
        if (tab === 'assets' && typeof onSectionChange === 'function') onSectionChange(assetsSection);
    };
    const switchToSection = (section) => {
        setAssetsSection(section);
        if (typeof onSectionChange === 'function') onSectionChange(section);
    };
    const channel = pluginData ? pluginData.hosting_type : null;
    useEffect(() => {
        if (channel && !Pblsh.AssetsUtils.getAssetSections(isWporg).some((candidate) => candidate.key === assetsSection)) switchToSection('icons');
    }, [channel]);
    const openLivePreview = () => {
        switchToTab('assets');
        switchToSection('live-preview');
    };

    // Prefer releases from store (keeps UI in sync on toggles), fallback to pluginData.releases
    const releasesFromStore = useSelect(
        (select) => {
            const pid = pluginData && pluginData.id ? pluginData.id : null;
            if (!pid) return [];
            return select('pblsh/releases').getForPlugin(pid);
        },
        [pluginData && pluginData.id]
    );
    // Subscribed, not read ad hoc: an empty list that finishes loading changes nothing but this flag.
    const releasesLoaded = useSelect(
        (select) => !!(pluginData && pluginData.id) && select('pblsh/releases').hasLoadedForPlugin(pluginData.id),
        [pluginData && pluginData.id]
    );

    // The Refresh link: this plugin's figures and its state straight from SVN, whatever the
    // directory says — it lags SVN by wordpress.org's import —, tabs included (refreshWporg).
    // The data says the rest; a failure of the request itself is reported like the flip's.
    const refreshFromWporg = async () => {
        if (!pluginData || refreshingWporg) return;
        setRefreshingWporg(true);
        try {
            await refreshWporg(pluginData.id);
        } catch (e) {
            alert((e?.message || __('Could not refresh from wordpress.org.', 'peak-publisher'))
                + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
        } finally {
            setRefreshingWporg(false);
        }
    };

    const renderInfoBox = () => {
        // The figures beside Installations: the downloads of both channels, the rating of
        // wordpress.org; each names its source and its age in the tooltip.
        const downloads = pluginData ? getDownloads(pluginData) : null;
        const wporgFigures = isWporg ? { rating: getRating(pluginData) } : null;
        return [
            createElement('div', { className: 'pblsh--card pblsh--card--plugin-info' },
                createElement('div', { className: 'pblsh--plugin-header' },
                    createElement('div', { className: 'pblsh--plugin-header__main' },
                        pluginData?.icon_url ? createElement('img', {
                            className: 'pblsh--plugin-header__icon',
                            src: pluginData.icon_url,
                            alt: '',
                            width: 80,
                            height: 80,
                        }) : null,
                        createElement('div', null,
                            // The view's title: it docks into the page header once it has scrolled under it (admin.js).
                            createElement('h3', { className: 'pblsh--plugin-title', 'data-pblsh-title': '' }, pluginData?.name),
                            createElement('div', { className: 'pblsh--plugin-meta' },
                                createElement(Pblsh.Components.ChannelPath, { channel: pluginData?.hosting_type, slug: safe(pluginData?.slug) || '—' }),
                            ),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-header__actions' },
                        createElement(Button, {
                            isTertiary: true,
                            className: 'pblsh--status-btn pblsh--status-btn--' + getPluginStatus(pluginData?.status).modifier,
                            label: getPluginStatus(pluginData?.status).label,
                            icon: getSvgIcon('circle'),
                            isBusy: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id),
                            disabled: isWporg || (Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id)),
                            onClick: () => {
                                if (isWporg) return;
                                if (typeof onTogglePluginStatus === 'function' && pluginData?.id) {
                                    const next = pluginData.status === 'publish' ? 'draft' : 'publish';
                                    onTogglePluginStatus(pluginData.id, next);
                                }
                            },
                        }, getPluginStatus(pluginData?.status).label),
                    ),
                ),
                createElement('div', { className: 'pblsh--plugin-grid' },
                    createElement('div', { className: 'pblsh--plugin-grid__item' },
                        createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Current Release', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--plugin-grid__value' }, createElement(CurrentVersion, { plugin: pluginData })),
                        // Second line of a grid item: muted fragments, ' · ' separated, no period.
                        // Only when the latest release is not the current one.
                        pluginData?.latest_version && pluginData.latest_version !== pluginData.version && createElement('div', { className: 'pblsh--plugin-grid__hint' },
                            sprintf(__('Latest: %s', 'peak-publisher'), pluginData.latest_version)
                        ),
                    ),
                    // Not shown while the counting is switched off (a deliberate choice —
                    // the setting defaults to on); wordpress.org figures are always there.
                    pluginData && pluginData.installations.state !== 'disabled' && createElement('div', { className: 'pblsh--plugin-grid__item' },
                        createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Installations', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--plugin-grid__value' }, createElement(InstallationsCount, { plugin: pluginData })),
                    ),
                    downloads && createElement('div', { className: 'pblsh--plugin-grid__item' },
                        createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Downloads', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--plugin-grid__value' },
                            createElement(Figure, { title: downloads.title }, downloads.text),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-grid__item' },
                        createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Tested up to', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--plugin-grid__value' }, renderTestedUpToFigure()),
                    ),
                    // Stars with the number of ratings beside them, the way the plugin installer
                    // shows a rating — the count qualifies the stars; the tooltip spells both out.
                    wporgFigures && createElement('div', { className: 'pblsh--plugin-grid__item' },
                        createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Rating', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--plugin-grid__value' },
                            createElement(Figure, { title: wporgFigures.rating.title },
                                wporgFigures.rating.stars
                                    ? createElement('span', { className: 'pblsh--rating-stars', 'aria-hidden': 'true' },
                                        ...wporgFigures.rating.stars.map((icon, index) => createElement('span', { key: index, className: 'pblsh--rating-stars__star' }, getSvgIcon(icon, { size: 32 }))),
                                        createElement('span', { className: 'pblsh--rating-stars__count' }, '(' + wporgFigures.rating.count + ')'),
                                    )
                                    : wporgFigures.rating.text,
                            ),
                        ),
                    ),
                ),
            ),
        ];
    };

    // Makes another release current: confirm, request with the pointer the editor showed,
    // reload, transient notice. A stale pointer (someone else flipped) is reported and the
    // plugin reloaded — never overwritten.
    const makeCurrent = async (rel, relation) => {
        if (!pluginData || flippingReleaseId !== null) return;
        // A flip brings the release's Requires Plugins into the Live Preview, in the same commit.
        const carry = isWporg && rel.live_preview_missing && rel.live_preview_missing.length > 0 ? '\n' + Pblsh.LivePreviewUtils.getFlipCarryText(rel.live_preview_missing) : '';
        if (!confirm(getFlipConfirmText(isWporg, rel.version, relation, isWporg ? pluginData.wporg_stats.closed : null) + carry)) return;
        setFlipOutcome(null);
        setFlippingReleaseId(rel.id);
        try {
            const response = await Pblsh.API.setCurrentRelease(pluginData.id, rel.version, pluginData.pointer);
            if (typeof refreshPlugin === 'function') await refreshPlugin();
            setFlipOutcome({ version: response.to, revision: response.revision, added: response.live_preview_added || [] });
        } catch (e) {
            // apiFetch rejects with the REST error payload: message leads, the code follows.
            alert((e?.message || __('Could not change the current release.', 'peak-publisher'))
                + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
            if (e?.code === 'current_release_changed' && typeof refreshPlugin === 'function') refreshPlugin();
        } finally {
            setFlippingReleaseId(null);
        }
    };

    // Tested up to of a release, edited in its row: the field opens with the value shown and
    // saves with that value as the expectation — a change by someone else in the meantime is
    // refused, not overwritten —, then the plugin reloads and the notice tells the outcome.
    const startTestedUpTo = (rel) => {
        if (savingReleaseId !== null) return;
        setEditingReleaseId(rel.id);
        setEditingValue(rel.tested_up_to.value || '');
    };
    const cancelTestedUpTo = () => {
        setEditingReleaseId(null);
        setEditingValue('');
    };
    const saveTestedUpTo = async (rel) => {
        const value = editingValue.trim();
        if (savingReleaseId !== null || getFieldError(value, stableBranch) || value === (rel.tested_up_to.value || '')) return;
        // A value above the next WordPress version is allowed, as at upload — with the same
        // caution: the hint under the field, and a confirm before it is written.
        const hint = getFieldHint(value, rel.tested_up_to.source, stableBranch, isWporg);
        if (hint && !confirm(hint.join('\n') + '\n\n' + sprintf(__('Set Tested up to %1$s for version %2$s anyway?', 'peak-publisher'), value, rel.version))) return;
        setTestedOutcome(null);
        setSavingReleaseId(rel.id);
        try {
            const response = await Pblsh.API.setReleaseTestedUpTo(rel.id, value, rel.tested_up_to.value || '');
            setEditingReleaseId(null);
            if (typeof refreshPlugin === 'function') await refreshPlugin();
            setTestedOutcome(response);
        } catch (e) {
            // apiFetch rejects with the REST error payload: message leads, the code follows.
            alert((e?.message || __('Could not set Tested up to.', 'peak-publisher'))
                + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
            // The value moved, the tag or the ZIP is gone: what the row shows is stale.
            if (['tested_up_to_changed', 'invalid_tested_up_to_target', 'wporg_tag_not_found', 'release_zip_missing'].includes(e?.code)) {
                setEditingReleaseId(null);
                if (typeof refreshPlugin === 'function') refreshPlugin();
            }
        } finally {
            setSavingReleaseId(null);
        }
    };

    // Tested up to of what sites receive, beside the other figures: the value with a state
    // glyph whose tooltip says what to do (utils-tested-up-to.js), "—" without one; the
    // value's source in the tooltip. While wordpress.org distributes trunk, trunk's value.
    const renderTestedUpToFigure = () => {
        const tested = pluginData?.tested_up_to;
        const item = tested ? getStatusItem(pluginData, stableBranch) : null;
        let text = '—';
        let title;
        if (!tested) {
            title = getCurrentReleaseIssue(pluginData)?.fact || __('No current release.', 'peak-publisher');
        } else if (!tested.value) {
            // The judgement on both channels; only wordpress.org warns about it (the item).
            title = describeMissingValue(pluginData);
        } else {
            text = tested.value;
            title = [ describeSource(tested.source), item && item.fact ].filter(Boolean).join(' — ');
        }
        return createElement(Figure, { title },
            createElement('span', { className: 'pblsh--tested-figure' + (item ? ' pblsh--tested-figure--' + item.variant : '') },
                text,
                item && createElement('span', { className: 'pblsh--tested-figure__glyph' }, getSvgIcon('alert', { size: 16 })),
            ),
        );
    };

    // The Tested up to cell of a release row: the value (its source in the tooltip) with the
    // pencil, or the inline field with Save and Cancel — Enter saves, Escape cancels; the
    // field says what it will not accept, when the value goes down, and that emptying it
    // removes nothing. The current release's
    // row repeats the header figure's glyph right before its pencil: the figure says something
    // is wrong, the row is where it is fixed.
    const renderTestedUpToCell = (rel) => {
        const tested = rel.tested_up_to || { value: '', source: null };
        if (editingReleaseId === rel.id) {
            const value = editingValue.trim();
            const fieldHint = value ? getFieldHint(value, tested.source, stableBranch, isWporg) : null;
            // Two tiers: the controls in a row, and below them, each on a line of its own, what
            // the field has to say.
            return createElement('form', { className: 'pblsh--tested-edit', onSubmit: (e) => { e.preventDefault(); saveTestedUpTo(rel); } },
                createElement('div', { className: 'pblsh--tested-edit__controls' },
                createElement(TextControl, {
                    className: 'pblsh--tested-edit__input',
                    label: sprintf(__('Tested up to of version %s', 'peak-publisher'), rel.version),
                    hideLabelFromVision: true,
                    value: editingValue,
                    onChange: setEditingValue,
                    autoFocus: true,
                    onKeyDown: (e) => { if (e.key === 'Escape') cancelTestedUpTo(); },
                    __nextHasNoMarginBottom: true,
                    __next40pxDefaultSize: true,
                }),
                createElement(Button, { isPrimary: true, type: 'submit', isBusy: savingReleaseId === rel.id, disabled: savingReleaseId !== null || !!editingError || value === '' || value === (tested.value || '') }, __('Save', 'peak-publisher')),
                createElement(Button, { isTertiary: true, onClick: cancelTestedUpTo, disabled: savingReleaseId !== null }, __('Cancel', 'peak-publisher')),
                ),
                editingError && createElement('span', { className: 'pblsh--tested-edit__error' }, editingError),
                !editingError && value && fieldHint && createElement(NoticeBox, { variant: 'warning', className: 'pblsh--tested-edit__notice' }, createElement('p', null, fieldHint[0], createElement('br'), fieldHint[1])),
                !editingError && value && isLower(value, tested.value) && createElement('span', { className: 'pblsh--tested-edit__hint' }, __('Lower than before.', 'peak-publisher')),
                // A value is not removed here; the field says so instead of saving nothing.
                // Self-hosted it adds the way out — this release uploaded again without it, which
                // the checklist lets through with a receipt; on wordpress.org a plugin without the
                // value is a state the directory warns visitors about, so no guidance toward it.
                value === '' && tested.value && createElement('span', { className: 'pblsh--tested-edit__hint' },
                    __('Tested up to cannot be removed here.', 'peak-publisher'),
                    !isWporg && ' ' + __('Upload this release again without it.', 'peak-publisher')),
            );
        }
        // Nowhere to write: no readme, and the plugin file carries no value either (a line with
        // an empty value is invisible here — the server would still set it). The tooltip names
        // no file: it says that a value cannot be added to this release — elsewhere the pencil
        // adds the readme line — and the way out, on either channel.
        const nowhere = rel.readme_found === false && !tested.header;
        const editLabel = nowhere
            ? __('Tested up to cannot be added to this release. Upload it again with a value.', 'peak-publisher')
            : (wporgWriteBlocked && wporgWriteBlockedText ? wporgWriteBlockedText : sprintf(__('Set Tested up to of version %s', 'peak-publisher'), rel.version));
        const item = rel.is_current ? getStatusItem(pluginData, stableBranch) : null;
        return createElement('span', { className: 'pblsh--tested-value' + (item ? ' pblsh--tested-value--' + item.variant : '') },
            tested.value
                ? createElement(Tooltip, { text: describeSource(tested.source) }, createElement('span', { className: 'pblsh--tested-value__text', tabIndex: 0 }, tested.value))
                : createElement('span', { className: 'pblsh--tested-value__text' }, '—'),
            item && createElement(Tooltip, { text: item.fact },
                createElement('span', { className: 'pblsh--tested-value__glyph', tabIndex: 0, role: 'img', 'aria-label': item.fact },
                    getSvgIcon('alert', { size: 16 }),
                ),
            ),
            createElement(Button, {
                isTertiary: true,
                className: 'pblsh--tested-value__edit',
                icon: getSvgIcon('pencil', { size: 20 }),
                label: editLabel,
                showTooltip: true,
                __experimentalIsFocusable: true,
                disabled: nowhere || wporgWriteBlocked || savingReleaseId !== null,
                onClick: () => startTestedUpTo(rel),
            }),
        );
    };

    // Notices above the releases table, in this order: transient success → nothing is
    // distributed (closed on wordpress.org, or a self-hosted draft) → the pointer state
    // (no current release) → the Tested up to of what is distributed. Each problem the
    // list's status column names has its notice here — the fact, then the remedy.
    const renderReleaseNotices = () => {
        const issue = getCurrentReleaseIssue(pluginData);
        const tested = getStatusItem(pluginData, stableBranch);
        const reopenedNotices = (issue ? 1 : 0) + (tested ? 1 : 0);
        const closed = isWporg ? pluginData.wporg_stats.closed : null;
        const draft = pluginData?.hosting_type === 'self_hosted' && pluginData.status !== 'publish';
        return [
            // The last Tested up to edit — the value, the version, on wordpress.org the commit.
            // Whether trunk followed is not said: wordpress.org reads the value from the tag the
            // Stable tag names, so trunk's own value changes nothing the user sees.
            testedOutcome && createElement(NoticeBox, { key: 'tested', variant: 'info', className: 'pblsh--tab-panel__notice' },
                createElement('p', null, isWporg
                    ? createInterpolateElement(sprintf(__('Tested up to set to %1$s for version %2$s in <revision /> — sites see it with their next update check once wordpress.org has processed the commit, usually within a few minutes.', 'peak-publisher'), testedOutcome.to, testedOutcome.version), { revision: createElement(WporgRevisionLink, { revision: testedOutcome.revision }) })
                    : sprintf(__('Tested up to set to %1$s for version %2$s — sites see it with their next update check.', 'peak-publisher'), testedOutcome.to, testedOutcome.version)),
            ),
            // The self-hosted sentence has no <revision /> slot: the link stays unrendered there.
            // A wordpress.org flip that brought Requires Plugins into the Live Preview says so.
            flipOutcome && createElement(NoticeBox, { key: 'flip', variant: 'info', className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    createInterpolateElement(getFlipSuccessText(isWporg, flipOutcome.version), { revision: createElement(WporgRevisionLink, { revision: flipOutcome.revision }) }),
                    flipOutcome.added.length > 0 ? ' ' + Pblsh.LivePreviewUtils.getFlipCarriedText(flipOutcome.added) : null,
                ),
            ),
            // Closed on wordpress.org — the most important fact of the daily stats fetch:
            // nothing is distributed, whatever the pointer says. Red, above everything else,
            // and — when the pointer's or the Tested up to notice follows — the line that says
            // they apply only once the plugin is reopened, so they do not contradict each other.
            closed && createElement(NoticeBox, { key: 'closed', variant: 'error', className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    getWporgClosedNotice(closed),
                    ...(reopenedNotices > 0 ? [ createElement('br'), _n('The notice below applies once it is reopened.', 'The notices below apply once it is reopened.', reopenedNotices, 'peak-publisher') ] : []),
                ),
            ),
            // A draft is the self-hosted way of distributing nothing — chosen, and undone
            // with the status button right above, which the remedy names.
            draft && createElement(NoticeBox, { key: 'draft', variant: 'warning', className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    __('This plugin is a draft. Sites get no updates and cannot install it while it is a draft.', 'peak-publisher'),
                    createElement('br'),
                    createElement('strong', null, __('Make it public with the status button above.', 'peak-publisher')),
                ),
            ),
            // The fact, then the remedy emphasized on its own line — one paragraph, as the box
            // holds a single thought.
            issue && createElement(NoticeBox, { key: 'current-release', variant: issue.variant, className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    issue.fact,
                    ...(issue.consequence ? [ createElement('br'), issue.consequence ] : []),
                    ...(issue.remedy ? [ createElement('br'), createElement('strong', null, issue.remedy) ] : []),
                ),
            ),
            // The same anatomy for Tested up to, with the consequence as the checklist says it on
            // a line of its own between; the glyphs at the figure and in the current release's
            // row stay, as the pointer's glyph does beside its notice.
            tested && createElement(NoticeBox, { key: 'tested-up-to', variant: tested.variant, className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    tested.fact,
                    ...(tested.consequence ? [ createElement('br'), tested.consequence ] : []),
                    ...(tested.remedy ? [ createElement('br'), createElement('strong', null, tested.remedy) ] : []),
                ),
            ),
        ];
    };

    const renderReleasesTable = () => {
        const releases = Array.isArray(releasesFromStore) ? releasesFromStore : [];
        // A draft (self-hosted) or closed (wordpress.org) plugin distributes nothing; its
        // current release is what sites get once it does.
        const distributesNothing = pluginData?.status !== 'publish';
        const currentWhenTooltip = pluginData?.status === 'closed'
            ? __('Current once the plugin is reopened on wordpress.org', 'peak-publisher')
            : __('Current when the plugin is public', 'peak-publisher');
        // The list is sorted by version, descending: rows above the current one are higher
        // versions, rows below lower — the confirm's wording needs no version comparison.
        const currentIndex = releases.findIndex((rel) => rel.is_current);
        const relationToCurrent = (index) => currentIndex === -1 ? 'none' : (index < currentIndex ? 'higher' : 'lower');
        return [
            ...renderReleaseNotices(),
            createElement('div', { key: 'table', className: 'pblsh--table-container' },
                // A spinner only until the first list is in; a reload keeps the list it replaces.
                !releasesLoaded ?
                    createElement('div', { className: 'pblsh--loading pblsh--loading--table' },
                        createElement('div', { className: 'pblsh--loading__spinner' })
                    )
                : createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--table__current-header' }, __('Current', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Version', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__tested-header' }, __('Tested up to', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__released-header' }, __('Released', 'peak-publisher')),
                            showReleaseInstallations && createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher')),
                        ),
                    ),
                    createElement('tbody', null,
                        (releasesLoaded && releases.length === 0)
                            ? createElement('tr', null,
                                createElement('td', { colSpan: showReleaseInstallations ? 6 : 5 }, __('No releases.', 'peak-publisher')),
                            )
                            : releases.map((rel, index) =>
                                createElement('tr', { key: String(rel.id) },
                                    // Which release sites receive: a radio-like ring per row, filled on the
                                    // current one. "Current" is derived from the plugin's pointer, never a
                                    // release property; a draft plugin distributes nothing, so its dot is muted.
                                    createElement('td', { className: 'pblsh--table__current-cell' },
                                        rel.is_current
                                            ? createElement(Tooltip, {
                                                text: distributesNothing
                                                    ? currentWhenTooltip
                                                    : __('Current release — sites receive this version', 'peak-publisher'),
                                            },
                                                createElement('span', {
                                                    className: 'pblsh--current-ring pblsh--current-ring--current' + (distributesNothing ? ' pblsh--current-ring--muted' : ''),
                                                    tabIndex: 0,
                                                    role: 'img',
                                                    'aria-label': __('Current release', 'peak-publisher'),
                                                })
                                            )
                                            : flippingReleaseId === rel.id
                                                ? createElement(Spinner, { className: 'pblsh--current-ring-spinner' })
                                                // The ring of every other row is the action "Make … the current release" —
                                                // both channels; wordpress.org needs a usable account.
                                                : createElement(Button, {
                                                    className: 'pblsh--current-ring-button',
                                                    label: wporgWriteBlocked && wporgWriteBlockedText ? wporgWriteBlockedText : sprintf(__('Make %s the current release', 'peak-publisher'), rel.version),
                                                    showTooltip: true,
                                                    __experimentalIsFocusable: true,
                                                    disabled: wporgWriteBlocked || flippingReleaseId !== null,
                                                    onClick: () => makeCurrent(rel, relationToCurrent(index)),
                                                },
                                                    createElement('span', { className: 'pblsh--current-ring', 'aria-hidden': 'true' })
                                                ),
                                    ),
                                    createElement('td', { className: 'pblsh--table__version-cell' }, safe(rel.version)),
                                    createElement('td', { className: 'pblsh--table__tested-cell' }, renderTestedUpToCell(rel)),
                                    createElement('td', { className: 'pblsh--table__released-cell' }, createElement(RelativeTime, { value: rel.released_at, tooltip: true })),
                                    showReleaseInstallations && createElement('td', { className: 'pblsh--table__installations-cell' }, String(rel.installations_count || 0)),
                                    createElement('td', { className: 'pblsh--table__actions-cell' },
                                        createElement('div', { className: 'pblsh--table__actions' },
                                            !isWporg && (() => {
                                                const base = rel.download_url || '';
                                                const href = base ? base + (base.indexOf('?') >= 0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(window.wpApiSettings.nonce) : '';
                                                return createElement(Tooltip, { text: __('Download', 'peak-publisher') },
                                                    createElement('a', {
                                                        className: 'components-button has-icon is-tertiary',
                                                        href: href || undefined,
                                                        rel: 'noopener noreferrer',
                                                        'aria-label': __('Download', 'peak-publisher'),
                                                        onClick: (e) => { if (!href) { e.preventDefault(); alert(__('No download available for this release.', 'peak-publisher')); } },
                                                    },
                                                        Pblsh.Utils.getSvgIcon('download', { size: 24 }),
                                                    ),
                                                );
                                            })(),
                                            isWporg && createElement(Tooltip, { text: __('Download', 'peak-publisher') },
                                                createElement(Button, {
                                                    isTertiary: true,
                                                    icon: Pblsh.Utils.getSvgIcon('download', { size: 24 }),
                                                    label: __('Download', 'peak-publisher'),
                                                    isBusy: downloadingReleaseId === rel.id,
                                                    disabled: downloadingReleaseId !== null && downloadingReleaseId !== rel.id,
                                                    onClick: async () => {
                                                        if (downloadingReleaseIdRef.current !== null) return;
                                                        downloadingReleaseIdRef.current = rel.id;
                                                        setDownloadingReleaseId(rel.id);
                                                        try {
                                                            const response = await Pblsh.API.getWporgDownloadUrl(pluginData.id, rel.version);
                                                            if (response && response.status === 'ok' && response.url) {
                                                                window.location = response.url;
                                                                return;
                                                            }
                                                            Pblsh.Utils.showAlert(response?.message || __('Release not yet available on wordpress.org.', 'peak-publisher'), 'warning');
                                                        } catch (e) {
                                                            Pblsh.Utils.showAlert(e?.message || __('Release not yet available on wordpress.org.', 'peak-publisher'), 'warning');
                                                        } finally {
                                                            downloadingReleaseIdRef.current = null;
                                                            setDownloadingReleaseId(null);
                                                        }
                                                    },
                                                }),
                                            ),
                                            createElement(wp.components.DropdownMenu, {
                                                icon: Pblsh.Utils.getSvgIcon('dots_horizontal', { size: 24 }),
                                                label: __('More options', 'peak-publisher'),
                                                children: ({ onClose }) => [
                                                    // The current release cannot be deleted — sites would silently get
                                                    // nothing (self-hosted) or trunk (wordpress.org). The server guards it
                                                    // too; here the item says why it is off.
                                                    createElement(wp.components.MenuItem, {
                                                        key: 'delete',
                                                        isDestructive: true,
                                                        disabled: rel.is_current || wporgWriteBlocked,
                                                        info: rel.is_current
                                                            ? (isWporg
                                                                ? __('Make another release current first — the last release can only be removed via SVN.', 'peak-publisher')
                                                                : __('Make another release current first — the last release can only be removed together with the plugin.', 'peak-publisher'))
                                                            : (wporgWriteBlocked && wporgWriteBlockedText ? wporgWriteBlockedText : undefined),
                                                        onClick: async () => {
                                                            try {
                                                                const message = isWporg
                                                                    ? sprintf(__('Delete release %s from wordpress.org?', 'peak-publisher'), rel.version)
                                                                        + '\n'
                                                                        + __('This will permanently delete the wordpress.org SVN tag.', 'peak-publisher')
                                                                    : sprintf(__('Delete release %s?', 'peak-publisher'), rel.version)
                                                                        + '\n'
                                                                        + __('This will permanently delete the ZIP file.', 'peak-publisher');
                                                                if (!confirm(message)) { onClose(); return; }
                                                                await Pblsh.API.deleteRelease(rel.id);
                                                                onClose();
                                                                if (typeof refreshPlugin === 'function') {
                                                                    refreshPlugin();
                                                                }
                                                            } catch (e) {
                                                                // apiFetch rejects with the REST error payload: message leads, the code follows.
                                                                alert((e?.message || __('Could not delete the release.', 'peak-publisher'))
                                                                    + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
                                                                // The tag is gone already, or the release became current meanwhile: the list is stale.
                                                                if (['wporg_tag_not_found', 'current_release_protected'].includes(e?.code) && typeof refreshPlugin === 'function') refreshPlugin();
                                                            }
                                                        },
                                                    },
                                                        Pblsh.Utils.getSvgIcon('delete_forever', { size: 24 }),
                                                        isWporg ? __('Delete from wordpress.org', 'peak-publisher') : __('Delete release', 'peak-publisher')
                                                    ),
                                                ],
                                            })
                                        ),
                                    ),
                                ),
                            ),
                    ),
                ),
            ),
        ];
    };

    // The releases tab carries its count — what lies behind it, from the plugin like the
    // figures above (the one payload the header reads); the assets tab none, their number is
    // the assets store's, loaded with the tab. It carries a dot instead while asset changes
    // wait for their commit — a mark, not a number, which beside the releases' count would read
    // as one; its tooltip and the screen reader say it with the commit bar's words.
    const renderTabNav = () => createElement('div', { className: 'pblsh--tab-nav' },
        createElement('button', {
            type: 'button',
            className: 'pblsh--tab-nav__tab' + (activeTab === 'releases' ? ' pblsh--tab-nav__tab--active' : ''),
            onClick: () => switchToTab('releases'),
        }, sprintf(
            /* translators: %s: number of releases */
            __('Releases (%s)', 'peak-publisher'),
            (Number(pluginData?.count_of_releases) || 0).toLocaleString(),
        )),
        (() => {
            const pending = isWporg && Number(pluginData?.assets_pending) > 0 ? Pblsh.AssetsUtils.getPendingChangesText(Number(pluginData.assets_pending)) : null;
            const tab = createElement('button', {
                type: 'button',
                className: 'pblsh--tab-nav__tab' + (activeTab === 'assets' ? ' pblsh--tab-nav__tab--active' : ''),
                onClick: () => switchToTab('assets'),
            },
                __('Assets', 'peak-publisher'),
                pending && createElement('span', { className: 'pblsh--tab-nav__pending', 'aria-hidden': true }),
                pending && createElement('span', { className: 'screen-reader-text' }, ' (' + pending + ')'),
            );
            return pending ? createElement(Tooltip, { text: pending }, tab) : tab;
        })(),
        // wordpress.org, plugin-wide, so beside the tabs, each a line of its own: when the plugin
        // page shows the last commit, and how fresh the plugin's data is, with Refresh — locked
        // only while its own refresh runs: a click during the automatic check is taken, the
        // store runs it next.
        isWporg && pluginData && createElement('div', { className: 'pblsh--tab-nav__wporg' },
            createElement(Pblsh.Components.ImportForecast, { forecast: pluginData.wporg_import, slug: pluginData.slug }),
            createElement(WporgCheckStatus, { plugins: [ pluginData ] },
                createElement(Button, { isLink: true, isBusy: refreshingWporg, disabled: refreshingWporg, onClick: refreshFromWporg }, __('Refresh', 'peak-publisher')),
            ),
        ),
    );

    const livePreviewFails = isWporg && !!(pluginData && pluginData.live_preview && pluginData.live_preview.missing.length > 0);

    return createElement('div', { className: 'pblsh--editor' },
        createElement('div', { className: 'pblsh--editor__content' },
            createElement('div', { className: 'pblsh--main' },
                createElement('div', { className: 'pblsh--main__inner' },
                    createElement('div', { className: 'pblsh--main__content' },
                        renderInfoBox(),
                        createElement('div', { className: 'pblsh--tab-panel', 'data-active-tab': activeTab },
                            renderTabNav(),
                            createElement('div', { className: 'pblsh--tab-panel__body' },
                                // Plugin-wide, so above either tab: what refreshes found changed on
                                // wordpress.org, told until the plugin is left.
                                wporgChanges && createElement(NoticeBox, { variant: 'info', className: 'pblsh--tab-panel__notice' },
                                    createElement('p', null, getChangesText(wporgChanges)),
                                ),
                                // What visitors of the plugin page get fails: plugin-wide, so above either tab.
                                livePreviewFails && createElement(NoticeBox, { variant: 'error', className: 'pblsh--tab-panel__notice' },
                                    createElement('p', null, Pblsh.LivePreviewUtils.getFailsText(pluginData.live_preview), ' ',
                                        !(activeTab === 'assets' && assetsSection === 'live-preview') && createElement(Button, { isLink: true, onClick: openLivePreview }, __('Open Live Preview', 'peak-publisher'))),
                                ),
                                activeTab === 'releases' && renderReleasesTable(),
                                activeTab === 'assets' && createElement(Pblsh.Components.PluginAssets, { pluginData, refreshPlugin, refreshFromWporg, refreshingWporg, section: assetsSection, onSectionChange: switchToSection }),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
});
