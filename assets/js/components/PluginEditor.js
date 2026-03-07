// PluginEditor Component (simplified overview + releases list)
lodash.set(window, 'Pblsh.Components.PluginEditor', ({ pluginData, refreshPlugin, onToggleReleaseStatus, pendingReleaseIds, onTogglePluginStatus, pendingPluginStatus, isLoadingReleases, onBack, initialTab, onTabChange }) => {
    const { __ } = wp.i18n;
    const { createElement, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip, Button, DropdownMenu, MenuItem } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;

    const safe = (val) => (val === undefined || val === null) ? '' : val;
    const formatFilesize = (bytes) => {
        if (!bytes || bytes <= 0) return null;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    };
    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const showInstallations = !!(serverSettings && serverSettings.count_plugin_installations);

    // ---- Asset state ----
    const [assets, setAssets]               = useState(null);   // null = not yet loaded
    const [assetsLoading, setAssetsLoading] = useState(false);
    const [uploadingSlot, setUploadingSlot] = useState(null);   // 'icon_128' | 'screenshot-3' | null
    const [uploadProgress, setUploadProgress] = useState(0);
    const fileInputRefs = useRef({});  // { [slotKey]: HTMLInputElement }
    const validTabs = ['releases', 'assets'];
    const [activeTab, setActiveTab] = useState(initialTab && validTabs.includes(initialTab) ? initialTab : 'releases');
    const [draggingN, setDraggingN] = useState(null);     // screenshot_n being dragged
    const [dragOverN, setDragOverN] = useState(null);     // slot N being hovered during drag
    const dragOverTimeout = useRef(null);                   // auto-clear drag-over when cursor leaves

    // Auto-fetch assets when the tab is pre-selected via deep link
    useEffect(() => {
        if (activeTab === 'assets' && assets === null) fetchAssets();
    }, [pluginData && pluginData.id]);

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
        setActiveTab(tab);
        if (typeof onTabChange === 'function') onTabChange(tab);
        if (tab === 'assets' && assets === null) fetchAssets();
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
        const screenshotLabel = caption
            ? screenshotN + '. ' + caption
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

    const renderInfoBox = () => {
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
                                        createElement('strong', null, __('Slug', 'peak-publisher')),
                                        createElement('code', null, safe(pluginData?.slug) || '—'),
                                    ),
                                ),
                            ),
                            createElement('div', { className: 'pblsh--plugin-header__actions' },
                                createElement(Button, {
                                    isTertiary: true,
                                    className: 'pblsh--status-btn ' + (pluginData?.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                    label: pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher'),
                                    icon: getSvgIcon('circle'),
                                    isBusy: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id),
                                    disabled: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id),
                                    onClick: () => {
                                        if (typeof onTogglePluginStatus === 'function' && pluginData?.id) {
                                            const next = pluginData.status === 'publish' ? 'draft' : 'publish';
                                            onTogglePluginStatus(pluginData.id, next);
                                        }
                                    },
                                }, (pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher')))
                            ),
                        ),
                        createElement('div', { className: 'pblsh--plugin-grid' },
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Releases', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, (isLoadingReleases || !(wp.data.select('pblsh/releases').hasLoadedForPlugin && wp.data.select('pblsh/releases').hasLoadedForPlugin(pluginData && pluginData.id ? pluginData.id : null))) ? '—' : String((releasesFromStore || []).length))
                            ),
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Latest Release', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, safe(pluginData?.version) || '—')
                            ),
                            showInstallations && createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Installations', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, String(pluginData?.installations_count || 0))
                            ),
                        )
                    )
                )
            ),
        ];
    };

    const renderReleasesTable = () => {
        const releases = Array.isArray(releasesFromStore) ? releasesFromStore : [];
        const hasLoaded = wp.data.select('pblsh/releases').hasLoadedForPlugin
            ? wp.data.select('pblsh/releases').hasLoadedForPlugin(pluginData && pluginData.id ? pluginData.id : null)
            : false;
        return [
            createElement('div', { className: 'pblsh--table-container' },
                (isLoadingReleases || !hasLoaded) ?
                    createElement('div', { className: 'pblsh--loading pblsh--loading--table' },
                        createElement('div', { className: 'pblsh--loading__spinner' })
                    )
                : createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--table__status-header' }, __('Status', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Version', 'peak-publisher')),
                            createElement('th', null, __('Date', 'peak-publisher')),
                            showInstallations && createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher')),
                        ),
                    ),
                    createElement('tbody', null,
                        (hasLoaded && releases.length === 0)
                            ? createElement('tr', null,
                                createElement('td', { colSpan: showInstallations ? 5 : 4 }, __('No releases.', 'peak-publisher')),
                            )
                            : releases.map((rel) =>
                                createElement('tr', { key: String(rel.id) },
                                    createElement('td', { className: 'pblsh--table__status-cell' },
                                        createElement(wp.components.Button, {
                                            isTertiary: true,
                                            className: 'pblsh--status-btn ' + (rel.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                            label: rel.status === 'publish' ? (pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Public if plugin is public', 'peak-publisher')) : __('Draft', 'peak-publisher'),
                                            icon: Pblsh.Utils.getSvgIcon('circle'),
                                            isBusy: Array.isArray(pendingReleaseIds) && pendingReleaseIds.includes(rel.id),
                                            disabled: Array.isArray(pendingReleaseIds) && pendingReleaseIds.includes(rel.id),
                                            style: {
                                                opacity: pluginData?.status === 'publish' ? 1 : 0.5,
                                            },
                                            onClick: () => {
                                                const next = rel.status === 'publish' ? 'draft' : 'publish';
                                                if (typeof onToggleReleaseStatus === 'function') onToggleReleaseStatus(rel.id, next);
                                            },
                                        })
                                    ),
                                    createElement('td', { className: 'pblsh--table__version-cell' }, safe(rel.version)),
                                    createElement('td', null, safe(rel.date)),
                                    showInstallations && createElement('td', { className: 'pblsh--table__installations-cell' }, String(rel.installations_count || 0)),
                                    createElement('td', { className: 'pblsh--table__actions-cell' },
                                        createElement('div', { className: 'pblsh--table__actions' },
                                            (() => {
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
                                            createElement(wp.components.DropdownMenu, {
                                                icon: Pblsh.Utils.getSvgIcon('dots_horizontal', { size: 24 }),
                                                label: __('More options', 'peak-publisher'),
                                                children: ({ onClose }) => [
                                                    createElement(wp.components.MenuItem, {
                                                        key: 'delete',
                                                        isDestructive: true,
                                                        onClick: async () => {
                                                            try {
                                                                if (!confirm(__('Are you sure you want to permanently delete this release?', 'peak-publisher'))) { onClose(); return; }
                                                                await Pblsh.API.deleteRelease(rel.id);
                                                                onClose();
                                                                if (typeof refreshPlugin === 'function') {
                                                                    refreshPlugin();
                                                                }
                                                            } catch (e) {
                                                                alert(e?.message || 'Error deleting release');
                                                            }
                                                        },
                                                    },
                                                        Pblsh.Utils.getSvgIcon('delete_forever', { size: 24 }),
                                                        __('Delete permanently', 'peak-publisher')
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
                                activeTab === 'assets'   && renderAssetsSection(),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );
});