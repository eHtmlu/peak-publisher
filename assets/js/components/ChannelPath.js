// ChannelPath Component - the publish path in short: channel icon + slug. One
// recognizable pairing wherever a plugin's home is named (plugin list, editor
// header, wporg import). The line under a plugin name shows what identifies
// and is not redundant in its context — where the channel is a given (the
// wporg import), pass no channel and only the slug renders. The icon carries
// the channel's name for hover and screen readers. The line is the plugin's
// address on its channel; where the channel has a page for the plugin —
// wordpress.org — the way there stands at the line's end, an arrow that names
// the channel for hover and screen readers.
const ChannelPath = ({ channel = '', slug = '' } = {}) => {
    const { createElement } = wp.element;
    const { Tooltip } = wp.components;
    const { __, sprintf } = wp.i18n;
    const { getSvgIcon, getChannelIcon, getChannelLabel, getWporgPluginUrl } = Pblsh.Utils;

    // The plugin's page on its channel: wordpress.org has one, self-hosted none.
    const url = channel === 'wporg' && slug ? getWporgPluginUrl(slug) : '';
    /* translators: %s: the channel's name, e.g. wordpress.org */
    const linkLabel = url ? sprintf(__('View on %s', 'peak-publisher'), getChannelLabel(channel)) : '';
    return createElement('span', { className: 'pblsh--channel-path' },
        channel && createElement(Tooltip, { text: getChannelLabel(channel) },
            createElement('span', {
                className: 'pblsh--channel-path__icon',
                role: 'img',
                'aria-label': getChannelLabel(channel),
            }, getSvgIcon(getChannelIcon(channel), { size: 14 })),
        ),
        createElement(Tooltip, { text: slug },
            createElement('code', null, slug),
        ),
        url && createElement(Tooltip, { text: linkLabel },
            createElement('a', {
                className: 'pblsh--channel-path__link',
                href: url,
                target: '_blank',
                rel: 'noreferrer',
                'aria-label': linkLabel,
            }, getSvgIcon('open_in_new', { size: 14 })),
        ),
    );
};

lodash.set(window, 'Pblsh.Components.ChannelPath', ChannelPath);
