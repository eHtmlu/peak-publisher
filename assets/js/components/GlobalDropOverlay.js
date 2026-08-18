// GlobalDropOverlay Component - handles app-wide drag & drop upload
lodash.set(window, 'Pblsh.Components.GlobalDropOverlay', ({ onCreated, activeUploadContext = {} } = {}) => {
    const { __ } = wp.i18n;
    const sprintf = wp.i18n.sprintf ?? window.sprintf;
    const { useState, useEffect, useRef, createElement, createInterpolateElement } = wp.element;
    const { Button, TextControl } = wp.components;
    const { useSelect } = wp.data;
    const { getSvgIcon, getFaqUrl } = Pblsh.Utils;
    const { getReleaseContext, getReleasePresentation } = Pblsh.UploadResultUtils;
    const { useUploadChecks } = Pblsh.Hooks;
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
    const [destinationPanelOpen, setDestinationPanelOpen] = useState(false);
    const [slugEditValue, setSlugEditValue] = useState('');
    const [slugEditError, setSlugEditError] = useState('');
    const [selectedHostingType, setSelectedHostingType] = useState(null);

    // Owns the per-upload confirmation decisions and builds the checklist items (upload-checks.js).
    const uploadChecks = useUploadChecks();

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
        setSelectedHostingType(null);
        uploadChecks.reset();
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
        const checklistItems = gate ? null : uploadChecks.buildUploadCheckItems(context);

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
