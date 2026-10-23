// PluginList Component
lodash.set(window, 'Pblsh.Components.PluginList', ({ plugins, onEdit, onDelete, onExport, onCreateNew }) => {
    const { __, _n, sprintf } = wp.i18n;
    const { createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, DropdownMenu, MenuItem, Tooltip } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    const { ChannelPath, CurrentVersion, InstallationsCount, WporgCheckStatus, RelativeTime } = Pblsh.Components;
    const { getCheck } = Pblsh.WporgCheckUtils;
    const { getPluginStatusItems } = Pblsh.PluginStatusUtils;
    const stableBranch = Pblsh.Utils.getStableBranch();
    //const { exportPlugin } = Pblsh.API;

    const handleDelete = async (plugin) => {
        let message = plugin && plugin.hosting_type === 'wporg'
            ? __('Remove this wordpress.org plugin from Peak Publisher? The plugin on wordpress.org and its SVN repository will remain untouched.', 'peak-publisher')
            : __('Are you sure you want to permanently delete this plugin?', 'peak-publisher');
        // The working copy lives only here — removing the plugin drops it.
        if (plugin && plugin.assets_pending > 0) {
            message += '\n\n' + sprintf(_n('%d asset change that is not on wordpress.org yet will be lost.', '%d asset changes that are not on wordpress.org yet will be lost.', plugin.assets_pending, 'peak-publisher'), plugin.assets_pending);
        }

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

    const hasLoadedList = useSelect((select) => {
        try { return !!select('pblsh/plugins').hasLoadedList(); } catch (e) { return false; }
    }, []);
    // The column shows whenever any row has a figure to show — self-hosted with counting
    // on, wordpress.org always (its figure is public); the setting is not read here.
    const showInstallations = plugins.some((plugin) => plugin.installations.state !== 'disabled');
    const wporgPlugins = plugins.filter((plugin) => plugin.hosting_type === 'wporg');
    // The list says nothing about its checks while they pass — its rows are at most one
    // interval old, and a plugin's own status line is in its editor. A check that failed is
    // said here: the rows would age without a word.
    const checkFailed = wporgPlugins.length > 0 && !!getCheck(wporgPlugins).error;

    // The status column: what needs attention, one item per line in the editor's notice
    // vocabulary (utils-plugin-status.js) — a mark, not a control: the remedies live in the
    // editor. The whole sentence is the tooltip, reachable by keyboard like the row's other
    // tooltips; a check says all clear, so the column is never blank.
    // The glyph per variant: a problem warns or informs; a state (closed, draft) shows the
    // editor status button's dot.
    const statusGlyphs = { warning: 'alert', info: 'information_outline', closed: 'circle', draft: 'circle' };
    const renderStatusCell = (plugin) => {
        const items = getPluginStatusItems(plugin, stableBranch);
        if (items.length === 0) {
            const allClear = __('All clear', 'peak-publisher');
            return createElement(Tooltip, { text: allClear },
                createElement('span', { className: 'pblsh--status-item pblsh--status-item--ok', tabIndex: 0, role: 'img', 'aria-label': allClear },
                    createElement('span', { className: 'pblsh--status-item__glyph' }, getSvgIcon('check_bold', { size: 16 })),
                ),
            );
        }
        return createElement('span', { className: 'pblsh--status-items' },
            ...items.map((item) => createElement(Tooltip, { key: item.key, text: item.text },
                createElement('span', { className: 'pblsh--status-item pblsh--status-item--' + item.variant, tabIndex: 0 },
                    createElement('span', { className: 'pblsh--status-item__glyph' }, getSvgIcon(statusGlyphs[item.variant] || 'information_outline', { size: 16 })),
                    createElement('span', { className: 'pblsh--status-item__label' }, item.label),
                ),
            )),
        );
    };

    return createElement('div', { className: 'pblsh--list' },
        !hasLoadedList
            ? createElement('div', { className: 'pblsh--loading' },
                createElement('div', { className: 'pblsh--loading__spinner' })
            )
            :
        plugins.length === 0 
            ? createElement('p', { className: 'pblsh--no-plugins' }, __('No plugins created yet.', 'peak-publisher'))
            : createElement(wp.element.Fragment, null,
              checkFailed && createElement('div', { className: 'pblsh--list__check' },
                createElement(WporgCheckStatus, { plugins: wporgPlugins }),
              ),
              createElement('div', { className: 'pblsh--table-container' },
                createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            // The column's head carries the count, like the releases tab in the editor.
                            createElement('th', { className: 'pblsh--table__plugin-header' }, sprintf(
                                /* translators: %s: number of plugins */
                                __('Plugins (%s)', 'peak-publisher'),
                                plugins.length.toLocaleString(),
                            )),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Version', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__released-header' }, __('Released', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__status-header' }, __('Status', 'peak-publisher')),
                            showInstallations && createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher'))
                        )
                    ),
                    createElement('tbody', null,
                        plugins.map(plugin => 
                            createElement('tr', { key: plugin.id, className: 'pblsh--row' },
                                // Icon and name block in one cell — the icon is always served (the
                                // directory icon or the generated fallback).
                                createElement('td', { className: 'pblsh--table__plugin-cell' },
                                    createElement('div', { className: 'pblsh--table__plugin' },
                                        createElement('img', {
                                            src: plugin.icon_url,
                                            alt: '',
                                            className: 'pblsh--table__icon',
                                            width: 48,
                                            height: 48,
                                        }),
                                        createElement('div', { className: 'pblsh--table__name-content' },
                                            createElement('div', { className: 'pblsh--table__name-line' },
                                                createElement('strong', null, plugin.name),
                                            ),
                                            createElement(ChannelPath, { channel: plugin.hosting_type, slug: plugin.slug }),
                                        ),
                                    ),
                                ),
                                createElement('td', { className: 'pblsh--table__version-cell' },
                                    createElement(CurrentVersion, { plugin, glyph: false })
                                ),
                                // When the current release was published; nothing to date without one.
                                createElement('td', { className: 'pblsh--table__released-cell' },
                                    plugin.released_at ? createElement(RelativeTime, { value: plugin.released_at, tooltip: true }) : '—'
                                ),
                                createElement('td', { className: 'pblsh--table__status-cell' }, renderStatusCell(plugin)),
                                showInstallations && createElement('td', { className: 'pblsh--table__installations-cell' },
                                    createElement(InstallationsCount, { plugin })
                                ),
                                createElement('td', { className: 'pblsh--table__actions-cell' },
                                    createElement('div', { className: 'pblsh--table__actions' },
                                        createElement(Button, {
                                            isTertiary: true,
                                            onClick: () => onEdit(plugin.id),
                                            label: __('Edit', 'peak-publisher'),
                                            icon: getSvgIcon('pencil', { size: 24 })
                                        }),
                                        // The row's menu holds the removal alone — not for the plugin running this site, which has no menu then.
                                        plugin.slug !== PblshData.currentPlugin && createElement(DropdownMenu, {
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
                                                createElement(MenuItem, {
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
              ),
            )
    );
}); 
