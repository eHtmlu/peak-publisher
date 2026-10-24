// ChannelChoiceCards Component - the app's distribution-channel choice: two
// prominent cards, each with an identity header (icon, label, description), a
// fact list comparing the same dimensions on both sides, a "Best for" audience
// line, and a choose button; the FAQ pointers sit below the pair. Used by
// the upload overlay's channel screen and the add-new flow's channel step, so
// the choice looks and reads identically wherever it appears. Label and
// description come from the static PblshData.channelTexts config (authoritative
// source: get_channel_texts()).

// The app's two channels, wporg before self_hosted — mirrors the server's
// target insertion order (the single display-order authority).
const CHANNEL_KEYS = ['wporg', 'self_hosted'];

const ChannelChoiceCards = ({ onSelect = () => {} } = {}) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement } = wp.element;
    const { Button } = wp.components;
    const { getSvgIcon, getChannelLabel, getChannelDescription, getChannelIcon } = Pblsh.Utils;
    const { FaqLink } = Pblsh.Components;

    // The comparison dimensions — label and icon per dimension, shared by both
    // cards so the rows pair up visually. The icons mark the dimension, not a
    // verdict: the facts stay neutral, valence is the reader's call.
    const FACT_DIMENSIONS = [
        { key: 'golive', label: __('Go-live', 'peak-publisher'), icon: 'rocket_launch' },
        { key: 'access', label: __('Access', 'peak-publisher'), icon: 'account_group' },
        { key: 'install', label: __('Install', 'peak-publisher'), icon: 'download' },
        { key: 'updates', label: __('Updates', 'peak-publisher'), icon: 'update' },
        { key: 'license', label: __('License', 'peak-publisher'), icon: 'scale_balance' },
    ];

    // Per-channel content: the audience line and the fact values per dimension.
    const channelContent = {
        wporg: {
            bestFor: createInterpolateElement(
                __('Only for <strong>free, open-source plugins</strong> meant for everyone.', 'peak-publisher'),
                { strong: createElement('strong') },
            ),
            facts: {
                golive: __('Approval required', 'peak-publisher'),
                install: __('Via the plugin search in their WP admin panel', 'peak-publisher'),
                updates: __('As usual — delivered by wordpress.org', 'peak-publisher'),
                license: __('GPL-compatible required', 'peak-publisher'),
                access: __('Officially listed on wordpress.org', 'peak-publisher'),
            },
        },
        self_hosted: {
            // 'premium' deliberately absent from the audience until license
            // management exists.
            bestFor: createInterpolateElement(
                __('Best for <strong>client-specific or internal plugins</strong> you control yourself.', 'peak-publisher'),
                { strong: createElement('strong') },
            ),
            facts: {
                golive: __('Instantly', 'peak-publisher'),
                install: __('Via ZIP upload in their WP admin panel', 'peak-publisher'),
                updates: __('As usual — delivered by this site', 'peak-publisher'),
                license: __('Any', 'peak-publisher'),
                access: __('Unlisted but accessible by default, restrict via whitelist', 'peak-publisher'),
            },
        },
    };

    return createElement('div', { className: 'pblsh--channel-choice' },
        createElement('div', { className: 'pblsh--choice-intro' },
            createElement('h3', { className: 'pblsh--choice-intro__prompt' },
                __('Choose the distribution channel', 'peak-publisher')),
            // The stake of this choice, spelled out for first-timers.
            createElement('p', { className: 'pblsh--choice-intro__subtitle' },
                __('This determines where your users get the plugin and its updates.', 'peak-publisher')),
        ),
        createElement('div', { className: 'pblsh--channel-choice__cards' },
            CHANNEL_KEYS.map((channelKey) => createElement('div', {
                key: channelKey,
                className: 'pblsh--channel-choice__option',
            },
                createElement('div', { className: 'pblsh--channel-choice__header' },
                    createElement('span', { className: 'pblsh--channel-choice__icon', 'aria-hidden': 'true' },
                        getSvgIcon(getChannelIcon(channelKey), { size: 40 })),
                    createElement('div', { className: 'pblsh--channel-choice__header-text' },
                        createElement('h4', { className: 'pblsh--channel-choice__label' }, getChannelLabel(channelKey)),
                        createElement('p', { className: 'pblsh--channel-choice__desc' }, getChannelDescription(channelKey)),
                    ),
                ),
                createElement('ul', { className: 'pblsh--channel-choice__facts' },
                    FACT_DIMENSIONS.map((dimension) => createElement('li', {
                        key: dimension.key,
                        className: 'pblsh--channel-choice__fact',
                    },
                        createElement('span', { className: 'pblsh--channel-choice__fact-icon', 'aria-hidden': 'true' },
                            getSvgIcon(dimension.icon, { size: 16 })),
                        createElement('span', { className: 'pblsh--channel-choice__fact-text' },
                            createElement('strong', { className: 'pblsh--channel-choice__fact-label' }, dimension.label),
                            ': ',
                            channelContent[channelKey].facts[dimension.key],
                        ),
                    )),
                ),
                createElement('p', { className: 'pblsh--channel-choice__best-for' }, channelContent[channelKey].bestFor),
                createElement(Button, {
                    className: 'pblsh--channel-choice__select',
                    isPrimary: true,
                    onClick: () => onSelect(channelKey),
                    __next40pxDefaultSize: true,
                }, sprintf(__('Choose %s', 'peak-publisher'), getChannelLabel(channelKey))),
            )),
        ),
        createElement('div', { className: 'pblsh--help-links pblsh--channel-choice__faq' },
            createElement(FaqLink, { faqKey: 'bothChannels' }, __('Can I use both channels for one plugin?', 'peak-publisher')),
            createElement(FaqLink, { faqKey: 'switchLater' }, __('Can I switch the channel later?', 'peak-publisher')),
        ),
    );
};

lodash.set(window, 'Pblsh.Components.ChannelChoiceCards', ChannelChoiceCards);
