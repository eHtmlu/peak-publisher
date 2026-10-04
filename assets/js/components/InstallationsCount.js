// InstallationsCount Component - a plugin's installations figure as the list cell and
// the editor header show it: self-hosted the exact 24-hour count, wordpress.org the
// rounded public figure, or "—" when there is none (closed, not listed or not
// fetched yet), with the explanation in the tooltip. While a plugin's first fetch runs,
// a spinner stands in. One rendering for both surfaces; wording from
// Pblsh.InstallationsUtils.
const InstallationsCount = ({ plugin }) => {
    const { createElement } = wp.element;
    const { useSelect } = wp.data;
    const { Spinner } = wp.components;
    const { __ } = wp.i18n;
    const { getInstallationsCell } = Pblsh.InstallationsUtils;
    const { Figure } = Pblsh.Components;

    const refreshing = useSelect((select) => select('pblsh/plugins').isRefreshingWporg(), []);
    if (refreshing && plugin.hosting_type === 'wporg' && plugin.installations.state === 'never') {
        return createElement(Figure, { title: __('Fetching from wordpress.org…', 'peak-publisher') },
            createElement(Spinner, { className: 'pblsh--figure__spinner' }),
        );
    }
    const cell = getInstallationsCell(plugin);
    return createElement(Figure, { title: cell.title }, cell.text);
};

lodash.set(window, 'Pblsh.Components.InstallationsCount', InstallationsCount);
