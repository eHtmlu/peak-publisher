// PluginList Component
lodash.set(window, 'Pblsh.Components.PluginList', ({ plugins, onEdit, onDelete, onExport, onCreateNew, onToggleStatus, pendingPluginStatus }) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, DropdownMenu, MenuItem, Icon, Tooltip } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    //const { exportPlugin } = Pblsh.API;

    const handleDelete = async (plugin) => {
        const message = plugin && plugin.hosting_type === 'wporg'
            ? __('Remove this wordpress.org plugin from Peak Publisher? The plugin on wordpress.org and its SVN repository will remain untouched.', 'peak-publisher')
            : __('Are you sure you want to permanently delete this plugin?', 'peak-publisher');

        if (!confirm(message)) {
            return;
        }

        await onDelete(plugin);
    };

    const handleExport = async (plugin) => {
        try {
            //await exportPlugin(plugin.slug);
        } catch (error) {
            showAlert(error.message, 'error');
        }
    };

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const hasLoadedList = useSelect((select) => {
        try { return !!select('pblsh/plugins').hasLoadedList(); } catch (e) { return false; }
    }, []);
    const showInstallations = !!(serverSettings && serverSettings.count_plugin_installations);
    const getHostingLabel = (plugin) => plugin && plugin.hosting_type === 'wporg'
        ? __('WordPress.org', 'peak-publisher')
        : __('Self-hosted', 'peak-publisher');
    const getHostingClass = (plugin) => plugin && plugin.hosting_type === 'wporg'
        ? 'pblsh--hosting-badge pblsh--hosting-badge--wporg'
        : 'pblsh--hosting-badge pblsh--hosting-badge--self-hosted';

    return createElement('div', { className: 'pblsh--list' },
        !hasLoadedList
            ? createElement('div', { className: 'pblsh--loading' },
                createElement('div', { className: 'pblsh--loading__spinner' })
            )
            :
        plugins.length === 0 
            ? createElement('p', { className: 'pblsh--no-plugins' }, __('No plugins created yet.', 'peak-publisher'))
            : createElement('div', { className: 'pblsh--table-container' },
                createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--table__status-header' }, __('Status', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__icon-header' }),
                            createElement('th', { className: 'pblsh--table__name-header' }, __('Plugin Name', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__slug-header' }, __('Slug', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Latest Version', 'peak-publisher')),
                            showInstallations && createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher'))
                        )
                    ),
                    createElement('tbody', null,
                        plugins.map(plugin => 
                            createElement('tr', { key: plugin.id, className: 'pblsh--row' },
                                createElement('td', { className: 'pblsh--table__status-cell' },
                                    createElement(wp.components.Button, {
                                        isTertiary: true,
                                        className: 'pblsh--status-btn ' + (plugin.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                        label: plugin.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher'),
                                        icon: Pblsh.Utils.getSvgIcon('circle'),
                                        isBusy: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(plugin.id),
                                        disabled: plugin.hosting_type === 'wporg' || (Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(plugin.id)),
                                        onClick: () => {
                                            if (plugin.hosting_type === 'wporg') return;
                                            const next = plugin.status === 'publish' ? 'draft' : 'publish';
                                            if (typeof onToggleStatus === 'function') onToggleStatus(plugin.id, next);
                                        },
                                    })
                                ),
                                createElement('td', { className: 'pblsh--table__icon-cell' },
                                    plugin.icon_url && createElement('img', {
                                        src: plugin.icon_url,
                                        alt: '',
                                        className: 'pblsh--table__icon',
                                        width: 48,
                                        height: 48,
                                    }),
                                ),
                                createElement('td', { className: 'pblsh--table__name-cell' },
                                    createElement('div', { className: 'pblsh--table__name-content' },
                                        createElement('strong', null, plugin.name),
                                        createElement('span', { className: getHostingClass(plugin) }, getHostingLabel(plugin))
                                    )
                                ),
                                createElement('td', { className: 'pblsh--table__slug-cell' },
                                    createElement(Tooltip, { text: plugin.slug },
                                        createElement('code', null, plugin.slug)
                                    ),
                                ),
                                createElement('td', { className: 'pblsh--table__version-cell' },
                                    plugin.version
                                ),
                                showInstallations && createElement('td', { className: 'pblsh--table__installations-cell' },
                                    String(plugin.installations_count || 0)
                                ),
                                createElement('td', { className: 'pblsh--table__actions-cell' },
                                    createElement('div', { className: 'pblsh--table__actions' },
                                        createElement(Button, {
                                            isTertiary: true,
                                            onClick: () => onEdit(plugin.id),
                                            label: __('Edit', 'peak-publisher'),
                                            icon: getSvgIcon('pencil', { size: 24 })
                                        }),
                                        createElement(DropdownMenu, {
                                            icon: getSvgIcon('dots_horizontal', { size: 24 }),
                                            label: __('More options', 'peak-publisher'),
                                            children: ({ onClose }) => [
                                                /* createElement(MenuItem, {
                                                    key: 'export',
                                                    onClick: () => { handleExport(plugin); onClose(); },
                                                    disabled: !PblshData.exportSupported
                                                },
                                                    getSvgIcon('download', { size: 24 }),
                                                    __('Download Installable', 'peak-publisher')
                                                ), */
                                                plugin.slug !== PblshData.currentPlugin && createElement(MenuItem, {
                                                    key: 'delete',
                                                    isDestructive: true,
                                                    onClick: () => { handleDelete(plugin); onClose(); }
                                                },
                                                    getSvgIcon('delete_forever', { size: 24 }),
                                                    plugin.hosting_type === 'wporg'
                                                        ? __('Remove from Peak Publisher', 'peak-publisher')
                                                        : __('Delete permanently', 'peak-publisher')
                                                )
                                            ].filter(Boolean)
                                        })
                                    )
                                )
                            )
                        )
                    )
                )
            )
    );
}); 
