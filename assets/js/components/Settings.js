// Settings Component
lodash.set(window, 'Pblsh.Components.Settings', ({ onClose, onOpenWporgImport } = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, createElement, createInterpolateElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, DropdownMenu, MenuItem, ToggleControl, TextControl, TextareaControl } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    const { WporgAccountForm, NoticeBox } = Pblsh.Components;
    const settingsController = window.Pblsh.Controllers.Settings;

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const loading = useSelect((select) => select('pblsh/settings').isLoading(), []);
    const saving = useSelect((select) => select('pblsh/settings').isSaving(), []);
    const [settings, setSettings] = useState({
        standalone_mode: false,
        auto_remove_workspace_artifacts: false,
        wordspace_artifacts_to_remove: [],
        readme_txt_convert_to_utf8_without_bom: false,
        ip_whitelist: [],
        count_plugin_installations: false,
        standalone_redirect_url: '',
    });
    const [currentSection, setCurrentSection] = useState('general');
    const [wporgAccountEditing, setWporgAccountEditing] = useState(false);
    // Avatar loading can fail (Gravatar blocked, offline admin) — an empty-avatar
    // placeholder (person icon in the avatar's round footprint) steps in then.
    const [wporgAvatarFailed, setWporgAvatarFailed] = useState(false);

    const storedAccount = (Array.isArray(serverSettings?.wporg_accounts) ? serverSettings.wporg_accounts : [])[0] || null;
    // A different stored account gets a fresh avatar attempt.
    useEffect(() => {
        setWporgAvatarFailed(false);
    }, [storedAccount?.username || '']);

    useEffect(() => {
        settingsController.fetch();
    }, []);

    useEffect(() => {
        if (serverSettings) {
            setSettings({
                standalone_mode: !!serverSettings.standalone_mode,
                auto_remove_workspace_artifacts: !!serverSettings.auto_remove_workspace_artifacts,
                readme_txt_convert_to_utf8_without_bom: !!serverSettings.readme_txt_convert_to_utf8_without_bom,
                wordspace_artifacts_to_remove: getTextareaFromList(Array.isArray(serverSettings.wordspace_artifacts_to_remove) ? serverSettings.wordspace_artifacts_to_remove : []),
                ip_whitelist: getTextareaFromList(Array.isArray(serverSettings.ip_whitelist) ? serverSettings.ip_whitelist : []),
                count_plugin_installations: !!serverSettings.count_plugin_installations,
                standalone_redirect_url: serverSettings.standalone_redirect_url || '',
            });
        }
    }, [serverSettings && JSON.stringify(serverSettings)]);

    const setField = (key, value) => {
        setSettings(prev => ({ ...prev, [key]: value }));
    };

    const normalizeListFromTextarea = (text) => {
        return String(text)
            .split('\n')
            .map(s => s.trim())
            .filter(s => s !== '');
    };

    const getTextareaFromList = (list) => {
        return (Array.isArray(list) ? list : []).join('\n');
    };

    const cloneServerSettings = () => {
        try {
            return JSON.parse(JSON.stringify(serverSettings || {}));
        } catch (e) {
            return {};
        }
    };

    const buildSavePayload = () => {
        // The account card saves itself through the shared form — this payload only
        // carries the plain settings and passes the stored (masked) accounts through.
        const payload = cloneServerSettings();
        delete payload.wporg_credentials;

        payload.standalone_mode = !!settings.standalone_mode;
        payload.auto_remove_workspace_artifacts = !!settings.auto_remove_workspace_artifacts;
        payload.readme_txt_convert_to_utf8_without_bom = !!settings.readme_txt_convert_to_utf8_without_bom;
        payload.wordspace_artifacts_to_remove = normalizeListFromTextarea(settings.wordspace_artifacts_to_remove);
        payload.ip_whitelist = normalizeListFromTextarea(settings.ip_whitelist);
        payload.count_plugin_installations = !!settings.count_plugin_installations;
        payload.standalone_redirect_url = settings.standalone_redirect_url || '';

        return payload;
    };

    const handleSave = async () => {
        try {
            const payload = buildSavePayload();
            await settingsController.save(payload);
            if (typeof onClose === 'function') onClose();
        } catch (e) {
            showAlert(e.message, 'error');
        }
    };

    const handleDisconnectWporgAccount = async () => {
        if (!confirm(__('Disconnect the wordpress.org account? The stored SVN credentials will be deleted — publishing to wordpress.org will require connecting the account again.', 'peak-publisher'))) {
            return;
        }
        try {
            const payload = cloneServerSettings();
            delete payload.wporg_credentials;
            payload.wporg_accounts = [];
            await settingsController.save(payload);
        } catch (e) {
            showAlert(e.message, 'error');
        }
    };

    if (loading) {
        return createElement('div', { className: 'pblsh--loading' },
            createElement('div', { className: 'pblsh--loading__spinner' }),
        );
    }
    const sections = [
        { id: 'general', title: __('General', 'peak-publisher'), icon: 'cog' },
        { id: 'analytics', title: __('Analytics', 'peak-publisher'), icon: 'chart_line' },
        { id: 'uploads', title: __('Uploads', 'peak-publisher'), icon: 'cloud_upload' },
        { id: 'security', title: __('Security', 'peak-publisher'), icon: 'security' },
        { id: 'wordpress-org', title: __('wordpress.org', 'peak-publisher'), icon: 'wordpress', separatorBefore: true },
    ];

    // Tests the stored (masked) credentials against wordpress.org — the endpoint
    // records the verdict on the account. Success feedback is the badge jumping
    // to a fresh "Verified" time after the reload; failures alert their message.
    const handleTestStoredCredentials = async (accountUsername) => {
        try {
            const result = await window.Pblsh.API.testSvnCredentials(accountUsername, Pblsh.Utils.WPORG_PASSWORD_MASKED);
            if (!result || result.status !== 'ok') {
                showAlert(__('Connection test failed.', 'peak-publisher'), 'error');
            }
        } catch (e) {
            showAlert(e && e.message ? e.message : __('Connection test failed.', 'peak-publisher'), 'error');
        } finally {
            // Reload the masked accounts so verified_at / rejection state update.
            await settingsController.fetch();
        }
    };

    // The badge states what we actually know, most severe first: an unusable
    // stored password (key material changed), a rejection by wordpress.org, the
    // last verified moment — or, for accounts saved before verification existed,
    // an honest "not verified yet". One anatomy for every state: container with
    // state modifier, icon, and a body in which status word and optional time
    // element can wrap.
    const renderWporgAccountState = (storedAccount, passwordUsable) => {
        const stateBadge = (modifier, icon, label, time = null) => createElement('span', {
            className: 'pblsh--wporg-account-card__state ' + modifier,
        },
            createElement('span', { className: 'pblsh--wporg-account-card__state-icon', 'aria-hidden': 'true' },
                getSvgIcon(icon, { size: 16 })),
            createElement('span', { className: 'pblsh--wporg-account-card__state-body' },
                createElement('span', { className: 'pblsh--wporg-account-card__state-status' }, label),
                time,
            ),
        );

        if (!passwordUsable || storedAccount.credentials_rejected) {
            return stateBadge('is-blocked', 'close_circle', __('Password re-entry required', 'peak-publisher'));
        }
        if (storedAccount.verified_at) {
            const verifiedDate = new Date(storedAccount.verified_at * 1000);
            return stateBadge('is-verified', 'check_circle', __('Verified', 'peak-publisher'),
                createElement('time', Pblsh.Utils.getTimeTooltipProps(verifiedDate),
                    Pblsh.Utils.formatRelativeTime(verifiedDate)));
        }
        return stateBadge('is-unknown', 'help_circle_outline', __('Not verified yet', 'peak-publisher'));
    };

    const renderWporgAccountCard = () => {
        const hasStoredAccount = !!(storedAccount && storedAccount.username);
        const passwordUsable = !!storedAccount?.password_usable;

        if (wporgAccountEditing || !hasStoredAccount) {
            // The form carries its own prominent frame — it replaces the card
            // instead of nesting inside it (one frame at a time).
            return createElement(WporgAccountForm, {
                onSaved: () => setWporgAccountEditing(false),
                onCancel: hasStoredAccount ? () => setWporgAccountEditing(false) : null,
            });
        }

        // Flat grid children — the card's CSS grid places them (identity left,
        // management icons top right, import bottom right, notices across the
        // full width).
        return createElement('div', { className: 'pblsh--wporg-account-card' },
            createElement('div', { className: 'pblsh--wporg-account-card__identity' },
                createElement('span', { className: 'pblsh--wporg-account-card__avatar' },
                    wporgAvatarFailed
                        ? createElement('span', { className: 'pblsh--wporg-account-card__avatar-fallback' },
                            getSvgIcon('account', { size: 40 }))
                        : createElement('img', {
                            className: 'pblsh--wporg-account-card__avatar-image',
                            // Official wordpress.org avatar redirect — resolves the
                            // account's Gravatar by username alone (s=128 for retina).
                            src: 'https://wordpress.org/grav-redirect.php?user=' + encodeURIComponent(storedAccount.username) + '&s=128',
                            alt: '',
                            onError: () => setWporgAvatarFailed(true),
                        }),
                ),
                createElement('div', { className: 'pblsh--wporg-account-card__identity-text' },
                    createElement('span', { className: 'pblsh--wporg-account-card__username' }, storedAccount.username),
                    renderWporgAccountState(storedAccount, passwordUsable),
                ),
            ),
            // Management actions — the same icon language as the plugin list:
            // pencil to edit, destructive removal behind the menu (which is also
            // the future home of multi-account actions).
            createElement('div', { className: 'pblsh--wporg-account-card__manage' },
                createElement(Button, {
                    isTertiary: true,
                    onClick: () => setWporgAccountEditing(true),
                    label: __('Edit account', 'peak-publisher'),
                    icon: getSvgIcon('pencil', { size: 24 }),
                }),
                createElement(DropdownMenu, {
                    icon: getSvgIcon('dots_horizontal', { size: 24 }),
                    label: __('More options', 'peak-publisher'),
                    // The settings live in a native <dialog> top layer — a
                    // portaled popover would land behind it, so render inline.
                    popoverProps: { inline: true },
                    children: ({ onClose }) => [
                        createElement(MenuItem, {
                            key: 'test',
                            disabled: !passwordUsable,
                            onClick: () => { handleTestStoredCredentials(storedAccount.username); onClose(); },
                        },
                            getSvgIcon('check_circle', { size: 24 }),
                            __('Test connection', 'peak-publisher'),
                        ),
                        createElement(MenuItem, {
                            key: 'remove',
                            isDestructive: true,
                            disabled: saving,
                            onClick: () => { handleDisconnectWporgAccount(); onClose(); },
                        },
                            getSvgIcon('delete_forever', { size: 24 }),
                            __('Disconnect account', 'peak-publisher'),
                        ),
                    ],
                }),
            ),
            !passwordUsable ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-card__reentry' },
                __('The stored password can no longer be decrypted because the encryption key material changed. Edit the account and re-enter it.', 'peak-publisher'),
            ) : (storedAccount.credentials_rejected ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-card__reentry' },
                __('wordpress.org rejected the stored password. Edit the account and re-enter it.', 'peak-publisher'),
            ) : null),
            createElement(Button, {
                className: 'pblsh--wporg-account-card__import',
                isSecondary: true,
                onClick: () => { if (typeof onOpenWporgImport === 'function') onOpenWporgImport(); },
                icon: getSvgIcon('download', { size: 24 }),
                __next40pxDefaultSize: true,
            }, __('Import plugins…', 'peak-publisher')),
        );
    };

    const renderSection = () => {
        if (currentSection === 'general') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('General', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Standalone mode', 'peak-publisher'),
                            help: [
                                __('Attention: This disables the frontend, several admin menus and other features not needed for Peak Publisher. However, you can simply deactivate standalone mode again at any time, and everything will be back.', 'peak-publisher'),
                            ],
                            checked: settings.standalone_mode,
                            onChange: (val) => setField('standalone_mode', val),
                            __next40pxDefaultSize: true,
                        }),
                        settings.standalone_mode ? createElement('div', {
                                style: {
                                    marginInlineStart: '40px',
                                },
                            },
                            createElement(TextControl, {
                                type: 'url',
                                label: __('Frontend redirect URL', 'peak-publisher'),
                                help: __('Leave blank to show a white page.', 'peak-publisher'),
                                value: settings.standalone_redirect_url,
                                placeholder: 'https://',
                                onChange: (val) => setField('standalone_redirect_url', val),
                                __next40pxDefaultSize: true,
                            })
                        ) : null,
                        createElement('p', null, createElement('strong', null, __('Peak Publisher can be used within any WordPress website, but it\'s highly recommended to use a separate WordPress installation for Peak Publisher from the start so that the plugin update URL doesn\'t have to change later. Changing the URL later may require a lengthy transition period.', 'peak-publisher'))),
                    ),
                ),
            );
        }
        if (currentSection === 'analytics') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('Analytics', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Count installations', 'peak-publisher'),
                            help: [
                                __('Counts unique plugin installations based on update checks. For technical reasons, there is always a delay of up to 24 hours in the displayed number of installations.', 'peak-publisher'),
                            ],
                            checked: !!settings.count_plugin_installations,
                            onChange: (val) => setField('count_plugin_installations', val),
                            __next40pxDefaultSize: true,
                        }),
                    ),
                ),
            );
        }
        if (currentSection === 'uploads') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('Automatic cleanup of your uploads', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Remove workspace artifacts', 'peak-publisher'),
                            help: [
                                __('Keeps your installation files small and clean by removing files and folders of your operating system and development environment.', 'peak-publisher'),
                            ],
                            checked: settings.auto_remove_workspace_artifacts,
                            onChange: (val) => setField('auto_remove_workspace_artifacts', val),
                            __next40pxDefaultSize: true,
                        }),
                        createElement('div', {
                                style: {
                                    marginInlineStart: '40px',
                                },
                            },
                            createElement(TextareaControl, {
                                label: __('Files and folders to remove', 'peak-publisher'),
                                help: [
                                    __('One file or folder name per line (no paths). Examples: .git, .svn', 'peak-publisher'),
                                    createElement('br', null),
                                    __('Use * to match any sequence of characters. Examples: *.bak, .env.*', 'peak-publisher'),
                                    createElement('br', null),
                                    createInterpolateElement(__('For more special patterns check out the <a>PHP fnmatch documentation</a>.', 'peak-publisher'),
                                        {
                                            a: createElement('a', { href: 'https://www.php.net/manual/en/function.fnmatch.php', target: '_blank' }),
                                        }
                                    ),
                                ],
                                value: settings.wordspace_artifacts_to_remove,
                                onChange: (val) => setField('wordspace_artifacts_to_remove', val),
                                rows: 6,
                                __next40pxDefaultSize: true,
                            })
                        ),
                        createElement('hr'),
                        createElement(ToggleControl, {
                            label: __('Convert readme.txt to UTF-8 without a BOM', 'peak-publisher'),
                            help: [
                                __('To ensure that the information in a plugin\'s readme.txt file is processed and displayed correctly, the file must be UTF-8 encoded. If this option is enabled, readme.txt files that are not encoded in this way will be automatically converted.', 'peak-publisher'),
                            ],
                            checked: settings.readme_txt_convert_to_utf8_without_bom,
                            onChange: (val) => setField('readme_txt_convert_to_utf8_without_bom', val),
                            __next40pxDefaultSize: true,
                        }),
                    ),
                ),
            );
        }
        if (currentSection === 'security') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('Restrict access to the plugins', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(TextareaControl, {
                            label: __('Whitelist of allowed IP addresses or domain names (one per line)', 'peak-publisher'),
                            help: [
                                createElement('strong', null, __('SECURITY NOTICE:', 'peak-publisher')),
                                ' ',
                                __('Domain names are resolved to the IP address, and only the IP address can be reliably verified. So, there is a risk that a website on the same server could pretend to be the legitimate website. Never store sensitive data directly in the plugin files.', 'peak-publisher'),
                                //createElement('br', null),
                                //createElement('br', null),
                                //__('CIDR notation is also allowed (e.g. 192.168.1.0/24)', 'peak-publisher'),
                            ],
                            value: settings.ip_whitelist,
                            placeholder: __('Leave blank to allow access from anywhere', 'peak-publisher'),
                            onChange: (val) => setField('ip_whitelist', val),
                            rows: 6,
                            __next40pxDefaultSize: true,
                        })
                    ),
                ),
            );
        }
        if (currentSection === 'wordpress-org') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('wordpress.org', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        renderWporgAccountCard(),
                    )
                )
            );
        }
        return null;
    };

    return createElement('div', { className: 'pblsh--settings' },
        createElement('div', { className: 'pblsh--settings__inner' },
            createElement('div', { className: 'pblsh--settings--sidebar' },
                createElement('div', { className: 'pblsh--settings--sidebar__nav' },
                    sections.map(section =>
                        createElement(wp.element.Fragment, { key: section.id },
                            section.separatorBefore ? createElement('div', { className: 'pblsh--settings--sidebar__nav-separator' }) : null,
                            createElement('div', {
                                className: `pblsh--settings--sidebar__nav-item ${currentSection === section.id ? 'pblsh--settings--sidebar__nav-item--active' : ''}`,
                                onClick: () => setCurrentSection(section.id)
                            },
                                Pblsh.Utils.getSvgIcon(section.icon),
                                createElement('span', { className: 'pblsh--settings--sidebar__nav-title' }, section.title)
                            )
                        )
                    )
                )
            ),
            createElement('div', { className: 'pblsh--settings--main' },
                createElement('div', { className: 'pblsh--settings--main__inner' },
                    createElement('div', { className: 'pblsh--settings--main__content' },
                        renderSection()
                    ),
                    createElement('section', { className: 'pblsh--settings--main__section pblsh--settings--main__section--buttons' },
                        createElement('div', { className: 'pblsh--settings--main__section-content pblsh--settings--main__section-content--buttons' },
                            createElement(Button, {
                                isSecondary: true,
                                onClick: () => { if (typeof onClose === 'function') onClose(); },
                                __next40pxDefaultSize: true,
                            }, __('Cancel', 'peak-publisher')),
                            ' ',
                            createElement(Button, {
                                isPrimary: true,
                                onClick: handleSave,
                                isBusy: saving,
                                __next40pxDefaultSize: true,
                            }, __('Save settings', 'peak-publisher')),
                        ),
                    )
                )
            )
        )
    );
});
