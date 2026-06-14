// PluginAdditionProcess Component
lodash.set(window, 'Pblsh.Components.PluginAdditionProcess', ({ onCreated, onOpenSettings } = {}) => {
    const { __, sprintf } = wp.i18n;
    const { useState, useEffect, useRef, createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, TextControl, Spinner } = wp.components;
    const { getSvgIcon, showAlert } = Pblsh.Utils;
    const hljs = window.hljs;
    const WPORG_IMPORT_CHUNK_SIZE = Math.max(1, parseInt(PblshData?.wporgImportChunkSize, 10) || 5);

    const [bootstrapCode, setBootstrapCode] = useState('');
    const [hostingType, setHostingType] = useState(null);
    const [selfHostedStep, setSelfHostedStep] = useState(1);
    const [wporgStep, setWporgStep] = useState(1);
    const [wporgAction, setWporgAction] = useState('import');
    const [discoverStatus, setDiscoverStatus] = useState('idle');
    const [discoverError, setDiscoverError] = useState('');
    const [importRows, setImportRows] = useState([]);
    const [importStatus, setImportStatus] = useState('idle');
    const [importProgress, setImportProgress] = useState({ total: 0, processed: 0 });
    const [importedPlugins, setImportedPlugins] = useState([]);
    const [skippedImports, setSkippedImports] = useState([]);
    const [importError, setImportError] = useState('');
    const [manualSlug, setManualSlug] = useState('');
    const [manualSlugError, setManualSlugError] = useState('');
    const lookupBatchRef = useRef(0);
    const settingsFetchRequestedRef = useRef(false);

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const settingsLoading = useSelect((select) => select('pblsh/settings').isLoading(), []);

    const selfHostedSteps = [
        { id: 1, label: __('Header', 'peak-publisher'), description: __('Add required plugin headers', 'peak-publisher') },
        { id: 2, label: __('Code', 'peak-publisher'), description: __('Add the required bootstrap code', 'peak-publisher') },
        { id: 3, label: __('Upload', 'peak-publisher'), description: __('Upload to Peak Publisher', 'peak-publisher') },
    ];

    const wporgSteps = [
        { id: 1, label: __('Account', 'peak-publisher'), description: __('Check wordpress.org access', 'peak-publisher') },
        { id: 2, label: __('Action', 'peak-publisher'), description: __('Choose the wordpress.org workflow', 'peak-publisher') },
        { id: 3, label: __('Import', 'peak-publisher'), description: __('Select existing plugins', 'peak-publisher') },
    ];

    const wporgAccounts = serverSettings && Array.isArray(serverSettings.wporg_accounts)
        ? serverSettings.wporg_accounts
        : [];
    const wporgAccount = wporgAccounts.find((account) => account && account.username && account.has_password) || null;
    const wporgUsername = wporgAccount ? String(wporgAccount.username || '') : '';
    const encryptionKeyStatus = serverSettings && serverSettings.wporg_credentials
        ? (serverSettings.wporg_credentials.encryption_key_status || 'missing')
        : 'missing';
    const wporgAccountReady = !!wporgUsername && encryptionKeyStatus === 'valid';
    const selectedRows = importRows.filter((row) => row && row.selected);
    const importInProgress = importStatus === 'importing';

    const loadBootstrapCode = async () => {
        const response = await Pblsh.API.getBootstrapCode();
        setBootstrapCode(response.code);
    };

    const highlightCode = () => {
        setTimeout(() => {
            if (hljs && typeof hljs.highlightAll === 'function') {
                document.querySelectorAll('pre code[data-highlighted="yes"]').forEach(code => {
                    delete code.dataset.highlighted;
                });
                hljs.highlightAll();
                if (selfHostedStep === 1) {
                    const markColor = 'rgba(255, 0, 0, 0.3)';
                    hljs.highlightLinesAll([
                        [],
                        [{ start: 2, end: 3, color: markColor }, { start: 10, end: 10, color: markColor }],
                    ]);
                }
            }
        }, 0);
    };

    const copyText = async (text) => {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (e) {}
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(textarea);
        return ok;
    };

    const rowIsSelectable = (row) => {
        return !!(
            row &&
            row.access_status === 'ok' &&
            row.has_write_access === true &&
            row.already_imported !== true
        );
    };

    const updateImportRow = (slug, updater) => {
        setImportRows((prev) => prev.map((row) => {
            if (!row || row.slug !== slug) {
                return row;
            }
            const next = typeof updater === 'function' ? updater(row) : { ...row, ...updater };
            return rowIsSelectable(next) ? next : { ...next, selected: false };
        }));
    };

    const setRowsFromDiscover = (plugins) => {
        const rows = (Array.isArray(plugins) ? plugins : []).map((plugin) => ({
            slug: String(plugin.slug || ''),
            name: String(plugin.name || plugin.slug || ''),
            already_imported: !!plugin.already_imported,
            imported: false,
            existing_plugin_id: plugin.existing_plugin_id || null,
            has_write_access: null,
            access_status: plugin.already_imported ? 'pending' : 'pending',
            message: null,
            source: 'discover',
            selected: false,
        })).filter((row) => row.slug);

        setImportRows((prev) => {
            const previousBySlug = new Map((Array.isArray(prev) ? prev : []).map((row) => [row.slug, row]));
            const discoveredSlugs = new Set(rows.map((row) => row.slug));
            const mergedDiscoverRows = rows.map((row) => {
                const previous = previousBySlug.get(row.slug);
                if (!previous) {
                    return row;
                }
                const next = {
                    ...previous,
                    ...row,
                    name: row.name || previous.name || row.slug,
                    selected: previous.selected,
                };
                return rowIsSelectable(next) ? next : { ...next, selected: false };
            });
            const manualOnlyRows = (Array.isArray(prev) ? prev : []).filter((row) => {
                return row && row.source === 'manual' && !discoveredSlugs.has(row.slug);
            });
            return mergedDiscoverRows.concat(manualOnlyRows);
        });
        return rows;
    };

    const lookupPluginAccess = async (username, slug, batchId) => {
        updateImportRow(slug, {
            access_status: 'pending',
            has_write_access: null,
            message: null,
        });

        try {
            const response = await window.Pblsh.API.lookupWporgPlugin(username, slug);
            if (batchId !== lookupBatchRef.current && batchId !== null) {
                return;
            }

            const plugin = response && response.plugin ? response.plugin : {};
            updateImportRow(slug, (row) => {
                const next = {
                    ...row,
                    name: plugin.name || row.name || plugin.slug || slug,
                    already_imported: !!plugin.already_imported,
                    existing_plugin_id: plugin.existing_plugin_id || row.existing_plugin_id || null,
                    has_write_access: plugin.has_write_access === true,
                    access_status: plugin.access_status || (plugin.has_write_access ? 'ok' : 'error'),
                    message: plugin.message || null,
                };
                return next;
            });
        } catch (error) {
            if (batchId !== lookupBatchRef.current && batchId !== null) {
                return;
            }
            updateImportRow(slug, {
                has_write_access: false,
                access_status: 'error',
                message: error && error.message ? error.message : __('Error checking access.', 'peak-publisher'),
            });
        }
    };

    const runLookupChecks = async (slugs, batchId) => {
        const queue = Array.isArray(slugs) ? slugs.slice() : [];
        let index = 0;
        const concurrency = Math.min(5, queue.length);
        const workers = Array.from({ length: concurrency }, async () => {
            while (index < queue.length) {
                const slug = queue[index];
                index += 1;
                await lookupPluginAccess(wporgUsername, slug, batchId);
            }
        });
        await Promise.all(workers);
    };

    const resetImportExecution = () => {
        setImportStatus('idle');
        setImportProgress({ total: 0, processed: 0 });
        setImportedPlugins([]);
        setSkippedImports([]);
        setImportError('');
    };

    const loadDiscoverPlugins = async () => {
        if (!wporgAccountReady || !wporgUsername) {
            return;
        }

        const batchId = lookupBatchRef.current + 1;
        lookupBatchRef.current = batchId;
        setDiscoverStatus('loading');
        setDiscoverError('');
        setImportRows([]);
        resetImportExecution();

        try {
            const response = await window.Pblsh.API.discoverWporgPlugins(wporgUsername);
            if (batchId !== lookupBatchRef.current) {
                return;
            }
            const rows = setRowsFromDiscover(response && Array.isArray(response.plugins) ? response.plugins : []);
            setDiscoverStatus('loaded');
            if (rows.length > 0) {
                runLookupChecks(rows.map((row) => row.slug), batchId);
            }
        } catch (error) {
            if (batchId !== lookupBatchRef.current) {
                return;
            }
            setDiscoverStatus('error');
            setDiscoverError(error && error.message ? error.message : __('wordpress.org API unavailable, try again later.', 'peak-publisher'));
        }
    };

    const refreshPluginList = async () => {
        if (window.Pblsh?.Controllers?.Plugins?.fetchList) {
            await window.Pblsh.Controllers.Plugins.fetchList();
        }
    };

    const applyImportResultsToRows = (imported, skipped) => {
        const importedBySlug = new Map((Array.isArray(imported) ? imported : []).map((plugin) => {
            return [String(plugin.slug || ''), plugin];
        }).filter(([slug]) => slug));
        const skippedBySlug = new Map((Array.isArray(skipped) ? skipped : []).map((entry) => {
            return [String(entry.slug || ''), entry];
        }).filter(([slug]) => slug));

        setImportRows((prev) => prev.map((row) => {
            if (!row || !row.slug) {
                return row;
            }

            const importedPlugin = importedBySlug.get(row.slug);
            if (importedPlugin) {
                return {
                    ...row,
                    name: importedPlugin.name || row.name || row.slug,
                    imported: true,
                    already_imported: true,
                    existing_plugin_id: importedPlugin.id || row.existing_plugin_id || null,
                    has_write_access: true,
                    access_status: 'ok',
                    message: null,
                    selected: false,
                };
            }

            const skippedImport = skippedBySlug.get(row.slug);
            if (!skippedImport) {
                return row;
            }

            const reason = skippedImport.reason || 'access_check_failed';
            const alreadyImported = reason === 'already_imported';
            const accessStatus = reason === 'no_write_access'
                ? 'no_write_access'
                : (reason === 'not_found' ? 'not_found' : (alreadyImported ? 'ok' : 'error'));

            return {
                ...row,
                imported: false,
                already_imported: alreadyImported || row.already_imported === true,
                existing_plugin_id: skippedImport.existing_plugin_id || row.existing_plugin_id || null,
                has_write_access: alreadyImported ? null : false,
                access_status: accessStatus,
                message: skippedImport.message || null,
                selected: false,
            };
        }));
    };

    const importSelectedPlugins = async () => {
        if (!wporgAccountReady || importInProgress) {
            return;
        }

        const slugs = selectedRows.map((row) => row.slug).filter(Boolean);
        if (slugs.length === 0) {
            return;
        }

        setImportStatus('importing');
        setImportProgress({ total: slugs.length, processed: 0 });
        setImportedPlugins([]);
        setSkippedImports([]);
        setImportError('');

        const allImported = [];
        const allSkipped = [];
        let processed = 0;

        try {
            for (let offset = 0; offset < slugs.length; offset += WPORG_IMPORT_CHUNK_SIZE) {
                const chunk = slugs.slice(offset, offset + WPORG_IMPORT_CHUNK_SIZE);
                const response = await window.Pblsh.API.importWporgPlugins(wporgUsername, chunk);
                const imported = response && Array.isArray(response.imported) ? response.imported : [];
                const skipped = response && Array.isArray(response.skipped) ? response.skipped : [];

                allImported.push(...imported);
                allSkipped.push(...skipped);
                applyImportResultsToRows(imported, skipped);

                processed += imported.length + skipped.length;
                setImportedPlugins(allImported.slice());
                setSkippedImports(allSkipped.slice());
                setImportProgress({
                    total: slugs.length,
                    processed: Math.min(slugs.length, processed),
                });
            }

            setImportStatus('done');
            setImportProgress({ total: slugs.length, processed: slugs.length });
            try {
                await refreshPluginList();
            } catch (error) {}
        } catch (error) {
            setImportStatus('error');
            setImportError(error && error.message ? error.message : __('wordpress.org import failed.', 'peak-publisher'));
            if (allImported.length > 0) {
                try {
                    await refreshPluginList();
                } catch (refreshError) {}
            }
        }
    };

    const validateManualSlug = (value) => {
        const slug = String(value || '').trim();
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
            return {
                slug,
                error: __('Invalid plugin slug.', 'peak-publisher'),
            };
        }
        return { slug, error: '' };
    };

    const addManualSlug = () => {
        if (!wporgAccountReady || importInProgress) {
            return;
        }

        const result = validateManualSlug(manualSlug);
        if (result.error) {
            setManualSlugError(result.error);
            return;
        }

        if (importRows.some((row) => row && row.slug === result.slug)) {
            setManualSlugError(__('This slug is already in the import list.', 'peak-publisher'));
            return;
        }

        setManualSlug('');
        setManualSlugError('');
        setImportRows((prev) => prev.concat([{
            slug: result.slug,
            name: result.slug,
            already_imported: false,
            imported: false,
            existing_plugin_id: null,
            has_write_access: null,
            access_status: 'pending',
            message: null,
            source: 'manual',
            selected: false,
        }]));
        lookupPluginAccess(wporgUsername, result.slug, null);
    };

    const toggleRowSelection = (slug, selected) => {
        setImportRows((prev) => prev.map((row) => {
            if (!row || row.slug !== slug || !rowIsSelectable(row)) {
                return row;
            }
            return { ...row, selected: !!selected };
        }));
    };

    const resetToHostingChoice = () => {
        setHostingType(null);
        setSelfHostedStep(1);
        setWporgStep(1);
        setWporgAction('import');
    };

    useEffect(() => {
        loadBootstrapCode();
    }, []);

    useEffect(() => {
        if (hostingType === 'self_hosted' && selfHostedStep < 3) {
            highlightCode();
        }
    }, [hostingType, selfHostedStep, bootstrapCode]);

    useEffect(() => {
        if (hostingType === 'wporg' && !serverSettings && !settingsLoading && !settingsFetchRequestedRef.current) {
            settingsFetchRequestedRef.current = true;
            window.Pblsh.Controllers.Settings.fetch();
        }
    }, [hostingType, serverSettings, settingsLoading]);

    useEffect(() => {
        lookupBatchRef.current += 1;
        setDiscoverStatus('idle');
        setDiscoverError('');
        setImportRows([]);
        resetImportExecution();
        setManualSlug('');
        setManualSlugError('');
    }, [wporgUsername]);

    useEffect(() => {
        if (
            hostingType === 'wporg' &&
            wporgStep === 3 &&
            wporgAction === 'import' &&
            wporgAccountReady &&
            discoverStatus === 'idle'
        ) {
            loadDiscoverPlugins();
        }
    }, [hostingType, wporgStep, wporgAction, wporgAccountReady, discoverStatus]);

    const renderStepper = (steps, currentStep, onStepClick) => {
        return createElement('div', {
            className: 'pblsh--stepper',
            role: 'navigation',
            'aria-label': __('Setup steps', 'peak-publisher'),
            style: { '--steps': steps.length, '--step': currentStep },
        },
            createElement('div', { className: 'pblsh--stepper__bar', 'aria-hidden': 'true' },
                createElement('div', { className: 'pblsh--stepper__bar-fill' }),
            ),
            createElement('ol', { className: 'pblsh--stepper__list' },
                steps.map((stepItem, index) => {
                    const statusClass = stepItem.id < currentStep ? 'is-complete' : (stepItem.id === currentStep ? 'is-active' : 'is-upcoming');
                    const isActive = stepItem.id === currentStep;
                    return createElement('li', { key: stepItem.id, className: 'pblsh--stepper__item ' + statusClass },
                        createElement('button', {
                            type: 'button',
                            className: 'pblsh--stepper__link',
                            'aria-current': isActive ? 'step' : undefined,
                            onClick: () => onStepClick(stepItem.id),
                        },
                            createElement('span', { className: 'pblsh--stepper__index', 'aria-hidden': 'true' }, String(index + 1)),
                            createElement('span', { className: 'pblsh--stepper__text' },
                                createElement('span', { className: 'pblsh--stepper__label' }, stepItem.label),
                                createElement('span', { className: 'pblsh--stepper__desc' }, stepItem.description),
                            ),
                        ),
                    );
                }),
            ),
        );
    };

    const renderHeaderTips = () => {
        const example = [
            '/**',
            ' * Plugin Name: Example Plugin',
            ' * Version: 1.0.0',
            ' * Description: Short description of what it does',
            ' * Author: Your Name',
            ' * Author URI: https://example.com/',
            ' * Requires at least: ' + PblshData.wpVersion.split('.').slice(0, 2).join('.'),
            ' * Tested up to: ' + PblshData.wpVersion.split('.').slice(0, 2).join('.'),
            ' * Requires PHP: ' + PblshData.phpVersion.split('.').slice(0, 2).join('.'),
            ' * Update URI: ' + PblshData.bootstrapUpdateURI,
            ' */',
        ].join('\n');

        return [
            createElement('h3', { key: 'headers-title', className: 'pblsh--card__title' }, __('Required and recommended headers', 'peak-publisher')),
            createElement('p',
                { key: 'headers-copy' },
                __('The "Plugin Name," "Version," and "Update URI" headers are required. However, all other headers below are also highly recommended. Adjust the values according to your individual needs.', 'peak-publisher'),
                ' ',
                __('A full list of headers can be found in the WordPress Documentation: ', 'peak-publisher'),
                createElement('a', { href: 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/', target: '_blank' }, 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/')
            ),
            createElement('div', { key: 'headers-snippet', className: 'pblsh--snippet-wrapper' },
                createElement('div', { className: 'pblsh--snippet-toolbar' },
                    createElement('button', {
                        type: 'button',
                        className: 'button pblsh--copy-btn',
                        onClick: () => copyText(example),
                        'aria-label': __('Copy example header', 'peak-publisher'),
                    }, __('Copy', 'peak-publisher')),
                ),
                createElement('pre', null,
                    createElement('code', { className: 'language-plaintext' }, example),
                ),
            ),
            createElement('h3', { key: 'version-title' }, __('3 facts about version numbers', 'peak-publisher')),
            createElement('ul', { key: 'version-list', className: 'pblsh--ul' },
                createElement('li', null, __('A plugin is never finished, so dare to name the first version of your plugin what it is: 1.0.0', 'peak-publisher')),
                createElement('li', null, __('A good and very common version number convention is the format "X.X.X" (major.minor.patch). Read more about it here: ', 'peak-publisher'), createElement('a', { href: 'https://semver.org/', target: '_blank' }, 'https://semver.org/')),
                createElement('li', null, __('Each integer part of the version number increments independently and can have more than one digit. So feel free to increment 1.9.0 to 1.10.0.', 'peak-publisher')),
            ),
        ];
    };

    const renderHostingChoice = () => {
        return createElement('div', { className: 'pblsh--step pblsh--hosting-choice' },
            createElement('div', { className: 'pblsh--choice-grid' },
                createElement('button', {
                    type: 'button',
                    className: 'pblsh--choice-card',
                    onClick: () => setHostingType('self_hosted'),
                },
                    createElement('span', { className: 'pblsh--choice-card__icon' }, getSvgIcon('store', { size: 32 })),
                    createElement('span', { className: 'pblsh--choice-card__title' }, __('Self-hosted', 'peak-publisher')),
                    createElement('span', { className: 'pblsh--choice-card__text' }, __('Publish updates from this WordPress site.', 'peak-publisher')),
                ),
                createElement('button', {
                    type: 'button',
                    className: 'pblsh--choice-card',
                    onClick: () => setHostingType('wporg'),
                },
                    createElement('span', { className: 'pblsh--choice-card__icon' }, getSvgIcon('wordpress', { size: 32 })),
                    createElement('span', { className: 'pblsh--choice-card__title' }, __('wordpress.org', 'peak-publisher')),
                    createElement('span', { className: 'pblsh--choice-card__text' }, __('Import plugins from the wordpress.org SVN repository.', 'peak-publisher')),
                ),
            ),
        );
    };

    const renderSelfHostedStepContent = () => {
        if (selfHostedStep === 1) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card' },
                    createElement('h3', { className: 'pblsh--card__title' }, __('Add this line to your plugin header', 'peak-publisher')),
                    createElement('p', null, __('This is the API endpoint that your plugin will use to check for updates.', 'peak-publisher')),
                    PblshData.bootstrapUpdateURI.match(/^http:\/\//) && createElement('div', { className: 'pblsh--addition-process--protocol-warning' }, createElement('strong', null, __('WARNING: Your WordPress site is using an insecure connection. We strongly recommend switching to HTTPS before copying and using this update URI.', 'peak-publisher'))),
                    createElement('div', { className: 'pblsh--snippet-wrapper' },
                        createElement('div', { className: 'pblsh--snippet-toolbar' },
                            createElement('button', {
                                type: 'button',
                                className: 'button pblsh--copy-btn',
                                onClick: () => copyText('Update URI: ' + PblshData.bootstrapUpdateURI),
                                'aria-label': __('Copy Update URI', 'peak-publisher'),
                            }, __('Copy', 'peak-publisher')),
                        ),
                        createElement('pre', null,
                            createElement('code', { className: 'language-plaintext' }, 'Update URI: ' + PblshData.bootstrapUpdateURI),
                        ),
                    ),
                    renderHeaderTips(),
                ),
            );
        }

        if (selfHostedStep === 2) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card pblsh--card--bootstrap-code' },
                    createElement('div', null,
                        createElement('h3', { className: 'pblsh--card__title' }, __('Add this code to your plugin', 'peak-publisher')),
                        createElement('p', null, __('Add this code to your plugin\'s main file or to any other PHP file within your plugin. Just make sure it executes immediately when the plugin loads, so keep it outside of any additional action or filter hooks. Keep the code as it is, as it is optimized for several requirements.', 'peak-publisher')),
                    ),
                    createElement('div', { className: 'pblsh--snippet-wrapper' },
                        createElement('div', { className: 'pblsh--snippet-toolbar' },
                            createElement('button', {
                                type: 'button',
                                className: 'button pblsh--copy-btn',
                                onClick: () => copyText(bootstrapCode),
                                'aria-label': __('Copy bootstrap code', 'peak-publisher'),
                            }, __('Copy', 'peak-publisher')),
                        ),
                        createElement('pre', null,
                            createElement('code', { className: 'language-php' }, bootstrapCode),
                        ),
                    ),
                ),
            );
        }

        return createElement('div', { className: 'pblsh--step' },
            createElement('div', { className: 'pblsh--card pblsh--card--upload-zip' },
                createElement('div', null,
                    createElement('h3', { className: 'pblsh--card__title' }, __('Upload your plugin', 'peak-publisher')),
                ),
                createElement('div', {
                    className: 'pblsh--dropzone',
                    onClick: () => {
                        window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker'));
                    },
                    role: 'button',
                    tabIndex: 0,
                    onKeyDown: (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            window.dispatchEvent(new CustomEvent('pblsh:open-overlay-file-picker'));
                        }
                    },
                    'aria-label': __('Drop plugin or click to select', 'peak-publisher'),
                },
                    createElement('div', { className: 'pblsh--dropzone__inner' },
                        createElement('div', { className: 'pblsh--dropzone__icon' }, getSvgIcon('cloud_upload', { size: 32 })),
                        createElement('div', { className: 'pblsh--dropzone__text' }, __('Drop plugin or click to select', 'peak-publisher')),
                        createElement('div', { className: 'pblsh--dropzone__desc' },
                            createElement('p', null, __('You can drop:', 'peak-publisher')),
                            createElement('ul', { className: 'pblsh--ul' },
                                createElement('li', null, __('a zip file', 'peak-publisher')),
                                createElement('li', null, __('a plugin folder', 'peak-publisher')),
                                createElement('li', null, __('files of a plugin folder', 'peak-publisher')),
                            ),
                        ),
                    ),
                ),
            ),
        );
    };

    const renderWporgAccountStep = () => {
        if (settingsLoading && !serverSettings) {
            return createElement('div', { className: 'pblsh--step' },
                createElement('div', { className: 'pblsh--card pblsh--wporg-account-step' },
                    createElement(Spinner),
                ),
            );
        }

        return createElement('div', { className: 'pblsh--step' },
            createElement('div', { className: 'pblsh--card pblsh--wporg-account-step' },
                createElement('h3', { className: 'pblsh--card__title' }, __('wordpress.org account', 'peak-publisher')),
                createElement('p', null, __('Your plugin must be approved on wordpress.org before you can import or deploy it through Peak Publisher.', 'peak-publisher')),
                wporgAccountReady
                    ? createElement('div', { className: 'pblsh--wporg-account-step__ready' },
                        createElement('span', { className: 'pblsh--wporg-account-step__icon' }, getSvgIcon('check_circle', { size: 22 })),
                        createElement('span', null, sprintf(__('Connected as %s', 'peak-publisher'), wporgUsername)),
                    )
                    : createElement('div', { className: 'pblsh--wporg-account-step__missing' },
                        createElement('p', null, __('Add a wordpress.org account in Settings before importing plugins.', 'peak-publisher')),
                        createElement(Button, {
                            isSecondary: true,
                            onClick: () => {
                                if (typeof onOpenSettings === 'function') {
                                    onOpenSettings();
                                }
                            },
                        }, __('Open Settings', 'peak-publisher')),
                    ),
            ),
        );
    };

    const renderWporgActionStep = () => {
        return createElement('div', { className: 'pblsh--step pblsh--wporg-action-step' },
            createElement('div', { className: 'pblsh--choice-grid' },
                createElement('button', {
                    type: 'button',
                    className: 'pblsh--choice-card is-selected',
                    onClick: () => {
                        setWporgAction('import');
                        setWporgStep(3);
                    },
                },
                    createElement('span', { className: 'pblsh--choice-card__icon' }, getSvgIcon('download', { size: 32 })),
                    createElement('span', { className: 'pblsh--choice-card__title' }, __('Import existing plugin', 'peak-publisher')),
                    createElement('span', { className: 'pblsh--choice-card__text' }, __('Add existing wordpress.org plugins to this dashboard.', 'peak-publisher')),
                ),
                createElement('button', {
                    type: 'button',
                    className: 'pblsh--choice-card is-disabled',
                    disabled: true,
                },
                    createElement('span', { className: 'pblsh--choice-card__icon' }, getSvgIcon('cloud_upload', { size: 32 })),
                    createElement('span', { className: 'pblsh--choice-card__title' }, __('Deploy ZIP release', 'peak-publisher')),
                    createElement('span', { className: 'pblsh--choice-card__text' }, __('Upload a release ZIP to wordpress.org SVN.', 'peak-publisher')),
                ),
            ),
        );
    };

    const getRowStatusText = (row) => {
        if (row.imported) {
            return __('Imported', 'peak-publisher');
        }
        if (row.already_imported) {
            return __('Already imported', 'peak-publisher');
        }
        if (row.access_status === 'pending') {
            return __('Checking access...', 'peak-publisher');
        }
        if (row.access_status === 'ok' && row.has_write_access) {
            return __('Ready', 'peak-publisher');
        }
        if (row.access_status === 'no_write_access') {
            return __('No write access', 'peak-publisher');
        }
        if (row.access_status === 'not_found') {
            return __('Not found', 'peak-publisher');
        }
        return __('Error checking access', 'peak-publisher');
    };

    const getRowStatusClass = (row) => {
        if (row.imported || row.already_imported) {
            return 'is-imported';
        }
        if (row.access_status === 'pending') {
            return 'is-pending';
        }
        if (row.access_status === 'ok' && row.has_write_access) {
            return 'is-ready';
        }
        return 'is-blocked';
    };

    const renderImportExecutionState = () => {
        if (importStatus === 'idle') {
            return null;
        }

        const total = Math.max(0, importProgress.total || 0);
        const processed = Math.max(0, Math.min(total, importProgress.processed || 0));
        const percentage = total > 0 ? Math.round((processed / total) * 100) : 0;
        const importedCount = importedPlugins.length;
        const skippedCount = skippedImports.length;
        const statusText = importStatus === 'importing'
            ? sprintf(__('Importing %1$d of %2$d plugins...', 'peak-publisher'), processed, total)
            : (importStatus === 'done'
                ? sprintf(__('Import finished: %1$d imported, %2$d skipped.', 'peak-publisher'), importedCount, skippedCount)
                : (importError || __('wordpress.org import failed.', 'peak-publisher')));

        return createElement('div', { className: 'pblsh--wporg-import__execution is-' + importStatus },
            createElement('div', { className: 'pblsh--wporg-import__execution-head' },
                importStatus === 'importing' ? createElement(Spinner) : null,
                createElement('strong', null, statusText),
            ),
            total > 0 ? createElement('div', { className: 'pblsh--progress pblsh--wporg-import__progress' },
                createElement('div', { className: 'pblsh--progress__bar', style: { '--percentage': percentage + '%' } }),
                createElement('div', { className: 'pblsh--progress__label' }, percentage + '%'),
            ) : null,
            skippedCount > 0 ? createElement('ul', { className: 'pblsh--wporg-import__skipped-list' },
                skippedImports.map((entry) => createElement('li', { key: String(entry.slug || '') + ':' + String(entry.reason || '') },
                    createElement('code', null, entry.slug || ''),
                    ' ',
                    entry.message || entry.reason || __('Skipped', 'peak-publisher'),
                )),
            ) : null,
        );
    };

    const renderImportRows = () => {
        if (discoverStatus === 'loading' && importRows.length === 0) {
            return createElement('div', { className: 'pblsh--wporg-import__loading' },
                createElement(Spinner),
                createElement('span', null, __('Loading wordpress.org plugins...', 'peak-publisher')),
            );
        }

        if (discoverStatus === 'error') {
            return createElement('div', { className: 'pblsh--wporg-import__error' },
                createElement('p', null, discoverError || __('wordpress.org API unavailable, try again later.', 'peak-publisher')),
                createElement(Button, {
                    isSecondary: true,
                    onClick: loadDiscoverPlugins,
                    disabled: !wporgAccountReady,
                }, __('Retry', 'peak-publisher')),
            );
        }

        if (discoverStatus === 'loaded' && importRows.length === 0) {
            return createElement('div', { className: 'pblsh--wporg-import__empty' },
                createElement('p', null, __('No wordpress.org plugins found for this account.', 'peak-publisher')),
                createElement('p', null, __('If your plugin was just approved or does not appear yet, add it by slug.', 'peak-publisher')),
            );
        }

        if (importRows.length === 0) {
            return null;
        }

        return createElement('div', { className: 'pblsh--wporg-import__results' },
            discoverStatus === 'loading' ? createElement('div', { className: 'pblsh--wporg-import__loading pblsh--wporg-import__loading--compact' },
                createElement(Spinner),
                createElement('span', null, __('Loading wordpress.org plugins...', 'peak-publisher')),
            ) : null,
            createElement('div', { className: 'pblsh--wporg-import__table-wrap' },
                createElement('table', { className: 'pblsh--wporg-import__table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--wporg-import__select-header' }),
                            createElement('th', null, __('Plugin', 'peak-publisher')),
                            createElement('th', null, __('Slug', 'peak-publisher')),
                            createElement('th', null, __('Status', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--wporg-import__action-header' }),
                        ),
                    ),
                    createElement('tbody', null,
                        importRows.map((row) => {
                            const selectable = rowIsSelectable(row);
                            return createElement('tr', { key: row.slug, className: 'pblsh--wporg-import__row ' + getRowStatusClass(row) },
                                createElement('td', { className: 'pblsh--wporg-import__select-cell' },
                                    createElement('input', {
                                        type: 'checkbox',
                                        checked: !!row.selected,
                                        disabled: importInProgress || !selectable,
                                        'aria-label': sprintf(__('Select %s', 'peak-publisher'), row.name || row.slug),
                                        onChange: (event) => toggleRowSelection(row.slug, event.target.checked),
                                    }),
                                ),
                                createElement('td', null,
                                    createElement('strong', null, row.name || row.slug),
                                ),
                                createElement('td', null,
                                    createElement('code', null, row.slug),
                                ),
                                createElement('td', null,
                                    createElement('span', { className: 'pblsh--wporg-import__status' }, getRowStatusText(row)),
                                    row.message ? createElement('span', { className: 'pblsh--wporg-import__message' }, row.message) : null,
                                ),
                                createElement('td', { className: 'pblsh--wporg-import__action-cell' },
                                    row.already_imported && row.existing_plugin_id
                                        ? createElement(Button, {
                                            isTertiary: true,
                                            onClick: () => {
                                                if (typeof onCreated === 'function') {
                                                    onCreated(row.existing_plugin_id);
                                                }
                                            },
                                        }, __('Open plugin', 'peak-publisher'))
                                        : null,
                                ),
                            );
                        }),
                    ),
                ),
            ),
        );
    };

    const renderManualSlugLookup = () => {
        return createElement('div', { className: 'pblsh--wporg-import__manual' },
            createElement('h4', { className: 'pblsh--wporg-import__manual-title' }, __('Import by slug', 'peak-publisher')),
            createElement('div', { className: 'pblsh--wporg-import__manual-row' },
                createElement(TextControl, {
                    label: __('Plugin slug', 'peak-publisher'),
                    value: manualSlug,
                    disabled: importInProgress,
                    onChange: (value) => {
                        setManualSlug(value);
                        setManualSlugError('');
                    },
                    onKeyDown: (event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            addManualSlug();
                        }
                    },
                    __nextHasNoMarginBottom: true,
                }),
                createElement(Button, {
                    isSecondary: true,
                    onClick: addManualSlug,
                    disabled: importInProgress || !wporgAccountReady || manualSlug.trim() === '',
                }, __('Add to list', 'peak-publisher')),
            ),
            manualSlugError ? createElement('p', { className: 'pblsh--wporg-import__manual-error' }, manualSlugError) : null,
        );
    };

    const renderWporgImportStep = () => {
        return createElement('div', { className: 'pblsh--step pblsh--wporg-import-step' },
            createElement('div', { className: 'pblsh--card pblsh--wporg-import' },
                createElement('div', { className: 'pblsh--wporg-import__header' },
                    createElement('div', null,
                        createElement('h3', { className: 'pblsh--card__title' }, __('Import existing wordpress.org plugins', 'peak-publisher')),
                        createElement('p', null, sprintf(__('Account: %s', 'peak-publisher'), wporgUsername)),
                    ),
                    createElement(Button, {
                        isSecondary: true,
                        onClick: loadDiscoverPlugins,
                        disabled: importInProgress || discoverStatus === 'loading' || !wporgAccountReady,
                    }, __('Refresh', 'peak-publisher')),
                ),
                renderImportExecutionState(),
                renderImportRows(),
                renderManualSlugLookup(),
                createElement('div', { className: 'pblsh--wporg-import__footer' },
                    createElement('span', null, sprintf(__('%d selected', 'peak-publisher'), selectedRows.length)),
                    createElement(Button, {
                        isPrimary: true,
                        onClick: importSelectedPlugins,
                        disabled: importInProgress || !wporgAccountReady || selectedRows.length === 0,
                    }, importInProgress
                        ? __('Importing...', 'peak-publisher')
                        : (selectedRows.length > 0 ? sprintf(__('Import selected (%d)', 'peak-publisher'), selectedRows.length) : __('Import selected', 'peak-publisher'))),
                ),
            ),
        );
    };

    const renderWporgStepContent = () => {
        if (wporgStep === 1) {
            return renderWporgAccountStep();
        }
        if (wporgStep === 2) {
            return renderWporgActionStep();
        }
        return renderWporgImportStep();
    };

    const renderContent = () => {
        if (hostingType === null) {
            return renderHostingChoice();
        }
        if (hostingType === 'self_hosted') {
            return renderSelfHostedStepContent();
        }
        return renderWporgStepContent();
    };

    const renderControls = () => {
        if (hostingType === null) {
            return null;
        }

        const isSelfHosted = hostingType === 'self_hosted';
        const currentStep = isSelfHosted ? selfHostedStep : wporgStep;
        const maxStep = isSelfHosted ? selfHostedSteps.length : wporgSteps.length;
        const canGoNext = isSelfHosted
            ? currentStep < maxStep
            : (currentStep === 1 ? wporgAccountReady : currentStep < maxStep);

        const previous = () => {
            if (currentStep === 1) {
                resetToHostingChoice();
                return;
            }
            if (isSelfHosted) {
                setSelfHostedStep(Math.max(1, currentStep - 1));
            } else {
                setWporgStep(Math.max(1, currentStep - 1));
            }
        };

        const next = () => {
            if (!canGoNext) {
                if (!isSelfHosted && currentStep === 1) {
                    showAlert(__('Add a valid wordpress.org account in Settings first.', 'peak-publisher'), 'warning');
                }
                return;
            }
            if (isSelfHosted) {
                setSelfHostedStep(Math.min(maxStep, currentStep + 1));
            } else {
                setWporgStep(Math.min(maxStep, currentStep + 1));
            }
        };

        return createElement('div', { className: 'pblsh--controls' },
            createElement('div', null,
                createElement(Button, {
                    isSecondary: true,
                    onClick: previous,
                }, currentStep === 1 ? __('Back', 'peak-publisher') : __('Previous', 'peak-publisher')),
            ),
            createElement('div', null,
                currentStep < maxStep && createElement(Button, {
                    isPrimary: true,
                    onClick: next,
                    disabled: !canGoNext,
                }, __('Next', 'peak-publisher')),
            ),
        );
    };

    return createElement('div', { className: 'pblsh--addition-process' },
        hostingType === 'self_hosted' ? renderStepper(selfHostedSteps, selfHostedStep, setSelfHostedStep) : null,
        hostingType === 'wporg' ? renderStepper(wporgSteps, wporgStep, (nextStep) => {
            if (nextStep > 1 && !wporgAccountReady) {
                return;
            }
            setWporgStep(nextStep);
        }) : null,
        renderContent(),
        renderControls(),
    );
});
