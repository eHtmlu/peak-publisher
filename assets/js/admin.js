// Publisher Admin App
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    const { __ } = wp.i18n;
    const { useState, useEffect, useRef, createElement, render } = wp.element;
    const { Button } = wp.components;
    const { PluginList, PluginAdditionProcess, PluginEditor/* , SuccessMessage */ , GlobalDropOverlay, Settings } = window.Pblsh.Components;
    const { showAlert, getDefaultConfig } = Pblsh.Utils;
    const { getPlugins, getPlugin, updatePlugin, deletePlugin } = Pblsh.API;

    // Main App Component
    const PublisherApp = () => {
        const [view, setView] = useState('list'); // 'list' | 'editor' | 'addition-process'
        const [plugins, setPlugins] = useState([]);
        const [currentPlugin, setCurrentPlugin] = useState(null);
        const [isLoading, setIsLoading] = useState(true);
        const [isNew, setIsNew] = useState(false);
        const settingsDialogRef = useRef(null);
        const [pendingPluginStatus, setPendingPluginStatus] = useState([]);
        const [pendingReleaseStatus, setPendingReleaseStatus] = useState([]);
        const [isLoadingReleases, setIsLoadingReleases] = useState(false);

        // Load plugins on mount
        useEffect(() => {
            loadPlugins();
        }, []);

        const loadPlugins = async (silent = false) => {
            try {
                if (!silent) {
                    setIsLoading(true);
                }
                const pluginsData = await getPlugins();
                setPlugins(pluginsData);
            } catch (error) {
                if (!silent) {
                    showAlert(error.message, 'error');
                }
            } finally {
                if (!silent) {
                    setIsLoading(false);
                }
            }
        };

        
        const setPluginInList = (pluginId, updater) => {
            setPlugins(prev => prev.map(p => p.id === pluginId ? updater({ ...p }) : p));
        };

        const togglePluginStatus = async (pluginId, nextStatus) => {
            try {
                //if (nextStatus === 'publish' && !confirm(__('Do you want to publish this plugin?', 'publisher'))) return;
                //if (nextStatus === 'draft' && !confirm(__('Do you want to unpublish this plugin?', 'publisher'))) return;
                setPendingPluginStatus(prev => prev.includes(pluginId) ? prev : [...prev, pluginId]);
                await Pblsh.API.updatePlugin(pluginId, { status: nextStatus });
                setPluginInList(pluginId, (p) => ({ ...p, status: nextStatus }));
                setCurrentPlugin(prev => prev && prev.id === pluginId ? { ...prev, status: nextStatus } : prev);
            } catch (error) {
                showAlert(error.message, 'error');
            } finally {
                setPendingPluginStatus(prev => prev.filter(id => id !== pluginId));
            }
        };

        const toggleReleaseStatus = async (releaseId, nextStatus) => {
            try {
                //if (nextStatus === 'publish' && !confirm(__('Do you want to publish this release?', 'publisher'))) return;
                //if (nextStatus === 'draft' && !confirm(__('Do you want to unpublish this release?', 'publisher'))) return;
                setPendingReleaseStatus(prev => prev.includes(releaseId) ? prev : [...prev, releaseId]);
                await Pblsh.API.updateRelease(releaseId, { status: nextStatus });
                setCurrentPlugin(prev => {
                    if (!prev) return prev;
                    const updatedReleases = Array.isArray(prev.releases) ? prev.releases.map(r => r.id === releaseId ? { ...r, status: nextStatus } : r) : [];
                    return { ...prev, releases: updatedReleases };
                });
            } catch (error) {
                showAlert(error.message, 'error');
            } finally {
                setPendingReleaseStatus(prev => prev.filter(id => id !== releaseId));
            }
        };


        const handleAddNewPlugin = () => {
            setCurrentPlugin(getDefaultConfig());
            setIsNew(true);
            setView('addition-process');
        };

        const handleEdit = async (id) => {
            try {
                const fromList = (plugins || []).find(p => p.id === id) || null;
                if (fromList) {
                    setCurrentPlugin(fromList);
                }
                setIsNew(false);
                setView('editor');
                setIsLoadingReleases(true);
                const pluginData = await getPlugin(id);
                setCurrentPlugin(pluginData);
            } catch (error) {
                showAlert(error.message, 'error');
            } finally {
                setIsLoadingReleases(false);
            }
        };

        const handleDelete = async (plugin) => {
            try {
                // Optimistic remove from list
                setPlugins(prev => prev.filter(p => p.id !== plugin.id));
                await deletePlugin(plugin.id);
                // If the deleted plugin is currently open, close editor
                setCurrentPlugin(prev => prev && prev.id === plugin.id ? null : prev);
                setView(prev => (prev === 'editor' && currentPlugin && currentPlugin.id === plugin.id) ? 'list' : prev);
                //showSuccessMessage(__('Plugin deleted successfully.', 'publisher'));
            } catch (error) {
                showAlert(error.message, 'error');
                // reload list to ensure consistency on error
                loadPlugins(true);
            }
        };

        

        const handleCancel = () => {
            setView('list');
            setCurrentPlugin(null);
            setIsNew(false);
        };

        const updatePluginData = (updates) => {
            setCurrentPlugin(prev => ({ ...prev, ...updates }));
        };

        const updatePluginJson = (path, value) => {
            setCurrentPlugin(prev => {
                const newData = deepClone(prev);
                const keys = path.split('.');
                let current = newData.theme_json;
                
                // Navigate to the parent object
                for (let i = 0; i < keys.length - 1; i++) {
                    if (!current[keys[i]]) {
                        current[keys[i]] = {};
                    }
                    current = current[keys[i]];
                }
                
                const lastKey = keys[keys.length - 1];
                
                // Check if value should be removed (empty, null, undefined, empty array, empty object)
                const isEmptyValue = value === '' || 
                                   value === null || 
                                   value === undefined || 
                                   (Array.isArray(value) && value.length === 0) ||
                                   (typeof value === 'object' && value !== null && Object.keys(value).length === 0);
                
                if (isEmptyValue) {
                    // Remove the property if it exists
                    if (current.hasOwnProperty(lastKey)) {
                        delete current[lastKey];
                    }
                    
                    // Clean up empty parent objects
                    let parent = newData.theme_json;
                    for (let i = 0; i < keys.length - 1; i++) {
                        const key = keys[i];
                        if (parent[key] && Object.keys(parent[key]).length === 0) {
                            delete parent[key];
                        }
                        parent = parent[key];
                    }
                } else {
                    // Set the value normally
                    current[lastKey] = value;
                }
                
                return newData;
            });
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
                        __('Publisher', 'publisher'),
                        ' - ',
                        __('Add New Plugin', 'publisher')
                    ),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isSecondary: true,
                            onClick: handleCancel,
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Cancel', 'publisher')),
                    )
                );
            }
            else if (view === 'editor') {
                return createElement('div', { className: 'pblsh--header' },
                    createElement('h2', { className: 'pblsh--header__title' }, __('Publisher', 'publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: handleAddNewPlugin,
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Add New Plugin', 'publisher')),
                        createElement(Button, {
                            isTertiary: true,
                            onClick: openSettings,
                            label: __('Settings', 'publisher'),
                            icon: Pblsh.Utils.getSvgIcon('cog', { size: 24 }),
                            __next40pxDefaultSize: true,
                        })
                    )
                );
            } else {
                return createElement('div', { className: 'pblsh--header' },
                    createElement('h2', { className: 'pblsh--header__title' }, __('Publisher', 'publisher')),
                    createElement('div', { className: 'pblsh--header__actions' },
                        createElement(Button, {
                            isPrimary: true,
                            onClick: handleAddNewPlugin,
                            disabled: isLoading,
                            __next40pxDefaultSize: true,
                        }, __('Add New Plugin', 'publisher')),
                        createElement(Button, {
                            isTertiary: true,
                            onClick: openSettings,
                            label: __('Settings', 'publisher'),
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
                setIsLoading(true);
                const pluginData = await getPlugin(pluginId);
                loadPlugins(true);
                setCurrentPlugin(pluginData);
                setIsNew(false);
                setView('editor');
            } catch (error) {
                showAlert(error.message, 'error');
            } finally {
                setIsLoading(false);
            }
        };

        // Provide a stable refresh callback for children
        const refreshCurrentPlugin = wp.element.useCallback(async () => {
            try {
                if (!currentPlugin || !currentPlugin.id) return;
                setIsLoading(true);
                const pluginData = await getPlugin(currentPlugin.id);
                setCurrentPlugin(pluginData);
                await loadPlugins(true);
            } catch (error) {
                showAlert(error.message, 'error');
            } finally {
                setIsLoading(false);
            }
        }, [currentPlugin && currentPlugin.id]);

        const renderMainContent = () => {
            if (view === 'addition-process') {
                return createElement(PluginAdditionProcess, {
                    onCreated: handleCreated,
                });
            }
            else if (view === 'editor') {
                return createElement(PluginEditor, {
                    pluginData: currentPlugin,
                    updatePluginData,
                    updatePluginJson,
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
        render(createElement(PublisherApp), container);
    }
}); 