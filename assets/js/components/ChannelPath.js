// ChannelPath Component - the publish path in short: channel icon + slug. One
// recognizable pairing wherever a plugin's home is named (plugin list, editor
// header, wporg import). The line under a plugin name shows what identifies
// and is not redundant in its context — where the channel is a given (the
// wporg import), pass no channel and only the slug renders. The icon carries
// the channel's name for hover and screen readers.
const ChannelPath = ({ channel = '', slug = '' } = {}) => {
    const { createElement } = wp.element;
    const { Tooltip } = wp.components;
    const { getSvgIcon, getChannelIcon, getChannelLabel } = Pblsh.Utils;

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
    );
};

lodash.set(window, 'Pblsh.Components.ChannelPath', ChannelPath);
