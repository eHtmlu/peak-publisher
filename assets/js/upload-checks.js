// Upload checklist engine — a hook that owns the user's per-upload confirmation decisions
// and builds the checklist items for the upload result. The check context (assembled by
// getUploadCheckContext() in GlobalDropOverlay.js) is the single door for upload facts;
// the decision states live here because the checks are their only consumer.
lodash.set(window, 'Pblsh.Hooks.useUploadChecks', () => {
    const { __ } = wp.i18n;
    const sprintf = wp.i18n.sprintf ?? window.sprintf;
    const { useState, createElement, createInterpolateElement } = wp.element;
    const { CheckboxControl } = wp.components;
    const { formatBytes } = Pblsh.UploadResultUtils;

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

    function reset() {
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

    return { reset, buildUploadCheckItems };
});
