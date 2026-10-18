// PluginIdentity Component - a plugin as a row names it: its icon, its name, its slug beneath
// (ChannelPath). The one anatomy wherever plugins stand in rows — the wporg import table, the
// Live Preview's plugins —, so they read alike. Without an icon the slug's geopattern stands
// in, like the directory's own fallback; without a name the slug names it. With `href` the
// block links to the plugin's page there — quiet by default, the affordance (underline,
// external marker) appears on hover and keyboard focus. `channel` reaches ChannelPath; none
// where the channel is a given, as in both rows today.
const PluginIdentity = ({ icon = null, name = '', slug = '', href = null, channel = '' } = {}) => {
    const { createElement } = wp.element;
    const { getGeopatternIconUrl, getSvgIcon } = Pblsh.Utils;
    const { ChannelPath } = Pblsh.Components;

    // The name's last word carries the marker in an unbreakable tail, so the marker never
    // starts a line of its own.
    const words = (name || slug).split(' ');
    const last = words.pop();
    const parts = [
        createElement('img', { key: 'icon', src: icon || getGeopatternIconUrl(slug), alt: '', className: 'pblsh--plugin-identity__icon', width: 48, height: 48 }),
        createElement('div', { key: 'text', className: 'pblsh--plugin-identity__text' },
            createElement('strong', { className: 'pblsh--plugin-identity__name' },
                words.length > 0 ? words.join(' ') + ' ' : '',
                createElement('span', { className: 'pblsh--plugin-identity__tail' },
                    last,
                    href ? createElement('span', { className: 'pblsh--plugin-identity__external', 'aria-hidden': 'true' }, getSvgIcon('open_in_new', { size: 14 })) : null,
                ),
            ),
            createElement(ChannelPath, { channel, slug }),
        ),
    ];
    return href
        ? createElement('a', { className: 'pblsh--plugin-identity', href, target: '_blank', rel: 'noreferrer' }, ...parts)
        : createElement('div', { className: 'pblsh--plugin-identity' }, ...parts);
};

lodash.set(window, 'Pblsh.Components.PluginIdentity', PluginIdentity);
