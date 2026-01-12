// PluginEditor Component (simplified overview + releases list)
lodash.set(window, 'Pblsh.Components.PluginEditor', ({ pluginData, refreshPlugin, onToggleReleaseStatus, pendingReleaseIds, onTogglePluginStatus, pendingPluginStatus, isLoadingReleases, onBack }) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Tooltip, Button } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;

    const safe = (val) => (val === undefined || val === null) ? '' : val;

    // Prefer releases from store (keeps UI in sync on toggles), fallback to pluginData.releases
    const releasesFromStore = useSelect(
        (select) => {
            const pid = pluginData && pluginData.id ? pluginData.id : null;
            if (!pid) return [];
            return select('pblsh/releases').getForPlugin(pid);
        },
        [pluginData && pluginData.id]
    );

    const renderInfoBox = () => {
        return [
                createElement('div', { className: 'pblsh--card pblsh--card--plugin-info' },
                createElement('div', { className: 'pblsh--plugin-info__row' },
                    createElement('div', { className: 'pblsh--plugin-info__left' },
                        createElement(Button, {
                            isTertiary: true,
                            className: 'has-icon',
                            label: __('Back to list', 'peak-publisher'),
                            icon: getSvgIcon('arrow_back', { size: 24 }),
                            onClick: () => { if (typeof onBack === 'function') onBack(); },
                        })
                    ),
                    createElement('div', { className: 'pblsh--plugin-info__right' },
                        createElement('div', { className: 'pblsh--plugin-header' },
                            createElement('div', { className: 'pblsh--plugin-header__main' },
                                createElement('h3', { className: 'pblsh--plugin-title' }, pluginData?.name),
                                createElement('div', { className: 'pblsh--plugin-meta' },
                                    createElement('strong', null, __('Slug', 'peak-publisher')),
                                    createElement('code', null, safe(pluginData?.slug) || '—'),
                                ),
                            ),
                            createElement('div', { className: 'pblsh--plugin-header__actions' },
                                createElement(Button, {
                                    isTertiary: true,
                                    className: 'pblsh--status-btn ' + (pluginData?.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                    label: pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher'),
                                    icon: getSvgIcon('circle'),
                                    isBusy: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id),
                                    disabled: Array.isArray(pendingPluginStatus) && pendingPluginStatus.includes(pluginData?.id),
                                    onClick: () => {
                                        if (typeof onTogglePluginStatus === 'function' && pluginData?.id) {
                                            const next = pluginData.status === 'publish' ? 'draft' : 'publish';
                                            onTogglePluginStatus(pluginData.id, next);
                                        }
                                    },
                                }, (pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Draft', 'peak-publisher')))
                            ),
                        ),
                        createElement('div', { className: 'pblsh--plugin-grid' },
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Releases', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, String((pluginData?.releases || []).length))
                            ),
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Latest Release', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, safe(pluginData?.version) || '—')
                            ),
                            createElement('div', { className: 'pblsh--plugin-grid__item' },
                                createElement('div', { className: 'pblsh--plugin-grid__label' }, __('Installations', 'peak-publisher')),
                                createElement('div', { className: 'pblsh--plugin-grid__value' }, String(pluginData?.installations_count || 0))
                            ),
                        )
                    )
                )
            ),
        ];
    };

    const renderReleasesTable = () => {
        const releases = (Array.isArray(releasesFromStore) && releasesFromStore.length > 0)
            ? releasesFromStore
            : (Array.isArray(pluginData?.releases) ? pluginData.releases : []);
        return [
            createElement('div', { className: 'pblsh--table-container' },
                isLoadingReleases ?
                    createElement('div', { className: 'pblsh--loading pblsh--loading--table' },
                        createElement('div', { className: 'pblsh--loading__spinner' })
                    )
                : createElement('table', { className: 'pblsh--table' },
                    createElement('thead', null,
                        createElement('tr', null,
                            createElement('th', { className: 'pblsh--table__status-header' }, __('Status', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__version-header' }, __('Version', 'peak-publisher')),
                            createElement('th', null, __('Date', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__installations-header' }, __('Installations', 'peak-publisher')),
                            createElement('th', { className: 'pblsh--table__actions-header' }, __('Actions', 'peak-publisher')),
                        ),
                    ),
                    createElement('tbody', null,
                        releases.length === 0
                            ? createElement('tr', null,
                                createElement('td', { colSpan: 5 }, __('No releases yet.', 'peak-publisher')),
                            )
                            : releases.map((rel) =>
                                createElement('tr', { key: String(rel.id) },
                                    createElement('td', { className: 'pblsh--table__status-cell' },
                                        createElement(wp.components.Button, {
                                            isTertiary: true,
                                            className: 'pblsh--status-btn ' + (rel.status === 'publish' ? 'pblsh--status-btn--public' : 'pblsh--status-btn--draft'),
                                            label: rel.status === 'publish' ? (pluginData?.status === 'publish' ? __('Public', 'peak-publisher') : __('Public if plugin is public', 'peak-publisher')) : __('Draft', 'peak-publisher'),
                                            icon: Pblsh.Utils.getSvgIcon('circle'),
                                            isBusy: Array.isArray(pendingReleaseIds) && pendingReleaseIds.includes(rel.id),
                                            disabled: Array.isArray(pendingReleaseIds) && pendingReleaseIds.includes(rel.id),
                                            style: {
                                                opacity: pluginData?.status === 'publish' ? 1 : 0.5,
                                            },
                                            onClick: () => {
                                                const next = rel.status === 'publish' ? 'draft' : 'publish';
                                                if (typeof onToggleReleaseStatus === 'function') onToggleReleaseStatus(rel.id, next);
                                            },
                                        })
                                    ),
                                    createElement('td', { className: 'pblsh--table__version-cell' }, safe(rel.version)),
                                    createElement('td', null, safe(rel.date)),
                                    createElement('td', { className: 'pblsh--table__installations-cell' }, String(rel.installations_count || 0)),
                                    createElement('td', { className: 'pblsh--table__actions-cell' },
                                        createElement('div', { className: 'pblsh--table__actions' },
                                            (() => {
                                                const base = rel.download_url || '';
                                                const href = base ? base + (base.indexOf('?') >= 0 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(window.wpApiSettings.nonce) : '';
                                                return createElement(Tooltip, { text: __('Download', 'peak-publisher') },
                                                    createElement('a', {
                                                        className: 'components-button has-icon is-tertiary',
                                                        href: href || undefined,
                                                        rel: 'noopener noreferrer',
                                                        'aria-label': __('Download', 'peak-publisher'),
                                                        onClick: (e) => { if (!href) { e.preventDefault(); alert(__('No download available for this release.', 'peak-publisher')); } },
                                                    },
                                                        Pblsh.Utils.getSvgIcon('download', { size: 24 }),
                                                    ),
                                                );
                                            })(),
                                            createElement(wp.components.DropdownMenu, {
                                                icon: Pblsh.Utils.getSvgIcon('dots_horizontal', { size: 24 }),
                                                label: __('More options', 'peak-publisher'),
                                                children: ({ onClose }) => [
                                                    createElement(wp.components.MenuItem, {
                                                        key: 'delete',
                                                        isDestructive: true,
                                                        onClick: async () => {
                                                            try {
                                                                if (!confirm(__('Are you sure you want to permanently delete this release?', 'peak-publisher'))) { onClose(); return; }
                                                                await Pblsh.API.deleteRelease(rel.id);
                                                                onClose();
                                                                if (typeof refreshPlugin === 'function') {
                                                                    refreshPlugin();
                                                                }
                                                            } catch (e) {
                                                                alert(e?.message || 'Error deleting release');
                                                            }
                                                        },
                                                    },
                                                        Pblsh.Utils.getSvgIcon('delete_forever', { size: 24 }),
                                                        __('Delete permanently', 'peak-publisher')
                                                    ),
                                                ],
                                            })
                                        ),
                                    ),
                                ),
                            ),
                    ),
                ),
            ),
        ];
    };

    return createElement('div', { className: 'pblsh--editor' },
        createElement('div', { className: 'pblsh--editor__content' },
            createElement('div', { className: 'pblsh--main' },
                createElement('div', { className: 'pblsh--main__inner' },
                    createElement('div', { className: 'pblsh--main__content' },
                        renderInfoBox(),
                        renderReleasesTable(),
                    ),
                ),
            ),
        ),
    );
});