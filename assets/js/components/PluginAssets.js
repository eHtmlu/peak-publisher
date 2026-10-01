// PluginAssets Component - the assets tab of the plugin editor: icons, banners and
// screenshots in fixed slots, uploaded, replaced, deleted and reordered from here. Owns
// its state (the manifest, the upload in progress, the drag); the plugin comes with
// pluginData, a changed icon reaches the header and the list through refreshPlugin.
lodash.set(window, 'Pblsh.Components.PluginAssets', ({ pluginData, refreshPlugin }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, useState, useEffect, useRef } = wp.element;
    const { Button, DropdownMenu, MenuItem } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;
    const { getExpectedText, getTooLargeText, getMetaLine, getSwapConfirmText } = Pblsh.AssetsUtils;

    const [assets, setAssets]               = useState(null);   // null = not yet loaded
    const [assetsLoading, setAssetsLoading] = useState(false);
    const [uploadingSlot, setUploadingSlot] = useState(null);   // 'icon_128' | 'screenshot-3' | null
    const [uploadProgress, setUploadProgress] = useState(0);
    const fileInputRefs = useRef({});  // { [slotKey]: HTMLInputElement }
    const [draggingN, setDraggingN] = useState(null);     // screenshot_n being dragged
    const [dragOverN, setDragOverN] = useState(null);     // slot N being hovered during drag
    const dragOverTimeout = useRef(null);                   // auto-clear drag-over when cursor leaves

    // The tab mounts when it is opened (also via deep link) — load once per plugin.
    useEffect(() => { fetchAssets(); }, [pluginData && pluginData.id]);

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

    // Every write answers the fresh manifest — no second request; the icon may have changed.
    const applyAssets = (result) => {
        setAssets(result.assets);
        if (typeof refreshPlugin === 'function') refreshPlugin();
    };
    // apiFetch and the upload reject with the REST error payload: message leads, the code follows.
    const reportError = (e, fallback) => Pblsh.Utils.showAlert(((e && e.message) || fallback) + (e && e.code ? '\n' + sprintf(__('Error code: %s', 'peak-publisher'), e.code) : ''), 'error');

    const handleAssetUpload = async (slot, screenshotN, file) => {
        if (!file || !pluginData || !pluginData.id) return;
        // The same limit the server enforces — refused before the upload starts.
        const slotDef = ASSET_SLOTS[slot];
        if (slotDef && file.size > slotDef.maxBytes) {
            Pblsh.Utils.showAlert(getTooLargeText(slotDef, file.size), 'error');
            return;
        }
        const slotKey = slot === 'screenshot' ? 'screenshot-' + screenshotN : slot;
        setUploadingSlot(slotKey);
        setUploadProgress(0);
        try {
            const result = await Pblsh.API.uploadPluginAsset(
                pluginData.id, slot, screenshotN, file,
                (pct) => setUploadProgress(Math.floor(pct))
            );
            applyAssets(result);
            if (result.warnings && result.warnings.length > 0) {
                Pblsh.Utils.showAlert(result.warnings.map(w => w.message).join('\n'), 'warning');
            }
        } catch (e) {
            reportError(e, __('Upload failed.', 'peak-publisher'));
        } finally {
            setUploadingSlot(null);
            setUploadProgress(0);
        }
    };

    const handleAssetDelete = async (slot, screenshotN) => {
        if (!pluginData || !pluginData.id) return;
        if (!confirm(__('Delete this asset?', 'peak-publisher'))) return;
        try {
            applyAssets(await Pblsh.API.deletePluginAsset(pluginData.id, slot, screenshotN));
        } catch (e) {
            reportError(e, __('Delete failed.', 'peak-publisher'));
        }
    };

    const handleScreenshotMove = async (fromN, toN) => {
        if (!pluginData || !pluginData.id || fromN === toN) return;
        try {
            applyAssets(await Pblsh.API.moveScreenshot(pluginData.id, fromN, toN));
        } catch (e) {
            reportError(e, __('Move failed.', 'peak-publisher'));
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

        const metaLine = hasAsset ? getMetaLine(assetData) : '';
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
                    if (!confirm(getSwapConfirmText(fromN, screenshotN))) return;
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
                        metaLine && createElement('div', { className: 'pblsh--asset-slot__box-meta' }, metaLine),
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
                            createElement('div', { className: 'pblsh--asset-slot__box-empty-expected' }, getExpectedText(raw)),
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
                        createElement('div', { className: 'pblsh--asset-slot__box-empty-expected' }, getExpectedText(ASSET_SLOTS.screenshot)),
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

    return renderAssetsSection();
});
