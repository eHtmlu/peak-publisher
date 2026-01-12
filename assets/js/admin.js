// Peak Publisher Admin App
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    const { __ } = wp.i18n;
    const { useState, useEffect, useRef, createElement, render } = wp.element;
    const { useSelect } = wp.data;
    const { Button } = wp.components;
    const { PluginList, PluginAdditionProcess, PluginEditor/* , SuccessMessage */ , GlobalDropOverlay, Settings } = window.Pblsh.Components;
    const { showAlert, getDefaultConfig } = Pblsh.Utils;

    // Main App Component
    const PeakPublisherApp = () => {
        const [view, setView] = useState('list'); // 'list' | 'editor' | 'addition-process'
        const [currentPluginId, setCurrentPluginId] = useState(null);
        const isLoading = useSelect((select) => select('pblsh/plugins').isLoadingList(), []);
        const plugins = useSelect((select) => select('pblsh/plugins').getPlugins(), []);
        const pendingPluginStatus = useSelect((select) => select('pblsh/plugins').getPendingIds(), []);

        const [isNew, setIsNew] = useState(false);
        const settingsDialogRef = useRef(null);
        const pendingReleaseStatus = useSelect((select) => select('pblsh/releases').getPendingReleaseIds(), []);
        const isLoadingReleases = useSelect((select) => currentPluginId ? select('pblsh/releases').isLoadingForPlugin(currentPluginId) : false, [currentPluginId]);
        const currentPlugin = useSelect((select) => currentPluginId ? select('pblsh/plugins').getById(currentPluginId) : null, [currentPluginId]);

        // Helpers for URL state
        const parseQuery = () => {
            try {
                const params = new URLSearchParams(window.location.search);
                return {
                    plugin: params.get('plugin'),
                    view: params.get('view'),
                };
            } catch (e) {
                return { plugin: null, view: null };
            }
        };
        const setQuery = (next) => {
            try {
                const params = new URLSearchParams(window.location.search);
                if ('plugin' in next) {
                    if (next.plugin) { params.set('plugin', String(next.plugin)); } else { params.delete('plugin'); }
                }
                if ('view' in next) {
                    if (next.view) { params.set('view', String(next.view)); } else { params.delete('view'); }
                }
                const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newUrl);
            } catch (e) {}
        };

        // Load plugins on mount + restore view from URL
        useEffect(() => {
            (async () => {
                await window.Pblsh.Controllers.Plugins.fetchList();
                const q = parseQuery();
                if (q && q.plugin) {
                    const idNum = Number(q.plugin);
                    if (!isNaN(idNum)) {
                        await handleEdit(idNum);
                        return;
                    }
                }
                if (q && q.view === 'addition') {
                    handleAddNewPlugin();
                    return;
                }
            })();
        }, []);

        
        const togglePluginStatus = async (pluginId, nextStatus) => {
            try {
                await window.Pblsh.Controllers.Plugins.toggleStatus(pluginId, nextStatus);
            } catch (error) {
                showAlert(error.message, 'error');
            }
        };

        const toggleReleaseStatus = async (releaseId, nextStatus) => {
            try {
                if (!currentPluginId) return;
                await window.Pblsh.Controllers.Releases.toggleReleaseStatus(currentPluginId, releaseId, nextStatus);
            } catch (error) {
                showAlert(error.message, 'error');
            }
        };


        const handleAddNewPlugin = () => {
            // keep local draft for UI only
            setIsNew(true);
            setView('addition-process');
            setQuery({ view: 'addition', plugin: null });
        };

        const handleEdit = async (id) => {
            try {
                setIsNew(false);
                setView('editor');
                setCurrentPluginId(id);
                setQuery({ plugin: id, view: null });
                await window.Pblsh.Controllers.Plugins.fetchById(id);
                await window.Pblsh.Controllers.Releases.fetchForPlugin(id);
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
                window.Pblsh.Controllers.Plugins.fetchList();
            }
        };

        

        const handleCancel = () => {
            setView('list');
            setCurrentPluginId(null);
            setIsNew(false);
            setQuery({ plugin: null, view: null });
        };


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

        // Render header based on current view
        const renderHeader = () => {
            if (view === 'addition-process') {
                return createElement('div', { className: 'pblsh--header' },
                    createElement('h2', { className: 'pblsh--header__title' }, 
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
                return createElement('div', { className: 'pblsh--header' },
                    createElement('h2', { className: 'pblsh--header__title' }, __('Peak Publisher', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: handleAddNewPlugin,
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
                return createElement('div', { className: 'pblsh--header' },
                    createElement('h2', { className: 'pblsh--header__title' }, __('Peak Publisher', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: handleAddNewPlugin,
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
                await window.Pblsh.Controllers.Plugins.fetchList();
                setCurrentPluginId(pluginId);
                setIsNew(false);
                setView('editor');
                setQuery({ plugin: pluginId, view: null });
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
            } catch (error) {
                showAlert(error.message, 'error');
            }
        }, [currentPluginId]);

        const renderMainContent = () => {
            if (view === 'addition-process') {
                return createElement(PluginAdditionProcess, {
                    onCreated: handleCreated,
                });
            }
            else if (view === 'editor') {
                return createElement(PluginEditor, {
                    pluginData: currentPlugin,
                    isNew,
                    refreshPlugin: refreshCurrentPlugin,
                    onToggleReleaseStatus: toggleReleaseStatus,
                    pendingReleaseIds: pendingReleaseStatus,
                    onTogglePluginStatus: togglePluginStatus,
                    pendingPluginStatus: pendingPluginStatus,
                    isLoadingReleases: isLoadingReleases,
                    onBack: handleCancel,
                });
            } else {
                if (isLoading) {
                    return createElement('div', { className: 'pblsh--loading' },
                        createElement('div', { className: 'pblsh--loading__spinner' })
                    );
                }
                return createElement(PluginList, {
                    plugins: plugins,
                    onEdit: handleEdit,
                    onDelete: handleDelete,
                    onCreateNew: handleAddNewPlugin,
                    onToggleStatus: togglePluginStatus,
                    pendingPluginStatus: pendingPluginStatus,
                });
            }
        };

        // Render footer
        const renderFooter = () => {
            /* return createElement('div', { className: 'pblsh--footer' },
                
            ); */
        };

        return createElement('div', { className: 'pblsh-app' },
            // Global drop overlay (always mounted)
            createElement(GlobalDropOverlay, { onCreated: handleCreated }),

            // Header (always visible)
            renderHeader(),

            // Main content (with loading state)
            renderMainContent(),

            // Footer (always visible)
            renderFooter(),

            // Settings dialog (always mounted)
            createElement('dialog', { className: 'pblsh--modal pblsh--modal--settings', ref: settingsDialogRef, onClick: (e) => { if (e.target === e.currentTarget) { closeSettings(); } } },
                createElement(Settings, {
                    onClose: closeSettings,
                })
            )
        );
    };

    // Render the app
    const container = document.getElementById('pblsh-app');
    if (container) {
        render(createElement(PeakPublisherApp), container);
    }
}); 