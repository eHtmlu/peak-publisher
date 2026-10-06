// PluginAssets Component - the assets tab of the plugin editor: icons, banners and
// screenshots in fixed slots, uploaded, replaced, deleted and reordered from here. The view
// lives in the store pblsh/assets (loaded when the editor opens, reloaded with the plugin,
// kept across tab switches), the upload in progress and the drag are the component's own;
// the plugin comes with pluginData, a changed icon reaches the header and the list through
// refreshPlugin. A
// wordpress.org plugin edits a working copy over its mirror of SVN's assets/: while it holds
// changes, the bar names them and commits them as one, every box shows what its change does,
// a conflict box lets the user decide, and wordpress.org's state can be shown alone. A mirror
// that was never read leaves the tab showing only; its notice offers the editor's Refresh
// (refreshFromWporg, refreshingWporg).
lodash.set(window, 'Pblsh.Components.PluginAssets', ({ pluginData, refreshPlugin, refreshFromWporg, refreshingWporg }) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement, Fragment, useState, useEffect, useRef } = wp.element;
    const { useSelect } = wp.data;
    const { Button, DropdownMenu, MenuItem, Spinner } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;
    const {
        getExpectedText, getTooLargeText, getMetaLine, getSwapConfirmText, getCaptionsSourceText, getPositionsHint, getNoCaptionsText, getNotSyncedNotice, getOtherFilesText,
        getPendingBarText, getConflictBarText, getCommittingText, getCommitsAsText, getNoAccountCommitText, getClosedAssetsNotice, getCommittedText, getShowWporgStateText,
        getCommitConfirmText, getDiscardConfirmText, getDiscardUploadConfirmText, getDeleteConfirmText, getBandText,
    } = Pblsh.AssetsUtils;
    const { TipLink, NoticeBox, WporgRevisionLink } = Pblsh.Components;

    const isWporg = !!pluginData && pluginData.hosting_type === 'wporg';
    // The working copy waits without an account; only the commit needs one.
    const account = isWporg ? pluginData.wporg_account : null;
    const canCommit = !!(account && account.can_write);

    const pluginId = pluginData ? pluginData.id : null;
    const assets = useSelect((select) => pluginId ? select('pblsh/assets').getForPlugin(pluginId) : null, [pluginId]);   // null = not yet loaded
    const assetsLoading = useSelect((select) => pluginId ? select('pblsh/assets').isLoadingForPlugin(pluginId) : false, [pluginId]);
    const setAssets = (view) => Pblsh.Controllers.Assets.showAfterWrite(pluginId, view);   // every caller is a write's answer
    const [busy, setBusy]                   = useState(null);   // 'committing' | 'working' | null — a working-copy request in flight
    const [showWporgState, setShowWporgState] = useState(false); // wordpress.org's state alone, read-only
    const [committedRevision, setCommittedRevision] = useState(null);   // the last commit's outcome: its revision
    const [uploadingSlot, setUploadingSlot] = useState(null);   // 'icon_128' | 'screenshot-3' | null
    const [uploadProgress, setUploadProgress] = useState(0);
    const fileInputRefs = useRef({});  // { [slotKey]: HTMLInputElement }
    const [draggingN, setDraggingN] = useState(null);     // screenshot_n being dragged
    const [dragOverN, setDragOverN] = useState(null);     // slot N being hovered during drag
    const dragOverTimeout = useRef(null);                   // auto-clear drag-over when cursor leaves

    // The editor loads the view when it opens; the tab finds it in the store. Only when that
    // load failed and nothing is in flight does the tab try again.
    useEffect(() => {
        if (!pluginId || assets || assetsLoading) return;
        Pblsh.Controllers.Assets.fetchForPlugin(pluginId).catch((e) => reportError(e, __('Loading the assets failed.', 'peak-publisher')));
    }, [pluginId]);

    // Every write answers the fresh view — no second request. A self-hosted write may change
    // the icon of header and list; a wordpress.org change does so only once it is committed.
    const applyAssets = (result) => {
        setCommittedRevision(null);
        setAssets(result.assets);
        if (!isWporg && typeof refreshPlugin === 'function') refreshPlugin();
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
        if (!confirm(getDeleteConfirmText(isWporg))) return;
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

    // The working copy of a wordpress.org plugin as one commit. A conflict answers with the view
    // after the commit's fresh pull: the boxes show what to decide.
    const handleCommit = async () => {
        if (!confirm(getCommitConfirmText(assets.wporg.slots))) return;
        setBusy('committing');
        try {
            const result = await Pblsh.API.commitPluginAssets(pluginData.id);
            setAssets(result.assets);
            setShowWporgState(false);
            setCommittedRevision(result.committed ? result.revision : null);
            if (typeof refreshPlugin === 'function') refreshPlugin();
        } catch (e) {
            if (e && e.assets) setAssets(e.assets);
            reportError(e, __('Commit failed.', 'peak-publisher'));
        } finally {
            setBusy(null);
        }
    };

    const runWorkingCopyRequest = async (request, fallback) => {
        setBusy('working');
        try {
            applyAssets(await request());
            setShowWporgState(false);
        } catch (e) {
            reportError(e, fallback);
        } finally {
            setBusy(null);
        }
    };

    const handleDiscard = () => {
        if (!confirm(getDiscardConfirmText(assets.wporg.pending_count))) return;
        runWorkingCopyRequest(() => Pblsh.API.discardPluginAssets(pluginData.id), __('Discard failed.', 'peak-publisher'));
    };

    // keep 'theirs' drops the slot's change (the band's Discard); 'mine' keeps it over wordpress.org's new file.
    const handleResolve = (slotKey, keep) => runWorkingCopyRequest(() => Pblsh.API.resolvePluginAsset(pluginData.id, slotKey, keep), __('The decision could not be saved.', 'peak-publisher'));

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

    // Caption details only matter once the readme has a caption: then a missing one usually
    // means a numbering that slipped; without any, one line and the tip say what to do.
    const hasCaptions = !!(assets && assets.screenshot_captions && Object.keys(assets.screenshot_captions).length > 0);

    const wporg = isWporg && assets ? assets.wporg : null;
    // wordpress.org's state alone means something only while the working copy holds changes.
    const showingWporgState = showWporgState && !!wporg && wporg.pending_count > 0;
    // Before the first pull there is nothing to build a change on (the server refuses it too).
    const notSynced = !!wporg && wporg.revision === null;
    // Nothing changes while wordpress.org's state is shown, a working-copy request runs or the
    // mirror was never read.
    const readOnly = showingWporgState || busy !== null || notSynced;

    // wordpress.org's state: every slot as the mirror holds it, the working copy left out.
    const wporgStateView = () => {
        const entryOf = (slotKey) => {
            const file = wporg.slots[slotKey] && wporg.slots[slotKey].on_wporg;
            return file ? { ...file, warnings: [] } : null;
        };
        const view = { ...assets, screenshots: [] };
        Object.keys(ASSET_SLOTS).filter((slot) => slot !== 'screenshot').forEach((slot) => { view[slot] = entryOf(slot); });
        Object.keys(wporg.slots).forEach((slotKey) => {
            const match = /^screenshot-(\d+)$/.exec(slotKey);
            const entry = match && entryOf(slotKey);
            if (entry) view.screenshots.push({ ...entry, screenshot_n: parseInt(match[1], 10) });
        });
        view.screenshots.sort((a, b) => a.screenshot_n - b.screenshot_n);
        return view;
    };

    // A conflict side: what it is, the picture (file: { url, filename }) in the slot's frame — or
    // that frame empty, so the missing picture still shows its shape —, what the file is, and
    // the decision that takes this side. The frame stands in a row of its own, so it keeps the
    // picture's width and its height follows that width, not the side's.
    const renderCompareSide = (label, file, meta, imageModClass, decision) => createElement('div', { className: 'pblsh--asset-slot__compare-side' },
        createElement('div', { className: 'pblsh--asset-slot__compare-label' }, label),
        createElement('div', { className: 'pblsh--asset-slot__compare-picture' },
            createElement('div', { className: 'pblsh--asset-slot__box-image ' + imageModClass + (file && file.url ? '' : ' pblsh--asset-slot__box-image--empty') },
                createElement('div', { className: 'pblsh--asset-slot__box-image-inner' },
                    file && file.url && createElement('img', { src: file.url, alt: file.filename, className: 'pblsh--asset-slot__box-img', draggable: false }),
                ),
            ),
        ),
        createElement('div', { className: 'pblsh--asset-slot__box-meta' }, meta),
        createElement('div', { className: 'pblsh--asset-slot__compare-decision' }, decision),
    );

    // Changed here and on wordpress.org: both states side by side, each with the decision that
    // takes it beneath. The wordpress.org side names the commit that changed it there — who,
    // when and why is what the decision needs.
    const renderConflict = (slotKey, label, mine, slotInfo, imageModClass) => createElement('div', { className: 'pblsh--asset-slot__conflict' },
        createElement('div', { className: 'pblsh--asset-slot__box-label' }, label),
        createElement('div', { className: 'pblsh--asset-slot__compare' },
            renderCompareSide(__('Mine', 'peak-publisher'), mine, mine ? mine.filename : __('Deleted here', 'peak-publisher'), imageModClass,
                createElement(Button, { isPrimary: true, disabled: busy !== null, onClick: () => handleResolve(slotKey, 'mine') }, __('Keep mine', 'peak-publisher'))),
            renderCompareSide(__('wordpress.org', 'peak-publisher'), slotInfo.on_wporg, slotInfo.on_wporg
                ? createElement(Fragment, null, slotInfo.on_wporg.filename, ' ', createElement(WporgRevisionLink, { revision: slotInfo.on_wporg.revision }))
                : __('Deleted there', 'peak-publisher'), imageModClass,
                createElement(Button, { isSecondary: true, disabled: busy !== null, onClick: () => handleResolve(slotKey, 'theirs') }, __('Take theirs', 'peak-publisher'))),
        ),
    );

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
        // What the working copy does to this slot (wordpress.org) — not while wordpress.org's state is shown.
        const slotInfo = wporg && !showingWporgState ? wporg.slots[slotKey] || null : null;
        const conflict = !!(slotInfo && slotInfo.conflict);
        const pendingDelete = !!(slotInfo && slotInfo.pending === 'delete');
        // The band names what the commit will do to the slot: add, replace or delete its file there.
        const band = conflict ? 'conflict' : pendingDelete ? 'delete' : slotInfo && slotInfo.pending ? (slotInfo.on_wporg ? 'replace' : 'add') : null;
        const editable = !readOnly && !conflict;
        const warnings = (assetData && assetData.warnings) || [];
        const isScreenshot = slot === 'screenshot';
        const isDragging = isScreenshot && draggingN === screenshotN;
        const isDragOver = isScreenshot && dragOverN === screenshotN && draggingN !== screenshotN;

        const metaLine = hasAsset ? getMetaLine(assetData) : '';
        const imageModClass = def.group === 'banners' ? 'pblsh--asset-slot__box-image--banner'
            : def.group === 'screenshots' ? 'pblsh--asset-slot__box-image--screenshot'
            : 'pblsh--asset-slot__box-image--icon';

        // Drag-and-drop handlers for screenshot slots
        const dragProps = isScreenshot && editable ? {
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
        const dragSourceProps = (isScreenshot && hasAsset && editable) ? {
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
            band && createElement('div', { className: 'pblsh--asset-slot__band pblsh--asset-slot__band--' + band },
                getBandText(band),
                // The slot's change is taken back here, on every band alike — in a conflict too, where
                // this is the familiar way out and "Take theirs" in the box below does the same as the
                // decision beside the pictures: kept both on purpose, one word learnt from every other
                // band. An upload is lost with its change, so that one asks; a move or a delete is only
                // taken back.
                createElement(Button, {
                    isSecondary: true,
                    className: 'pblsh--asset-slot__band-discard',
                    disabled: busy !== null,
                    onClick: () => {
                        if (slotInfo.pending === 'put' && !confirm(getDiscardUploadConfirmText())) return;
                        handleResolve(slotKey, 'theirs');
                    },
                }, __('Discard', 'peak-publisher')),
            ),
            editable && createElement('input', {
                ref: (el) => { fileInputRefs.current[slotKey] = el; },
                type: 'file',
                accept: def.accept,
                className: 'pblsh--hidden-file-input',
                onChange: (e) => { const file = e.target.files && e.target.files[0]; if (file) handleAssetUpload(slot, screenshotN, file); },
            }),
            conflict ? renderConflict(slotKey, def.label, assetData, slotInfo, imageModClass)
            : hasAsset
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
                        isScreenshot && hasCaptions && !caption && createElement('div', { className: 'pblsh--asset-slot__hint' }, __('No caption in readme.txt', 'peak-publisher')),
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
                            editable && createElement(Button, {
                                isPrimary: true,
                                className: 'pblsh--asset-slot__box-upload-btn',
                                onClick: () => openFilePicker(slot, screenshotN),
                                disabled: isUploading,
                            }, __('Select File', 'peak-publisher')),
                        ),
                ),
            hasAsset && editable && createElement('div', { className: 'pblsh--asset-slot__actions' },
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
            return createElement('div', { key: 'card', className: 'pblsh--card pblsh--assets-card' },
                createElement('div', { className: 'pblsh--loading pblsh--loading--small' },
                    createElement('div', { className: 'pblsh--loading__spinner' }),
                ),
            );
        }

        const view = showingWporgState ? wporgStateView() : assets;

        // Build screenshot slot map: { N: assetData } for quick lookup
        const screenshots = (view && view.screenshots) || [];
        const captions = (view && view.screenshot_captions) || {};
        const screenshotMap = {};
        screenshots.forEach(s => { screenshotMap[s.screenshot_n] = s; });
        // Positions with a pending change stay visible — a deleted screenshot keeps its box until the commit.
        const changedNs = {};
        if (wporg && !showingWporgState) {
            Object.keys(wporg.slots).forEach((slotKey) => {
                const match = /^screenshot-(\d+)$/.exec(slotKey);
                if (match && wporg.slots[slotKey].pending) changedNs[parseInt(match[1], 10)] = true;
            });
        }

        // Determine visible slot range
        const captionKeys = Object.keys(captions).map(Number).filter(n => n > 0);
        const screenshotKeys = screenshots.map(s => s.screenshot_n);
        const maxCaption = captionKeys.length > 0 ? Math.max(...captionKeys) : 0;
        const maxScreenshot = screenshotKeys.length > 0 ? Math.max(...screenshotKeys) : 0;
        const maxN = Math.max(maxCaption, maxScreenshot, ...Object.keys(changedNs).map(Number));

        // Trim trailing empty slots that have no caption
        let visibleMaxN = maxN;
        while (visibleMaxN > 0 && !screenshotMap[visibleMaxN] && !captions[visibleMaxN] && !changedNs[visibleMaxN]) {
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

        return [
            ...renderNotices(),
            createElement('div', { key: 'card', className: 'pblsh--card pblsh--assets-card' },
                renderBar(),
                // Icons group
                createElement('div', { className: 'pblsh--assets-group' },
                    createElement('div', { className: 'pblsh--assets-group__label' }, __('Icons', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                        renderAssetBox('icon_svg', view && view.icon_svg),
                        renderAssetBox('icon_256', view && view.icon_256),
                        renderAssetBox('icon_128', view && view.icon_128),
                    ),
                ),
                // Banners group
                createElement('div', { className: 'pblsh--assets-group' },
                    createElement('div', { className: 'pblsh--assets-group__label' }, __('Banners', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                        renderAssetBox('banner_svg', view && view.banner_svg),
                        renderAssetBox('banner_hd', view && view.banner_hd),
                        renderAssetBox('banner_sd', view && view.banner_sd),
                    ),
                ),
                // Screenshots group (slot-based)
                createElement('div', { className: 'pblsh--assets-group' },
                    createElement('div', { className: 'pblsh--assets-group__label' }, __('Screenshots', 'peak-publisher')),
                    hasCaptions
                        ? createElement('div', { className: 'pblsh--assets-group__hints' },
                            createElement('div', null, getCaptionsSourceText(pluginData, assets.captions_source)),
                            createElement('div', null, getPositionsHint(), ' · ', createElement(TipLink, { tipKey: 'screenshotCaptions' })),
                        )
                        : screenshots.length > 0 && createElement('div', { className: 'pblsh--assets-group__hints' },
                            createElement('div', null, getNoCaptionsText(), ' · ', createElement(TipLink, { tipKey: 'screenshotCaptions' })),
                        ),
                    createElement('div', { className: 'pblsh--assets-slots pblsh--assets-slots--boxes' },
                        slots.map(({ n, screenshot, caption }) =>
                            renderAssetBox('screenshot', screenshot, n, caption)
                        ),
                        !readOnly && newScreenshotBox,
                    ),
                ),
                // Files of assets/ that are no slot here stay as they are on wordpress.org.
                wporg && wporg.other_files > 0 && createElement('div', { className: 'pblsh--assets-footer' },
                    getOtherFilesText(wporg.other_files), ' · ',
                    createElement('a', {
                        href: 'https://plugins.svn.wordpress.org/' + encodeURIComponent(pluginData.slug) + '/assets/',
                        target: '_blank',
                        rel: 'noreferrer',
                    }, __('Open on wordpress.org', 'peak-publisher')),
                ),
            ),
        ];
    };

    // Above the card, flush in the tab panel: the last commit's outcome, why nothing can be
    // changed while the mirror was never read, the directory's verdict, and why the commit
    // waits when no account can make it.
    const renderNotices = () => [
        committedRevision && createElement(NoticeBox, { key: 'committed', variant: 'info', className: 'pblsh--tab-panel__notice' },
            createElement('p', null, createInterpolateElement(getCommittedText(), { revision: createElement(WporgRevisionLink, { revision: committedRevision }) })),
        ),
        notSynced && createElement(NoticeBox, { key: 'not-synced', variant: 'warning', className: 'pblsh--tab-panel__notice' },
            createElement('p', null, getNotSyncedNotice(), ' ',
                createElement(Button, { isLink: true, isBusy: refreshingWporg, disabled: refreshingWporg, onClick: refreshFromWporg }, __('Refresh', 'peak-publisher')),
            ),
        ),
        isWporg && pluginData.wporg_stats && pluginData.wporg_stats.closed && createElement(NoticeBox, { key: 'closed', variant: 'warning', className: 'pblsh--tab-panel__notice' },
            createElement('p', null, getClosedAssetsNotice()),
        ),
        wporg && wporg.pending_count > 0 && !canCommit && createElement(NoticeBox, { key: 'account', variant: 'info', className: 'pblsh--tab-panel__notice' },
            createElement('p', null, getNoAccountCommitText()),
        ),
    ].filter(Boolean);

    // wordpress.org: the working copy's changes with commit, discard and the switch to
    // wordpress.org's state; while committing, only that. Nothing to commit, no bar — how
    // fresh the mirror is says the editor's status line for the whole plugin.
    const renderBar = () => {
        if (!wporg) return null;
        if (busy === 'committing') {
            return createElement('div', { className: 'pblsh--assets-bar' },
                createElement('div', { className: 'pblsh--assets-bar__status pblsh--assets-bar__status--busy' }, createElement(Spinner), getCommittingText()),
            );
        }
        if (wporg.pending_count === 0) return null;
        return createElement('div', { className: 'pblsh--assets-bar pblsh--assets-bar--pending' },
            createElement('div', { className: 'pblsh--assets-bar__status' },
                createElement('strong', null, getPendingBarText(wporg.pending_count)),
                wporg.conflict_count > 0 && createElement('div', { className: 'pblsh--assets-bar__conflicts' }, getConflictBarText(wporg.conflict_count)),
                canCommit && createElement('div', { className: 'pblsh--assets-bar__account' }, getCommitsAsText(account.username)),
            ),
            createElement('div', { className: 'pblsh--assets-bar__actions' },
                createElement(Button, { isPrimary: true, disabled: !canCommit || wporg.conflict_count > 0 || busy !== null, onClick: handleCommit }, __('Commit to wordpress.org', 'peak-publisher')),
                createElement(Button, { isSecondary: true, disabled: busy !== null, onClick: handleDiscard }, __('Discard', 'peak-publisher')),
                createElement(Button, { isLink: true, disabled: busy !== null, onClick: () => setShowWporgState(!showingWporgState) }, getShowWporgStateText(showingWporgState)),
            ),
        );
    };

    return renderAssetsSection();
});
