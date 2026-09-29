// PluginEditor Component (simplified overview + releases list)
lodash.set(window, 'Pblsh.Components.PluginEditor', ({ pluginData, refreshPlugin, onTogglePluginStatus, pendingPluginStatus, isLoadingReleases, onBack, initialTab, onTabChange }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip, Button, DropdownMenu, MenuItem, Spinner } = wp.components;
    const { getSvgIcon, formatRelativeTime, getTimeTooltipProps, getPluginStatus } = Pblsh.Utils;
    const { getCurrentReleaseIssue, getFlipConfirmText, getFlipSuccessText } = Pblsh.CurrentReleaseUtils;
    const { getLastErrorText, getDownloads, getRating, getWporgClosedNotice } = Pblsh.InstallationsUtils;
    const { NoticeBox, CurrentVersion, InstallationsCount, Figure } = Pblsh.Components;

    const safe = (val) => (val === undefined || val === null) ? '' : val;
    const formatFilesize = (bytes) => {
        if (!bytes || bytes <= 0) return null;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };
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

    // ---- Asset state ----
    const [assets, setAssets]               = useState(null);   // null = not yet loaded
    const [assetsLoading, setAssetsLoading] = useState(false);
    const [uploadingSlot, setUploadingSlot] = useState(null);   // 'icon_128' | 'screenshot-3' | null
    const [uploadProgress, setUploadProgress] = useState(0);
    const fileInputRefs = useRef({});  // { [slotKey]: HTMLInputElement }
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
    const isRefreshingAnyStats = useSelect((select) => select('pblsh/plugins').isRefreshingWporgStats(), []);
    const validTabs = isWporg ? ['releases'] : ['releases', 'assets'];
    const [activeTab, setActiveTab] = useState(initialTab && validTabs.includes(initialTab) ? initialTab : 'releases');
    const [draggingN, setDraggingN] = useState(null);     // screenshot_n being dragged
    const [dragOverN, setDragOverN] = useState(null);     // slot N being hovered during drag
    const dragOverTimeout = useRef(null);                   // auto-clear drag-over when cursor leaves

    // Auto-fetch assets when the tab is pre-selected via deep link
    useEffect(() => {
        if (activeTab === 'assets' && !isWporg && assets === null) fetchAssets();
    }, [pluginData && pluginData.id]);

    useEffect(() => {
        if (isWporg && activeTab === 'assets') {
            setActiveTab('releases');
            if (typeof onTabChange === 'function') onTabChange('releases');
        }
    }, [isWporg, activeTab]);

    const fetchAssets = async () => {
        if (!pluginData || !pluginData.id) return;
        setAssetsLoading(true);
        try {
            const data = await Pblsh.API.getPluginAssets(pluginData.id);
            setAssets(data);
        } catch (e) {
            // Non-fatal: just show empty asset state
            setAssets({});
        } finally {
            setAssetsLoading(false);
        }
    };

    const switchToTab = (tab) => {
        if (!validTabs.includes(tab)) tab = 'releases';
        setActiveTab(tab);
        if (typeof onTabChange === 'function') onTabChange(tab);
        if (tab === 'assets' && !isWporg && assets === null) fetchAssets();
    };

    const handleAssetUpload = async (slot, screenshotN, file) => {
        if (!file || !pluginData || !pluginData.id) return;
        const slotKey = slot === 'screenshot' ? 'screenshot-' + screenshotN : slot;
        setUploadingSlot(slotKey);
        setUploadProgress(0);
        try {
            const result = await Pblsh.API.uploadPluginAsset(
                pluginData.id, slot, screenshotN, file,
                (pct) => setUploadProgress(Math.floor(pct))
            );
            if (result && result.status === 'ok') {
                await fetchAssets();
                if (typeof refreshPlugin === 'function') refreshPlugin();
                if (result.warnings && result.warnings.length > 0) {
                    Pblsh.Utils.showAlert(result.warnings.map(w => w.message).join('\n'), 'warning');
                }
            } else {
                Pblsh.Utils.showAlert((result && result.message) || __('Upload failed.', 'peak-publisher'), 'error');
            }
        } catch (e) {
            Pblsh.Utils.showAlert(e.message || __('Upload failed.', 'peak-publisher'), 'error');
        } finally {
            setUploadingSlot(null);
            setUploadProgress(0);
        }
    };

    const handleAssetDelete = async (slot, screenshotN) => {
        if (!pluginData || !pluginData.id) return;
        if (!confirm(__('Delete this asset?', 'peak-publisher'))) return;
        try {
            const result = await Pblsh.API.deletePluginAsset(pluginData.id, slot, screenshotN);
            if (result && result.assets) {
                setAssets(result.assets);
            } else {
                await fetchAssets();
            }
            if (typeof refreshPlugin === 'function') refreshPlugin();
        } catch (e) {
            Pblsh.Utils.showAlert(e.message || __('Delete failed.', 'peak-publisher'), 'error');
        }
    };

    const handleScreenshotMove = async (fromN, toN) => {
        if (!pluginData || !pluginData.id || fromN === toN) return;
        try {
            const result = await Pblsh.API.moveScreenshot(pluginData.id, fromN, toN);
            if (result && result.assets) {
                setAssets(result.assets);
            } else {
                await fetchAssets();
            }
        } catch (e) {
            Pblsh.Utils.showAlert(e.message || __('Move failed.', 'peak-publisher'), 'error');
        }
    };

    const openFilePicker = (slot, screenshotN) => {
        const key = slot === 'screenshot' ? 'screenshot-' + (screenshotN !== null && screenshotN !== undefined ? screenshotN : 'new') : slot;
        if (fileInputRefs.current[key]) {
            fileInputRefs.current[key].value = '';
            fileInputRefs.current[key].click();
        }
    };

    // Slot configurations from server (single source of truth: AssetManager::get_slots())
    const ASSET_SLOTS = window.PblshData.assetSlots || {};
    // Helper: build accept string from exts array, e.g. ['png','jpg','gif'] → '.png,.jpg,.jpeg,.gif'
    const slotAccept = (s) => (s.exts || []).flatMap(e => e === 'jpg' ? ['.jpg', '.jpeg'] : ['.' + e]).join(',');
    const slotHint   = (s) => s.prefix + '.{' + (s.exts || []).join('|') + '}';

    const renderAssetBox = (slot, assetData, screenshotN = null, caption = null) => {
        const stripTags = (html) => { const el = document.createElement('div'); el.innerHTML = html; return el.textContent || ''; };
        const screenshotLabel = caption
            ? screenshotN + '. ' + stripTags(caption)
            : __('Screenshot', 'peak-publisher') + ' ' + screenshotN;
        const raw = ASSET_SLOTS[slot];
        const def = slot === 'screenshot'
            ? { label: screenshotLabel, accept: slotAccept(raw), hint: raw.prefix + '-' + screenshotN + '.{' + raw.exts.join('|') + '}', group: raw.group, expectedW: raw.expectedW, expectedH: raw.expectedH }
            : raw ? { label: raw.label, accept: slotAccept(raw), hint: slotHint(raw), group: raw.group, expectedW: raw.expectedW, expectedH: raw.expectedH } : null;
        if (!def) return null;
        const slotKey = slot === 'screenshot' ? 'screenshot-' + screenshotN : slot;
        const isUploading = uploadingSlot === slotKey;
        const hasAsset = !!(assetData && assetData.filename);
        const warnings = (assetData && assetData.warnings) || [];
        const isScreenshot = slot === 'screenshot';
        const isDragging = isScreenshot && draggingN === screenshotN;
        const isDragOver = isScreenshot && dragOverN === screenshotN && draggingN !== screenshotN;

        const parts = [];
        if (hasAsset && assetData.width && assetData.height) parts.push(assetData.width + '\u00d7' + assetData.height + '\u00a0px');
        const fs = hasAsset ? formatFilesize(assetData.filesize) : null;
        if (fs) parts.push(fs);

        const acceptLabel = (raw.exts || []).map(e => e.toUpperCase()).join(' · ');
        const sizeLabel = def.expectedW && def.expectedH ? def.expectedW + '\u00d7' + def.expectedH + '\u00a0px' : null;
        const imageModClass = def.group === 'banners' ? 'pblsh--asset-slot__box-image--banner'
            : def.group === 'screenshots' ? 'pblsh--asset-slot__box-image--screenshot'
            : 'pblsh--asset-slot__box-image--icon';

        // Drag-and-drop handlers for screenshot slots
        const dragProps = isScreenshot ? {
            onDragOver: (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                setDragOverN(screenshotN);
                clearTimeout(dragOverTimeout.current);
                dragOverTimeout.current = setTimeout(() => setDragOverN(null), 150);
            },
            onDrop: (e) => {
                e.preventDefault();
                clearTimeout(dragOverTimeout.current);
                setDragOverN(null);
                setDraggingN(null);
                const fromN = parseInt(e.dataTransfer.getData('text/plain'), 10);
                if (!fromN || fromN === screenshotN) return;
                if (hasAsset) {
                    if (!confirm(__('Replace the existing screenshot at this position?', 'peak-publisher'))) return;
                }
                handleScreenshotMove(fromN, screenshotN);
            },
        } : {};

        // Drag source props (only on the image area of filled screenshot slots)
        const dragSourceProps = (isScreenshot && hasAsset) ? {
            draggable: true,
            onDragStart: (e) => {
                e.dataTransfer.setData('text/plain', String(screenshotN));
                e.dataTransfer.effectAllowed = 'move';
                setDraggingN(screenshotN);
            },
            onDragEnd: () => { setDraggingN(null); setDragOverN(null); clearTimeout(dragOverTimeout.current); },
        } : {};

        const classNames = [
            'pblsh--asset-slot',
            'pblsh--asset-slot--box',
            isUploading ? 'pblsh--asset-slot--uploading' : '',
            isDragging ? 'pblsh--asset-slot--dragging' : '',
            isDragOver ? 'pblsh--asset-slot--drag-target' : '',
        ].filter(Boolean).join(' ');

        return createElement('div', {
            key: slotKey,
            className: classNames,
            ...dragProps,
        },
            createElement('input', {
                ref: (el) => { fileInputRefs.current[slotKey] = el; },
                type: 'file',
                accept: def.accept,
                className: 'pblsh--hidden-file-input',
                onChange: (e) => { const file = e.target.files && e.target.files[0]; if (file) handleAssetUpload(slot, screenshotN, file); },
            }),
            hasAsset
                ? createElement('div', { className: 'pblsh--asset-slot__box-body' },
                    createElement('div', {
                        className: 'pblsh--asset-slot__box-image ' + imageModClass,
                        ...dragSourceProps,
                    },
                        createElement('div', { className: 'pblsh--asset-slot__box-image-inner' },
                            isUploading && createElement('div', { className: 'pblsh--asset-slot__progress' },
                                createElement('div', { className: 'pblsh--asset-slot__progress-bar', style: { '--pct': uploadProgress + '%' } }),
                                createElement('div', { className: 'pblsh--asset-slot__progress-label' }, uploadProgress + '%'),
                            ),
                            createElement('img', {
                                src: assetData.url,
                                alt: assetData.filename,
                                className: 'pblsh--asset-slot__box-img',
                                draggable: false,
                            }),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--asset-slot__box-info' },
                        createElement('div', { className: 'pblsh--asset-slot__box-label' }, def.label),
                        createElement('div', { className: 'pblsh--asset-slot__box-filename' }, assetData.filename),
                        parts.length > 0 && createElement('div', { className: 'pblsh--asset-slot__box-meta' },
                            parts.join('\u2002\u2022\u2002'),
                        ),
                        warnings.length > 0 && createElement('div', { className: 'pblsh--asset-slot__warnings' },
                            warnings.map((w, i) => createElement('div', { key: i, className: 'pblsh--asset-slot__warning', title: w.message },
                                getSvgIcon('information_outline', { size: 14 }),
                                createElement('span', null, w.message),
                            ))
                        ),
                    ),
                )
                : createElement('div', {
                    className: 'pblsh--asset-slot__box-body pblsh--asset-slot__box-body--empty',
                },
                    isUploading
                        ? createElement('div', { className: 'pblsh--asset-slot__progress' },
                            createElement('div', { className: 'pblsh--asset-slot__progress-bar', style: { '--pct': uploadProgress + '%' } }),
                            createElement('div', { className: 'pblsh--asset-slot__progress-label' }, uploadProgress + '%'),
                        )
                        : createElement('div', { className: 'pblsh--asset-slot__box-empty' },
                            createElement('div', { className: 'pblsh--asset-slot__box-empty-title' }, def.label),
                            createElement('div', { className: 'pblsh--asset-slot__box-empty-expected' },
                                __('Expected:', 'peak-publisher') + ' ' + [acceptLabel, sizeLabel].filter(Boolean).join(' · '),
                            ),
                            createElement(Button, {
                                isPrimary: true,
                                className: 'pblsh--asset-slot__box-upload-btn',
                                onClick: () => openFilePicker(slot, screenshotN),
                                disabled: isUploading,
                            }, __('Select File', 'peak-publisher')),
                        ),
                ),
            hasAsset && createElement('div', { className: 'pblsh--asset-slot__actions' },
                createElement(Button, {
                    isTertiary: true,
                    className: 'has-icon',
                    label: __('Replace', 'peak-publisher'),
                    icon: getSvgIcon('pencil', { size: 18 }),
                    onClick: () => openFilePicker(slot, screenshotN),
                    disabled: isUploading,
                }),
                createElement(DropdownMenu, {
                    icon: getSvgIcon('dots_horizontal', { size: 24 }),
                    label: __('More options', 'peak-publisher'),
                    children: ({ onClose }) => createElement(MenuItem, {
                        isDestructive: true,
                        onClick: () => { handleAssetDelete(slot, screenshotN); onClose(); },
                    },
                        getSvgIcon('delete_forever', { size: 24 }),
                        __('Delete', 'peak-publisher'),
                    ),
                }),
            ),
        );
    };

    const renderAssetsSection = () => {
        if (assetsLoading && !assets) {
            return createElement('div', { className: 'pblsh--card pblsh--assets-card' },
                createElement('div', { className: 'pblsh--loading pblsh--loading--small' },
                    createElement('div', { className: 'pblsh--loading__spinner' }),
                ),
            );
        }

        // Build screenshot slot map: { N: assetData } for quick lookup
        const screenshots = (assets && assets.screenshots) || [];
        const captions = (assets && assets.screenshot_captions) || {};
        const screenshotMap = {};
        screenshots.forEach(s => { screenshotMap[s.screenshot_n] = s; });

        // Determine visible slot range
        const captionKeys = Object.keys(captions).map(Number).filter(n => n > 0);
        const screenshotKeys = screenshots.map(s => s.screenshot_n);
        const maxCaption = captionKeys.length > 0 ? Math.max(...captionKeys) : 0;
        const maxScreenshot = screenshotKeys.length > 0 ? Math.max(...screenshotKeys) : 0;
        const maxN = Math.max(maxCaption, maxScreenshot);

        // Trim trailing empty slots that have no caption
        let visibleMaxN = maxN;
        while (visibleMaxN > 0 && !screenshotMap[visibleMaxN] && !captions[visibleMaxN]) {
            visibleMaxN--;
        }

        // Build slot list: 1..visibleMaxN + "+new" at the end
        const nextN = visibleMaxN + 1;
        const slots = [];
        for (let i = 1; i <= visibleMaxN; i++) {
            slots.push({ n: i, screenshot: screenshotMap[i] || null, caption: captions[i] || null });
        }

        // "+New" slot
        const newSlotKey = 'screenshot-new';
        const isUploadingNew = uploadingSlot === newSlotKey;
        const isDragOverNew = dragOverN === nextN && draggingN !== nextN;

        const newScreenshotBox = createElement('div', {
            key: newSlotKey,
            className: ['pblsh--asset-slot', 'pblsh--asset-slot--box', 'pblsh--asset-slot--new', isUploadingNew ? 'pblsh--asset-slot--uploading' : '', isDragOverNew ? 'pblsh--asset-slot--drag-target' : ''].filter(Boolean).join(' '),
            onDragOver: (e) => {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                setDragOverN(nextN);
                clearTimeout(dragOverTimeout.current);
                dragOverTimeout.current = setTimeout(() => setDragOverN(null), 150);
            },
            onDrop: (e) => {
                e.preventDefault();
                clearTimeout(dragOverTimeout.current);
                setDragOverN(null);
                setDraggingN(null);
                const fromN = parseInt(e.dataTransfer.getData('text/plain'), 10);
                if (!fromN || fromN === nextN) return;
                handleScreenshotMove(fromN, nextN);
            },
        },
            createElement('input', {
                ref: (el) => { fileInputRefs.current[newSlotKey] = el; },
                type: 'file',
                accept: slotAccept(ASSET_SLOTS.screenshot),
                className: 'pblsh--hidden-file-input',
                onChange: (e) => {
                    const file = e.target.files && e.target.files[0];
                    if (file) {
                        setUploadingSlot(newSlotKey);
                        handleAssetUpload('screenshot', nextN, file).finally(() => setUploadingSlot(null));
                    }
                },
            }),
            createElement('div', {
                className: 'pblsh--asset-slot__box-body pblsh--asset-slot__box-body--empty',
            },
                isUploadingNew
                    ? createElement('div', { className: 'pblsh--asset-slot__progress' },
                        createElement('div', { className: 'pblsh--asset-slot__progress-bar', style: { '--pct': uploadProgress + '%' } }),
                        createElement('div', { className: 'pblsh--asset-slot__progress-label' }, uploadProgress + '%'),
                    )
                    : createElement('div', { className: 'pblsh--asset-slot__box-empty' },
                        createElement('div', { className: 'pblsh--asset-slot__box-empty-title' },
                            __('Screenshot', 'peak-publisher') + ' ' + nextN + ' — ' + __('New', 'peak-publisher'),
                        ),
                        createElement('div', { className: 'pblsh--asset-slot__box-empty-expected' }, (ASSET_SLOTS.screenshot.exts || []).map(function(e) { return e.toUpperCase(); }).join(' · ')),
                        createElement(Button, {
                            isPrimary: true,
                            className: 'pblsh--asset-slot__box-upload-btn',
                            onClick: () => { fileInputRefs.current[newSlotKey] && (fileInputRefs.current[newSlotKey].value = '', fileInputRefs.current[newSlotKey].click()); },
                            disabled: isUploadingNew,
                        }, __('Select File', 'peak-publisher')),
                    ),
            ),
        );

        return createElement('div', { className: 'pblsh--card pblsh--assets-card' },
            // Icons group
            createElement('div', { className: 'pblsh--assets-group' },
                createElement('div', { className: 'pblsh--assets-group__label' }, __('Icons', 'peak-publisher')),
                createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                    renderAssetBox('icon_svg', assets && assets.icon_svg),
                    renderAssetBox('icon_256', assets && assets.icon_256),
                    renderAssetBox('icon_128', assets && assets.icon_128),
                ),
            ),
            // Banners group
            createElement('div', { className: 'pblsh--assets-group' },
                createElement('div', { className: 'pblsh--assets-group__label' }, __('Banners', 'peak-publisher')),
                createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                    renderAssetBox('banner_svg', assets && assets.banner_svg),
                    renderAssetBox('banner_hd', assets && assets.banner_hd),
                    renderAssetBox('banner_sd', assets && assets.banner_sd),
                ),
            ),
            // Screenshots group (slot-based)
            createElement('div', { className: 'pblsh--assets-group' },
                createElement('div', { className: 'pblsh--assets-group__label' }, __('Screenshots', 'peak-publisher')),
                createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                    slots.map(({ n, screenshot, caption }) =>
                        renderAssetBox('screenshot', screenshot, n, caption)
                    ),
                    newScreenshotBox,
                ),
            ),
        );
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

    // The manual refresh of the wordpress.org stats: force skips the daily cut-off. The
    // patched data says the rest; a failure of the request itself is reported like the
    // flip's.
    const refreshStats = async () => {
        if (!pluginData || refreshingStats) return;
        setRefreshingStats(true);
        try {
            await Pblsh.Controllers.Plugins.refreshWporgStats(pluginData.id, { force: true });
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
        } else if (inst.state === 'never' && isRefreshingAnyStats) {
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
        // Locked only while a refresh runs — after a failure the user may try again at once.
        if (refreshable) {
            fragments.push(createElement(Button, {
                isLink: true,
                isBusy: refreshingStats,
                disabled: refreshingStats || isRefreshingAnyStats,
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
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, (isLoadingReleases || !(wp.data.select('pblsh/releases').hasLoadedForPlugin && wp.data.select('pblsh/releases').hasLoadedForPlugin(pluginData && pluginData.id ? pluginData.id : null))) ? '—' : String((releasesFromStore || []).length))
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
            flipNotice && createElement(NoticeBox, { key: 'flip', variant: 'info', className: 'pblsh--releases-notice' },
                createElement('p', null, flipNotice),
            ),
            // Closed on wordpress.org — the most important fact of the daily stats fetch:
            // nothing is distributed, whatever the pointer says.
            closed && createElement(NoticeBox, { key: 'closed', variant: 'warning', className: 'pblsh--releases-notice' },
                createElement('p', null, getWporgClosedNotice(closed)),
            ),
            // The fact, then the remedy emphasized on its own line — one paragraph, as the box
            // holds a single thought.
            issue && createElement(NoticeBox, { key: 'current-release', variant: issue.variant, className: 'pblsh--releases-notice' },
                createElement('p', null,
                    issue.fact,
                    ...(issue.remedy ? [ createElement('br'), createElement('strong', null, issue.remedy) ] : []),
                ),
            ),
        ];
    };

    const renderReleasesTable = () => {
        const releases = Array.isArray(releasesFromStore) ? releasesFromStore : [];
        const hasLoaded = wp.data.select('pblsh/releases').hasLoadedForPlugin
            ? wp.data.select('pblsh/releases').hasLoadedForPlugin(pluginData && pluginData.id ? pluginData.id : null)
            : false;
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
                (isLoadingReleases || !hasLoaded) ?
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
                        (hasLoaded && releases.length === 0)
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
        !isWporg && createElement('button', {
            type: 'button',
            className: 'pblsh--tab-nav__tab' + (activeTab === 'assets' ? ' pblsh--tab-nav__tab--active' : ''),
            onClick: () => switchToTab('assets'),
        }, __('Assets', 'peak-publisher')),
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
                                activeTab === 'assets' && !isWporg && renderAssetsSection(),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
});
