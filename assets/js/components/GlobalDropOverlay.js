// GlobalDropOverlay Component - handles app-wide drag & drop upload
lodash.set(window, 'Pblsh.Components.GlobalDropOverlay', ({ onCreated, activeUploadContext = {} } = {}) => {
    const { __ } = wp.i18n;
    const sprintf = wp.i18n.sprintf ?? window.sprintf;
    const { useState, useEffect, useRef, createElement, createInterpolateElement } = wp.element;
    const { Button, CheckboxControl, TextControl } = wp.components;
    const { useSelect } = wp.data;
    const { getSvgIcon, getFaqUrl } = Pblsh.Utils;
    const { WporgAccessGate } = Pblsh.Components;

    const fileInputRef = useRef(null);
    const dialogRef = useRef(null);
    const destinationPanelRef = useRef(null);
    const destinationTriggerRef = useRef(null);
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
    const [destinationPanelOpen, setDestinationPanelOpen] = useState(false);
    const [slugEditValue, setSlugEditValue] = useState('');
    const [slugEditError, setSlugEditError] = useState('');
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
        setDestinationPanelOpen(false);
        setSlugEditValue('');
        setSlugEditError('');
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
        // Close the destination panel on any pointerdown outside of it — blur alone can't tell
        // an outside click from a click on the panel's scrollbar or other non-focusable areas.
        if (!destinationPanelOpen) return;
        const onPointerDown = (event) => {
            if (destinationPanelRef.current && !destinationPanelRef.current.contains(event.target)) {
                setDestinationPanelOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointerDown, true);
        return () => document.removeEventListener('pointerdown', onPointerDown, true);
    }, [destinationPanelOpen]);

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
        // Projects the active channel's identity and release context onto the meta the
        // checklist and gate read — each target carries its own resolved slug.
        const target = targetKey ? meta?.hosting_type_targets?.[targetKey] : null;
        if (!target) return meta;
        return {
            ...meta,
            hosting_type_resolved: targetKey,
            slug: target.slug || '',
            plugin_info: { ...(meta.plugin_info || {}), plugin_basename: target.plugin_basename || '' },
            existing_plugin: target.existing_plugin_id || false,
            related_releases: target.related_releases || false,
        };
    }

    const targetIcons = {
        wporg: 'wordpress',
        self_hosted: 'server',
    };

    // Channel display texts live in the static PblshData config (authoritative source:
    // get_channel_texts()) — upload state and targets carry facts only, never UI text.
    // This also labels destinations of a channel that is currently not included in the targets.
    const getChannelLabel = (channelKey) => PblshData?.channelTexts?.[channelKey]?.label || channelKey;
    const getChannelDescription = (channelKey) => PblshData?.channelTexts?.[channelKey]?.description || '';

    function candidateDestination(candidate, channelKey) {
        // The single place a candidate becomes a destination object — the existing flag always
        // derives from the candidate's analysis annotation, never from assumptions at the call site.
        return {
            hostingType: channelKey,
            slug: candidate.slug,
            existing: (candidate.existing || []).includes(channelKey),
            sources: candidate.sources || [],
        };
    }

    function getDestinations(meta = {}) {
        // Concrete destinations (channel × slug) derived from the per-channel targets and the
        // candidate annotations: the channel's resolved slug is one destination, and every
        // candidate identifying an existing plugin in a channel stays a destination of its
        // own — regardless of how the automatic resolution settled and even when the channel
        // is currently not included in the targets (choosing the match restores the channel).
        // An existing match must never drop off the list.
        const targets = meta?.hosting_type_targets || {};
        const candidates = Array.isArray(meta?.plugin_info?.slug_candidates) ? meta.plugin_info.slug_candidates : [];
        const destinations = [];
        // wporg before self_hosted — mirrors the server's target insertion order.
        ['wporg', 'self_hosted'].forEach((key) => {
            const target = targets[key];
            if (target?.slug) {
                const candidate = candidates.find((c) => c.slug === target.slug);
                destinations.push({
                    hostingType: key,
                    slug: target.slug,
                    existing: !!target.existing_plugin_id,
                    sources: candidate?.sources || (target.slug_source ? [target.slug_source] : []),
                });
            }
            candidates.filter((c) => (c.existing || []).includes(key) && c.slug !== target?.slug).forEach((candidate) => {
                destinations.push(candidateDestination(candidate, key));
            });
            if (target && !target.slug && !candidates.some((c) => (c.existing || []).includes(key))) {
                // No usable slug at all — the included channel still appears so the custom input has an anchor.
                destinations.push({ hostingType: key, slug: '', existing: false, sources: [] });
            }
        });
        return destinations;
    }

    function candidateAlternativesForChannel(meta, destinations, key) {
        // Candidates that would be NEW destinations of the channel: not already represented
        // by one of its rows, and whose choice keeps the channel included (candidate_channels
        // — same rule as the actual inclusion). Candidates with an existing match in the
        // channel are always among its destinations already, so they never appear here.
        const candidates = Array.isArray(meta?.plugin_info?.slug_candidates) ? meta.plugin_info.slug_candidates : [];
        const coveredSlugs = destinations.filter((destination) => destination.hostingType === key).map((destination) => destination.slug);
        return candidates.filter((candidate) => !coveredSlugs.includes(candidate.slug)
            && (meta?.candidate_channels?.[candidate.slug] || []).includes(key));
    }

    function selectDestination(destination, meta, uploadId) {
        const target = meta?.hosting_type_targets?.[destination.hostingType];
        const needsSlugChange = !!destination.slug && destination.slug !== target?.slug;
        if (!needsSlugChange) {
            setSelectedHostingType(destination.hostingType);
            setDestinationPanelOpen(false);
            // The clicked option unmounts with the panel — focus returns to the trigger.
            window.requestAnimationFrame(() => destinationTriggerRef.current?.focus());
            return;
        }
        // Panel close and channel switch apply on success inside runSlugSelection: a failed
        // roundtrip keeps the panel (and error) visible, and switching the channel before
        // the result arrives would flash the destination screen while the current data
        // still shows the channel as unresolved (or not included at all).
        applySlug(uploadId, destination.slug, destination.hostingType);
    }

    function renderNewPluginOptionText(destination) {
        // Assignment-list variant for "new plugin" rows: a new plugin's slug is provisional —
        // it resolves later and stays editable in the publish-path row (same philosophy as
        // the channel cards) — so the row shows the channel description instead of a slug.
        return createElement('span', { className: 'pblsh--destination-option__text' },
            createElement('span', { className: 'pblsh--destination-option__headline' },
                targetIcons[destination.hostingType] && createElement('span', { className: 'pblsh--destination-option__icon' }, getSvgIcon(targetIcons[destination.hostingType], { size: 18 })),
                createElement('span', { className: 'pblsh--destination-option__label' }, getChannelLabel(destination.hostingType)),
                createElement('span', { className: 'pblsh--destination-option__badge is-new' }, __('new plugin', 'peak-publisher')),
            ),
            createElement('span', { className: 'pblsh--destination-option__desc' }, getChannelDescription(destination.hostingType)),
        );
    }

    function renderDestinationOptionText(destination) {
        // Shared option anatomy for the publish-path panel options and the assignment list's
        // existing rows: "channel → slug" like the closed trigger, with the badge
        // right-aligned (flex-wrap lets it break to its own line for long slugs).
        return createElement('span', { className: 'pblsh--destination-option__text' },
            createElement('span', { className: 'pblsh--destination-option__headline' },
                targetIcons[destination.hostingType] && createElement('span', { className: 'pblsh--destination-option__icon' }, getSvgIcon(targetIcons[destination.hostingType], { size: 18 })),
                createElement('span', { className: 'pblsh--destination-option__label' }, getChannelLabel(destination.hostingType)),
                createElement('span', { className: 'pblsh--destination-option__sep', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 16 })),
                // Every rendered destination row carries a slug (assignment rows and panel
                // options are candidate- or target-backed; the slugless state never reaches them).
                createElement('code', { className: 'pblsh--destination-option__slug' }, destination.slug),
                createElement('span', { className: 'pblsh--destination-option__badge' + (destination.existing ? ' is-existing' : ' is-new') },
                    destination.existing ? __('existing', 'peak-publisher') : __('new plugin', 'peak-publisher')),
            ),
            destination.sources.length > 0 && createElement('span', { className: 'pblsh--destination-option__desc' },
                sprintf(__('slug from %s', 'peak-publisher'), destination.sources.map(getSlugSourceLabel).join(', '))),
        );
    }

    function renderDestinationChoice(meta = {}, uploadId) {
        const destinations = getDestinations(meta);
        if (destinations.length === 0) return null;
        const allNew = destinations.every((destination) => !destination.existing);

        if (!allNew) {
            // Assignment mode: at least one destination is an existing plugin — the decision is
            // an assignment, so the options render as one vertical list with the shared
            // "channel → slug" anatomy. Existing rows first, then one new-plugin alternative
            // per channel (the release may belong to neither match).
            const rows = [...destinations.filter((destination) => destination.existing)];
            const newRows = destinations.filter((destination) => !destination.existing);
            getTargetKeys(meta).forEach((key) => {
                if (newRows.some((destination) => destination.hostingType === key)) return;
                const candidate = candidateAlternativesForChannel(meta, destinations, key)[0];
                if (candidate) {
                    newRows.push(candidateDestination(candidate, key));
                }
            });
            rows.push(...newRows);

            return createElement('div', { className: 'pblsh--destination-choice pblsh--destination-choice--list', role: 'radiogroup', 'aria-label': __('Publish destination', 'peak-publisher') },
                createElement('h3', { className: 'pblsh--destination-choice__prompt' }, __('Where does this release belong?', 'peak-publisher')),
                rows.map((destination) => {
                    // A channel can appear more than once here (existing row + new-plugin
                    // alternative) — the id needs the slug; "--" separates the two parts
                    // unambiguously (a hosting type never contains consecutive dashes).
                    const value = destination.hostingType + '--' + destination.slug;
                    const id = 'pblsh-upload-destination-' + value;
                    return createElement('label', {
                        key: destination.hostingType + ':' + destination.slug,
                        className: 'pblsh--destination-choice__option pblsh--destination-option',
                        htmlFor: id,
                    },
                        createElement('input', {
                            id,
                            type: 'radio',
                            name: 'pblsh-upload-destination',
                            value,
                            checked: false,
                            onChange: () => selectDestination(destination, meta, uploadId),
                        }),
                        destination.existing ? renderDestinationOptionText(destination) : renderNewPluginOptionText(destination),
                    );
                }),
                createElement('div', { className: 'pblsh--destination-choice__slug-edit' },
                    createElement('span', { className: 'pblsh--destination-choice__slug-edit-label' },
                        // Every assignment row carries a slug (candidate rows always do, and the
                        // no-candidates case never reaches this mode), so the label is static.
                        __('None of these? Enter a custom slug:', 'peak-publisher')),
                    renderSlugEdit(uploadId),
                ),
            );
        }

        const slugMissing = destinations.some((destination) => !destination.slug);
        if (slugMissing) {
            // No usable slug from the upload (uniform across channels — the candidates are
            // global): a destination is channel × slug, so without a slug the channel cards
            // would be dead ends. The slug is the only open question here; the channel
            // choice follows on this same screen once an identity exists.
            return createElement('div', { className: 'pblsh--destination-choice' },
                createElement('div', { className: 'pblsh--destination-choice__slug-edit' },
                    createElement('span', { className: 'pblsh--destination-choice__slug-edit-label' },
                        __('No usable plugin slug could be derived from your upload. Enter one:', 'peak-publisher')),
                    renderSlugEdit(uploadId),
                ),
            );
        }

        // Channel mode: every destination is a new plugin — the decision is only the channel.
        // The slug resolves automatically afterwards and stays editable in the publish-path
        // row, so the cards deliberately don't show it.
        return createElement('div', { className: 'pblsh--destination-choice', role: 'radiogroup', 'aria-label': __('Publish destination', 'peak-publisher') },
            destinations.length > 1 && createElement('h3', { className: 'pblsh--destination-choice__prompt' },
                __('Choose the distribution channel', 'peak-publisher')),
            destinations.map((destination) => {
                // One card per channel in this mode — the hosting type is the natural id.
                const id = 'pblsh-upload-destination-' + destination.hostingType;
                return createElement('label', {
                    key: destination.hostingType + ':' + destination.slug,
                    className: 'pblsh--destination-choice__option',
                    htmlFor: id,
                },
                    createElement('input', {
                        id,
                        type: 'radio',
                        name: 'pblsh-upload-destination',
                        value: destination.hostingType,
                        checked: false,
                        onChange: () => selectDestination(destination, meta, uploadId),
                    }),
                    targetIcons[destination.hostingType] && createElement('span', { className: 'pblsh--destination-choice__icon' }, getSvgIcon(targetIcons[destination.hostingType], { size: 24 })),
                    createElement('span', { className: 'pblsh--destination-choice__label' }, getChannelLabel(destination.hostingType)),
                    createElement('span', { className: 'pblsh--destination-choice__desc' }, getChannelDescription(destination.hostingType)),
                );
            }),
            destinations.length > 1 && createElement('div', { className: 'pblsh--destination-choice__note' },
                createElement('span', { className: 'pblsh--destination-choice__note-icon' }, getSvgIcon('information_outline', { size: 20 })),
                createElement('div', { className: 'pblsh--destination-choice__note-text' },
                    createElement('p', null,
                        createElement('strong', null,
                            __('Not sure?', 'peak-publisher')
                        ),
                    ),
                    createElement('p', null,
                        createInterpolateElement(
                            sprintf(__('Choose <strong>%s</strong> if your plugin is free and open-source and you want it listed in the official WordPress plugin directory so that it can be easily found and installed by everyone — however, your plugin must first be <a>reviewed and approved</a> by the WordPress.org plugin team.', 'peak-publisher'), getChannelLabel('wporg')),
                            {
                                strong: createElement('strong'),
                                a: createElement('a', { href: 'https://developer.wordpress.org/plugins/wordpress-org/', target: '_blank' }),
                            }
                        ),
                    ),
                    createElement('p', null,
                        // Once license management exists, examples become useful again: "— for example premium, internal, or client plugins."
                        createInterpolateElement(
                            sprintf(__('Otherwise choose <strong>%1$s</strong> — releases are then distributed directly from your own server %2$s, without any review process, and go live instantly. Note that self-hosted plugins are also publicly accessible via the update API by default; you can restrict access with the IP/domain whitelist in the settings.', 'peak-publisher'), getChannelLabel('self_hosted'), window.location.hostname),
                            {
                                strong: createElement('strong'),
                            }
                        ),
                    ),
                    createElement('ul', { className: 'pblsh--destination-choice__note-links' },
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
        identity = null,
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
            identity,
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
            case 'plugin_name':
                return __('plugin name', 'peak-publisher');
            case 'top_level_folder':
                return __('top-level folder', 'peak-publisher');
            case 'zip_filename':
                return __('ZIP filename', 'peak-publisher');
            case 'main_file_basename':
                return __('main plugin file name', 'peak-publisher');
            case 'user_defined':
                return __('your input', 'peak-publisher');
            default:
                return source ? String(source) : __('source unknown', 'peak-publisher');
        }
    }

    function renderSlugEdit(uploadId) {
        return createElement('div', { className: 'pblsh--slug-edit' },
            createElement(TextControl, {
                label: __('Custom plugin slug', 'peak-publisher'),
                hideLabelFromVision: true,
                placeholder: __('custom-slug', 'peak-publisher'),
                value: slugEditValue,
                // Live transform while typing (lowercase, whitespace/underscores to hyphens, rest stripped);
                // the structural hyphen rules stay Apply-only so intermediate states like "my-" remain typable.
                onChange: (value) => {
                    setSlugEditValue(value.toLowerCase().replace(/[\s_]+/g, '-').replace(/[^a-z0-9-]/g, ''));
                    setSlugEditError('');
                },
                disabled: isProcessing,
                __next40pxDefaultSize: true,
                __nextHasNoMarginBottom: true,
            }),
            createElement(Button, {
                isPrimary: true,
                onClick: () => applySlug(uploadId, slugEditValue),
                isBusy: isProcessing,
                disabled: isProcessing,
                __next40pxDefaultSize: true,
            }, __('Apply', 'peak-publisher')),
            slugEditError && createElement('p', { className: 'pblsh--slug-edit__error' }, slugEditError),
        );
    }

    function renderDestinationControl(meta, uploadId) {
        const targets = meta?.hosting_type_targets || {};
        const activeKey = getActiveTargetKey(meta);
        const active = targets[activeKey];
        if (!active) return null;
        const destinations = getDestinations(meta);
        const rowContent = [
            targetIcons[activeKey] && createElement('span', { className: 'pblsh--publish-path__trigger-icon' }, getSvgIcon(targetIcons[activeKey], { size: 18 })),
            createElement('span', null, getChannelLabel(activeKey)),
            createElement('span', { className: 'pblsh--publish-path__sep', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 16 })),
            // The result gate only renders this row for a target with a resolved slug —
            // a slugless state stays on the destination screen.
            createElement('code', { className: 'pblsh--publish-path__value' }, active.slug),
        ];

        if (!meta?.hosting_type_choice && getTargetKeys(meta).length === 1 && active.slug_locked) {
            // A settled fact, not a decision: exactly one existing match and no channel choice.
            return createElement('span', { className: 'pblsh--publish-path__trigger is-static' }, ...rowContent);
        }

        // The panel lists every alternative of the active channel — a selection must never
        // silently drop the channel it was offered under (guaranteed by the helper's
        // candidate_channels check).
        const extraCandidates = candidateAlternativesForChannel(meta, destinations, activeKey);

        return createElement('div', {
            className: 'pblsh--publish-path__select',
            ref: destinationPanelRef,
            onKeyDown: (event) => {
                if (event.key === 'Escape') {
                    if (destinationPanelOpen) {
                        // Close only the panel, not the dialog: cancelling the keydown keeps
                        // the native <dialog> Escape behavior from firing.
                        event.preventDefault();
                        setDestinationPanelOpen(false);
                        destinationTriggerRef.current?.focus();
                    }
                    return;
                }
                if (![ 'ArrowDown', 'ArrowUp', 'Home', 'End' ].includes(event.key)) return;
                // Native caret movement stays untouched inside the custom-slug input.
                if (event.target instanceof HTMLInputElement) return;
                const key = event.key;
                if (!destinationPanelOpen) {
                    if (key === 'ArrowDown' || key === 'ArrowUp') {
                        event.preventDefault();
                        setDestinationPanelOpen(true);
                        window.requestAnimationFrame(() => {
                            const options = destinationPanelRef.current?.querySelectorAll('[role="option"]') || [];
                            options[key === 'ArrowDown' ? 0 : options.length - 1]?.focus();
                        });
                    }
                    return;
                }
                const options = Array.from(event.currentTarget.querySelectorAll('[role="option"]'));
                if (options.length === 0) return;
                event.preventDefault();
                const currentIndex = options.indexOf(document.activeElement);
                const nextIndex = key === 'Home' ? 0
                    : key === 'End' ? options.length - 1
                    : currentIndex === -1 ? (key === 'ArrowDown' ? 0 : options.length - 1)
                    : Math.min(Math.max(currentIndex + (key === 'ArrowDown' ? 1 : -1), 0), options.length - 1);
                options[nextIndex]?.focus();
            },
            // Close only on real focus travel (Tab). Clicks on non-focusable areas move focus
            // to null or to the dialog itself (it carries tabindex="-1") — both cases can't
            // distinguish inside from outside, so the outside-pointerdown listener decides there.
            onBlur: (event) => {
                const next = event.relatedTarget;
                if (!next || next === dialogRef.current) return;
                if (!event.currentTarget.contains(next)) setDestinationPanelOpen(false);
            },
        },
            createElement('button', {
                type: 'button',
                ref: destinationTriggerRef,
                className: 'pblsh--publish-path__trigger',
                'aria-haspopup': 'listbox',
                'aria-expanded': destinationPanelOpen,
                'aria-controls': destinationPanelOpen ? 'pblsh-publish-path-listbox' : undefined,
                onClick: () => setDestinationPanelOpen(!destinationPanelOpen),
                disabled: isProcessing,
            },
                // Visually hidden prefix: the accessible name must contain the current value
                // (channel → slug), so no aria-label may override the button's contents.
                createElement('span', { className: 'screen-reader-text' }, __('Publish destination:', 'peak-publisher') + ' '),
                ...rowContent,
                createElement('span', { className: 'pblsh--publish-path__chevron' }, getSvgIcon('chevron_down', { size: 18 })),
            ),
            destinationPanelOpen && createElement('div', { className: 'pblsh--publish-path__options' },
                // Only the option list scrolls; the custom-slug input sits below it as a fixed footer.
                createElement('div', { id: 'pblsh-publish-path-listbox', className: 'pblsh--publish-path__list', role: 'listbox', 'aria-label': __('Publish destination', 'peak-publisher') },
                    destinations.map((destination) => {
                        const isActive = destination.hostingType === activeKey && destination.slug === (active.slug || '');
                        return createElement('button', {
                            key: destination.hostingType + ':' + destination.slug,
                            type: 'button',
                            role: 'option',
                            'aria-selected': isActive,
                            className: 'pblsh--publish-path__option pblsh--destination-option' + (isActive ? ' is-selected' : ''),
                            onClick: () => selectDestination(destination, meta, uploadId),
                            disabled: isProcessing,
                        }, renderDestinationOptionText(destination));
                    }),
                    extraCandidates.map((candidate) => createElement('button', {
                        key: 'candidate:' + candidate.slug,
                        type: 'button',
                        role: 'option',
                        'aria-selected': false,
                        className: 'pblsh--publish-path__option pblsh--destination-option',
                        onClick: () => applySlug(uploadId, candidate.slug),
                        disabled: isProcessing,
                    }, renderDestinationOptionText(candidateDestination(candidate, activeKey)))),
                ),
                renderSlugEdit(uploadId),
            ),
        );
    }

    function renderPublishPath(meta, uploadId) {
        if (!meta?.plugin_ok) return null;
        return createElement('div', { className: 'pblsh--publish-path' }, renderDestinationControl(meta, uploadId));
    }

    async function runSlugSelection(upload_id, slug, selectHostingTypeOnSuccess = null) {
        setIsProcessing(true);
        try {
            const resSet = await Pblsh.API.uploadContinue(upload_id, 'set_slug', { slug });
            if (resSet?.status !== 'ok') {
                setSlugEditError(resSet?.errors?.[0]?.message || __('Could not set the plugin slug.', 'peak-publisher'));
                return;
            }
            const resResult = await Pblsh.API.uploadContinue(upload_id, 'result');
            setDestinationPanelOpen(false);
            setSlugEditValue('');
            setValidationResult(withUploadId(resResult, upload_id));
            if (selectHostingTypeOnSuccess) {
                // Deferred channel switch: applied together with the result that resolves the
                // channel, never before it (see selectDestination).
                setSelectedHostingType(selectHostingTypeOnSuccess);
            }
            // The focused element (option or Apply button) unmounts with the panel/screen —
            // focus continues on the publish-path trigger of the fresh result view.
            window.requestAnimationFrame(() => destinationTriggerRef.current?.focus());
        } catch (error) {
            setSlugEditError(error?.message || __('Could not set the plugin slug.', 'peak-publisher'));
        } finally {
            setIsProcessing(false);
        }
    }

    async function applySlug(upload_id, value, selectHostingTypeOnSuccess = null) {
        const slug = String(value || '').trim();
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
            setSlugEditError(__('Slugs consist of lowercase letters, numbers, and hyphens.', 'peak-publisher'));
            return;
        }
        setSlugEditError('');
        await runSlugSelection(upload_id, slug, selectHostingTypeOnSuccess);
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
        const canFinalize = !!meta.plugin_ok && !!meta.slug && checksPass && targetFinalizable;
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
            identity: renderPublishPath(meta, context.uploadId),
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
            body: gate,
            checklistItems,
            actions: gate ? [renderDiscardButton(context.uploadId)] : buildUploadActions(context, checklistItems),
        };
    }

    function renderValidation(result) {
        let meta = result?.data || {};
        const activeTargetKey = getActiveTargetKey(meta);
        const activeTarget = activeTargetKey ? meta?.hosting_type_targets?.[activeTargetKey] : null;
        if (meta?.plugin_ok && ((meta?.hosting_type_choice && !activeTargetKey) || (activeTarget && !activeTarget.slug))) {
            // Open destination decision — channel choice or slug ambiguity: the choice is the
            // only content, so it lives in the scrollable body instead of the fixed slot and
            // the publish-path row stays hidden until the destination is settled.
            return renderUploadResultShell({
                header: {
                    headline: meta?.plugin_data?.Name || '',
                    desc: meta?.plugin_data?.Version || '',
                },
                body: renderDestinationChoice(meta, result?.upload_id),
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

    const dialog = createElement('dialog', {
        className: 'pblsh--modal pblsh--modal--upload-result',
        ref: dialogRef,
        tabIndex: -1,
        // The dialog holds a pending upload awaiting a decision — leaving it is an explicit
        // action (Discard), so the native Escape close is cancelled. Chrome's close-watcher
        // still force-closes on a second Escape without intervening interaction; onClose
        // catches that (and any other unexpected close) and resets the overlay state instead
        // of leaving a closed dialog behind that the state still believes is open. The upload
        // then stays in tmp for the regular stale-uploads cleanup — deliberately no discard
        // call without an explicit user decision.
        onCancel: (event) => event.preventDefault(),
        onClose: () => { if (validationResult) closeOverlay(); },
    }, validationResult ? renderValidation(validationResult) : null);

    return createElement(wp.element.Fragment, null, overlay, dialog);
});
