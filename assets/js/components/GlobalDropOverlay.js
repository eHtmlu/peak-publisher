// GlobalDropOverlay Component - handles app-wide drag & drop upload
lodash.set(window, 'Pblsh.Components.GlobalDropOverlay', ({ onCreated, activeUploadContext = {} } = {}) => {
    const { __ } = wp.i18n;
    const sprintf = wp.i18n.sprintf ?? window.sprintf;
    const { useState, useEffect, useRef, createElement, createInterpolateElement } = wp.element;
    const { Button, CheckboxControl } = wp.components;
    const { useSelect } = wp.data;
    const { getSvgIcon, getFaqUrl } = Pblsh.Utils;
    const { WporgAccessGate } = Pblsh.Components;

    const fileInputRef = useRef(null);
    const dialogRef = useRef(null);
    const activeUploadContextRef = useRef(activeUploadContext && typeof activeUploadContext === 'object' ? activeUploadContext : {});
    const [visible, setVisible] = useState(false);
    const [dragCounter, setDragCounter] = useState(0);
    const [zipProgress, setZipProgress] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);
    const [processPhase, setProcessPhase] = useState('');
    const [validationResult, setValidationResult] = useState(null);
    const [filename, setFilename] = useState('');
    const [useDifferentCustomUpdateServer, setUseDifferentCustomUpdateServer] = useState(false);
    const [usePeakPublisherForNewUpdateServer, setUsePeakPublisherForNewUpdateServer] = useState(false);
    const [useWordPressOrgUpdateServer, setUseWordPressOrgUpdateServer] = useState(false);
    const [replaceRelease, setReplaceRelease] = useState(false);
    const [changePluginFileName, setChangePluginFileName] = useState(false);
    const [useUnexpectedPluginVersion, setUseUnexpectedPluginVersion] = useState(false);
    const [useOlderPluginVersion, setUseOlderPluginVersion] = useState(false);
    const [useNotPeakPublisherForNewUpdateServer, setUseNotPeakPublisherForNewUpdateServer] = useState(false);
    const [keepWorkspaceArtifacts, setKeepWorkspaceArtifacts] = useState(false);
    const [keepReadmeTxtBom, setKeepReadmeTxtBom] = useState(false);
    const [keepReadmeTxtEncoding, setKeepReadmeTxtEncoding] = useState(false);
    const [keepReadmeTxtAsIs, setKeepReadmeTxtAsIs] = useState(false);
    const [keepOldBootstrapCode, setKeepOldBootstrapCode] = useState(false);
    const [selectedHostingType, setSelectedHostingType] = useState(null);

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);

    function getActiveUploadContext() {
        // Snapshot the central upload context for this upload run
        const context = activeUploadContextRef.current;
        return context && typeof context === 'object' ? { ...context } : {};
    }

    function isUploadPhaseOk(result) {
        return result && result.status === 'ok';
    }

    function withUploadId(result, upload_id) {
        return {
            ...result,
            upload_id: result?.upload_id || upload_id,
        };
    }

    function showUploadPhaseResult(result, upload_id) {
        setIsProcessing(false);
        setUploadProgress(false);
        setZipProgress(false);
        setValidationResult(withUploadId(result, upload_id));
    }

    function resetOverlayTransientState() {
        setDragCounter(0);
        setVisible(false);
        setZipProgress(false);
        setUploadProgress(false);
        setIsProcessing(false);
        setProcessPhase('');
        setFilename('');
        resetResultDecisions();
    }

    function resetResultDecisions() {
        setUseDifferentCustomUpdateServer(false);
        setUsePeakPublisherForNewUpdateServer(false);
        setUseWordPressOrgUpdateServer(false);
        setReplaceRelease(false);
        setChangePluginFileName(false);
        setUseUnexpectedPluginVersion(false);
        setUseOlderPluginVersion(false);
        setUseNotPeakPublisherForNewUpdateServer(false);
        setKeepWorkspaceArtifacts(false);
        setKeepReadmeTxtBom(false);
        setKeepReadmeTxtEncoding(false);
        setKeepReadmeTxtAsIs(false);
        setKeepOldBootstrapCode(false);
        setSelectedHostingType(null);
    }

    useEffect(() => {
        // Mirror the latest upload context for window event handlers
        activeUploadContextRef.current = activeUploadContext && typeof activeUploadContext === 'object' ? activeUploadContext : {};
    }, [activeUploadContext]);

    useEffect(() => {
        // Check if a drag event carries external files (not an internal page drag like screenshot reordering)
        const isExternalFileDrag = (e) => e.dataTransfer && e.dataTransfer.types && e.dataTransfer.types.indexOf('Files') !== -1;

        const onDragEnter = (e) => {
            if (!isExternalFileDrag(e)) return;
            e.preventDefault();
            setDragCounter((c) => c + 1);
            setVisible(true);
        };
        const onDragOver = (e) => {
            if (!isExternalFileDrag(e)) return;
            e.preventDefault();
        };
        const onDragLeave = (e) => {
            e.preventDefault();
            setDragCounter((c) => Math.max(0, c - 1));
        };
        const onDragEnd = (e) => {
            setDragCounter(0);
        };
        const onDrop = (e) => {
            if (!isExternalFileDrag(e)) return;
            e.preventDefault();
            setDragCounter(0);
            setVisible(true);
            const items = e.dataTransfer && e.dataTransfer.items ? e.dataTransfer.items : null;
            const files = e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : null;
            if (items && items.length > 0 && typeof items[0].webkitGetAsEntry === 'function') {
                // show a short collecting indicator via zipProgress=0
                setZipProgress(0);
                Pblsh.UploadUtils.gatherFilesFromItems(items).then((result) => {
                    const list = result.list;
                    const roots = result.roots;
                    const hasDirectory = result.hasDirectory;
                    if (list.length > 0) {
                        // direct zip upload if single zip file
                        if (!hasDirectory && list.length === 1 && /\.zip$/i.test(list[0].file.name)) {
                            setFilename(list[0].file.name);
                            setZipProgress(false);
                            startUpload(list[0].file);
                            return;
                        }
                        const isSingleTopLevelFolder = hasDirectory && roots.length === 1;
                        setFilename(isSingleTopLevelFolder ? roots[0] : '');
                        startUploadDirectory(list);
                    } else if (files && files.length > 0) {
                        const f = files[0];
                        if (f && /\.zip$/i.test(f.name)) {
                            setFilename(f.name);
                            setZipProgress(false);
                            startUpload(f);
                        } else {
                            setZipProgress(false);
                        }
                    } else {
                        setZipProgress(false);
                    }
                });
                return;
            }
            if (files && files.length > 0) {
                const f = files[0];
                if (f && /\.zip$/i.test(f.name)) {
                    setFilename(f.name);
                    startUpload(f);
                }
            }
        };
        window.addEventListener('dragenter', onDragEnter);
        window.addEventListener('dragover', onDragOver);
        window.addEventListener('dragleave', onDragLeave);
        window.addEventListener('dragend', onDragEnd);
        window.addEventListener('drop', onDrop);
        return () => {
            window.removeEventListener('dragenter', onDragEnter);
            window.removeEventListener('dragover', onDragOver);
            window.removeEventListener('dragleave', onDragLeave);
            window.removeEventListener('dragend', onDragEnd);
            window.removeEventListener('drop', onDrop);
        };
    }, []);

    // Auto-hide overlay when dragging leaves the window and no process is running
    useEffect(() => {
        if (dragCounter === 0 && uploadProgress === false && zipProgress === false && !isProcessing && !validationResult) {
            // small delay to avoid flicker when moving between child elements
            const t = setTimeout(() => setVisible(false), 120);
            return () => clearTimeout(t);
        }
    }, [dragCounter, uploadProgress, zipProgress, isProcessing, validationResult]);

    // Allow external trigger to open file picker (without showing overlay yet)
    useEffect(() => {
        const onOpenPicker = () => {
            // Delay to ensure input is in DOM
            setTimeout(() => {
                if (fileInputRef.current) {
                    try { fileInputRef.current.click(); } catch (e) {}
                }
            }, 0);
        };
        window.addEventListener('pblsh:open-overlay-file-picker', onOpenPicker);
        return () => window.removeEventListener('pblsh:open-overlay-file-picker', onOpenPicker);
    }, []);

    const closeOverlay = () => {
        resetOverlayTransientState();
        setValidationResult(null);
    };

    useEffect(() => {
        // Reset the target selection per upload. Keyed on upload_id so later updates of the same
        // result (finalize errors, import refresh) keep the user's choice.
        const meta = validationResult?.data || {};
        if (meta?.hosting_type_choice && meta?.hosting_type_targets) {
            setSelectedHostingType(meta.hosting_type_default || null);
        } else {
            setSelectedHostingType(null);
        }
    }, [validationResult?.upload_id]);

    async function runUploadWorkflow(file, context, uploadStartOptions = {}) {
        setUploadProgress(0);

        try {
            const res1 = await Pblsh.API.uploadStart(file, (percent) => {
                setUploadProgress(percent);
                if (percent >= 100) {
                    setUploadProgress(false);
                    setIsProcessing(true);
                    setProcessPhase('upload_prepare');
                }
            }, uploadStartOptions);

            setUploadProgress(false);
            const uploadId = res1 && res1.upload_id ? res1.upload_id : null;
            if (!isUploadPhaseOk(res1) && Array.isArray(res1?.errors)) {
                showUploadPhaseResult(res1, uploadId);
                return;
            }
            if (!uploadId) throw new Error('Missing upload_id after upload');

            setIsProcessing(true);
            setProcessPhase('unpack');
            const resUnpack = await Pblsh.API.uploadContinue(uploadId, 'unpack');
            if (!isUploadPhaseOk(resUnpack)) {
                showUploadPhaseResult(resUnpack, uploadId);
                return;
            }

            setProcessPhase('analyze');
            const resAnalyze = await Pblsh.API.uploadContinue(uploadId, 'analyze', context);
            if (!isUploadPhaseOk(resAnalyze)) {
                showUploadPhaseResult(resAnalyze, uploadId);
                return;
            }

            if ((resAnalyze && resAnalyze.next ? resAnalyze.next : 'result') === 'rebuild_zip') {
                setProcessPhase('rebuild_zip');
                const resRebuild = await Pblsh.API.uploadContinue(uploadId, 'rebuild_zip');
                if (!isUploadPhaseOk(resRebuild)) {
                    showUploadPhaseResult(resRebuild, uploadId);
                    return;
                }
            }

            setProcessPhase('result');
            const resResult = await Pblsh.API.uploadContinue(uploadId, 'result');
            setIsProcessing(false);
            setValidationResult(withUploadId(resResult, uploadId));
        } catch (err) {
            setZipProgress(false);
            setUploadProgress(false);
            setIsProcessing(false);
            setValidationResult({ status: 'error', errors: [ { code: 'upload_error', message: err?.message || __('Upload failed.', 'peak-publisher') } ], data: {} });
        }
    }

    function startUpload(file) {
        // Read upload context exactly when the upload starts
        const context = getActiveUploadContext();
        setVisible(true);
        setZipProgress(false);
        setIsProcessing(false);
        setValidationResult(null);
        runUploadWorkflow(file, context);
    }

    async function startUploadDirectory(filesWithPaths) {
        // Read upload context exactly when directory upload starts
        const context = getActiveUploadContext();
        setVisible(true);
        setZipProgress(0);
        setUploadProgress(false);
        setIsProcessing(false);
        setValidationResult(null);

        let zipFile = null;
        try {
            zipFile = await Pblsh.UploadUtils.createZipFromFiles(filesWithPaths, (p) => {
                setZipProgress(prev => Math.max(prev, Math.floor(p)));
            });
        } catch (err) {
            setZipProgress(false);
            setUploadProgress(false);
            setIsProcessing(false);
            setValidationResult({ status: 'error', errors: [ { code: 'zip_error', message: err?.message || 'Zipping failed' } ], data: {} });
            return;
        }

        setZipProgress(false);
        runUploadWorkflow(zipFile, context, { built_in_browser: 'jszip' });
    }
    

    function getTargetKeys(meta = {}) {
        // Key order follows the server's target order — it defines the display order.
        const targets = meta?.hosting_type_targets || {};
        return Object.keys(targets).filter((key) => targets[key]);
    }

    function getActiveTargetKey(meta = {}) {
        const targets = meta?.hosting_type_targets || {};
        if (!targets || typeof targets !== 'object') return null;
        if (meta?.hosting_type_choice) {
            if (selectedHostingType && targets[selectedHostingType]) return selectedHostingType;
            if (meta.hosting_type_default && targets[meta.hosting_type_default]) return meta.hosting_type_default;
            return null;
        }
        if (meta?.hosting_type_resolved && targets[meta.hosting_type_resolved]) return meta.hosting_type_resolved;
        const keys = getTargetKeys(meta);
        return keys.length === 1 ? keys[0] : null;
    }

    function getEffectiveMetaForTarget(meta = {}, targetKey = null) {
        const target = targetKey ? meta?.hosting_type_targets?.[targetKey] : null;
        if (!target) return meta;
        return {
            ...meta,
            hosting_type_resolved: targetKey,
            existing_plugin: target.existing_plugin_id || false,
            related_releases: target.related_releases || false,
        };
    }

    function renderHostingTypeChoice(meta = {}) {
        if (!meta?.hosting_type_choice || !meta?.hosting_type_targets) return null;
        const keys = getTargetKeys(meta);
        if (keys.length === 0) return null;
        const visualSelectedHostingType = selectedHostingType || meta.hosting_type_default || null;
        const targetIcons = {
            wporg: 'wordpress',
            self_hosted: 'server',
        };

        return createElement('div', { className: 'pblsh--hosting-type-choice', role: 'radiogroup', 'aria-label': __('Distribution channel', 'peak-publisher') },
            !visualSelectedHostingType && createElement('h3', { className: 'pblsh--hosting-type-choice__prompt' }, __('Choose the distribution channel', 'peak-publisher')),
            keys.map((key) => {
                const target = meta.hosting_type_targets[key] || {};
                const id = 'pblsh-upload-target-' + key;
                return createElement('label', {
                    key,
                    className: [
                        'pblsh--hosting-type-choice__option',
                        visualSelectedHostingType === key ? 'is-selected' : '',
                    ].filter(Boolean).join(' '),
                    htmlFor: id,
                },
                    createElement('input', {
                        id,
                        type: 'radio',
                        name: 'pblsh-upload-hosting-type',
                        value: key,
                        checked: visualSelectedHostingType === key,
                        onChange: () => setSelectedHostingType(key),
                    }),
                    targetIcons[key] && createElement('span', { className: 'pblsh--hosting-type-choice__icon' }, getSvgIcon(targetIcons[key], { size: 24 })),
                    createElement('span', { className: 'pblsh--hosting-type-choice__label' }, target.label || key),
                    target.description && createElement('span', { className: 'pblsh--hosting-type-choice__desc' }, target.description),
                );
            }),
            !visualSelectedHostingType && createElement('div', { className: 'pblsh--hosting-type-choice__note' },
                createElement('span', { className: 'pblsh--hosting-type-choice__note-icon' }, getSvgIcon('information_outline', { size: 20 })),
                createElement('div', { className: 'pblsh--hosting-type-choice__note-text' },
                    createElement('p', null,
                        createElement('strong', null,
                            __('Not sure?', 'peak-publisher')
                        ),
                    ),
                    createElement('p', null,
                        createInterpolateElement(
                            sprintf(__('Choose <strong>%s</strong> if your plugin is free and open-source and you want it listed in the official WordPress plugin directory so that it can be easily found and installed by everyone — however, your plugin must first be <a>reviewed and approved</a> by the WordPress.org plugin team.', 'peak-publisher'), meta.hosting_type_targets?.wporg?.label || 'wporg'),
                            {
                                strong: createElement('strong'),
                                a: createElement('a', { href: 'https://developer.wordpress.org/plugins/wordpress-org/', target: '_blank' }),
                            }
                        ),
                    ),
                    createElement('p', null,
                        // Once license management exists, examples become useful again: "— for example premium, internal, or client plugins."
                        createInterpolateElement(
                            sprintf(__('Otherwise choose <strong>%1$s</strong> — releases are then distributed directly from your own server %2$s, without any review process, and go live instantly. Note that self-hosted plugins are also publicly accessible via the update API by default; you can restrict access with the IP/domain whitelist in the settings.', 'peak-publisher'), meta.hosting_type_targets?.self_hosted?.label || 'self_hosted', window.location.hostname),
                            {
                                strong: createElement('strong'),
                            }
                        ),
                    ),
                    createElement('ul', { className: 'pblsh--hosting-type-choice__note-links' },
                        createElement('li', null,
                            createElement('a', { href: getFaqUrl('bothChannels'), target: '_blank' }, __('Can I use both channels for one plugin?', 'peak-publisher')),
                        ),
                        createElement('li', null,
                            createElement('a', { href: getFaqUrl('switchLater'), target: '_blank' }, __('Can I switch the channel later?', 'peak-publisher')),
                        ),
                    ),
                ),
            ),
        );
    }

    async function finalizeCreation(uploadId, opts = {}) {
        if (!uploadId) return;
        setIsProcessing(true);
        let res;
        try {
            res = await Pblsh.API.finalizeUpload(uploadId, opts);
        } catch (err) {
            // Shape the exception like an error response so a single error path handles both.
            res = { code: 'finalize_error', message: err?.message || '' };
        }
        setIsProcessing(false);

        if (res && res.status === 'ok' && res.plugin_id) {
            if (typeof onCreated === 'function') {
                closeOverlay();
                onCreated(res.plugin_id);
            }
            return;
        }

        const fallbackError = { code: res?.code || 'finalize_failed', message: res?.message || __('Finalize failed.', 'peak-publisher') };
        setValidationResult({
            ...(validationResult || {}),
            ...(res && typeof res === 'object' ? res : {}),
            status: 'error',
            upload_id: res?.upload_id || uploadId,
            data: res?.data || validationResult?.data || {},
            errors: Array.isArray(res?.errors) && res.errors.length > 0 ? res.errors : [fallbackError],
        });
    }

    function renderUploadResultHeader(header = {}) {
        return createElement('header', { className: 'pblsh--upload-result__plugin' },
            createElement('h2', { className: 'pblsh--upload-result__plugin__headline' }, header.headline),
            header.desc !== undefined && createElement('div', { className: 'pblsh--upload-result__plugin__desc' }, header.desc),
            header.type !== undefined && createElement('div', { className: 'pblsh--upload-result__plugin__type' }, header.type),
        );
    }

    function renderChecklist(items) {
        const checkTypes = {
            ok: { className: 'pblsh--check pblsh--check--ok', icon: 'check_bold' },
            info: { className: 'pblsh--check pblsh--check--info', icon: 'information_outline' },
            error: { className: 'pblsh--check pblsh--check--error', icon: 'close_thick' },
        };
        const normalizedItems = items.flat(Infinity).filter(Boolean);
        if (normalizedItems.length === 0) return null;
        return createElement('div', { className: 'pblsh--upload-result__checks' },
            createElement('ul', { className: 'pblsh--checklist' },
                normalizedItems.map((item, index) => {
                    const checkType = checkTypes[item.type] || checkTypes.error;
                    return createElement('li', {
                        key: index,
                        className: checkType.className,
                    },
                        createElement('span', { className: 'pblsh--check__icon' }, getSvgIcon(checkType.icon, { size: 24 })),
                        createElement('span', { className: 'pblsh--check__text' },
                            createElement('span', { className: 'pblsh--check__title' }, item.title),
                            item.desc && createElement('span', { className: 'pblsh--check__desc' }, item.desc),
                        ),
                    );
                }),
            ),
        );
    }

    function renderDiscardButton(uploadId) {
        return createElement(Button, {
            isSecondary: true,
            onClick: async () => {
                closeOverlay();
                if (uploadId) {
                    // Fire-and-forget: leftovers of a failed discard are collected by the tmp-uploads cleanup.
                    try { await Pblsh.API.discardUpload(uploadId); } catch (e) {}
                }
            },
            __next40pxDefaultSize: true,
        }, __('Discard', 'peak-publisher'));
    }

    function renderUploadResultShell({
        classNames = [],
        header,
        meta = null,
        body = null,
        checklistItems = null,
        actions = [],
    }) {
        const bodyContent = [
            body,
            checklistItems !== null && renderChecklist(checklistItems),
        ].filter(Boolean);
        const visibleActions = actions.flat(Infinity).filter(Boolean);
        return createElement('div', {
            className: ['pblsh--upload-result', ...classNames].filter(Boolean).join(' '),
        },
            renderUploadResultHeader(header),
            meta && renderHostingTypeChoice(meta),
            bodyContent.length > 0 && createElement('div', { className: 'pblsh--upload-result__body' }, ...bodyContent),
            visibleActions.length > 0 && createElement('footer', { className: 'pblsh--upload-result__actions' }, ...visibleActions),
        );
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const value = Number(bytes) || 0;
        if (value <= 0) return '0 B';
        const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        return (value / Math.pow(1024, index)).toFixed(2) + ' ' + units[index];
    }

    function getSlugSourceLabel(source) {
        switch (source) {
            case 'top_level_folder':
                return __('from top-level folder', 'peak-publisher');
            case 'main_file_basename':
                return __('from main plugin file name', 'peak-publisher');
            case 'zip_filename':
                return __('from ZIP filename', 'peak-publisher');
            default:
                return source ? String(source) : __('source unknown', 'peak-publisher');
        }
    }

    function getSlugSummary(meta = {}) {
        const slug = meta?.slug || '';
        const source = getSlugSourceLabel(meta?.slug_source);
        return slug ? sprintf(__('%s (%s)', 'peak-publisher'), slug, source) : source;
    }

    async function importWporgAndContinue(upload_id, meta, target) {
        // Import current wordpress.org state before refreshing the deploy target
        const slug = meta?.slug || '';
        const username = target?.pre_deploy_import?.username || '';
        if (!upload_id || !slug || !username) return;

        setIsProcessing(true);
        try {
            const response = await Pblsh.API.importWporgPlugins(username, [slug]);
            const imported = Array.isArray(response?.imported) ? response.imported : [];
            const skipped = Array.isArray(response?.skipped) ? response.skipped : [];
            const importedOk = imported.some((item) => item && item.slug === slug);
            const alreadyImported = skipped.some((item) => item && item.slug === slug && item.reason === 'already_imported');
            if (!importedOk && !alreadyImported) {
                const firstSkip = skipped.find((item) => item && item.slug === slug) || skipped[0] || null;
                throw new Error(firstSkip?.message || __('wordpress.org import failed.', 'peak-publisher'));
            }

            const refreshed = await Pblsh.API.uploadContinue(upload_id, 'refresh_target_context');
            setValidationResult(refreshed);
        } catch (error) {
            setValidationResult({
                status: 'error',
                errors: [{ code: 'wporg_import_failed', message: error?.message || __('wordpress.org import failed.', 'peak-publisher') }],
                data: meta || {},
                upload_id,
            });
        } finally {
            setIsProcessing(false);
        }
    }

    function getReleaseContext(meta = {}) {
        const related = meta?.related_releases || {};
        const previousRelease = related?.previous || false;
        const nextRelease = related?.next || false;
        const latestRelease = related?.latest || false;
        const existingRelease = related?.existing || false;

        const previousReleaseVersion = previousRelease?.normalized_version ? previousRelease.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        const pluginVersion = meta?.plugin_info?.normalized_version ? meta.plugin_info.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        const naturalSuccessors = [];

        if (previousReleaseVersion.length > 0 && pluginVersion.length > 0) {
            while (previousReleaseVersion.length < pluginVersion.length) {
                previousReleaseVersion.push(0);
            }
            while (pluginVersion.length < previousReleaseVersion.length) {
                pluginVersion.push(0);
            }

            for (let i = 0; i < previousReleaseVersion.length; i++) {
                naturalSuccessors.push([...previousReleaseVersion.slice(0, i), previousReleaseVersion[i] + 1, ...(new Array(pluginVersion.length - i - 1).fill(0))].join('.'));
            }
        }

        const isNaturalSuccessor = naturalSuccessors.includes(pluginVersion.join('.'));
        const releaseKind = {
            major: meta.existing_plugin && (
                (previousRelease && pluginVersion[0] > previousReleaseVersion[0]) ||
                (!previousRelease && pluginVersion[1] === 0 && pluginVersion[2] === 0)
            ),
            minor: meta.existing_plugin && (
                (previousRelease && pluginVersion[0] === previousReleaseVersion[0] && pluginVersion[1] > previousReleaseVersion[1]) ||
                (!previousRelease && pluginVersion[1] > 0 && pluginVersion[2] === 0)
            ),
            patch: meta.existing_plugin && (
                (previousRelease && pluginVersion[0] === previousReleaseVersion[0] && pluginVersion[1] === previousReleaseVersion[1] && pluginVersion[2] > previousReleaseVersion[2]) ||
                (!previousRelease && pluginVersion[2] > 0)
            ),
        };
        releaseKind.unknown = meta.existing_plugin && !releaseKind.major && !releaseKind.minor && !releaseKind.patch;

        return {
            previousRelease,
            nextRelease,
            latestRelease,
            existingRelease,
            hasReleaseData: !!(previousRelease || nextRelease || latestRelease || existingRelease),
            previousReleaseVersion,
            pluginVersion,
            naturalSuccessors,
            isNaturalSuccessor,
            releaseKind,
        };
    }

    function getReleasePresentation(meta, releaseContext, isWporg) {
        const existingRelease = releaseContext.existingRelease || false;
        const releaseKind = releaseContext.releaseKind || {};
        let state = 'invalid';

        if (meta.plugin_ok) {
            if (!meta.existing_plugin) {
                state = 'new_plugin';
            } else if (existingRelease) {
                state = 'replace_release';
            } else if (releaseKind.major) {
                state = 'new_major_release';
            } else if (releaseKind.minor) {
                state = 'new_minor_release';
            } else if (releaseKind.patch) {
                state = 'new_patch_release';
            } else {
                state = 'new_release';
            }
        }

        const presentations = {
            invalid: {
                classNames: ['pblsh--upload-result--invalid'],
                type: __('Invalid', 'peak-publisher'),
                submitLabel: '',
            },
            new_plugin: {
                classNames: ['pblsh--upload-result--newplugin'],
                type: __('New Plugin', 'peak-publisher'),
                submitLabel: isWporg ? __('Deploy to wordpress.org', 'peak-publisher') : __('Add Plugin', 'peak-publisher'),
            },
            replace_release: {
                classNames: ['pblsh--upload-result--releasereplacement'],
                type: __('Replace Existing Release', 'peak-publisher'),
                submitLabel: isWporg ? __('Replace on wordpress.org', 'peak-publisher') : __('Replace Existing Release', 'peak-publisher'),
            },
            new_major_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newmajor'],
                type: __('New Major Release', 'peak-publisher'),
                submitLabel: isWporg ? __('Deploy to wordpress.org', 'peak-publisher') : __('Add Major Release', 'peak-publisher'),
            },
            new_minor_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newminor'],
                type: __('New Minor Release', 'peak-publisher'),
                submitLabel: isWporg ? __('Deploy to wordpress.org', 'peak-publisher') : __('Add Minor Release', 'peak-publisher'),
            },
            new_patch_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newpatch'],
                type: __('New Patch Release', 'peak-publisher'),
                submitLabel: isWporg ? __('Deploy to wordpress.org', 'peak-publisher') : __('Add Patch Release', 'peak-publisher'),
            },
            new_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newunknown'],
                type: __('New Release', 'peak-publisher'),
                submitLabel: isWporg ? __('Deploy to wordpress.org', 'peak-publisher') : __('Add Release', 'peak-publisher'),
            },
        };
        return presentations[state];
    }

    function getUploadCheckContext(result, meta, targetKey) {
        const pluginData = meta?.plugin_data || {};
        const uploadId = result?.upload_id || false;
        const target = targetKey ? (meta?.hosting_type_targets?.[targetKey] || {}) : {};
        const validation = target?.target_validation || {};
        const blockers = Array.isArray(validation.blocking_errors) ? validation.blocking_errors : [];
        const preDeployRequired = !!target?.pre_deploy_import?.required;
        const releaseContext = getReleaseContext(meta);
        const resultErrors = Array.isArray(result?.errors) ? result.errors : [];
        const firstResultError = resultErrors[0] || null;
        // Mirrors the access-blocked condition in WporgAccessGate.getVariant: when the gate shows,
        // it displays the first result error as its status row, so the checklist must skip it.
        const extraResultErrors = targetKey === 'wporg' && !preDeployRequired && target.wporg_access_status !== 'ok'
            ? resultErrors.slice(1)
            : resultErrors;

        return {
            result,
            meta,
            pluginData,
            settings: serverSettings || {},
            uploadId,
            targetKey,
            isWporg: targetKey === 'wporg',
            target,
            validation,
            blockers,
            preDeployRequired,
            releaseContext,
            presentation: getReleasePresentation(meta, releaseContext, targetKey === 'wporg'),
            resultErrors,
            firstResultError,
            extraResultErrors,
        };
    }

    function checkPluginFile(context) {
        const { meta, pluginData, releaseContext } = context;
        const previousRelease = releaseContext.previousRelease;

        if (!previousRelease) {
            return {
                title: __('Valid plugin file', 'peak-publisher'),
                type: 'ok',
                desc: [meta.plugin_info?.main_file],
            };
        }

        if (previousRelease.plugin_basename === meta.plugin_info?.plugin_basename) {
            return {
                title: __('Expected plugin file', 'peak-publisher'),
                type: 'ok',
                desc: __('The plugin file name matches the previous release.', 'peak-publisher'),
            };
        }

        return {
            title: __('Unexpected plugin file', 'peak-publisher'),
            type: changePluginFileName ? 'ok' : 'error',
            desc: [
                sprintf(__('The uploaded release %s has the plugin file name %s which does not match the previous release %s with the plugin file name %s.', 'peak-publisher'), pluginData.Version, (meta.plugin_info?.plugin_basename || '').split('/').pop(), previousRelease.version, (previousRelease.plugin_basename || '').split('/').pop()),
                createElement('br'),
                createElement(CheckboxControl, {
                    __nextHasNoMarginBottom: true,
                    label: __('That\'s fine, I want to change the plugin filename. I\'m aware that WordPress will interpret this as a different plugin, and that this is very risky and should be avoided if possible.', 'peak-publisher'),
                    checked: changePluginFileName,
                    onChange: (value) => setChangePluginFileName(value),
                }),
            ],
        };
    }

    function checkVersion(context) {
        const {
            meta,
            pluginData,
            target,
            isWporg,
            releaseContext,
        } = context;
        const previousRelease = releaseContext.previousRelease;
        const nextRelease = releaseContext.nextRelease;
        const latestRelease = releaseContext.latestRelease;
        const existingRelease = releaseContext.existingRelease;
        const previousReleaseVersion = releaseContext.previousReleaseVersion;
        const pluginVersion = releaseContext.pluginVersion;
        const naturalSuccessors = releaseContext.naturalSuccessors;
        const isNaturalSuccessor = releaseContext.isNaturalSuccessor;
        const deployModeText = isWporg && target.deploy_mode === 'trunk_and_tag'
            ? __('Deploy mode: update trunk and tag in one SVN commit.', 'peak-publisher')
            : (isWporg && target.deploy_mode === 'tag_only'
                ? __('Deploy mode: tag-only deploy. Trunk stays unchanged.', 'peak-publisher')
                : '');
        const withDeployMode = (desc) => deployModeText ? [desc, createElement('br'), deployModeText] : desc;

        if (!pluginData.Version) {
            return {
                title: __('Missing version number', 'peak-publisher'),
                type: 'error',
                desc: __('You need to add a version number to your plugin file.', 'peak-publisher'),
            };
        }

        if (existingRelease) {
            return {
                title: __('Version number already exists', 'peak-publisher'),
                type: replaceRelease ? 'ok' : 'error',
                desc: [
                    sprintf(__('A release with the version number %s already exists for this plugin.', 'peak-publisher'), pluginData.Version),
                    createElement('br'),
                    createElement(CheckboxControl, {
                        __nextHasNoMarginBottom: true,
                        label: __('That\'s fine, I want to replace the existing release. I understand that this is not recommended if the existing release is or was already published.', 'peak-publisher'),
                        checked: replaceRelease,
                        onChange: (value) => setReplaceRelease(value),
                    }),
                    deployModeText && [createElement('br'), deployModeText],
                ],
            };
        }

        if (!latestRelease) {
            return {
                title: __('Valid version number', 'peak-publisher'),
                type: 'ok',
                desc: withDeployMode(pluginData.Version),
            };
        }

        if (isNaturalSuccessor && !nextRelease) {
            return {
                title: __('Expected version number', 'peak-publisher'),
                type: 'ok',
                desc: withDeployMode([
                    sprintf(__('Version %s, as expected after the latest release (%s).', 'peak-publisher'), pluginData.Version, latestRelease.version),
                ]),
            };
        }

        return {
            title: __('Unexpected version number', 'peak-publisher'),
            type: (!nextRelease || useOlderPluginVersion) && (!previousRelease || isNaturalSuccessor || (previousRelease && !isNaturalSuccessor && useUnexpectedPluginVersion)) ? 'ok' : 'error',
            desc: withDeployMode([
                nextRelease && [
                    latestRelease.normalized_version !== nextRelease.normalized_version && sprintf(__('Releases with higher version numbers (%s to %s) already exist.', 'peak-publisher'), nextRelease.version, latestRelease.version),
                    latestRelease.normalized_version === nextRelease.normalized_version && sprintf(__('A release with a higher version number (%s) already exists.', 'peak-publisher'), latestRelease.version),
                    createElement('br'),
                    createElement(CheckboxControl, {
                        __nextHasNoMarginBottom: true,
                        label: __('That\'s fine, this release isn\'t meant to be the latest one.', 'peak-publisher'),
                        checked: useOlderPluginVersion,
                        onChange: (value) => setUseOlderPluginVersion(value),
                    }),
                ],
                previousRelease && !isNaturalSuccessor && [
                    sprintf(__('%s is an unexpected successor to the previous release (%s).', 'peak-publisher'), pluginData.Version, previousRelease.version),
                    createElement('br'),
                    sprintf(__('Expected would be %s.', 'peak-publisher'), naturalSuccessors.join(', ')),
                    createElement('br'),
                    createElement(CheckboxControl, {
                        __nextHasNoMarginBottom: true,
                        label: sprintf(__('That\'s fine, I want to use the version number %s anyway.', 'peak-publisher'), pluginData.Version),
                        checked: useUnexpectedPluginVersion,
                        onChange: (value) => setUseUnexpectedPluginVersion(value),
                    }),
                ],
            ]),
        };
    }

    function checkUpdateUri(context) {
        const { pluginData, isWporg } = context;

        if (isWporg) {
            return [
                pluginData?.UpdateURI && {
                    title: __('Update URI must be removed', 'peak-publisher'),
                    type: 'error',
                    desc: [
                        sprintf(__('Found: %s', 'peak-publisher'), pluginData.UpdateURI),
                        createElement('br'),
                        __('wordpress.org plugins must not contain an Update URI header.', 'peak-publisher'),
                    ],
                },
            ];
        }

        return [
            pluginData?.UpdateURI && [
                pluginData?.UpdateURI === PblshData?.bootstrapUpdateURI && {
                    title: __('Expected update URI', 'peak-publisher'),
                    type: 'ok',
                    desc: pluginData.UpdateURI,
                },
                pluginData?.UpdateURI !== PblshData?.bootstrapUpdateURI && {
                    title: __('Unexpected update URI', 'peak-publisher'),
                    type: useDifferentCustomUpdateServer ? 'ok' : 'error',
                    desc: [
                        sprintf(__('The specified update URI is %s.', 'peak-publisher'), pluginData.UpdateURI),
                        createElement('br'),
                        sprintf(__('Expected would be %s.', 'peak-publisher'), PblshData.bootstrapUpdateURI),
                        createElement('br'),
                        createElement(CheckboxControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('That\'s fine, I will use a different update server for this plugin from now on.', 'peak-publisher'),
                            checked: useDifferentCustomUpdateServer,
                            onChange: (value) => setUseDifferentCustomUpdateServer(value),
                        }),
                    ],
                },
            ],
            !pluginData?.UpdateURI && {
                title: __('Missing update URI', 'peak-publisher'),
                type: useWordPressOrgUpdateServer ? 'ok' : 'error',
                desc: [
                    __('You need to add a valid update URI to your plugin file.', 'peak-publisher'),
                    createElement('br'),
                    createElement(CheckboxControl, {
                        __nextHasNoMarginBottom: true,
                        label: __('That\'s fine, my new update server will be wordpress.org, so no update URI is needed.', 'peak-publisher'),
                        checked: useWordPressOrgUpdateServer,
                        onChange: (value) => setUseWordPressOrgUpdateServer(value),
                    }),
                ],
            },
        ];
    }

    function checkBootstrapCode(context) {
        const { meta, isWporg } = context;
        const bootstrapFile = meta.plugin_info?.bootstrap_file || '';

        if (isWporg) {
            return [
                bootstrapFile && {
                    title: __('Bootstrap code must be removed', 'peak-publisher'),
                    type: 'error',
                    desc: sprintf(__('Found in %s. Peak Publisher bootstrap code must be removed before deploying to wordpress.org.', 'peak-publisher'), bootstrapFile),
                },
            ];
        }

        return [
            bootstrapFile && [
                !useDifferentCustomUpdateServer && !useWordPressOrgUpdateServer && [
                    meta.plugin_info?.bootstrap_is_latest && {
                        title: __('Expected bootstrap code', 'peak-publisher'),
                        type: 'ok',
                        desc: sprintf(__('Found in %s.', 'peak-publisher'), bootstrapFile),
                    },
                    !meta.plugin_info?.bootstrap_is_latest && {
                        title: __('Unexpected bootstrap code', 'peak-publisher'),
                        type: keepOldBootstrapCode ? 'ok' : 'error',
                        desc: [
                            sprintf(__('Found in %s, but it is not the latest bootstrap code version.', 'peak-publisher'), bootstrapFile),
                            createElement('br'),
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('That\'s fine, I want to use the old version of the bootstrap code. I understand that this is not recommended.', 'peak-publisher'),
                                checked: keepOldBootstrapCode,
                                onChange: (value) => setKeepOldBootstrapCode(value),
                            }),
                        ],
                    },
                ],
                useDifferentCustomUpdateServer && {
                    title: __('Found bootstrap code', 'peak-publisher'),
                    type: usePeakPublisherForNewUpdateServer ? 'ok' : 'error',
                    desc: [
                        sprintf(__('Do you plan to use Peak Publisher again for your new update server? Otherwise, you will need to remove the bootstrap code from %s.', 'peak-publisher'), bootstrapFile),
                        createElement('br'),
                        createElement(CheckboxControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('Yes, I will use Peak Publisher again for my new update server.', 'peak-publisher'),
                            checked: usePeakPublisherForNewUpdateServer,
                            onChange: (value) => setUsePeakPublisherForNewUpdateServer(value),
                        }),
                    ],
                },
                useWordPressOrgUpdateServer && {
                    title: __('Bootstrap code must be removed', 'peak-publisher'),
                    type: 'error',
                    desc: sprintf(__('You need to remove the bootstrap code from %s since your new update server is wordpress.org.', 'peak-publisher'), bootstrapFile),
                },
            ],
            !bootstrapFile && [
                !useDifferentCustomUpdateServer && !useWordPressOrgUpdateServer && {
                    title: __('Missing bootstrap code', 'peak-publisher'),
                    type: 'error',
                    desc: __('You need to add the bootstrap code to your plugin.', 'peak-publisher'),
                },
                useDifferentCustomUpdateServer && {
                    title: __('Bootstrap code not found', 'peak-publisher'),
                    type: useNotPeakPublisherForNewUpdateServer ? 'ok' : 'error',
                    desc: [
                        __('If you use Peak Publisher again for your new update server, you will need to add the bootstrap code to your plugin.', 'peak-publisher'),
                        createElement('br'),
                        createElement(CheckboxControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('That\'s fine, I will use something other than Peak Publisher for my new update server.', 'peak-publisher'),
                            checked: useNotPeakPublisherForNewUpdateServer,
                            onChange: (value) => setUseNotPeakPublisherForNewUpdateServer(value),
                        }),
                    ],
                },
                useWordPressOrgUpdateServer && {
                    title: __('Bootstrap code not found', 'peak-publisher'),
                    type: 'ok',
                    desc: __('This is as it should be if you plan to use wordpress.org as your new update server.', 'peak-publisher'),
                },
            ],
        ];
    }

    function checkTopLevelFolder(context) {
        const { meta, isWporg, blockers } = context;
        const pluginBasename = meta.plugin_info?.plugin_basename || '';
        const pluginFolder = pluginBasename ? pluginBasename.split('/')[0] : (meta?.slug || '');
        const installPath = pluginFolder ? '/wp-content/plugins/' + pluginFolder + '/' : '';

        if (isWporg && blockers.some((blocker) => blocker?.code === 'invalid_slug')) {
            return {
                title: __('Top-level folder is not a valid slug', 'peak-publisher'),
                type: 'error',
                desc: [
                    __('wordpress.org deploys require the top-level folder to match the slug of the plugin on wordpress.org.', 'peak-publisher'),
                    createElement('br'),
                    sprintf(__('The folder %s cannot be a wordpress.org slug (slugs consist of lowercase letters, numbers, and hyphens).', 'peak-publisher'), pluginFolder),
                ],
            };
        }
        return {
            title: __('Top-level folder exists', 'peak-publisher'),
            type: 'ok',
            desc: [
                meta?.cleanup_info?.fixed_top_level_folder && __('It was added to your upload automatically.', 'peak-publisher'),
                !meta?.cleanup_info?.fixed_top_level_folder && __('Your upload has a top-level folder.', 'peak-publisher'),
                installPath && createElement('br'),
                installPath && sprintf(__('The install folder will be %s.', 'peak-publisher'), installPath),
            ],
        };
    }

    function checkWorkspaceArtifacts(context) {
        const { meta, settings } = context;
        const artifacts = Array.isArray(meta?.cleanup_info?.found_workspace_artifacts) ? meta.cleanup_info.found_workspace_artifacts : [];
        const freeFromWorkspaceArtifacts = artifacts.every(item => item.deleted);
        const workspaceArtifactsCount = artifacts.reduce((total, item) => total + (Number(item.count) || 0), 0);
        const workspaceArtifactsSize = formatBytes(artifacts.reduce((total, item) => total + (Number(item.bytes) || 0), 0));
        const remainingArtifacts = artifacts.filter(item => !item.deleted);
        const workspaceArtifactsNotDeletedCount = remainingArtifacts.reduce((total, item) => total + (Number(item.count) || 0), 0);
        const workspaceArtifactsNotDeletedSize = formatBytes(remainingArtifacts.reduce((total, item) => total + (Number(item.bytes) || 0), 0));

        if (freeFromWorkspaceArtifacts) {
            return {
                title: __('Free from workspace artifacts', 'peak-publisher'),
                type: 'ok',
                desc: [
                    workspaceArtifactsCount === 0 && sprintf(__('No files or folders from your system or development environment were found.', 'peak-publisher')),
                    workspaceArtifactsCount > 0 && sprintf(__('%s in %s files and folders deleted as specified in the settings.', 'peak-publisher'), workspaceArtifactsSize, workspaceArtifactsCount),
                    createElement('br'),
                    sprintf(__('The installed release will be %s in total with %s files and folders.', 'peak-publisher'), formatBytes(meta?.cleanup_info?.size_after_cleanup), meta?.cleanup_info?.entry_count_after_cleanup),
                ],
            };
        }

        return {
            title: __('Workspace artifacts found', 'peak-publisher'),
            type: keepWorkspaceArtifacts ? 'ok' : 'error',
            desc: [
                settings.auto_remove_workspace_artifacts && __('The following artifacts could not be deleted automatically:', 'peak-publisher'),
                !settings.auto_remove_workspace_artifacts && __('Your upload contains the following artifacts:', 'peak-publisher'),
                createElement('br'),
                createElement('textarea', {
                    value: remainingArtifacts.map(file => file.path).join('\n'),
                    readOnly: true,
                    rows: Math.min(4, remainingArtifacts.length + 1),
                    style: {
                        width: '100%',
                        whiteSpace: 'nowrap',
                        fontFamily: 'monospace',
                        fontSize: '12px',
                    },
                }),
                createElement('br'),
                sprintf(__('The artifacts are %s in total with %s files and folders.', 'peak-publisher'), workspaceArtifactsNotDeletedSize, workspaceArtifactsNotDeletedCount),
                createElement('br'),
                createElement(CheckboxControl, {
                    __nextHasNoMarginBottom: true,
                    label: __('That\'s fine, I want to keep the artifacts in the release.', 'peak-publisher'),
                    checked: keepWorkspaceArtifacts,
                    onChange: (value) => setKeepWorkspaceArtifacts(value),
                }),
                sprintf(__('The installed release will be %s in total with %s files and folders.', 'peak-publisher'), formatBytes(meta?.cleanup_info?.size_after_cleanup), meta?.cleanup_info?.entry_count_after_cleanup),
            ],
        };
    }

    function checkReadmeTxt(context) {
        const { meta, settings, isWporg } = context;
        const readmeCleanup = meta?.cleanup_info?.readme_txt || {};
        const readmeTxtAlreadyUtf8 = !!readmeCleanup.already_utf8;
        const readmeTxtAlreadyWithoutBom = !!readmeCleanup.already_without_bom;
        const readmeTxtDetectedEncoding = readmeCleanup.detected_encoding || '';
        const readmeTxtConvertedToUtf8 = !!readmeCleanup.converted_to_utf8;
        const readmeTxtRemovedUtf8Bom = !!readmeCleanup.removed_utf8_bom;
        const readmeTxtCanBeEncodedToJson = !!readmeCleanup.can_be_encoded_to_json;

        if (!meta.plugin_readme_txt?.found) {
            return {
                title: __('No readme file found', 'peak-publisher'),
                type: isWporg ? 'error' : 'info',
                desc: isWporg
                    ? __('wordpress.org plugins require a readme.txt file.', 'peak-publisher')
                    : [
                        createInterpolateElement(__('A readme.txt is not required but would allow you to provide a description, changelog, and more to your users. Check out the <a>example on wordpress.org</a>.', 'peak-publisher'), {
                            a: createElement('a', { href: 'https://wordpress.org/plugins/readme.txt', target: '_blank' }),
                        }),
                    ],
            };
        }

        return {
            title: __('Readme file exists', 'peak-publisher'),
            type:
                (readmeTxtAlreadyUtf8 && readmeTxtAlreadyWithoutBom)
                ||
                (settings.readme_txt_convert_to_utf8_without_bom && (
                    (readmeTxtConvertedToUtf8 && readmeTxtAlreadyWithoutBom)
                    ||
                    (readmeTxtRemovedUtf8Bom && readmeTxtAlreadyUtf8)
                    ||
                    (readmeTxtConvertedToUtf8 && readmeTxtRemovedUtf8Bom)
                    ||
                    ((!readmeTxtAlreadyUtf8 !== readmeTxtConvertedToUtf8 || !readmeTxtAlreadyWithoutBom !== readmeTxtRemovedUtf8Bom) && keepReadmeTxtAsIs)
                ))
                ||
                (!settings.readme_txt_convert_to_utf8_without_bom && (
                    (readmeTxtAlreadyWithoutBom || keepReadmeTxtBom)
                    &&
                    (readmeTxtAlreadyUtf8 || keepReadmeTxtEncoding)
                ))
                ? 'ok' : 'error',
            desc: [
                meta.plugin_readme_txt?.file_name !== 'readme.txt' && [
                    sprintf(__('Although %s also works, the officially valid filename is readme.txt.', 'peak-publisher'), meta.plugin_readme_txt.file_name),
                    createElement('br'),
                ],
                readmeTxtAlreadyUtf8 && readmeTxtAlreadyWithoutBom && __('The file is a valid UTF-8 file without a BOM, exactly as it should be.', 'peak-publisher'),
                (!readmeTxtAlreadyUtf8 || !readmeTxtAlreadyWithoutBom) && [
                    settings.readme_txt_convert_to_utf8_without_bom && [
                        readmeTxtConvertedToUtf8 && [
                            readmeTxtDetectedEncoding && sprintf(__('The file was converted from %s to UTF-8 as specified in the settings.', 'peak-publisher'), readmeTxtDetectedEncoding),
                            !readmeTxtDetectedEncoding && __('The file was converted to UTF-8 as specified in the settings.', 'peak-publisher'),
                            createElement('br'),
                        ],
                        readmeTxtRemovedUtf8Bom && [
                            __('The UTF-8 BOM was removed from the file as specified in the settings.', 'peak-publisher'),
                            createElement('br'),
                        ],
                        (!readmeTxtAlreadyUtf8 !== readmeTxtConvertedToUtf8 || !readmeTxtAlreadyWithoutBom !== readmeTxtRemovedUtf8Bom) && [
                            __('The file couldn\'t be converted to UTF-8 without a BOM. Please check it manually.', 'peak-publisher'),
                            createElement('br'),
                            !readmeTxtCanBeEncodedToJson && [
                                __('The file can\'t be processed because it is not a valid UTF-8 file.', 'peak-publisher'),
                                createElement('br'),
                            ],
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: [
                                    readmeTxtCanBeEncodedToJson && __('That\'s fine, I want to keep the current encoding of the file as it is.', 'peak-publisher'),
                                    !readmeTxtCanBeEncodedToJson && __('That\'s fine, I want to keep the file even no information can be used from it.', 'peak-publisher'),
                                ],
                                checked: keepReadmeTxtAsIs,
                                onChange: (value) => setKeepReadmeTxtAsIs(value),
                            }),
                        ],
                    ],
                    !settings.readme_txt_convert_to_utf8_without_bom && [
                        !readmeTxtAlreadyWithoutBom && [
                            __('The file has a UTF-8 BOM, which can cause issues.', 'peak-publisher'),
                            createElement('br'),
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('That\'s fine, I want to keep the UTF-8 BOM in the file as it is.', 'peak-publisher'),
                                checked: keepReadmeTxtBom,
                                onChange: (value) => setKeepReadmeTxtBom(value),
                            }),
                        ],
                        !readmeTxtAlreadyUtf8 && [
                            readmeTxtDetectedEncoding && sprintf(__('The detected encoding is not UTF-8, but %s.', 'peak-publisher'), readmeTxtDetectedEncoding),
                            !readmeTxtDetectedEncoding && __('The detected encoding is not UTF-8.', 'peak-publisher'),
                            createElement('br'),
                            !readmeTxtCanBeEncodedToJson && [
                                __('The file can\'t be processed because it is not a valid UTF-8 file.', 'peak-publisher'),
                                createElement('br'),
                            ],
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: [
                                    readmeTxtCanBeEncodedToJson && __('That\'s fine, I want to keep the current encoding of the file as it is.', 'peak-publisher'),
                                    !readmeTxtCanBeEncodedToJson && __('That\'s fine, I want to keep the file even no information can be used from it.', 'peak-publisher'),
                                ],
                                checked: keepReadmeTxtEncoding,
                                onChange: (value) => setKeepReadmeTxtEncoding(value),
                            }),
                        ],
                    ],
                ],
            ],
        };
    }

    function checkResultErrors(context) {
        const blockerCodes = context.blockers.map((blocker) => blocker?.code).filter(Boolean);
        return context.extraResultErrors
            .filter((error) => error && !blockerCodes.includes(error.code))
            .map((error) => ({
                title: __('Error', 'peak-publisher'),
                type: 'error',
                desc: [
                    error?.message || __('An unknown error occurred.', 'peak-publisher'),
                    error?.code && createElement('br'),
                    error?.code && createElement('code', null, error.code),
                ],
            }));
    }

    function buildUploadCheckItems(context) {
        return [
            context.meta.plugin_ok && [
                checkPluginFile(context),
                checkVersion(context),
                checkUpdateUri(context),
                checkBootstrapCode(context),
                checkTopLevelFolder(context),
                checkWorkspaceArtifacts(context),
                checkReadmeTxt(context),
            ],
            checkResultErrors(context),
        ].flat(Infinity).filter(Boolean);
    }

    function renderWporgGate(context) {
        const { meta, uploadId, target, resultErrors, extraResultErrors, firstResultError, preDeployRequired } = context;
        const accessStatus = target?.wporg_access_status || '';
        const variant = meta.plugin_ok ? WporgAccessGate.getVariant({
            accessStatus,
            blockingReason: target?.blocking_reason || '',
            preDeployRequired,
            hasImportUsername: !!target?.pre_deploy_import?.username,
        }) : null;
        if (!variant) return null;

        const canImportAndContinue = !!meta?.slug && !!uploadId && !!target.available && !!target?.pre_deploy_import?.username;

        return createElement(WporgAccessGate, {
            variant,
            slugSummary: getSlugSummary(meta),
            username: target?.account_username || '',
            accessStatus,
            message: firstResultError?.message || '',
            errors: preDeployRequired ? resultErrors : extraResultErrors,
            isBusy: isProcessing,
            primaryDisabled: !canImportAndContinue,
            onPrimary: variant === 'import_required' ? () => importWporgAndContinue(uploadId, meta, target) : null,
        });
    }

    function buildUploadActions(context, checklistItems) {
        const { meta, uploadId, targetKey, isWporg, target, validation, blockers, presentation } = context;
        const checksPass = Array.isArray(checklistItems)
            ? checklistItems.every(item => item.type === 'ok' || item.type === 'info')
            : false;
        const targetFinalizable = !isWporg || (!!target?.available && !!validation.finalizable && blockers.length === 0);
        const canFinalize = !!meta.plugin_ok && checksPass && targetFinalizable;
        const finalizeOptions = meta?.hosting_type_choice ? { hosting_type: targetKey } : undefined;

        return [
            renderDiscardButton(uploadId),
            meta.plugin_ok && createElement(Button, {
                isPrimary: true,
                disabled: !canFinalize || isProcessing,
                className: 'pblsh--button--add-plugin',
                onClick: () => finalizeCreation(uploadId, finalizeOptions),
                __next40pxDefaultSize: true,
            }, presentation.submitLabel),
        ];
    }

    function buildUploadValidationModel(result, meta, targetKey) {
        const context = getUploadCheckContext(result, meta, targetKey);
        const { pluginData, isWporg, releaseContext, presentation } = context;
        const pendingReleaseImport = isWporg && meta.plugin_ok && context.preDeployRequired && !meta.existing_plugin && !releaseContext.hasReleaseData;
        const gate = isWporg ? renderWporgGate(context) : null;
        const checklistItems = gate ? null : buildUploadCheckItems(context);

        return {
            classNames: pendingReleaseImport ? [] : presentation.classNames,
            header: meta.plugin_ok ? {
                headline: pluginData.Name,
                desc: pluginData.Version,
                // No release type badge while the wporg import is still pending — the type is only known afterwards.
                type: pendingReleaseImport ? undefined : presentation.type,
            } : {
                headline: __('Not a plugin', 'peak-publisher'),
                desc: __('No valid plugin main file could be found', 'peak-publisher'),
                type: presentation.type,
            },
            meta,
            body: gate,
            checklistItems,
            actions: gate ? [renderDiscardButton(context.uploadId)] : buildUploadActions(context, checklistItems),
        };
    }

    function renderValidation(result) {
        let meta = result?.data || {};
        const activeTargetKey = getActiveTargetKey(meta);
        if (meta?.hosting_type_choice && !activeTargetKey) {
            // No target chosen yet: the choice is the only content, so it lives in the scrollable body instead of the fixed slot.
            return renderUploadResultShell({
                header: {
                    headline: meta?.plugin_data?.Name || '',
                    desc: meta?.plugin_data?.Version || '',
                },
                body: renderHostingTypeChoice(meta),
                actions: [renderDiscardButton(result?.upload_id)],
            });
        }
        if (activeTargetKey) {
            meta = getEffectiveMetaForTarget(meta, activeTargetKey);
            result = { ...result, data: meta };
        }

        const isWporgUploadResult = meta.hosting_type_resolved === 'wporg' || meta.hosting_type_intended === 'wporg';
        const modelTargetKey = activeTargetKey || (isWporgUploadResult ? 'wporg' : 'self_hosted');
        return renderUploadResultShell(buildUploadValidationModel(result, meta, modelTargetKey));
    }

    // Always keep overlay in DOM for smooth fade-in/out
    // Hide overlay completely while the result-dialog is shown
    const isActive = !validationResult && (visible || zipProgress !== false || uploadProgress !== false || isProcessing);

    const overlay = createElement('div', { className: 'pblsh--overlay' + (isActive ? ' is-visible' : ''), role: 'presentation', 'aria-hidden': isActive ? 'false' : 'true' },
        createElement('div', { className: 'pblsh--overlay__backdrop', onClick: () => { if (!isProcessing && uploadProgress === false && zipProgress === false) closeOverlay(); } }),
        (zipProgress === false && uploadProgress === false && !isProcessing) && createElement(wp.element.Fragment, null,
            createElement('div', { className: 'pblsh--overlay__border' }),
            createElement('div', { className: 'pblsh--overlay__hint' },
                getSvgIcon('cloud_upload', { size: 36 }),
                createElement('div', null, __('Drop always anywhere in the Peak Publisher to upload a new plugin or release', 'peak-publisher')),
            ),
        ),
        // Hidden input is always available for programmatic picker
        createElement('input', {
            ref: fileInputRef,
            type: 'file',
            accept: '.zip',
            onChange: (e) => {
                const f = e.target.files && e.target.files[0] ? e.target.files[0] : null;
                if (f) {
                    setFilename(f.name);
                    startUpload(f);
                }
                e.target.value = '';
            },
            className: 'pblsh--hidden-file-input',
        }),
        zipProgress !== false && createElement('div', { className: 'pblsh--progress' },
            filename && createElement('div', { className: 'pblsh--file-info' }, filename),
            createElement('div', { className: 'pblsh--progress__bar', style: { '--percentage': zipProgress + '%' } }),
            createElement('div', { className: 'pblsh--progress__label' }, __('creating zip …', 'peak-publisher'), ' ', Math.floor(zipProgress), '%'),
        ),
        uploadProgress !== false && createElement('div', { className: 'pblsh--progress' },
            filename && createElement('div', { className: 'pblsh--file-info' }, filename),
            createElement('div', { className: 'pblsh--progress__bar', style: { '--percentage': uploadProgress + '%' } }),
            createElement('div', { className: 'pblsh--progress__label' }, __('uploading …', 'peak-publisher'), ' ', Math.floor(uploadProgress), '%'),
        ),
        isProcessing && createElement('div', { className: 'pblsh--processing' },
            createElement('div', { className: 'pblsh--loading__spinner' }),
            createElement('div', { className: 'pblsh--processing__text' }, (
                processPhase === 'upload_prepare' ? __('validating upload …', 'peak-publisher') :
                processPhase === 'unpack' ? __('unpacking data …', 'peak-publisher') :
                processPhase === 'analyze' ? __('analyzing data …', 'peak-publisher') :
                processPhase === 'rebuild_zip' ? __('rebuilding zip …', 'peak-publisher') :
                __('loading results …', 'peak-publisher')
            )),
        ),
    );

    // Manage native dialog (always in DOM)
    useEffect(() => {
        const dialogEl = dialogRef.current;
        if (!dialogEl) return;
        try {
            if (validationResult) {
                if (typeof dialogEl.showModal === 'function' && !dialogEl.open) {
                    dialogEl.showModal();
                    // Focus the dialog itself so no control (e.g. the first target card) appears pre-focused.
                    dialogEl.focus();
                }
            } else {
                if (dialogEl.open) {
                    dialogEl.close();
                }
            }
        } catch (e) {}
    }, [validationResult]);

    const dialog = createElement('dialog', { className: 'pblsh--modal pblsh--modal--upload-result', ref: dialogRef, tabIndex: -1 }, validationResult ? renderValidation(validationResult) : null);

    return createElement(wp.element.Fragment, null, overlay, dialog);
});
