// Peak Publisher Admin App
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    const { __, sprintf } = wp.i18n;
    const { useState, useEffect, useRef, createElement, createPortal, Fragment, render } = wp.element;
    const { useSelect } = wp.data;
    const { Button } = wp.components;
    const { PluginList, PluginAdditionProcess, PluginEditor/* , SuccessMessage */ , GlobalDropOverlay, Settings, TipDialog, UpgradeNotice, NoticeBox } = window.Pblsh.Components;
    const { showAlert, getDefaultConfig } = Pblsh.Utils;

    // The page's header place (AdminUI::render_peak_publisher()): every view renders its
    // title and actions into it through a portal — the place itself sticks below the admin bar.
    const headerPlace = document.getElementById('pblsh-header');

    // Notices WordPress leaves above the page — its mover (common.js) skips those marked
    // "inline", the core update nag among them — go right before its marker at the top of
    // .wrap: the header attaches to the admin bar, and they lead the notices WordPress moves
    // after the marker. Before it, not after: whichever of the two movers runs first, the order
    // comes out the same.
    document.querySelector('.pblsh-app .wp-header-end').before(...document.querySelectorAll('#wpbody-content > :is(div.updated, div.error, div.notice)'));

    // Permalink check — shown instead of the app when permalinks are set to "Plain"
    const PermalinkNotice = () => {
        const { permalinkPlain, permalinkDayAndName } = PblshData.i18n;
        return createElement(Fragment, null,
            createPortal(
                createElement('h1', { className: 'pblsh--header__title' }, __('Peak Publisher', 'peak-publisher')),
                headerPlace
            ),
            createElement('div', { className: 'pblsh--permalink-notice' },
                createElement('div', { className: 'pblsh--permalink-notice__icon' },
                    Pblsh.Utils.getSvgIcon('alert_outline', { size: 48 })
                ),
                createElement('h3', { className: 'pblsh--permalink-notice__title' },
                    __('Pretty Permalinks Required', 'peak-publisher')
                ),
                createElement('p', { className: 'pblsh--permalink-notice__text' },
                    sprintf(
                        /* translators: %s: name of the "Plain" permalink option (translated by WordPress) */
                        __('Peak Publisher uses the WordPress REST API, which requires pretty permalinks. Your permalink structure is currently set to "%s", which does not support REST API routes.', 'peak-publisher'),
                        permalinkPlain
                    )
                ),
                createElement('p', { className: 'pblsh--permalink-notice__text' },
                    createElement('strong', null,
                        sprintf(
                            /* translators: %1$s: "Plain" option name, %2$s: "Day and name" option name (both translated by WordPress) */
                            __('To fix this, go to the permalink settings and select any structure other than "%1$s" (e.g. "%2$s").', 'peak-publisher'),
                            permalinkPlain,
                            permalinkDayAndName
                        )
                    ),
                ),
                createElement('a', {
                    className: 'components-button is-primary pblsh--permalink-notice__button',
                    href: PblshData.permalinkSettingsUrl,
                }, __('Go to Permalink Settings', 'peak-publisher'))
            )
        );
    };

    // A plugin's tabs — its releases and its assets view — loaded behind what the stores hold;
    // a failure is reported like any load.
    const loadPluginTabs = (id) => {
        window.Pblsh.Controllers.Releases.fetchForPlugin(id).catch((e) => showAlert(e.message, 'error'));
        window.Pblsh.Controllers.Assets.fetchForPlugin(id).catch((e) => showAlert(e.message, 'error'));
    };

    // The refresh against wordpress.org of every caller — the automatic check (every plugin,
    // whatever is due) and the editor's Refresh link (that plugin, straight against SVN). The
    // store takes the rows the server answers; a plugin that moved also gets its tabs reloaded,
    // so its header and its tabs show the same state. Which plugins that concerns is decided
    // when the answer arrives: every one whose tabs the client holds or is loading by then,
    // whichever view the request started from. A wordpress.org plugin added or removed
    // elsewhere — another tab, another admin — is no row's news: the list is loaded anew.
    const refreshWporg = async (pluginId = null) => {
        const answer = await window.Pblsh.Controllers.Plugins.refreshWporg(pluginId);
        const releases = wp.data.select('pblsh/releases');
        Object.keys(answer.plugins).map(Number)
            .filter((id) => releases.hasLoadedForPlugin(id) || releases.isLoadingForPlugin(id))
            .forEach(loadPluginTabs);
        if (answer.list_outdated) {
            window.Pblsh.Controllers.Plugins.fetchList().catch((e) => showAlert(e.message, 'error'));
        }
    };

    // Main App Component
    const PeakPublisherApp = () => {
        // Block the entire app when permalinks are set to "Plain"
        if (PblshData.hasPlainPermalinks) {
            return createElement(PermalinkNotice);
        }

        const [view, setView] = useState('list'); // 'list' | 'editor' | 'addition-process'
        const [currentPluginId, setCurrentPluginId] = useState(null);
        const [initialTab, setInitialTab] = useState(null);
        const [additionInitial, setAdditionInitial] = useState(null); // deep-link seed for the addition process
        const [activeUploadContext, setActiveUploadContext] = useState({});
        const isLoading = useSelect((select) => select('pblsh/plugins').isLoadingList(), []);
        const hasLoadedList = useSelect((select) => {
            try { return !!select('pblsh/plugins').hasLoadedList(); } catch (e) { return false; }
        }, []);
        const plugins = useSelect((select) => select('pblsh/plugins').getPlugins(), []);
        const pendingPluginStatus = useSelect((select) => select('pblsh/plugins').getPendingIds(), []);

        const [isNew, setIsNew] = useState(false);
        const settingsDialogRef = useRef(null);
        const currentPlugin = useSelect((select) => currentPluginId ? select('pblsh/plugins').getById(currentPluginId) : null, [currentPluginId]);

        // While the list or a plugin is in view, what it shows of wordpress.org is at most one
        // check interval old. The server keeps the one clock — a stamp check at most every five
        // minutes, site-wide — and names with every answer when the next check is due; the client
        // asks then and not before, so in between this costs neither server a request. That
        // moment is the timer's to find, and every look's that comes after it: showing the list,
        // opening a plugin, the browser tab coming back into view or getting the focus. A hidden
        // tab asks nothing. A failed check stays quiet; the status line says how old the data is,
        // and the editor's Refresh link reports its own.
        const nextWporgCheckAt = useSelect((select) => select('pblsh/plugins').getNextWporgCheckAt(), []);
        const showsWporgData = hasLoadedList && (view === 'list' || view === 'editor')
            && plugins.some((plugin) => plugin.hosting_type === 'wporg');
        const askWporg = wp.element.useCallback(() => {
            if (!showsWporgData || document.visibilityState !== 'visible' || wp.data.select('pblsh/plugins').isRefreshingWporg()) return;
            refreshWporg().catch(() => {});
        }, [showsWporgData]);
        // The timer asks when it fires — its running out is the moment, whatever a clock read a
        // millisecond early would say; a look asks when the moment has passed.
        useEffect(() => {
            const timer = setTimeout(askWporg, Math.max(0, nextWporgCheckAt - Date.now()));
            return () => clearTimeout(timer);
        }, [nextWporgCheckAt, askWporg]);
        const checkWporg = wp.element.useCallback(() => {
            if (Date.now() >= wp.data.select('pblsh/plugins').getNextWporgCheckAt()) askWporg();
        }, [askWporg]);
        useEffect(() => { checkWporg(); }, [view, currentPluginId, checkWporg]);
        useEffect(() => {
            document.addEventListener('visibilitychange', checkWporg);
            window.addEventListener('focus', checkWporg);
            return () => {
                document.removeEventListener('visibilitychange', checkWporg);
                window.removeEventListener('focus', checkWporg);
            };
        }, [checkWporg]);
        // What a refresh found changed on wordpress.org is told while the plugin stands open;
        // leaving it settles that.
        useEffect(() => () => {
            if (currentPluginId) wp.data.dispatch('pblsh/plugins').clearWporgChanges(currentPluginId);
        }, [currentPluginId]);

        // Helpers for URL state
        const parseQuery = () => {
            try {
                const params = new URLSearchParams(window.location.search);
                return {
                    plugin: params.get('plugin'),
                    view: params.get('view'),
                    tab: params.get('tab'),
                    channel: params.get('channel'),
                    import: params.get('import'),
                };
            } catch (e) {
                return { plugin: null, view: null };
            }
        };
        const setQuery = (next) => {
            try {
                const params = new URLSearchParams(window.location.search);
                ['plugin', 'view', 'tab', 'channel', 'import'].forEach((key) => {
                    if (key in next) {
                        if (next[key]) { params.set(key, String(next[key])); } else { params.delete(key); }
                    }
                });
                const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newUrl);
            } catch (e) {}
        };

        // What the app starts with: the settings, the plugins, then the view the URL names. A
        // list that cannot be loaded is told in its place, with the way to try again — nothing
        // else can start without it.
        const [listError, setListError] = useState(null);
        const loadApp = async () => {
            setListError(null);
            try { await window.Pblsh.Controllers.Settings.fetch(); } catch (e) {}
            try {
                await window.Pblsh.Controllers.Plugins.fetchList();
            } catch (error) {
                setListError(error);
                return;
            }
            const q = parseQuery();
            if (q && q.plugin) {
                const idNum = Number(q.plugin);
                if (!isNaN(idNum)) {
                    if (q.tab) setInitialTab(q.tab);
                    await handleEdit(idNum);
                    return;
                }
            }
            if (q && q.view === 'addition') {
                // Deep link: ?view=addition&channel=wporg&import=open opens the wporg
                // import route directly (used by the Settings account card).
                handleAddNewPlugin(q.channel || q.import ? { channel: q.channel, importOpen: q.import === 'open' } : null);
            }
        };
        useEffect(() => { loadApp(); }, []);

        
        const togglePluginStatus = async (pluginId, nextStatus) => {
            try {
                await window.Pblsh.Controllers.Plugins.toggleStatus(pluginId, nextStatus);
            } catch (error) {
                showAlert(error.message, 'error');
            }
        };



        const handleAddNewPlugin = (initial = null) => {
            // keep local draft for UI only
            setIsNew(true);
            setActiveUploadContext({});
            setAdditionInitial(initial && typeof initial === 'object' ? initial : null);
            setView('addition-process');
            setQuery({
                view: 'addition',
                plugin: null,
                channel: initial?.channel || null,
                import: initial?.importOpen ? 'open' : null,
            });
        };

        const handleAdditionStateChange = ({ channel = null, importOpen = false } = {}) => {
            // The wizard reports its route; the URL mirrors it so reloads and
            // copied links land where the user actually is.
            setQuery({ channel: channel || null, import: importOpen ? 'open' : null });
        };

        const handleOpenWporgImport = () => {
            // "Import plugins…" from the Settings account card — the import's single home
            // is the add-new flow's wporg "Add" step.
            closeSettings();
            handleAddNewPlugin({ channel: 'wporg', importOpen: true });
        };

        const handleEdit = async (id) => {
            try {
                setIsNew(false);
                setView('editor');
                setActiveUploadContext({});
                setCurrentPluginId(id);
                setQuery({ plugin: id, view: null, channel: null, import: null });
                await window.Pblsh.Controllers.Plugins.fetchById(id);
                // Not there — removed elsewhere, or a link to a plugin that is gone: nothing to
                // load, the effect on the open plugin says so and returns to the list.
                if (!wp.data.select('pblsh/plugins').getById(id)) return;
                // The plugin may have changed outside this client since the last visit. What the
                // server holds comes with this load; whether wordpress.org moved since is the
                // directory check's to find out (checkWporg, asked by this very open) — its
                // answer reloads the tabs again.
                loadPluginTabs(id);
            } catch (error) {
                showAlert(error.message, 'error');
            }
        };

        const handleDelete = async (plugin) => {
            try {
                await window.Pblsh.Controllers.Plugins.delete(plugin.id);
                setView(prev => (prev === 'editor' && currentPluginId === plugin.id) ? 'list' : prev);
                if (currentPluginId === plugin.id) setCurrentPluginId(null);
            } catch (error) {
                showAlert(error.message, 'error');
                // What the server holds now, whatever the failed delete left behind.
                window.Pblsh.Controllers.Plugins.fetchList().catch((e) => showAlert(e.message, 'error'));
            }
        };

        

        const handleCancel = () => {
            setView('list');
            setCurrentPluginId(null);
            setIsNew(false);
            setInitialTab(null);
            setActiveUploadContext({});
            setQuery({ plugin: null, view: null, tab: null, channel: null, import: null });
        };

        // The open plugin is gone from the list — removed elsewhere while it stood open, or a
        // link to one that no longer exists: said once, then back to the list.
        useEffect(() => {
            if (view === 'editor' && currentPluginId && hasLoadedList && !currentPlugin) {
                showAlert(__('This plugin no longer exists. It was removed in the meantime.', 'peak-publisher'), 'warning');
                handleCancel();
            }
        }, [view, currentPluginId, currentPlugin, hasLoadedList]);


        const openSettings = () => {
            const dlg = settingsDialogRef.current;
            if (!dlg) return;
            try { if (!dlg.open && typeof dlg.showModal === 'function') dlg.showModal(); } catch (e) {}
        };
        const closeSettings = () => {
            const dlg = settingsDialogRef.current;
            if (!dlg) return;
            try { if (dlg.open) dlg.close(); } catch (e) {}
        };

        // The header's title and actions for the current view — rendered into the header place.
        const renderHeader = () => {
            if (view === 'addition-process') {
                return createElement(Fragment, null,
                    createElement('h1', { className: 'pblsh--header__title' },
                        __('Peak Publisher', 'peak-publisher'),
                        ' - ',
                        __('Add New Plugin', 'peak-publisher')
                    ),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isSecondary: true,
                            onClick: handleCancel,
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Cancel', 'peak-publisher')),
                    )
                );
            }
            else if (view === 'editor') {
                return createElement(Fragment, null,
                    createElement('h1', { className: 'pblsh--header__title' }, __('Peak Publisher', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: () => handleAddNewPlugin(),
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Add New Plugin', 'peak-publisher')),
                        createElement(Button, {
                            isTertiary: true,
                            onClick: openSettings,
                            label: __('Settings', 'peak-publisher'),
                            icon: Pblsh.Utils.getSvgIcon('cog', { size: 24 }),
                            __next40pxDefaultSize: true,
                        })
                    )
                );
            } else {
                return createElement(Fragment, null,
                    createElement('h1', { className: 'pblsh--header__title' }, __('Peak Publisher', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: () => handleAddNewPlugin(),
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Add New Plugin', 'peak-publisher')),
                        createElement(Button, {
                            isTertiary: true,
                            onClick: openSettings,
                            label: __('Settings', 'peak-publisher'),
                            icon: Pblsh.Utils.getSvgIcon('cog', { size: 24 }),
                            __next40pxDefaultSize: true,
                        })
                    ),
                );
            }
        };

        // Render main content
        const handleCreated = async (pluginId) => {
            try {
                await window.Pblsh.Controllers.Plugins.fetchById(pluginId);
                await window.Pblsh.Controllers.Releases.fetchForPlugin(pluginId);
                await window.Pblsh.Controllers.Plugins.fetchList();
                setCurrentPluginId(pluginId);
                setIsNew(false);
                setView('editor');
                setActiveUploadContext({});
                setQuery({ plugin: pluginId, view: null, channel: null, import: null });
            } catch (error) {
                showAlert(error.message, 'error');
            }
        };

        // Provide a stable refresh callback for children
        const refreshCurrentPlugin = wp.element.useCallback(async () => {
            try {
                if (!currentPluginId) return;
                await window.Pblsh.Controllers.Plugins.fetchById(currentPluginId);
                await window.Pblsh.Controllers.Plugins.fetchList();
                await window.Pblsh.Controllers.Releases.fetchForPlugin(currentPluginId);
                // The assets view follows the plugin: the captions, the mirror after a pull.
                await window.Pblsh.Controllers.Assets.fetchForPlugin(currentPluginId);
            } catch (error) {
                showAlert(error.message, 'error');
            }
        }, [currentPluginId]);

        const renderMainContent = () => {
            if (view === 'addition-process') {
                return createElement(PluginAdditionProcess, {
                    onCreated: handleCreated,
                    // The flow's affirmative exit lands where Cancel lands —
                    // back on the plugin list.
                    onFinished: handleCancel,
                    onStateChange: handleAdditionStateChange,
                    setActiveUploadContext,
                    initialChannel: additionInitial?.channel || null,
                    initialImport: !!additionInitial?.importOpen,
                });
            }
            else if (view === 'editor') {
                return createElement(PluginEditor, {
                    pluginData: currentPlugin,
                    isNew,
                    refreshPlugin: refreshCurrentPlugin,
                    refreshWporg,
                    onTogglePluginStatus: togglePluginStatus,
                    pendingPluginStatus: pendingPluginStatus,
                    onBack: handleCancel,
                    initialTab: initialTab,
                    onTabChange: (tab) => setQuery({ tab: tab === 'releases' ? null : tab }),
                });
            } else {
                if (listError) {
                    return createElement(NoticeBox, { variant: 'error', title: __('The plugins could not be loaded', 'peak-publisher'), error: listError },
                        createElement('p', null, createElement(Button, { isLink: true, onClick: loadApp }, __('Try again', 'peak-publisher'))),
                    );
                }
                // A spinner only until the first list is in; a reload keeps the list it replaces.
                if (!hasLoadedList) {
                    return createElement('div', { className: 'pblsh--loading' },
                        createElement('div', { className: 'pblsh--loading__spinner' })
                    );
                }
                return createElement(wp.element.Fragment, null,
                    createElement(UpgradeNotice, { onOpenPlugin: handleEdit }),
                    createElement(PluginList, {
                        plugins: plugins,
                        onEdit: handleEdit,
                        onDelete: handleDelete,
                        onCreateNew: () => handleAddNewPlugin(),
                    }),
                );
            }
        };

        // Render footer
        const renderFooter = () => {
            /* return createElement('div', { className: 'pblsh--footer' },
                
            ); */
        };

        return createElement(Fragment, null,
            // Global drop overlay (always mounted)
            createElement(GlobalDropOverlay, { onCreated: handleCreated, activeUploadContext }),

            // Header (always visible), in the page's header place
            createPortal(renderHeader(), headerPlace),

            // Main content (with loading state)
            renderMainContent(),

            // Footer (always visible)
            renderFooter(),

            // Settings dialog (always mounted)
            createElement('dialog', { className: 'pblsh--modal pblsh--modal--settings', ref: settingsDialogRef, onClick: (e) => { if (e.target === e.currentTarget) { closeSettings(); } } },
                createElement(Settings, {
                    onClose: closeSettings,
                    onOpenWporgImport: handleOpenWporgImport,
                })
            ),

            // Tip dialog host — opened via the 'pblsh:open-tip' event from TipLink
            createElement(TipDialog)
        );
    };

    // Render the app
    const container = document.getElementById('pblsh-app');
    if (container) {
        render(createElement(PeakPublisherApp), container);
    }
}); 
