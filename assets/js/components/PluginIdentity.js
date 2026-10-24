// PluginIdentity Component - a plugin, or a theme, as a row names it: its image — a plugin's
// icon, a theme's screenshot (`kind`) —, its name, its slug beneath (ChannelPath). The one
// anatomy wherever plugins and themes stand in rows — the wporg import table, the Live
// Preview's plugins and theme —, so they read alike. Without an image a plugin gets the slug's
// geopattern, like the directory's own fallback, a theme an empty frame; without a name the
// slug names it. With `href` the block links to the page there — quiet by default, the
// affordance (underline, external marker) appears on hover and keyboard focus. `channel`
// reaches ChannelPath; none where the channel is a given, as in every row today.
const PluginIdentity = ({ kind = 'plugin', image = null, name = '', slug = '', href = null, channel = '' } = {}) => {
    const { createElement } = wp.element;
    const { getGeopatternIconUrl, getSvgIcon } = Pblsh.Utils;
    const { ChannelPath } = Pblsh.Components;

    const src = image || (kind === 'plugin' ? getGeopatternIconUrl(slug) : null);
    const imageClass = 'pblsh--plugin-identity__image pblsh--plugin-identity__image--' + kind;
    // The name's last word carries the marker in an unbreakable tail, so the marker never
    // starts a line of its own.
    const words = (name || slug).split(' ');
    const last = words.pop();
    const parts = [
        src
            ? createElement('img', { key: 'image', src, alt: '', className: imageClass, width: kind === 'theme' ? 64 : 48, height: 48 })
            : createElement('span', { key: 'image', className: imageClass, 'aria-hidden': 'true' }),
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
