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
    const [keepOldBootstrapCode, setKeepOldBootstrapCode] = useState(false);
    const [confirmRollback, setConfirmRollback] = useState(false);
    const [confirmPreReleaseCurrent, setConfirmPreReleaseCurrent] = useState(false);
    const [confirmReplaceReach, setConfirmReplaceReach] = useState(false);

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
        setKeepOldBootstrapCode(false);
        setConfirmRollback(false);
        setConfirmPreReleaseCurrent(false);
        setConfirmReplaceReach(false);
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

        if (!pluginData.Version) {
            return {
                title: __('Missing version number', 'peak-publisher'),
                type: 'error',
                desc: __('You need to add a version number to your plugin file.', 'peak-publisher'),
            };
        }

        // Server fact: the version format is a hard boundary (release ZIP name, wporg SVN tag).
        if (!meta.version_ok) {
            return {
                title: __('Unsupported version number', 'peak-publisher'),
                type: 'error',
                desc: [
                    __('Use digits and dots, optionally followed by -alpha, -beta or -RC and a number, e.g. 1.2.0 or 1.2.0-beta1.', 'peak-publisher'),
                    ' ',
                    createInterpolateElement(__('For more information check out the <a>FAQ</a>.', 'peak-publisher'), {
                        a: createElement('a', { href: Pblsh.Utils.getFaqUrl('versionFormat'), target: '_blank', rel: 'noreferrer' }),
                    }),
                ],
            };
        }

        if (existingRelease) {
            return {
                title: __('Version number already exists', 'peak-publisher'),
                type: replaceRelease ? 'ok' : 'error',
                desc: [
                    // Replacing a release for its readme alone is common practice; the receipt is
                    // about the code. What the replaced build does not reach is the current-release
                    // row's receipt (checkCurrentRelease).
                    sprintf(__('A release with the version number %s already exists for this plugin. Changing only its readme is harmless, but different code under the same version number makes it impossible to tell which code a site runs.', 'peak-publisher'), pluginData.Version),
                    createElement('br'),
                    createElement(CheckboxControl, {
                        __nextHasNoMarginBottom: true,
                        label: __('That\'s fine, I want to replace the existing release. I understand that this is not recommended if the code changed.', 'peak-publisher'),
                        checked: replaceRelease,
                        onChange: (value) => setReplaceRelease(value),
                    }),
                ],
            };
        }

        if (!latestRelease) {
            return {
                title: __('Valid version number', 'peak-publisher'),
                type: 'ok',
                desc: pluginData.Version,
            };
        }

        if (isNaturalSuccessor && !nextRelease) {
            return {
                title: __('Expected version number', 'peak-publisher'),
                type: 'ok',
                desc: sprintf(__('Version %s, as expected after the latest release (%s).', 'peak-publisher'), pluginData.Version, latestRelease.version),
            };
        }

        return {
            title: __('Unexpected version number', 'peak-publisher'),
            type: (!nextRelease || useOlderPluginVersion) && (!previousRelease || isNaturalSuccessor || (previousRelease && !isNaturalSuccessor && useUnexpectedPluginVersion)) ? 'ok' : 'error',
            desc: [
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
            ],
        };
    }

    // The consequence of the current-release switch above the checklist, said for the sites. The title
    // names the outcome. The text puts the row's main fact first — the lock's reason, the
    // rollback, the replace, the pointer that could not be read — then the reach, then the
    // pre-release with its receipt; where the pre-release is the only fact, it comes first.
    // Every risky fact carries a receipt of its own, a checkbox the row stays red without: a
    // pre-release going out as a regular update, a rollback, a replace that never reaches the
    // sites already on the version. The facts — relation, pre-release flag, choice, the
    // current version — come from the server's decide_current_release(); the client never
    // compares versions.
    function checkCurrentRelease(context) {
        const { pluginData, currentRelease } = context;
        if (!currentRelease) return null;
        const { facts, makeCurrent } = currentRelease;
        const version = pluginData.Version;
        const pre = !!facts.pre_release;

        const preRelease = pre && sprintf(__('%s is a pre-release, a version for testers.', 'peak-publisher'), version);
        const preReleaseReceipt = pre && {
            label: sprintf(__('That\'s fine, I want sites to receive the pre-release %s as a regular update.', 'peak-publisher'), version),
            checked: confirmPreReleaseCurrent,
            onChange: setConfirmPreReleaseCurrent,
        };
        const offered = sprintf(__('Every site will be offered %s as an update.', 'peak-publisher'), version);
        const notOffered = sprintf(__('%s will not be offered to sites as an update.', 'peak-publisher'), version);

        if (facts.relation === 'unknown') {
            // wordpress.org only: the pointer could not be read just now. The deploy reads it
            // again and stops where the live relation would have changed the decision; the row
            // stays a warning even after the receipt.
            const unreadable = sprintf(__('The current release on wordpress.org could not be read right now, so whether %s is newer than it is unknown.', 'peak-publisher'), version);
            return checkRow(__('Current release unknown', 'peak-publisher'), makeCurrent
                ? [ [ unreadable, sprintf(__('If %s is newer, every site will be offered it as an update; otherwise publishing stops and you decide again, then with the facts.', 'peak-publisher'), version) ], [ preRelease, preReleaseReceipt ] ]
                : [ [ unreadable, sprintf(__('%1$s will not be offered to sites as an update. Should %1$s turn out to be the current release already or an older version, publishing stops and you decide again, then with the facts.', 'peak-publisher'), version) ], [ preRelease ] ],
            'warning');
        }
        if (!makeCurrent) {
            return checkRow(__('Does not become the current release', 'peak-publisher'), [ [ preRelease, notOffered ] ]);
        }
        if (facts.relation === 'equal') {
            return checkRow(__('Stays the current release', 'peak-publisher'), [
                [
                    sprintf(__('You replace the current release %1$s with this build. New installs and sites below %1$s will receive it, but sites already on %1$s keep their build: WordPress only offers newer version numbers.', 'peak-publisher'), version),
                    {
                        label: sprintf(__('That\'s fine, I understand that this build will not reach sites already on %s.', 'peak-publisher'), version),
                        checked: confirmReplaceReach,
                        onChange: setConfirmReplaceReach,
                    },
                ],
                [ preRelease, preReleaseReceipt ],
            ]);
        }
        if (facts.relation === 'lower') {
            return checkRow(__('Rolls back the current release', 'peak-publisher'), [
                [
                    sprintf(__('You roll the current release back from %1$s to %2$s. New installs and sites below %2$s will receive %2$s, but sites already on %1$s keep it: WordPress only offers newer version numbers.', 'peak-publisher'), facts.version, version),
                    {
                        label: sprintf(__('That\'s fine, I want to roll the current release back to %1$s. I understand that sites already on %2$s keep it.', 'peak-publisher'), version, facts.version),
                        checked: confirmRollback,
                        onChange: setConfirmRollback,
                    },
                ],
                [ preRelease, preReleaseReceipt ],
            ]);
        }
        // Locked (a forced default): the reason there is no choice comes first.
        const lockReason = facts.choice ? null : {
            first: __('On wordpress.org, the first release is always the current release.', 'peak-publisher'),
            repairs_pointer: sprintf(__('The current release already names %s; publishing it makes that valid.', 'peak-publisher'), version),
            no_current: __('There is no valid current release to keep.', 'peak-publisher'),
        }[facts.relation];
        return checkRow(__('Becomes the current release', 'peak-publisher'), lockReason
            ? [ [ lockReason, offered ], [ preRelease, preReleaseReceipt ] ]
            : [ [ preRelease, offered, preReleaseReceipt ] ]);
    }

    // A check row from its topics in reading order. A topic is one matter — the lock's
    // reason with the reach, the rollback, the pre-release — as sentences that flow into one
    // paragraph, with its receipts ({label, checked, onChange}) as checkboxes beneath; topics
    // stand apart from each other. The row is an error while a receipt is unticked,
    // settledType once every receipt is.
    function checkRow(title, topics, settledType = 'ok') {
        const present = topics.map((parts) => parts.filter(Boolean)).filter((parts) => parts.length > 0);
        const type = present.flat().some((part) => typeof part === 'object' && !part.checked) ? 'error' : settledType;
        const desc = present.map((parts, index) => createElement('span', { key: index, className: 'pblsh--check__topic' },
            ...parts.map((part, position) => typeof part === 'string'
                ? (position > 0 && typeof parts[position - 1] === 'string' ? ' ' : '') + part
                : createElement(CheckboxControl, { key: position, __nextHasNoMarginBottom: true, ...part })),
        ));
        return { title, type, desc };
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
                    desc: sprintf(__('Found in %s. Peak Publisher bootstrap code must be removed before publishing on wordpress.org.', 'peak-publisher'), bootstrapFile),
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
        const { meta, isWporg } = context;
        const readmeCleanup = meta?.cleanup_info?.readme_txt || {};
        const readmeTxtAlreadyUtf8 = !!readmeCleanup.already_utf8;
        const readmeTxtAlreadyWithoutBom = !!readmeCleanup.already_without_bom;
        const readmeTxtDetectedEncoding = readmeCleanup.detected_encoding || '';
        const readmeTxtConvertedToUtf8 = !!readmeCleanup.converted_to_utf8;
        const readmeTxtRemovedUtf8Bom = !!readmeCleanup.removed_utf8_bom;
        // The conversion could not make the file UTF-8 (no mbstring/iconv, an unknown
        // encoding): its information cannot be processed. A hard stop on both channels —
        // no readme data could be stored, and wordpress.org requires UTF-8.
        const readmeTxtUnprocessable = !readmeTxtAlreadyUtf8 && !readmeTxtConvertedToUtf8;
        // R1 (server): the release's readme names its own version as Stable tag. found = the
        // value before (null = the file could not be processed), written = the value set.
        const readmeStableTag = readmeCleanup.stable_tag || { found: null, written: null };

        if (!meta.plugin_readme_txt?.found) {
            return {
                title: __('No readme file found', 'peak-publisher'),
                type: isWporg ? 'error' : 'info',
                desc: isWporg
                    ? __('wordpress.org plugins require a readme.txt file.', 'peak-publisher')
                    : [
                        createInterpolateElement(__('A readme.txt is not required but would allow you to provide a description, changelog, and more to your users. Check out the <a>example on wordpress.org</a>.', 'peak-publisher'), {
                            a: createElement('a', { href: 'https://wordpress.org/plugins/readme.txt', target: '_blank', rel: 'noreferrer' }),
                        }),
                    ],
            };
        }

        // One fact per line.
        const lines = [
            meta.plugin_readme_txt?.file_name !== 'readme.txt' && sprintf(__('Although %s also works, the officially valid filename is readme.txt.', 'peak-publisher'), meta.plugin_readme_txt.file_name),
            readmeTxtAlreadyUtf8 && readmeTxtAlreadyWithoutBom && __('The file is a valid UTF-8 file without a BOM, exactly as it should be.', 'peak-publisher'),
            readmeTxtConvertedToUtf8 && (readmeTxtDetectedEncoding
                ? sprintf(__('The file was converted from %s to UTF-8.', 'peak-publisher'), readmeTxtDetectedEncoding)
                : __('The file was converted to UTF-8.', 'peak-publisher')),
            readmeTxtRemovedUtf8Bom && __('The UTF-8 BOM was removed from the file.', 'peak-publisher'),
            readmeStableTag.written !== null && (readmeStableTag.found === ''
                ? sprintf(__('Stable tag %s added so that the release names its own version.', 'peak-publisher'), readmeStableTag.written)
                : sprintf(__('Stable tag set to %1$s (was %2$s) so that the release names its own version.', 'peak-publisher'), readmeStableTag.written, readmeStableTag.found)),
            readmeTxtUnprocessable && __('The file could not be converted to UTF-8, so its information cannot be processed. Convert it to UTF-8 without a BOM and upload again.', 'peak-publisher'),
        ].filter(Boolean);
        return {
            title: __('Readme file exists', 'peak-publisher'),
            type: readmeTxtUnprocessable ? 'error' : 'ok',
            desc: lines.flatMap((line, index) => index === 0 ? [ line ] : [ createElement('br', { key: index }), line ]),
        };
    }

    // Warn-only probe failure — the target stays usable: upfront verification
    // is a convenience, wordpress.org decides at publish time (the same
    // tolerance the import station shows for a failed access check).
    function checkWporgAccessProbe(context) {
        if (!context.isWporg || context.target?.wporg_access_status !== 'error') {
            return null;
        }
        return {
            title: __('wordpress.org access not verifiable right now', 'peak-publisher'),
            type: 'warning',
            // Guidance first, the raw server message labeled behind it — the
            // same anatomy as the closed-state's "Reason:" detail.
            desc: [
                __('You can still try publishing — wordpress.org will decide at publish time.', 'peak-publisher'),
                context.target?.wporg_access_message
                    ? ' ' + sprintf(__('Error message: %s', 'peak-publisher'), context.target.wporg_access_message)
                    : '',
            ],
        };
    }

    // The pre-dialog refresh of the local wordpress.org mirror failed — the
    // release facts below may be stale. Warn-only: the publish itself is
    // guarded by the deploy's concurrent-change detection.
    function checkWporgRefreshFailure(context) {
        if (!context.isWporg || !context.target?.wporg_refresh_error) {
            return null;
        }
        return {
            title: __('Plugin status cannot be retrieved', 'peak-publisher'),
            type: 'warning',
            // Guidance first, the raw server message labeled behind it — the
            // same anatomy as the closed-state's "Reason:" detail.
            desc: [
                __('You can still try publishing — the publish itself detects concurrent changes.', 'peak-publisher'),
                ' ' + sprintf(__('Error message: %s', 'peak-publisher'), context.target.wporg_refresh_error),
            ],
        };
    }

    function checkWporgOwnershipHint(context) {
        const { isWporg, target } = context;
        // Heuristic directory/ownership hint — warns, never blocks: write access is
        // only decided by wordpress.org at deploy time. The state/relation cases
        // mirror WporgImportFacts and the import table's renderRowHint — extend
        // them together. The texts speak to "you"; when multi-account support
        // arrives, reconsider naming the checked account.
        const hint = isWporg && target?.wporg_directory_hint && typeof target.wporg_directory_hint === 'object'
            ? target.wporg_directory_hint
            : null;
        if (!hint) {
            return null;
        }

        // Every fresh state is the first-release moment and celebrates (like
        // the import screen's celebration box) — deliberately without access
        // caveats: whether publishing goes through is a separate concern (the
        // import screen's relation panels carry it, and a rejected publish
        // surfaces right on the next click). Only the owner speaks personally.
        if (hint.state === 'fresh' && hint.relation === 'owner') {
            return {
                title: __('Your first wordpress.org release 🎉', 'peak-publisher'),
                type: 'celebrate',
                desc: __('You are publishing your very first release — the public plugin page will go live once wordpress.org has processed the release. This can take up to several minutes.', 'peak-publisher'),
            };
        }
        if (hint.state === 'fresh') {
            return {
                title: __('First wordpress.org release 🎉', 'peak-publisher'),
                type: 'celebrate',
                desc: __('You are publishing the very first release — the public plugin page will go live once wordpress.org has processed the release. This can take up to several minutes.', 'peak-publisher'),
            };
        }
        if (hint.state === 'closed') {
            return {
                title: __('Closed in the plugin directory', 'peak-publisher'),
                type: 'warning',
                desc: [
                    __('wordpress.org closed this plugin — with commit access you can usually still commit updates to SVN, but wordpress.org will only distribute them once the plugin has been reopened.', 'peak-publisher'),
                    hint.reason ? ' ' + sprintf(__('Reason: %s.', 'peak-publisher'), hint.reason) : '',
                ],
            };
        }
        // Info here, warning on the import surfaces — deliberately different
        // tiers for the same state: at import the slug is freshly chosen (a
        // wrong slug is the dominant risk there), while the checklist is only
        // reachable through an already imported plugin, so the slug had its
        // deliberate confirmation and the access fact is all that remains.
        if (hint.relation === 'not_listed') {
            return {
                title: __('Your account doesn’t seem to be associated with this plugin', 'peak-publisher'),
                type: 'info',
                desc: __('No evidence was found that you manage this plugin. Publishing updates requires commit access, which wordpress.org will verify at publish time.', 'peak-publisher'),
            };
        }
        if (!hint.relation || hint.relation === 'unknown') {
            return {
                title: __('Account connection not verified', 'peak-publisher'),
                type: 'info',
                desc: __('Could not determine how this plugin relates to your account. Your credentials were accepted — whether you can publish will be decided by wordpress.org at publish time.', 'peak-publisher'),
            };
        }
        return null;
    }

    function buildUploadCheckItems(context) {
        // Facts of upload × destination only — process errors (failed server
        // phases) are NOT checklist rows; they render as pinned error notices
        // between body and footer (GlobalDropOverlay).
        return [
            context.meta.plugin_ok && [
                checkWporgAccessProbe(context),
                checkWporgRefreshFailure(context),
                checkWporgOwnershipHint(context),
                checkPluginFile(context),
                checkVersion(context),
                checkCurrentRelease(context),
                checkUpdateUri(context),
                checkBootstrapCode(context),
                checkWorkspaceArtifacts(context),
                checkReadmeTxt(context),
            ],
        ].flat(Infinity).filter(Boolean);
    }

    return { reset, buildUploadCheckItems };
});
