// PluginList Component
lodash.set(window, 'Pblsh.Components.PluginList', ({ plugins, onEdit, onDelete, onExport, onCreateNew, onToggleStatus, pendingPluginStatus }) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;
    const { Button, DropdownMenu, MenuItem, Icon, Tooltip } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    //const { exportPlugin } = Pblsh.API;

    const handleDelete = async (plugin) => {
        if (!confirm(__('Are you sure you want to permanently delete this plugin?', 'peak-publisher'))) {
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

    return createElement('div', { className: 'pblsh--list' },
        plugins.length === 0 
            ? createElement('p', { className: 'pblsh--no-plugins' }, __('No plugins created yet.', 'peak-publisher'))
            : createElement('div', { className: 'pblsh--table-container' },
                createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--table__status-header' }, __('Status', 'peak-publisher')),
                            //createElement('th', { className: 'pblsh--table__icon-header' }, __('Icon', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__name-header' }, __('Plugin Name', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__slug-header' }, __('Slug', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Latest Version', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher'))
                        )
                    ),
                    createElement('tbody', null,
                        plugins.map(plugin => 
                            createElement('tr', { key: plugin.slug, className: 'pblsh--row' },
                                createElement('td', { className: 'pblsh--table__status-cell' },
                                    createElement(wp.components.Button, {
                                        isTertiary: true,
                                        className: 'pblsh--status-btn ' + (plugin.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                        label: plugin.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher'),
                                        icon: Pblsh.Utils.getSvgIcon('circle'),
                                        isBusy: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(plugin.id),
                                        disabled: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(plugin.id),
                                        onClick: () => {
                                            const next = plugin.status === 'publish' ? 'draft' : 'publish';
                                            if (typeof onToggleStatus === 'function') onToggleStatus(plugin.id, next);
                                        },
                                    })
                                ),
                                /* createElement('td', { className: 'pblsh--table__icon-cell' },
                                    plugin.icon 
                                        ? createElement('img', { 
                                            src: plugin.icon, 
                                            alt: plugin.name,
                                            className: 'pblsh--table__icon-thumbnail',
                                            width: 80,
                                            height: 60
                                        })
                                        : createElement('div', { className: 'pblsh--table__no-icon' },
                                            getSvgIcon('image')
                                        )
                                ), */
                                createElement('td', { className: 'pblsh--table__name-cell' },
                                    createElement('strong', null, plugin.name)
                                ),
                                createElement('td', { className: 'pblsh--table__slug-cell' },
                                    createElement(Tooltip, { text: plugin.slug },
                                        createElement('code', null, plugin.slug)
                                    ),
                                ),
                                createElement('td', { className: 'pblsh--table__version-cell' },
                                    plugin.version
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
                                                    __('Delete permanently', 'peak-publisher')
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