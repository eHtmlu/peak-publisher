// PluginEditor Component (simplified overview + releases list)
lodash.set(window, 'Pblsh.Components.PluginEditor', ({ pluginData, refreshPlugin, refreshWporg, onTogglePluginStatus, pendingPluginStatus, onBack, initialTab, onTabChange }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip, Button, Spinner } = wp.components;
    const { getSvgIcon, formatRelativeTime, getTimeTooltipProps, getPluginStatus } = Pblsh.Utils;
    const { getCurrentReleaseIssue, getFlipConfirmText, getFlipSuccessText } = Pblsh.CurrentReleaseUtils;
    const { getLastErrorText, getDownloads, getRating, getWporgClosedNotice, isFirstFetchRunning } = Pblsh.InstallationsUtils;
    const { NoticeBox, CurrentVersion, InstallationsCount, Figure } = Pblsh.Components;

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
    // The flip in progress (its ring shows the spinner, every ring is locked) and the
    // transient success notice, which the next plugin change clears.
    const [flippingReleaseId, setFlippingReleaseId] = useState(null);
    const [flipNotice, setFlipNotice] = useState(null);
    useEffect(() => { setFlipNotice(null); }, [pluginData && pluginData.id]);
    // The manual stats refresh (wordpress.org): while it runs, the three figures show a
    // spinner. Its outcome shows through the data — the fetch age becomes "just now", a
    // failure appears beside it.
    const [refreshingStats, setRefreshingStats] = useState(false);
    const isRefreshingAnyStats = useSelect((select) => select('pblsh/plugins').isRefreshingWporg(), []);
    const validTabs = ['releases', 'assets'];
    const [activeTab, setActiveTab] = useState(initialTab && validTabs.includes(initialTab) ? initialTab : 'releases');

    const switchToTab = (tab) => {
        if (!validTabs.includes(tab)) tab = 'releases';
        setActiveTab(tab);
        if (typeof onTabChange === 'function') onTabChange(tab);
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
    const refreshStats = async () => {
        if (!pluginData || refreshingStats) return;
        setRefreshingStats(true);
        try {
            await refreshWporg(pluginData.id);
        } catch (e) {
            alert((e?.message || __('Could not refresh the wordpress.org figures.', 'peak-publisher'))
                + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
        } finally {
            setRefreshingStats(false);
        }
    };

    // The status of the wordpress.org stats (installations, downloads, rating) in the
    // card's header row, beside the plugin's status button: the fetch age, the last
    // failure, and the Refresh link — one line for the three values it covers; every
    // value names its source in its tooltip. A plugin never fetched because the server
    // cannot reach wordpress.org gets that as the line itself. Muted fragments, ' · '
    // separated, no period, sentence case except "wordpress.org".
    const renderWporgFiguresStatus = () => {
        const inst = pluginData.installations;
        const fragments = [];
        let refreshable = true;
        if (inst.state === 'never' && inst.last_error) {
            fragments.push(createElement(Tooltip, { text: getLastErrorText(inst.last_error) },
                createElement('span', { tabIndex: 0 }, __('wordpress.org could not be reached from this server', 'peak-publisher'))));
        } else if (isFirstFetchRunning(pluginData, isRefreshingAnyStats)) {
            fragments.push(__('Fetching stats…', 'peak-publisher'));
            refreshable = false;
        } else {
            if (inst.fetched_at) {
                fragments.push(createElement('span', null, __('Stats updated', 'peak-publisher'), ' ',
                    createElement('time', getTimeTooltipProps(inst.fetched_at), formatRelativeTime(inst.fetched_at))));
            } else {
                fragments.push(__('Stats not fetched yet', 'peak-publisher'));
            }
            if (inst.last_error) {
                fragments.push(createElement(Tooltip, { text: getLastErrorText(inst.last_error) },
                    createElement('span', { tabIndex: 0 }, __('Could not be reached', 'peak-publisher'))));
            }
        }
        // Locked only while its own refresh runs — after a failure the user may try again at
        // once, and a click during the automatic check is taken: the store runs it next.
        if (refreshable) {
            fragments.push(createElement(Button, {
                isLink: true,
                isBusy: refreshingStats,
                disabled: refreshingStats,
                onClick: refreshStats,
            }, __('Refresh', 'peak-publisher')));
        }
        return fragments.flatMap((fragment, index) => index === 0 ? [ fragment ] : [ ' · ', fragment ]);
    };

    const renderInfoBox = () => {
        // The wordpress.org dashboard figures beside Installations; each names its source
        // in the tooltip, the header row carries the age. During the manual stats refresh
        // a figure gives way to a spinner; the automatic refresh after loading keeps
        // showing the cached values.
        const wporgFigures = isWporg ? { downloads: getDownloads(pluginData), rating: getRating(pluginData) } : null;
        const wporgFigure = (figure) => refreshingStats ? createElement(Spinner, { className: 'pblsh--plugin-grid__spinner' }) : figure;
        return [
                createElement('div', { className: 'pblsh--card pblsh--card--plugin-info' },
                createElement('div', { className: 'pblsh--plugin-info__row' },
                    createElement('div', { className: 'pblsh--plugin-info__left' },
                        createElement(Button, {
                            isTertiary: true,
                            className: 'has-icon',
                            label: __('Back to list', 'peak-publisher'),
                            icon: getSvgIcon('arrow_back', { size: 24 }),
                            onClick: () => { if (typeof onBack === 'function') onBack(); },
                        })
                    ),
                    createElement('div', { className: 'pblsh--plugin-info__right' },
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
                                    createElement('h3', { className: 'pblsh--plugin-title' }, pluginData?.name),
                                    createElement('div', { className: 'pblsh--plugin-meta' },
                                        createElement(Pblsh.Components.ChannelPath, { channel: pluginData?.hosting_type, slug: safe(pluginData?.slug) || '—' }),
                                    ),
                                ),
                            ),
                            createElement('div', { className: 'pblsh--plugin-header__actions' },
                                isWporg && createElement('div', { className: 'pblsh--plugin-header__figures' }, ...renderWporgFiguresStatus()),
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
                                }, getPluginStatus(pluginData?.status).label)
                            ),
                        ),
                        createElement('div', { className: 'pblsh--plugin-grid' },
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Releases', 'peak-publisher')),
                                // From the plugin like the current release beside it — the one payload the header reads.
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, String(Number(pluginData?.count_of_releases) || 0))
                            ),
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
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, wporgFigure(createElement(InstallationsCount, { plugin: pluginData }))),
                            ),
                            wporgFigures && createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Downloads', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' },
                                    wporgFigure(createElement(Figure, { title: wporgFigures.downloads.title }, wporgFigures.downloads.text)),
                                ),
                            ),
                            // Stars with the number of ratings beside them, the way the plugin installer
                            // shows a rating — the count qualifies the stars; the tooltip spells both out.
                            wporgFigures && createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Rating', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' },
                                    wporgFigure(createElement(Figure, { title: wporgFigures.rating.title },
                                        wporgFigures.rating.stars
                                            ? createElement('span', { className: 'pblsh--rating-stars', 'aria-hidden': 'true' },
                                                ...wporgFigures.rating.stars.map((icon, index) => createElement('span', { key: index, className: 'pblsh--rating-stars__star' }, getSvgIcon(icon, { size: 32 }))),
                                                createElement('span', { className: 'pblsh--rating-stars__count' }, '(' + wporgFigures.rating.count + ')'),
                                            )
                                            : wporgFigures.rating.text,
                                    )),
                                ),
                            ),
                        )
                    )
                )
            ),
        ];
    };

    // Makes another release current: confirm, request with the pointer the editor showed,
    // reload, transient notice. A stale pointer (someone else flipped) is reported and the
    // plugin reloaded — never overwritten.
    const makeCurrent = async (rel, relation) => {
        if (!pluginData || flippingReleaseId !== null) return;
        if (!confirm(getFlipConfirmText(isWporg, rel.version, relation, isWporg ? pluginData.wporg_stats.closed : null))) return;
        setFlipNotice(null);
        setFlippingReleaseId(rel.id);
        try {
            const response = await Pblsh.API.setCurrentRelease(pluginData.id, rel.version, pluginData.pointer);
            if (typeof refreshPlugin === 'function') await refreshPlugin();
            setFlipNotice(getFlipSuccessText(isWporg, response.to, response.revision));
        } catch (e) {
            // apiFetch rejects with the REST error payload: message leads, the code follows.
            alert((e?.message || __('Could not change the current release.', 'peak-publisher'))
                + (e?.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''));
            if (e?.code === 'current_release_changed' && typeof refreshPlugin === 'function') refreshPlugin();
        } finally {
            setFlippingReleaseId(null);
        }
    };

    // Notices above the releases table, in this order: transient success → closed on
    // wordpress.org → the pointer state (no current release).
    const renderReleaseNotices = () => {
        const issue = getCurrentReleaseIssue(pluginData);
        const closed = isWporg ? pluginData.wporg_stats.closed : null;
        return [
            flipNotice && createElement(NoticeBox, { key: 'flip', variant: 'info', className: 'pblsh--tab-panel__notice' },
                createElement('p', null, flipNotice),
            ),
            // Closed on wordpress.org — the most important fact of the daily stats fetch:
            // nothing is distributed, whatever the pointer says.
            closed && createElement(NoticeBox, { key: 'closed', variant: 'warning', className: 'pblsh--tab-panel__notice' },
                createElement('p', null, getWporgClosedNotice(closed)),
            ),
            // The fact, then the remedy emphasized on its own line — one paragraph, as the box
            // holds a single thought.
            issue && createElement(NoticeBox, { key: 'current-release', variant: issue.variant, className: 'pblsh--tab-panel__notice' },
                createElement('p', null,
                    issue.fact,
                    ...(issue.remedy ? [ createElement('br'), createElement('strong', null, issue.remedy) ] : []),
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
                            createElement('th', null, __('Date', 'peak-publisher')),
                            showReleaseInstallations && createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher')),
                        ),
                    ),
                    createElement('tbody', null,
                        (releasesLoaded && releases.length === 0)
                            ? createElement('tr', null,
                                createElement('td', { colSpan: showReleaseInstallations ? 5 : 4 }, __('No releases.', 'peak-publisher')),
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
                                    createElement('td', null, safe(rel.date)),
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

    const renderTabNav = () => createElement('div', { className: 'pblsh--tab-nav' },
        createElement('button', {
            type: 'button',
            className: 'pblsh--tab-nav__tab' + (activeTab === 'releases' ? ' pblsh--tab-nav__tab--active' : ''),
            onClick: () => switchToTab('releases'),
        }, __('Releases', 'peak-publisher')),
        createElement('button', {
            type: 'button',
            className: 'pblsh--tab-nav__tab' + (activeTab === 'assets' ? ' pblsh--tab-nav__tab--active' : ''),
            onClick: () => switchToTab('assets'),
        }, __('Assets', 'peak-publisher')),
        // wordpress.org: when the plugin page shows the last commit — plugin-wide, so beside the tabs.
        isWporg && pluginData && createElement(Pblsh.Components.ImportForecast, { forecast: pluginData.wporg_import, slug: pluginData.slug }),
    );

    return createElement('div', { className: 'pblsh--editor' },
        createElement('div', { className: 'pblsh--editor__content' },
            createElement('div', { className: 'pblsh--main' },
                createElement('div', { className: 'pblsh--main__inner' },
                    createElement('div', { className: 'pblsh--main__content' },
                        renderInfoBox(),
                        createElement('div', { className: 'pblsh--tab-panel', 'data-active-tab': activeTab },
                            renderTabNav(),
                            createElement('div', { className: 'pblsh--tab-panel__body' },
                                activeTab === 'releases' && renderReleasesTable(),
                                activeTab === 'assets' && createElement(Pblsh.Components.PluginAssets, { pluginData, refreshPlugin }),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
});
