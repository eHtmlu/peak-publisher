// WporgImportTable Component - bulk import of a wordpress.org account's plugins.
// Home: the add-new flow's wporg "Add" step (other surfaces link there instead of
// embedding a second copy). Discovers the account's plugins, checks repository
// access and the contributor hint, and imports the selection one plugin per
// request — each request is an honest progress step (release-weighted) and a
// clean stopping point. Requires a usable stored account — hosts render the
// shared WporgAccountForm instead while none exists.
const WporgImportTable = ({ onOpenPlugin = null, onImported = null } = {}) => {
    const { __, _n, sprintf } = wp.i18n;
    const { useState, useEffect, useRef, createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, TextControl, Spinner } = wp.components;
    const { getGeopatternIconUrl, getSvgIcon } = Pblsh.Utils;
    const { ChannelPath } = Pblsh.Components;

    const [discoverStatus, setDiscoverStatus] = useState('idle');
    const [discoverError, setDiscoverError] = useState('');
    const [importRows, setImportRows] = useState([]);
    const [importStatus, setImportStatus] = useState('idle');
    const [importProgress, setImportProgress] = useState({ totalPlugins: 0, processedPlugins: 0 });
    const [importingSlug, setImportingSlug] = useState('');
    const [stopRequested, setStopRequested] = useState(false);
    const [importedPlugins, setImportedPlugins] = useState([]);
    const [skippedImports, setSkippedImports] = useState([]);
    const [importError, setImportError] = useState('');
    const [manualSlug, setManualSlug] = useState('');
    const [manualSlugError, setManualSlugError] = useState('');
    const lookupBatchRef = useRef(0);
    // The loop reads the ref (state would be stale inside the async run);
    // the state twin exists only to re-render the stop button.
    const stopRequestedRef = useRef(false);

    const wporgAccount = useSelect((select) => select('pblsh/settings').getUsableWporgAccount(), []);
    const wporgUsername = wporgAccount ? String(wporgAccount.username || '') : '';
    const accountReady = !!wporgUsername;
    const selectedRows = importRows.filter((row) => row && row.selected);
    const importInProgress = importStatus === 'importing';

    // Selectable = importable. Importing only mirrors public SVN data, so ownership
    // is deliberately NOT required — the contributor hint warns instead.
    const rowIsSelectable = (row) => {
        return !!(
            row &&
            row.access_status === 'ok' &&
            row.already_imported !== true
        );
    };

    const selectableRows = importRows.filter((row) => rowIsSelectable(row));
    const selectableCount = selectableRows.length;
    const allSelectableSelected = selectableCount > 0 && selectableRows.every((row) => row.selected);
    const pendingCheckExists = importRows.some((row) => row && row.access_status === 'pending');

    // The release count — for imported plugins the local mirror is the
    // authority (count_of_releases, delivered by discover/lookup/import
    // responses); for the rest the directory hint answers. Null when unknown.
    const releaseCountOf = (row) => {
        if (!row) {
            return null;
        }
        const count = Number.isFinite(row.count_of_releases)
            ? row.count_of_releases
            : (row.directory_hint ? row.directory_hint.release_count : null);
        return Number.isFinite(count) && count > 0 ? count : null;
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
            icon: typeof plugin.icon === 'string' ? plugin.icon : '',
            already_imported: !!plugin.already_imported,
            imported: false,
            existing_plugin_id: plugin.existing_plugin_id || null,
            count_of_releases: Number.isFinite(plugin.count_of_releases) ? plugin.count_of_releases : null,
            directory_hint: null,
            // Imported rows get no lookup — there is nothing left to decide
            // for them, and discover already delivered their identity.
            access_status: plugin.already_imported ? 'not_checked' : 'pending',
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
                    icon: row.icon || previous.icon || '',
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
            directory_hint: null,
            message: null,
        });

        try {
            const response = await window.Pblsh.API.lookupWporgPlugin(username, slug);
            if (batchId !== lookupBatchRef.current && batchId !== null) {
                return;
            }

            const plugin = response && response.plugin ? response.plugin : {};
            const hint = plugin.directory_hint && typeof plugin.directory_hint === 'object' ? plugin.directory_hint : null;
            updateImportRow(slug, (row) => {
                const next = {
                    ...row,
                    // The lookup carries the directory identity in its hint —
                    // for manually added slugs, the placeholder name (the slug)
                    // gets replaced by the real one here.
                    name: plugin.name || (hint && hint.name) || row.name || plugin.slug || slug,
                    already_imported: !!plugin.already_imported,
                    existing_plugin_id: plugin.existing_plugin_id || row.existing_plugin_id || null,
                    count_of_releases: Number.isFinite(plugin.count_of_releases)
                        ? plugin.count_of_releases
                        : (Number.isFinite(row.count_of_releases) ? row.count_of_releases : null),
                    directory_hint: plugin.directory_hint && typeof plugin.directory_hint === 'object' ? plugin.directory_hint : null,
                    access_status: plugin.access_status || 'error',
                    message: plugin.message || null,
                };
                return next;
            });
        } catch (error) {
            if (batchId !== lookupBatchRef.current && batchId !== null) {
                return;
            }
            updateImportRow(slug, {
                directory_hint: null,
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
        setImportProgress({ totalPlugins: 0, processedPlugins: 0 });
        setImportingSlug('');
        setStopRequested(false);
        stopRequestedRef.current = false;
        setImportedPlugins([]);
        setSkippedImports([]);
        setImportError('');
    };

    const loadDiscoverPlugins = async () => {
        if (!accountReady) {
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
            // Imported rows skip the lookup — no wordpress.org roundtrip for
            // rows whose state the check could not change.
            const slugsToCheck = rows.filter((row) => !row.already_imported).map((row) => row.slug);
            if (slugsToCheck.length > 0) {
                runLookupChecks(slugsToCheck, batchId);
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
                // Deliberately not adopting the local post title: this screen
                // is the directory view, so the row keeps the directory's
                // title like every other row (a refresh would restore it
                // anyway). The header name lives in the plugin list.
                return {
                    ...row,
                    imported: true,
                    already_imported: true,
                    existing_plugin_id: importedPlugin.id || row.existing_plugin_id || null,
                    count_of_releases: Number.isFinite(importedPlugin.count_of_releases)
                        ? importedPlugin.count_of_releases
                        : (Number.isFinite(row.count_of_releases) ? row.count_of_releases : null),
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
            const accessStatus = reason === 'not_found' ? 'not_found' : (alreadyImported ? 'ok' : 'error');

            return {
                ...row,
                imported: false,
                already_imported: alreadyImported || row.already_imported === true,
                existing_plugin_id: skippedImport.existing_plugin_id || row.existing_plugin_id || null,
                access_status: accessStatus,
                message: skippedImport.message || null,
                selected: false,
            };
        }));
    };

    const importSelectedPlugins = async () => {
        if (!accountReady || importInProgress) {
            return;
        }

        // Snapshot of the queue — slug and weight per selected row.
        const queue = selectedRows.filter((row) => row && row.slug);
        if (queue.length === 0) {
            return;
        }

        setImportStatus('importing');
        setStopRequested(false);
        stopRequestedRef.current = false;
        setImportProgress({ totalPlugins: queue.length, processedPlugins: 0 });
        setImportedPlugins([]);
        setSkippedImports([]);
        setImportError('');

        const allImported = [];
        const allSkipped = [];
        let processedPlugins = 0;
        let failed = false;

        // One plugin per request: the server batches the SVN work per plugin
        // anyway, so single-plugin requests cost nothing extra — and they are
        // the progress steps and the clean stopping points. Rows a stop
        // never reached keep their selection, so the next Import click resumes
        // exactly there.
        for (const row of queue) {
            if (stopRequestedRef.current) {
                break;
            }
            setImportingSlug(row.slug);
            try {
                const response = await window.Pblsh.API.importWporgPlugins(wporgUsername, [row.slug]);
                const imported = response && Array.isArray(response.imported) ? response.imported : [];
                const skipped = response && Array.isArray(response.skipped) ? response.skipped : [];

                allImported.push(...imported);
                allSkipped.push(...skipped);
                applyImportResultsToRows(imported, skipped);
                setImportedPlugins(allImported.slice());
                setSkippedImports(allSkipped.slice());
            } catch (error) {
                failed = true;
                setImportError(error && error.message ? error.message : __('wordpress.org import failed.', 'peak-publisher'));
                break;
            }
            processedPlugins += 1;
            setImportProgress({ totalPlugins: queue.length, processedPlugins });
        }

        setImportingSlug('');
        if (failed) {
            setImportStatus('error');
        } else {
            setImportStatus(processedPlugins < queue.length ? 'stopped' : 'done');
        }
        if (allImported.length > 0 && typeof onImported === 'function') {
            // The host learns that this visit produced imports (it offers the
            // flow's finishing exit on that).
            onImported();
        }
        if (!failed || allImported.length > 0) {
            try {
                await refreshPluginList();
            } catch (error) {}
        }
    };

    const validateManualSlug = (value) => {
        let raw = String(value || '').trim();
        // A pasted wordpress.org plugin URL carries the slug as its path
        // segment — accept it, so nobody has to know their slug to fill
        // this field.
        const urlMatch = raw.match(/wordpress\.org\/plugins\/([^\/?#]+)/i);
        if (urlMatch) {
            raw = urlMatch[1];
        }
        const slug = raw.toLowerCase();
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
            return {
                slug,
                error: __('Invalid plugin slug.', 'peak-publisher'),
            };
        }
        return { slug, error: '' };
    };

    const addManualSlug = () => {
        if (!accountReady || importInProgress) {
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
            count_of_releases: null,
            directory_hint: null,
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

    const toggleAllSelectable = (selected) => {
        setImportRows((prev) => prev.map((row) => {
            if (!row || !rowIsSelectable(row)) {
                return row;
            }
            return { ...row, selected: !!selected };
        }));
    };

    useEffect(() => {
        // A different (or vanished) account invalidates every loaded row and running lookup.
        lookupBatchRef.current += 1;
        setDiscoverStatus('idle');
        setDiscoverError('');
        setImportRows([]);
        resetImportExecution();
        setManualSlug('');
        setManualSlugError('');
    }, [wporgUsername]);

    useEffect(() => {
        if (accountReady && discoverStatus === 'idle') {
            loadDiscoverPlugins();
        }
    }, [accountReady, discoverStatus]);

    // The status column narrates; activity itself is signalled where it
    // belongs — the access check's spinner replaces the checkbox (the very
    // thing whose availability is being decided), the importing row shows
    // drifting stripes across the whole work unit.
    const getRowStatusText = (row) => {
        // Live states of a running import come first: the row being imported,
        // then the still-selected rows waiting in the queue. The status keeps
        // to its core statement — the release count is a fact about the
        // plugin, shown as its own plain line below.
        if (row.slug === importingSlug) {
            return __('Importing…', 'peak-publisher');
        }
        if (importInProgress && row.selected) {
            return __('Queued', 'peak-publisher');
        }
        if (row.imported) {
            return __('Imported', 'peak-publisher');
        }
        if (row.already_imported) {
            return __('Already imported', 'peak-publisher');
        }
        if (row.access_status === 'pending') {
            return __('Checking access…', 'peak-publisher');
        }
        if (row.access_status === 'ok') {
            return __('Ready', 'peak-publisher');
        }
        if (row.access_status === 'not_found') {
            return __('Not found', 'peak-publisher');
        }
        if (row.access_status === 'credentials_rejected') {
            return __('Credentials rejected', 'peak-publisher');
        }
        return __('Error checking access', 'peak-publisher');
    };

    const getRowStatusClass = (row) => {
        if (row.slug === importingSlug) {
            return 'is-importing';
        }
        if (importInProgress && row.selected) {
            return 'is-pending';
        }
        if (row.imported || row.already_imported) {
            return 'is-imported';
        }
        if (row.access_status === 'pending') {
            return 'is-pending';
        }
        if (row.access_status === 'ok') {
            return 'is-ready';
        }
        return 'is-blocked';
    };

    // The run's result — end states only. While importing, the row states and
    // the busy submit button are the progress display; a bar would repeat them.
    const renderImportResult = () => {
        if (!['done', 'stopped', 'error'].includes(importStatus)) {
            return null;
        }

        const importedCount = importedPlugins.length;
        const skippedCount = skippedImports.length;
        const statusText = importStatus === 'done'
            ? sprintf(__('Import finished: %1$d imported, %2$d skipped.', 'peak-publisher'), importedCount, skippedCount)
            : (importStatus === 'stopped'
                ? sprintf(__('Import stopped: %1$d imported, %2$d skipped. The remaining plugins stay selected.', 'peak-publisher'), importedCount, skippedCount)
                : (importError || __('wordpress.org import failed.', 'peak-publisher')));

        return createElement('div', { className: 'pblsh--wporg-import__execution is-' + importStatus },
            createElement('strong', null, statusText),
            skippedCount > 0 ? createElement('ul', { className: 'pblsh--content-list pblsh--wporg-import__skipped-list' },
                skippedImports.map((entry) => createElement('li', { key: String(entry.slug || '') + ':' + String(entry.reason || '') },
                    createElement('code', null, entry.slug || ''),
                    ' ',
                    entry.message || entry.reason || __('Skipped', 'peak-publisher'),
                )),
            ) : null,
        );
    };

    // Heuristic directory hint per row — the same warn-only signal the gate shows.
    // The state/relation cases mirror WporgImportFacts (the overlay's import
    // station, in full-explanation register) — extend both together.
    // The texts speak to "you" (the account is named in the table header); when
    // multi-account support arrives, reconsider naming the checked account per row.
    const renderRowHint = (row) => {
        const hint = row.directory_hint && typeof row.directory_hint === 'object' ? row.directory_hint : null;
        if (!hint) {
            return null;
        }
        if (hint.state === 'fresh' && hint.relation === 'owner') {
            return createElement('span', { className: 'pblsh--wporg-import__message' },
                __('Freshly approved — no releases yet.', 'peak-publisher'));
        }
        if (hint.state === 'fresh' && hint.relation === 'not_listed') {
            return createElement('span', { className: 'pblsh--wporg-import__message is-warning' },
                sprintf(__('Freshly approved for account %s — publishing will likely be rejected.', 'peak-publisher'), hint.owner || '?'));
        }
        if (hint.state === 'closed') {
            return createElement('span', { className: 'pblsh--wporg-import__message is-warning' },
                __('Closed in the plugin directory.', 'peak-publisher'));
        }
        if (hint.relation === 'not_listed') {
            return createElement('span', { className: 'pblsh--wporg-import__message is-warning' },
                __('Not connected to your account — publishing will likely be rejected.', 'peak-publisher'));
        }
        return null;
    };

    // Identity cell — icon + name/slug, previewing the plugin list's row anatomy;
    // the channel is a given here, so ChannelPath renders the slug only. Like the
    // overlay's identity card, the block links to the live directory page (the
    // place to verify "is this really my plugin?") — but only where that page
    // exists: discover rows are published by definition, manual slugs link once
    // the lookup confirms a page state (a fresh repository has no page yet).
    const renderRowIdentity = (row) => {
        const hint = row.directory_hint && typeof row.directory_hint === 'object' ? row.directory_hint : null;
        const hasDirectoryPage = row.source === 'discover' || (hint && ['published', 'closed', 'unknown'].includes(hint.state));
        const identity = [
            createElement('img', {
                src: row.icon || (hint && hint.icon) || getGeopatternIconUrl(row.slug),
                alt: '',
                className: 'pblsh--wporg-import__plugin-identity-icon',
                width: 48,
                height: 48,
            }),
            createElement('div', { className: 'pblsh--wporg-import__plugin-identity-text' },
                createElement('strong', null,
                    row.name || row.slug,
                    hasDirectoryPage ? createElement('span', { className: 'pblsh--wporg-import__plugin-identity-external', 'aria-hidden': 'true' },
                        getSvgIcon('open_in_new', { size: 14 })) : null,
                ),
                createElement(ChannelPath, { slug: row.slug }),
            ),
        ];
        return hasDirectoryPage
            ? createElement('a', {
                className: 'pblsh--wporg-import__plugin-identity',
                href: 'https://wordpress.org/plugins/' + encodeURIComponent(row.slug) + '/',
                target: '_blank',
                rel: 'noreferrer',
            }, ...identity)
            : createElement('div', { className: 'pblsh--wporg-import__plugin-identity' }, ...identity);
    };

    // The list's states (loading, discover failed, nothing found) render as one
    // full-width row inside the table — the table itself, with its add row in
    // the foot, exists in every state, because manual lookup (SVN) works even
    // while discover (api.wordpress.org) fails.
    const renderTableStateRow = () => {
        const content = (() => {
            if (discoverStatus === 'loading') {
                return createElement(wp.element.Fragment, null,
                    createElement(Spinner),
                    createElement('span', null, __('Loading wordpress.org plugins…', 'peak-publisher')),
                );
            }
            if (discoverStatus === 'error') {
                return createElement(wp.element.Fragment, null,
                    createElement('p', null, discoverError || __('wordpress.org API unavailable, try again later.', 'peak-publisher')),
                    createElement(Button, {
                        isSecondary: true,
                        onClick: loadDiscoverPlugins,
                        disabled: !accountReady,
                    }, __('Retry', 'peak-publisher')),
                );
            }
            if (discoverStatus === 'loaded') {
                return createElement(wp.element.Fragment, null,
                    createElement('p', null, __('No wordpress.org plugins found for this account.', 'peak-publisher')),
                    createElement('p', null, __('If your plugin was just approved or does not appear yet, add it below.', 'peak-publisher')),
                );
            }
            return null;
        })();
        return content ? createElement('tr', null,
            createElement('td', { colSpan: 3, className: 'pblsh--wporg-import__table-state' }, content),
        ) : null;
    };

    // The add row — the list's own "empty next row": a new plugin gets typed
    // (or its wordpress.org URL pasted) right where its row will appear.
    const renderAddRow = () => {
        return createElement('tfoot', null,
            createElement('tr', null,
                createElement('td', { colSpan: 3, className: 'pblsh--wporg-import__add-cell' },
                    createElement('div', { className: 'pblsh--wporg-import__add-controls' },
                        createElement(TextControl, {
                            value: manualSlug,
                            placeholder: __('Add plugin by slug or URL…', 'peak-publisher'),
                            'aria-label': __('Add plugin by slug or wordpress.org URL', 'peak-publisher'),
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
                            disabled: importInProgress || !accountReady || manualSlug.trim() === '',
                        }, __('Add to list', 'peak-publisher')),
                    ),
                    manualSlugError ? createElement('p', { className: 'pblsh--wporg-import__manual-error' }, manualSlugError) : null,
                ),
            ),
        );
    };

    const renderImportRows = () => {
        return createElement('div', { className: 'pblsh--wporg-import__results' },
            discoverStatus === 'loading' && importRows.length > 0 ? createElement('div', { className: 'pblsh--wporg-import__loading' },
                createElement(Spinner),
                createElement('span', null, __('Loading wordpress.org plugins…', 'peak-publisher')),
            ) : null,
            createElement('div', { className: 'pblsh--wporg-import__table-wrap' },
                createElement('table', { className: 'pblsh--wporg-import__table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--wporg-import__select-header' },
                                // The header speaks the column's language: while
                                // any check runs, a spinner — whether (and over
                                // what) "select all" exists is still being
                                // decided. The checkbox appears once the list is
                                // fully checked and something is selectable, so
                                // the bulk gesture can never run ahead of the
                                // list; single-row selection stays available
                                // throughout. Indeterminate marks a partial
                                // selection.
                                pendingCheckExists ? createElement(Spinner) : (selectableCount > 0 ? createElement('input', {
                                    type: 'checkbox',
                                    checked: allSelectableSelected,
                                    disabled: importInProgress,
                                    'aria-label': __('Select all', 'peak-publisher'),
                                    ref: (el) => {
                                        if (el) {
                                            el.indeterminate = !allSelectableSelected && selectedRows.length > 0;
                                        }
                                    },
                                    onChange: (event) => toggleAllSelectable(event.target.checked),
                                }) : null),
                            ),
                            createElement('th', null, __('Plugin', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--wporg-import__status-header' }, __('Status', 'peak-publisher')),
                        ),
                    ),
                    createElement('tbody', null,
                        importRows.length === 0 ? renderTableStateRow() : importRows.map((row) => {
                            const selectable = rowIsSelectable(row);
                            return createElement('tr', {
                                key: row.slug,
                                className: 'pblsh--wporg-import__row ' + getRowStatusClass(row)
                                    + (row.slug === importingSlug ? ' pblsh--importing-stripes' : ''),
                            },
                                createElement('td', { className: 'pblsh--wporg-import__select-cell' },
                                    // While the access check decides whether this
                                    // row is selectable at all, its spinner takes
                                    // the checkbox's place — the checkbox appears
                                    // once the answer is there. Definitively
                                    // non-importable rows (already imported, not
                                    // found, errors) get no checkbox at all: a
                                    // disabled one would promise "maybe later".
                                    // During a run the checkboxes merely lock, so
                                    // the queue membership stays visible.
                                    row.access_status === 'pending' && !row.already_imported && !row.imported
                                        ? createElement(Spinner)
                                        : (selectable ? createElement('input', {
                                            type: 'checkbox',
                                            checked: !!row.selected,
                                            disabled: importInProgress,
                                            'aria-label': sprintf(__('Select %s', 'peak-publisher'), row.name || row.slug),
                                            onChange: (event) => toggleRowSelection(row.slug, event.target.checked),
                                        }) : null),
                                ),
                                createElement('td', null, renderRowIdentity(row)),
                                createElement('td', null,
                                    // The row's one action sits inline right after the
                                    // status it follows from ("Already imported → Open"),
                                    // WP-row-action style — no dedicated action column
                                    // that would materialize mid-run and shift every row.
                                    // The terse label works because the row answers
                                    // "open what?"; the aria-label restores the context.
                                    createElement('div', { className: 'pblsh--wporg-import__status-line' },
                                        createElement('span', { className: 'pblsh--wporg-import__status' }, getRowStatusText(row)),
                                        row.already_imported && row.existing_plugin_id
                                            ? createElement(Button, {
                                                isSecondary: true,
                                                isSmall: true,
                                                'aria-label': sprintf(__('Open %s', 'peak-publisher'), row.name || row.slug),
                                                onClick: () => {
                                                    if (typeof onOpenPlugin === 'function') {
                                                        onOpenPlugin(row.existing_plugin_id);
                                                    }
                                                },
                                            },
                                                __('Open', 'peak-publisher'),
                                                // The arrow marks it as navigation (it leaves
                                                // this screen) — the same in-app "go" sign the
                                                // wizard's Next and the triage cards use.
                                                createElement('span', { className: 'pblsh--button-icon', 'aria-hidden': 'true' }, getSvgIcon('arrow_right', { size: 14 })),
                                            )
                                            : null,
                                    ),
                                    // The plugin's release count — a plain, status-independent
                                    // fact line (it also sets the duration expectation of the
                                    // row's import).
                                    releaseCountOf(row) !== null ? createElement('span', { className: 'pblsh--wporg-import__release-count' },
                                        sprintf(_n('%d release', '%d releases', releaseCountOf(row), 'peak-publisher'), releaseCountOf(row))) : null,
                                    row.message ? createElement('span', { className: 'pblsh--wporg-import__message' }, row.message) : null,
                                    // Heuristic directory/ownership hint — importable, but with context.
                                    !row.message && selectable ? renderRowHint(row) : null,
                                ),
                            );
                        }),
                    ),
                    renderAddRow(),
                ),
            ),
        );
    };

    return createElement('div', { className: 'pblsh--wporg-import' },
        createElement('div', { className: 'pblsh--wporg-import__header' },
            createElement('div', null,
                createElement('p', { className: 'pblsh--wporg-import__account' }, sprintf(__('Account: %s', 'peak-publisher'), wporgUsername)),
            ),
            createElement(Button, {
                isSecondary: true,
                onClick: loadDiscoverPlugins,
                disabled: importInProgress || discoverStatus === 'loading' || !accountReady,
            }, __('Refresh', 'peak-publisher')),
        ),
        renderImportRows(),
        // Below the list, right above the button that started it — the result
        // appears where the user just clicked, even with a long list.
        renderImportResult(),
        createElement('div', { className: 'pblsh--wporg-import__footer' },
            // Stop promises "skip what has not started yet" — it shows only
            // while queued plugins remain (the running one can't be stopped),
            // so single-plugin runs and the last plugin never offer it.
            importInProgress && importProgress.processedPlugins + 1 < importProgress.totalPlugins ? createElement(Button, {
                isSecondary: true,
                disabled: stopRequested,
                onClick: () => {
                    stopRequestedRef.current = true;
                    setStopRequested(true);
                },
            }, stopRequested
                ? __('Stopping after this plugin…', 'peak-publisher')
                : __('Stop', 'peak-publisher')) : null,
            createElement(Button, {
                isPrimary: true,
                isBusy: importInProgress,
                onClick: importSelectedPlugins,
                disabled: importInProgress || !accountReady || selectedRows.length === 0,
            }, importInProgress
                ? sprintf(__('Importing %1$d of %2$d…', 'peak-publisher'), Math.min(importProgress.processedPlugins + 1, importProgress.totalPlugins), importProgress.totalPlugins)
                : (selectedRows.length > 0 ? sprintf(__('Import selected (%d)', 'peak-publisher'), selectedRows.length) : __('Import selected', 'peak-publisher'))),
        ),
    );
};

lodash.set(window, 'Pblsh.Components.WporgImportTable', WporgImportTable);
