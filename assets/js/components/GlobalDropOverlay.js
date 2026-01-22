// GlobalDropOverlay Component - handles app-wide drag & drop upload
lodash.set(window, 'Pblsh.Components.GlobalDropOverlay', ({ onCreated } = {}) => {
    const { __ } = wp.i18n;
    const sprintf = wp.i18n.sprintf ?? window.sprintf;
    const { useState, useEffect, useRef, createElement, createInterpolateElement } = wp.element;
    const { Button, CheckboxControl } = wp.components;
    const { useSelect } = wp.data;
    const { getSvgIcon } = Pblsh.Utils;

    const fileInputRef = useRef(null);
    const dialogRef = useRef(null);
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
    const [addWithoutTopLevelFolder, setAddWithoutTopLevelFolder] = useState(false);
    const [changePluginFileName, setChangePluginFileName] = useState(false);
    const [useUnexpectedPluginVersion, setUseUnexpectedPluginVersion] = useState(false);
    const [useOlderPluginVersion, setUseOlderPluginVersion] = useState(false);
    const [useNotPeakPublisherForNewUpdateServer, setUseNotPeakPublisherForNewUpdateServer] = useState(false);
    const [keepWorkspaceArtifacts, setKeepWorkspaceArtifacts] = useState(false);
    const [keepReadmeTxtBom, setKeepReadmeTxtBom] = useState(false);
    const [keepReadmeTxtEncoding, setKeepReadmeTxtEncoding] = useState(false);
    const [keepReadmeTxtAsIs, setKeepReadmeTxtAsIs] = useState(false);

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);

    function resetResultDecisions() {
        setUseDifferentCustomUpdateServer(false);
        setUsePeakPublisherForNewUpdateServer(false);
        setUseWordPressOrgUpdateServer(false);
        setReplaceRelease(false);
        setAddWithoutTopLevelFolder(false);
        setChangePluginFileName(false);
        setUseUnexpectedPluginVersion(false);
        setUseOlderPluginVersion(false);
        setUseNotPeakPublisherForNewUpdateServer(false);
        setKeepWorkspaceArtifacts(false);
        setKeepReadmeTxtBom(false);
        setKeepReadmeTxtEncoding(false);
        setKeepReadmeTxtAsIs(false);
    }

    useEffect(() => {
        const onDragEnter = (e) => {
            e.preventDefault();
            setDragCounter((c) => c + 1);
            setVisible(true);
        };
        const onDragOver = (e) => {
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
        setVisible(false);
        setZipProgress(false);
        setUploadProgress(false);
        setIsProcessing(false);
        setValidationResult(null);
        setFilename('');
        resetResultDecisions();
    };

    function startUpload(file) {
        setVisible(true);
        setUploadProgress(0);
        setIsProcessing(false);
        setValidationResult(null);
        Pblsh.API.uploadStart(file, (percent) => {
            setUploadProgress(percent);
            if (percent === 100) {
                setUploadProgress(false);
                setIsProcessing(true);
                setProcessPhase('upload_prepare');
            }
        }, { builtInBrowserBy: '' })
        .then(async (res1) => {
            setUploadProgress(false);
            const uploadId = res1 && res1.upload_id ? res1.upload_id : null;
            if (!uploadId) throw new Error('Missing upload_id after upload');

            setIsProcessing(true);
            setProcessPhase('unpack');
            const resUnpack = await Pblsh.API.uploadContinue(uploadId, 'unpack');
            setProcessPhase('analyze');
            const res2 = await Pblsh.API.uploadContinue(uploadId, 'analyze');
            const next = res2 && res2.next ? res2.next : 'result';
            if (next === 'rebuild_zip') {
                setProcessPhase('rebuild_zip');
                await Pblsh.API.uploadContinue(uploadId, 'rebuild_zip');
            }
            setProcessPhase('result');
                const res3 = await Pblsh.API.uploadContinue(uploadId, 'result');
            setIsProcessing(false);
            setValidationResult(res3);
        })
        .catch((err) => {
            setUploadProgress(false);
            setIsProcessing(false);
            setValidationResult({ status: 'error', errors: [ { code: 'upload_error', message: err.message } ], data: {} });
        });
    }

    function startUploadDirectory(filesWithPaths) {
        setVisible(true);
        setZipProgress(0);
        setUploadProgress(false);
        setIsProcessing(false);
        setValidationResult(null);
        Pblsh.UploadUtils.createZipFromFiles(filesWithPaths, (p) => {
            setZipProgress(prev => Math.max(prev, Math.floor(p)));
        })
        .then((zipFile) => {
            setZipProgress(false);
            setUploadProgress(0);
            Pblsh.API.uploadStart(zipFile, (percent) => {
                const mapped = percent;
                setUploadProgress(mapped);
                if (mapped >= 100) {
                    setUploadProgress(false);
                    setIsProcessing(true);
                    setProcessPhase('upload_prepare');
                }
            }, { builtInBrowserBy: 'jszip' })
            .then(async (res1) => {
                setUploadProgress(false);
                const uploadId = res1 && res1.upload_id ? res1.upload_id : null;
                if (!uploadId) throw new Error('Missing upload_id after upload');

                setIsProcessing(true);
                setProcessPhase('unpack');
                const resUnpack = await Pblsh.API.uploadContinue(uploadId, 'unpack');
                setProcessPhase('analyze');
                const res2 = await Pblsh.API.uploadContinue(uploadId, 'analyze');
                const next = res2 && res2.next ? res2.next : 'result';
                if (next === 'rebuild_zip') {
                    setProcessPhase('rebuild_zip');
                    await Pblsh.API.uploadContinue(uploadId, 'rebuild_zip');
                }
                setProcessPhase('result');
                const res3 = await Pblsh.API.uploadContinue(uploadId, 'result');
                setIsProcessing(false);
                setValidationResult(res3);
            })
            .catch((err) => {
                setUploadProgress(false);
                setIsProcessing(false);
                setValidationResult({ status: 'error', errors: [ { code: 'upload_error', message: err.message } ], data: {} });
            });
        })
        .catch((err) => {
            setZipProgress(false);
            setUploadProgress(false);
            setIsProcessing(false);
            setValidationResult({ status: 'error', errors: [ { code: 'zip_error', message: err?.message || 'Zipping failed' } ], data: {} });
        });
    }
    

    function finalizeCreation(uploadId) {
        if (!uploadId) return;
        setIsProcessing(true);
        Pblsh.API.finalizeUpload(uploadId)
            .then((res) => {
                setIsProcessing(false);
                if (res && res.status === 'ok' && res.plugin_id) {
                    if (typeof onCreated === 'function') {
                        closeOverlay();
                        onCreated(res.plugin_id);
                        return;
                    }
                } else {
                    setValidationResult({ status: 'error', errors: [ { code: 'finalize_failed', message: (res && res.message) ? res.message : 'Finalize failed.' } ], data: validationResult?.data || {} });
                }
            })
            .catch((err) => {
                setIsProcessing(false);
                setValidationResult({ status: 'error', errors: [ { code: 'finalize_error', message: err.message } ], data: validationResult?.data || {} });
            });
    }

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const index = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, index)).toFixed(2) + ' ' + units[index];
    }

    function renderValidation(result) {
        const ok = result && result.status === 'ok' && (!result.errors || result.errors.length === 0);
        const errors = Array.isArray(result?.errors) ? result.errors : [];
        const meta = result?.data || {};
        const pluginData = result?.data?.plugin_data || {};
        const settings = serverSettings || {};
        const upload_id = result?.upload_id || false;

        const previous_release = result?.data?.related_releases?.previous || false;
        const next_release = result?.data?.related_releases?.next || false;
        const latest_release = result?.data?.related_releases?.latest || false;
        const existing_release = result?.data?.related_releases?.existing || false;


        const previous_release_version = previous_release ? previous_release.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        const plugin_version = result?.data?.plugin_info?.normalized_version ? result.data.plugin_info.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        
        const natural_successors = [];
        if (previous_release_version.length > 0 && plugin_version.length > 0) {
            while (previous_release_version.length < plugin_version.length) {
                previous_release_version.push(0);
            }
            while (plugin_version.length < previous_release_version.length) {
                plugin_version.push(0);
            }

            for (let i = 0; i < previous_release_version.length; i++) {
                natural_successors.push([...previous_release_version.slice(0, i), previous_release_version[i] + 1, ...(new Array(plugin_version.length - i - 1).fill(0))].join('.'));
            }
        }
        const is_natural_successor = natural_successors.includes(plugin_version.join('.'));


        const is_new_major_release = meta.existing_plugin && (
            (previous_release && plugin_version[0] > previous_release_version[0]) ||
            (!previous_release && plugin_version[1] === 0 && plugin_version[2] === 0)
        );
        const is_new_minor_release = meta.existing_plugin && (
            (previous_release && plugin_version[0] === previous_release_version[0] && plugin_version[1] > previous_release_version[1]) ||
            (!previous_release && plugin_version[1] > 0 && plugin_version[2] === 0)
        );
        const is_new_patch_release = meta.existing_plugin && (
            (previous_release && plugin_version[0] === previous_release_version[0] && plugin_version[1] === previous_release_version[1] && plugin_version[2] > previous_release_version[2]) ||
            (!previous_release && plugin_version[2] > 0)
        );
        const is_new_unknown_release = meta.existing_plugin && !is_new_major_release && !is_new_minor_release && !is_new_patch_release;

        const free_from_workspace_artifacts = meta?.cleanup_info?.found_workspace_artifacts?.every(item => item.deleted);
        const workspace_artifacts_count = meta?.cleanup_info?.found_workspace_artifacts?.reduce((total, item) => total + item.count, 0);
        const workspace_artifacts_size = formatBytes(meta?.cleanup_info?.found_workspace_artifacts?.reduce((total, item) => total + item.bytes, 0));

        const workspace_artifacts_not_deleted_count = meta?.cleanup_info?.found_workspace_artifacts?.reduce((total, item) => total + (item.deleted ? 0 : item.count), 0);
        const workspace_artifacts_not_deleted_size = formatBytes(meta?.cleanup_info?.found_workspace_artifacts?.reduce((total, item) => total + (item.deleted ? 0 : item.bytes), 0));

        const readme_txt_already_utf8 = !!meta?.cleanup_info?.readme_txt?.already_utf8;
        const readme_txt_already_without_bom = !!meta?.cleanup_info?.readme_txt?.already_without_bom;
        const readme_txt_detected_encoding = meta?.cleanup_info?.readme_txt?.detected_encoding || '';
        const readme_txt_converted_to_utf8 = !!meta?.cleanup_info?.readme_txt?.converted_to_utf8;
        const readme_txt_removed_utf8_bom = !!meta?.cleanup_info?.readme_txt?.removed_utf8_bom;
        const readme_txt_can_be_encoded_to_json = !!meta?.cleanup_info?.readme_txt?.can_be_encoded_to_json;

        const resultList = (meta.plugin_ok ? [

            // Plugin file
            [
                !previous_release && {
                    title: __('Valid plugin file', 'peak-publisher'),
                    type: 'ok',
                    desc: [
                        meta.plugin_info?.main_file
                    ]
                },
                previous_release && [
                    // Expected plugin file
                    previous_release.plugin_basename === meta.plugin_info?.plugin_basename && {
                        title: __('Expected plugin file', 'peak-publisher'),
                        type: 'ok',
                        desc: __('The plugin file name matches the previous release.', 'peak-publisher'),
                    },
                    // Unexpected plugin file
                    previous_release.plugin_basename !== meta.plugin_info?.plugin_basename && {
                        title: __('Unexpected plugin file', 'peak-publisher'),
                        type: changePluginFileName ? 'ok' : 'error',
                        desc: [
                            sprintf(__('The uploaded release %s has the plugin file name %s which does not match the previous release %s with the plugin file name %s.', 'peak-publisher'), pluginData.Version, meta.plugin_info?.plugin_basename.split('/').pop(), previous_release.version, previous_release.plugin_basename.split('/').pop()),
                            createElement('br'),
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('That\'s fine, I want to change the plugin filename. I\'m aware that WordPress will interpret this as a different plugin, and that this is very risky and should be avoided if possible.', 'peak-publisher'),
                                checked: changePluginFileName,
                                onChange: (value) => setChangePluginFileName(value),
                            }),
                        ],
                    }
                ]
            ],

            // Plugin version number
            [
                !pluginData.Version && {
                    title: __('Missing version number', 'peak-publisher'),
                    type: 'error',
                    desc: __('You need to add a version number to your plugin file.', 'peak-publisher')
                },
                pluginData.Version && [
                    !existing_release && [
                        !latest_release && {
                            title: __('Valid version number', 'peak-publisher'),
                            type: 'ok',
                            desc: pluginData.Version,
                        },
                        latest_release && [
                            // Expected version number
                            is_natural_successor && !next_release && {
                                title: __('Expected version number', 'peak-publisher'),
                                type: 'ok',
                                desc: [
                                    sprintf(__('Version %s, as expected after the latest release (%s).', 'peak-publisher'), pluginData.Version, latest_release.version),
                                ]
                            },
                            // Unexpected version number
                            (!is_natural_successor || next_release) && {
                                title: __('Unexpected version number', 'peak-publisher'),
                                type: (previous_release.normalized_version === latest_release.normalized_version || useOlderPluginVersion) && (is_natural_successor || useUnexpectedPluginVersion) ? 'ok' : 'error',
                                desc: [
                                    next_release && [
                                        latest_release.normalized_version !== next_release.normalized_version && sprintf(__('Releases with higher version numbers (%s to %s) already exist.', 'peak-publisher'), next_release.version, latest_release.version),
                                        latest_release.normalized_version === next_release.normalized_version && sprintf(__('A release with a higher version number (%s) already exists.', 'peak-publisher'), latest_release.version),
                                        createElement('br'),
                                        createElement(CheckboxControl, {
                                            __nextHasNoMarginBottom: true,
                                            label: __('That\'s fine, this release isn\'t meant to be the latest one.', 'peak-publisher'),
                                            checked: useOlderPluginVersion,
                                            onChange: (value) => setUseOlderPluginVersion(value),
                                        }),
                                    ],
                                    previous_release && !is_natural_successor && [
                                        sprintf(__('%s is an unexpected successor to the previous release (%s).', 'peak-publisher'), pluginData.Version, previous_release.version),
                                        createElement('br'),
                                        sprintf(__('Expected would be %s.', 'peak-publisher'), natural_successors.join(', ')),
                                        createElement('br'),
                                        createElement(CheckboxControl, {
                                            __nextHasNoMarginBottom: true,
                                            label: sprintf(__('That\'s fine, I want to use the version number %s anyway.', 'peak-publisher'), pluginData.Version),
                                            checked: useUnexpectedPluginVersion,
                                            onChange: (value) => setUseUnexpectedPluginVersion(value),
                                        }),
                                    ],
                                ]
                            },
                        ],
                    ],
                    existing_release && {
                        title: __('Version number already exists', 'peak-publisher'),
                        type: replaceRelease ? 'ok' : 'error',
                        desc: [
                            sprintf(__('A release with the version number %s already exists for this plugin.', 'peak-publisher'), pluginData.Version),
                            createElement('br'),
                            //createElement('br'),
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('That\'s fine, I want to replace the existing release. I understand that this is not recommended if the existing release is or was already published.', 'peak-publisher'),
                                checked: replaceRelease,
                                onChange: (value) => setReplaceRelease(value),
                            }),
                        ]
                    }
                ]
            ],

            // Update URI
            [
                pluginData?.UpdateURI && [
                    pluginData?.UpdateURI === PblshData?.bootstrapUpdateURI && {
                        title: __('Expected update URI', 'peak-publisher'),
                        type: 'ok',
                        desc: pluginData.UpdateURI
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
                        ]
                    }
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
                    ]
                }
            ],

            // Bootstrap code
            [
                meta.plugin_info?.bootstrap_file && [
                    !useDifferentCustomUpdateServer && !useWordPressOrgUpdateServer && {
                        title: __('Expected bootstrap code', 'peak-publisher'),
                        type: 'ok',
                        desc: sprintf(__('Found in %s.', 'peak-publisher'), meta.plugin_info?.bootstrap_file)
                    },
                    useDifferentCustomUpdateServer && {
                        title: __('Found bootstrap code', 'peak-publisher'),
                        type: usePeakPublisherForNewUpdateServer ? 'ok' : 'error',
                        desc: [
                            sprintf(__('Do you plan to use Peak Publisher again for your new update server? Otherwise, you will need to remove the bootstrap code from %s.', 'peak-publisher'), meta.plugin_info?.bootstrap_file),
                            createElement('br'),
                            createElement(CheckboxControl, {
                                __nextHasNoMarginBottom: true,
                                label: __('Yes, I will use Peak Publisher again for my new update server.', 'peak-publisher'),
                                checked: usePeakPublisherForNewUpdateServer,
                                onChange: (value) => setUsePeakPublisherForNewUpdateServer(value),
                            })
                        ]
                    },
                    useWordPressOrgUpdateServer && {
                        title: __('Bootstrap code must be removed', 'peak-publisher'),
                        type: 'error',
                        desc: sprintf(__('You need to remove the bootstrap code from %s since your new update server is wordpress.org.', 'peak-publisher'), meta.plugin_info?.bootstrap_file)
                    }
                ],
                !meta.plugin_info?.bootstrap_file && [
                    !useDifferentCustomUpdateServer && !useWordPressOrgUpdateServer && {
                        title: __('Missing bootstrap code', 'peak-publisher'),
                        type: 'error',
                        desc: __('You need to add the bootstrap code to your plugin.', 'peak-publisher')
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
                        ]
                    },
                    useWordPressOrgUpdateServer && {
                        title: __('Bootstrap code not found', 'peak-publisher'),
                        type: 'ok',
                        desc: __('This is as it should be if you plan to use wordpress.org as your new update server.', 'peak-publisher')
                    },
                ]
            ],

            // Top-level folder
            [
                (meta?.cleanup_info?.has_top_level_folder || meta?.cleanup_info?.fixed_top_level_folder) && {
                    title: __('Top-level folder exists', 'peak-publisher'),
                    type: 'ok',
                    desc: [
                        meta?.cleanup_info?.fixed_top_level_folder && __('It was added to your upload automatically as specified in the settings.', 'peak-publisher'),
                        !meta?.cleanup_info?.fixed_top_level_folder && __('Your upload has a top-level folder.', 'peak-publisher'),
                        createElement('br'),
                        sprintf(__('The install folder will be %s.', 'peak-publisher'), '/wp-content/plugins/' + meta.plugin_info?.plugin_basename.split('/')[0] + '/'),
                    ]
                },
                !meta?.cleanup_info?.has_top_level_folder && !meta?.cleanup_info?.fixed_top_level_folder && {
                    title: __('Top-level folder missing', 'peak-publisher'),
                    type: addWithoutTopLevelFolder ? 'ok' : 'error',
                    desc: [
                        __('Your upload does not have a top-level folder.', 'peak-publisher'),
                        createElement('br'),
                        sprintf(__('The install folder will be %s.', 'peak-publisher'), '/wp-content/plugins/' + meta.plugin_info?.plugin_basename.split('/')[0] + '/'),
                        createElement(CheckboxControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('That\'s fine, I want to add the release without a top-level folder.', 'peak-publisher'),
                            checked: addWithoutTopLevelFolder,
                            onChange: (value) => setAddWithoutTopLevelFolder(value),
                        }),
                    ]
                }
            ],
            
            // Workspace artifacts
            [
                // Nothing to remove
                free_from_workspace_artifacts && {
                    title: __('Free from workspace artifacts', 'peak-publisher'),
                    type: 'ok',
                    desc: [
                        workspace_artifacts_count === 0 && sprintf(__('No files or folders from your system or development environment were found.', 'peak-publisher')),
                        workspace_artifacts_count > 0 && sprintf(__('%s in %s files and folders deleted as specified in the settings.', 'peak-publisher'), workspace_artifacts_size, workspace_artifacts_count),
                        createElement('br'),
                        sprintf(__('The installed release will be %s in total with %s files and folders.', 'peak-publisher'), formatBytes(meta?.cleanup_info?.size_after_cleanup), meta?.cleanup_info?.entry_count_after_cleanup),
                    ],
                },
                // Removed files and folders
                !free_from_workspace_artifacts && {
                    title: __('Workspace artifacts found', 'peak-publisher'),
                    type: keepWorkspaceArtifacts ? 'ok' : 'error',
                    desc: [
                        settings.auto_remove_workspace_artifacts && __('The following artifacts could not be deleted automatically:', 'peak-publisher'),
                        !settings.auto_remove_workspace_artifacts && __('Your upload contains the following artifacts:', 'peak-publisher'),
                        createElement('br'),
                        createElement('textarea', {
                            value: meta?.cleanup_info?.found_workspace_artifacts?.filter(item => !item.deleted).map(file => file.path).join('\n'),
                            readOnly: true,
                            rows: Math.min(4, meta?.cleanup_info?.found_workspace_artifacts?.filter(item => !item.deleted).length + 1),
                            style: {
                                width: '100%',
                                whiteSpace: 'nowrap',
                                fontFamily: 'monospace',
                                fontSize: '12px',
                            },
                        }),
                        createElement('br'),
                        sprintf(__('The artifacts are %s in total with %s files and folders.', 'peak-publisher'), workspace_artifacts_not_deleted_size, workspace_artifacts_not_deleted_count),
                        createElement('br'),
                        createElement(CheckboxControl, {
                            __nextHasNoMarginBottom: true,
                            label: __('That\'s fine, I want to keep the artifacts in the release.', 'peak-publisher'),
                            checked: keepWorkspaceArtifacts,
                            onChange: (value) => setKeepWorkspaceArtifacts(value),
                        }),
                        sprintf(__('The installed release will be %s in total with %s files and folders.', 'peak-publisher'), formatBytes(meta?.cleanup_info?.size_after_cleanup), meta?.cleanup_info?.entry_count_after_cleanup),
                    ],
                },
            ],
            
            // Readme.txt presence
            [
                meta.plugin_readme_txt?.found && [
                    {
                        title: __('Readme file exists', 'peak-publisher'),
                        type:
                            (readme_txt_already_utf8 && readme_txt_already_without_bom)
                            ||
                            (settings.readme_txt_convert_to_utf8_without_bom && (
                                (readme_txt_converted_to_utf8 && readme_txt_already_without_bom)
                                ||
                                (readme_txt_removed_utf8_bom && readme_txt_already_utf8)
                                ||
                                (readme_txt_converted_to_utf8 && readme_txt_removed_utf8_bom)
                                ||
                                ((!readme_txt_already_utf8 !== readme_txt_converted_to_utf8 || !readme_txt_already_without_bom !== readme_txt_removed_utf8_bom) && keepReadmeTxtAsIs)
                            ))
                            ||
                            (!settings.readme_txt_convert_to_utf8_without_bom && (
                                (readme_txt_already_without_bom || keepReadmeTxtBom)
                                &&
                                (readme_txt_already_utf8 || keepReadmeTxtEncoding)
                            ))
                            ? 'ok' : 'error',
                        desc: [
                            meta.plugin_readme_txt?.file_name !== 'readme.txt' && [
                                sprintf(__('Although %s also works, the officially valid filename is readme.txt.', 'peak-publisher'), meta.plugin_readme_txt.file_name),
                                createElement('br'),
                            ],
                            readme_txt_already_utf8 && readme_txt_already_without_bom && __('The file is a valid UTF-8 file without a BOM, exactly as it should be.', 'peak-publisher'),
                            (!readme_txt_already_utf8 || !readme_txt_already_without_bom) && [
                                settings.readme_txt_convert_to_utf8_without_bom && [
                                    readme_txt_converted_to_utf8 && [
                                        readme_txt_detected_encoding && sprintf(__('The file was converted from %s to UTF-8 as specified in the settings.', 'peak-publisher'), readme_txt_detected_encoding),
                                        !readme_txt_detected_encoding && __('The file was converted to UTF-8 as specified in the settings.', 'peak-publisher'),
                                        createElement('br'),
                                    ],
                                    readme_txt_removed_utf8_bom && [
                                        __('The UTF-8 BOM was removed from the file as specified in the settings.', 'peak-publisher'),
                                        createElement('br'),
                                    ],
                                    (!readme_txt_already_utf8 !== readme_txt_converted_to_utf8 || !readme_txt_already_without_bom !== readme_txt_removed_utf8_bom) && [
                                        __('The file couldn\'t be converted to UTF-8 without a BOM. Please check it manually.', 'peak-publisher'),
                                        createElement('br'),
                                        !readme_txt_can_be_encoded_to_json && [
                                            __('The file can\'t be processed because it is not a valid UTF-8 file.', 'peak-publisher'),
                                            createElement('br'),
                                        ],
                                        createElement(CheckboxControl, {
                                            __nextHasNoMarginBottom: true,
                                            label: [
                                                readme_txt_can_be_encoded_to_json && __('That\'s fine, I want to keep the current encoding of the file as it is.', 'peak-publisher'),
                                                !readme_txt_can_be_encoded_to_json && __('That\'s fine, I want to keep the file even no information can be used from it.', 'peak-publisher')
                                            ],
                                            checked: keepReadmeTxtAsIs,
                                            onChange: (value) => setKeepReadmeTxtAsIs(value),
                                        }),
                                    ],
                                ],
                                !settings.readme_txt_convert_to_utf8_without_bom && [
                                    !readme_txt_already_without_bom && [
                                        __('The file has a UTF-8 BOM, which can cause issues.', 'peak-publisher'),
                                        createElement('br'),
                                        createElement(CheckboxControl, {
                                            __nextHasNoMarginBottom: true,
                                            label: __('That\'s fine, I want to keep the UTF-8 BOM in the file as it is.', 'peak-publisher'),
                                            checked: keepReadmeTxtBom,
                                            onChange: (value) => setKeepReadmeTxtBom(value),
                                        }),
                                    ],
                                    !readme_txt_already_utf8 && [
                                        readme_txt_detected_encoding && sprintf(__('The detected encoding is not UTF-8, but %s.', 'peak-publisher'), readme_txt_detected_encoding),
                                        !readme_txt_detected_encoding && __('The detected encoding is not UTF-8.', 'peak-publisher'),
                                        createElement('br'),
                                        !readme_txt_can_be_encoded_to_json && [
                                            __('The file can\'t be processed because it is not a valid UTF-8 file.', 'peak-publisher'),
                                            createElement('br'),
                                        ],
                                        createElement(CheckboxControl, {
                                            __nextHasNoMarginBottom: true,
                                            label: [
                                                readme_txt_can_be_encoded_to_json && __('That\'s fine, I want to keep the current encoding of the file as it is.', 'peak-publisher'),
                                                !readme_txt_can_be_encoded_to_json && __('That\'s fine, I want to keep the file even no information can be used from it.', 'peak-publisher'),
                                            ],
                                            checked: keepReadmeTxtEncoding,
                                            onChange: (value) => setKeepReadmeTxtEncoding(value),
                                        }),
                                    ],
                                ],
                            ],
                        ],
                    }
                ],
                !meta.plugin_readme_txt?.found && {
                    title: __('No readme file found', 'peak-publisher'),
                    type: 'info',
                    desc: [
                        createInterpolateElement(__('A readme.txt is not required but would allow you to provide a description, changelog, and more to your users. Check out the <a>example on wordpress.org</a>.', 'peak-publisher'), {
                            a: createElement('a', { href: 'https://wordpress.org/plugins/readme.txt', target: '_blank' }),
                        }),
                    ],
                },
            ]
        ] : []).flat(Infinity).filter(Boolean);

        return createElement('div', {
            className: [
                'pblsh--upload-result',
                !meta.plugin_ok && 'pblsh--upload-result--invalid',
                meta.plugin_ok && !meta.existing_plugin && 'pblsh--upload-result--newplugin',
                meta.plugin_ok && meta.existing_plugin && !existing_release && 'pblsh--upload-result--newrelease',
                meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_major_release && 'pblsh--upload-result--newmajor',
                meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_minor_release && 'pblsh--upload-result--newminor',
                meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_patch_release && 'pblsh--upload-result--newpatch',
                meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_unknown_release && 'pblsh--upload-result--newunknown',
                meta.plugin_ok && meta.existing_plugin && existing_release && 'pblsh--upload-result--releasereplacement',
            ].filter(Boolean).join(' '),
        },
            createElement('header', { className: 'pblsh--upload-result__plugin' },
                createElement('h2', { className: 'pblsh--upload-result__plugin__headline' },
                    meta.plugin_ok && pluginData.Name,
                    !meta.plugin_ok && __('Not a plugin', 'peak-publisher'),
                ),
                createElement('div', { className: 'pblsh--upload-result__plugin__desc' },
                    meta.plugin_ok && pluginData.Version,
                    !meta.plugin_ok && __('No valid plugin main file could be found', 'peak-publisher'),
                ),
                createElement('div', { className: 'pblsh--upload-result__plugin__type' },
                    !meta.plugin_ok && __('Invalid', 'peak-publisher'),
                    meta.plugin_ok && !meta.existing_plugin && __('New Plugin', 'peak-publisher'),
                    meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_major_release && __('New Major Release', 'peak-publisher'),
                    meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_minor_release && __('New Minor Release', 'peak-publisher'),
                    meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_patch_release && __('New Patch Release', 'peak-publisher'),
                    meta.plugin_ok && meta.existing_plugin && !existing_release && is_new_unknown_release && __('New Release', 'peak-publisher'),
                    meta.plugin_ok && meta.existing_plugin && existing_release && __('Replace Existing Release', 'peak-publisher'),
                ),
                /* meta.plugin_ok && !meta.existing_plugin && createElement('div', null,
                    sprintf(__('The plugin slug will be: %s', 'peak-publisher'), meta.plugin_info?.plugin_slug),
                    createElement('br'),
                    sprintf(__('The plugin file name will be: %s', 'peak-publisher'), meta.plugin_info?.plugin_basename.split('/').pop()),
                    createElement('br'),
                    sprintf(__('The plugin folder name will be: %s', 'peak-publisher'), meta.plugin_info?.plugin_basename.split('/')[0]),
                    createElement('br'),
                    sprintf(__('The plugin install path will be: %s', 'peak-publisher'), '/wp-content/plugins/' + meta.plugin_info?.plugin_basename.split('/')[0] + '/'),
                ), */
            ),
            meta.plugin_ok && createElement('div', { className: 'pblsh--upload-result__checks' },
                createElement('ul', { className: 'pblsh--checklist' },
                    resultList.map((r, i) => createElement('li', { key: 'r' + i, className: 'pblsh--check ' + (r.type === 'ok' ? 'pblsh--check--ok' : (r.type === 'info' ? 'pblsh--check--info' : 'pblsh--check--error')) },
                        createElement('span', { className: 'pblsh--check__icon' }, r.type === 'ok' ? getSvgIcon('check_bold', { size: 24 }) : (r.type === 'info' ? getSvgIcon('information_outline', { size: 24 }) : getSvgIcon('close_thick', { size: 24 }))),
                        createElement('span', { className: 'pblsh--check__text' },
                            createElement('span', { className: 'pblsh--check__title' }, r.title),
                            r.desc && createElement('span', { className: 'pblsh--check__desc' }, r.desc),
                        ),
                    )),
                ),
            ),
            !meta.plugin_ok && createElement('div', { className: 'pblsh--upload-result__checks' },
                createElement('ul', { className: 'pblsh--checklist' },
                    errors.map((r, i) => createElement('li', { key: 'r' + i, className: 'pblsh--check pblsh--check--error' },
                        createElement('span', { className: 'pblsh--check__icon' }, getSvgIcon('close_thick', { size: 24 })),
                        createElement('span', { className: 'pblsh--check__text' },
                            createElement('span', { className: 'pblsh--check__title' }, r.code),
                            createElement('span', { className: 'pblsh--check__desc' }, r.message),
                        ),
                    )),
                ),
            ),
            createElement('div', { className: 'pblsh--upload-result__actions' },
                createElement(Button, {
                    isSecondary: true,
                    onClick: async () => {
                        closeOverlay();
                        if (upload_id) {
                            try { await Pblsh.API.discardUpload(upload_id); } catch (e) {}
                        }
                    },
                    __next40pxDefaultSize: true,
                }, __('Discard', 'peak-publisher')),
                meta.plugin_ok && createElement(Button,
                    {
                        isPrimary: true,
                        disabled: !resultList.every(item => item.type === 'ok' || item.type === 'info'),
                        className: 'pblsh--button--add-plugin',
                        onClick: () => finalizeCreation(upload_id),
                        __next40pxDefaultSize: true,
                    },
                    !meta.existing_plugin && __('Add Plugin', 'peak-publisher'),
                    meta.existing_plugin && !existing_release && is_new_major_release && __('Add Major Release', 'peak-publisher'),
                    meta.existing_plugin && !existing_release && is_new_minor_release && __('Add Minor Release', 'peak-publisher'),
                    meta.existing_plugin && !existing_release && is_new_patch_release && __('Add Patch Release', 'peak-publisher'),
                    meta.existing_plugin && !existing_release && is_new_unknown_release && __('Add Release', 'peak-publisher'),
                    meta.existing_plugin && existing_release && __('Replace Existing Release', 'peak-publisher'),
                ),
            ),
        );
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
                }
            } else {
                if (dialogEl.open) {
                    dialogEl.close();
                }
            }
        } catch (e) {}
    }, [validationResult]);

    const dialog = createElement('dialog', { className: 'pblsh--modal pblsh--modal--upload-result', ref: dialogRef }, validationResult ? renderValidation(validationResult) : null);

    return createElement(wp.element.Fragment, null, overlay, dialog);
});


