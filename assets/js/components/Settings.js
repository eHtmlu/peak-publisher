// Settings Component
lodash.set(window, 'Pblsh.Components.Settings', ({ onClose } = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, createElement, createInterpolateElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, ToggleControl, TextControl, TextareaControl } = wp.components;
    const { showAlert } = Pblsh.Utils;
    const settingsController = window.Pblsh.Controllers && window.Pblsh.Controllers.Settings ? window.Pblsh.Controllers.Settings : null;
    const PASSWORD_MASKED = '__MASKED__';
    const PASSWORD_MANAGER_IGNORE_PROPS = {
        autoComplete: 'off',
        'data-lpignore': 'true',
        'data-1p-ignore': 'true',
        'data-bwignore': 'true',
        'data-dashlane-disabled-on-field': 'true',
        'data-form-type': 'other',
    };

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const loading = useSelect((select) => select('pblsh/settings').isLoading(), []);
    const saving = useSelect((select) => select('pblsh/settings').isSaving(), []);
    const [settings, setSettings] = useState({
        standalone_mode: false,
        auto_add_top_level_folder: false,
        auto_remove_workspace_artifacts: false,
        wordspace_artifacts_to_remove: [],
        readme_txt_convert_to_utf8_without_bom: false,
        ip_whitelist: [],
        count_plugin_installations: false,
        standalone_redirect_url: '',
        wporg_username: '',
        wporg_password: '',
        wporg_original_username: '',
    });
    const [currentSection, setCurrentSection] = useState('general');
    const [checkingEncryptionKey, setCheckingEncryptionKey] = useState(false);

    useEffect(() => {
        try {
            if (settingsController) settingsController.fetch();
        } catch (e) {}
    }, []);

    useEffect(() => {
        if (serverSettings) {
            const accounts = Array.isArray(serverSettings.wporg_accounts) ? serverSettings.wporg_accounts : [];
            const firstAccount = accounts[0] || {};
            const firstUsername = firstAccount.username || '';
            const firstHasPassword = !!firstAccount.has_password;
            setSettings({
                standalone_mode: !!serverSettings.standalone_mode,
                auto_add_top_level_folder: !!serverSettings.auto_add_top_level_folder,
                auto_remove_workspace_artifacts: !!serverSettings.auto_remove_workspace_artifacts,
                readme_txt_convert_to_utf8_without_bom: !!serverSettings.readme_txt_convert_to_utf8_without_bom,
                wordspace_artifacts_to_remove: getTextareaFromList(Array.isArray(serverSettings.wordspace_artifacts_to_remove) ? serverSettings.wordspace_artifacts_to_remove : []),
                ip_whitelist: getTextareaFromList(Array.isArray(serverSettings.ip_whitelist) ? serverSettings.ip_whitelist : []),
                count_plugin_installations: !!serverSettings.count_plugin_installations,
                standalone_redirect_url: serverSettings.standalone_redirect_url || '',
                wporg_username: firstUsername,
                wporg_password: firstHasPassword ? PASSWORD_MASKED : '',
                wporg_original_username: firstUsername,
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

    const setWporgUsername = (value) => {
        setSettings(prev => {
            const next = { ...prev, wporg_username: value };
            if (prev.wporg_password === PASSWORD_MASKED && value !== prev.wporg_original_username) {
                next.wporg_password = '';
            }
            return next;
        });
    };

    const setWporgPassword = (value) => {
        setSettings(prev => ({ ...prev, wporg_password: value }));
    };

    const getEncryptionKeyStatus = () => {
        return serverSettings && serverSettings.wporg_credentials
            ? (serverSettings.wporg_credentials.encryption_key_status || 'missing')
            : 'missing';
    };

    const handleCheckEncryptionKey = async () => {
        try {
            setCheckingEncryptionKey(true);
            const nextSettings = await window.Pblsh.API.getSettings();
            wp.data.dispatch('pblsh/settings').setServer(nextSettings);
        } catch (e) {
            showAlert(e && e.message ? e.message : __('Could not check the encryption key status.', 'peak-publisher'), 'error');
        } finally {
            setCheckingEncryptionKey(false);
        }
    };

    const buildSavePayload = () => {
        const payload = cloneServerSettings();
        delete payload.wporg_credentials;

        payload.standalone_mode = !!settings.standalone_mode;
        payload.auto_add_top_level_folder = !!settings.auto_add_top_level_folder;
        payload.auto_remove_workspace_artifacts = !!settings.auto_remove_workspace_artifacts;
        payload.readme_txt_convert_to_utf8_without_bom = !!settings.readme_txt_convert_to_utf8_without_bom;
        payload.wordspace_artifacts_to_remove = normalizeListFromTextarea(settings.wordspace_artifacts_to_remove);
        payload.ip_whitelist = normalizeListFromTextarea(settings.ip_whitelist);
        payload.count_plugin_installations = !!settings.count_plugin_installations;
        payload.standalone_redirect_url = settings.standalone_redirect_url || '';

        const accounts = Array.isArray(payload.wporg_accounts) ? payload.wporg_accounts : [];
        const firstAccount = {
            ...(accounts[0] && typeof accounts[0] === 'object' ? accounts[0] : {}),
            username: settings.wporg_username || '',
            password: settings.wporg_password || '',
        };

        if (accounts.length > 0 || firstAccount.username !== '' || firstAccount.password !== '') {
            accounts[0] = firstAccount;
            payload.wporg_accounts = accounts;
        } else {
            payload.wporg_accounts = [];
        }

        return payload;
    };

    const handleSave = async () => {
        try {
            const payload = buildSavePayload();
            if (settingsController) {
                await settingsController.save(payload);
            } else {
                await window.Pblsh.API.saveSettings(payload);
            }
            if (typeof onClose === 'function') onClose();
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

    const renderSection = () => {
        const encryptionKeyStatus = getEncryptionKeyStatus();
        const encryptionKeyIsValid = encryptionKeyStatus === 'valid';
        const encryptionKeyMessage = serverSettings && serverSettings.wporg_credentials
            ? (serverSettings.wporg_credentials.encryption_key_message || '')
            : '';
        const encryptionKeySnippet = serverSettings && serverSettings.wporg_credentials
            ? (serverSettings.wporg_credentials.wp_config_snippet || '')
            : '';

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
                    //createElement('h3', null, __('Fix top-level folder', 'peak-publisher')),
                    //createElement('p', null, __('If the top-level folder is missing in the ZIP file, it will be generated automatically. When the plugin is installed, WordPress adds this top-level folder unchanged to the /wp-content/plugins/ directory.', 'peak-publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Add top-level folder if missing', 'peak-publisher'),
                            help: [
                                __('Highly recommended if you usually give the plugin folder and the main file the same name, which is recommended by WordPress anyway. If enabled and the top-level folder in the ZIP file is missing, it will be added automatically and named based on the plugin\'s main file.', 'peak-publisher'),
                                createElement('br', null),
                                createElement('br', null),
                                __('If this option is disabled and the top-level folder is missing in a plugin ZIP file, WordPress will install the plugin in the /wp-content/plugins/[zip file name]/... folder, which is often not intended.', 'peak-publisher'),
                            ],
                            checked: settings.auto_add_top_level_folder,
                            onChange: (val) => setField('auto_add_top_level_folder', val),
                            __next40pxDefaultSize: true,
                        }),
                        createElement('hr'),
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
                        !encryptionKeyIsValid ? createElement('div', { className: 'pblsh--settings-wporg-encryption-setup' },
                            createElement('h3', { className: 'pblsh--settings-wporg-encryption-setup__title' }, __('First step:', 'peak-publisher')),
                            createElement('p', { className: 'pblsh--settings-wporg-encryption-setup__text' }, __('Before adding your WordPress.org credentials, you need to configure encrypted storage of those credentials.', 'peak-publisher')),
                            createElement('p', { className: 'pblsh--settings-wporg-encryption-setup__text' }, __('Please add the following line to your wp-config.php file:', 'peak-publisher')),
                            encryptionKeySnippet ? createElement('code', { className: 'pblsh--settings-wporg-encryption-setup__code' }, encryptionKeySnippet) : null,
                            createElement('h4', { className: 'pblsh--settings-wporg-encryption-setup__status-label' }, __('Status', 'peak-publisher')),
                            createElement('div', { className: 'pblsh--settings-wporg-encryption-setup__status-row' },
                                createElement('span', { className: `pblsh--settings-wporg-encryption-setup__status pblsh--settings-wporg-encryption-setup__status--${encryptionKeyStatus}` },
                                    createElement('span', null, encryptionKeyMessage)
                                ),
                                createElement(Button, {
                                    className: 'pblsh--settings-wporg-encryption-setup__status-check',
                                    isSecondary: true,
                                    onClick: handleCheckEncryptionKey,
                                    isBusy: checkingEncryptionKey,
                                    disabled: checkingEncryptionKey,
                                    __next40pxDefaultSize: true,
                                }, __('Check again', 'peak-publisher'))
                            )
                        ) : null,
                        encryptionKeyIsValid && [
                            createElement('p', null, __('Connect your wordpress.org account to manage your plugins in the wordpress.org SVN repository.', 'peak-publisher')),
                            createElement('div', { className: 'pblsh--settings-wporg-account' },
                                createElement(TextControl, {
                                    label: __('WordPress.org username', 'peak-publisher'),
                                    value: settings.wporg_username,
                                    onChange: setWporgUsername,
                                    ...PASSWORD_MANAGER_IGNORE_PROPS,
                                    help: __('Use your case-sensitive WordPress.org username, not your email address.', 'peak-publisher'),
                                    __next40pxDefaultSize: true,
                                }),
                                createElement(TextControl, {
                                    type: 'password',
                                    label: __('SVN Password', 'peak-publisher'),
                                    value: settings.wporg_password,
                                    onChange: setWporgPassword,
                                    disabled: !encryptionKeyIsValid,
                                    ...PASSWORD_MANAGER_IGNORE_PROPS,
                                    help: createInterpolateElement(
                                        __('Use your WordPress.org <profileLink>SVN password</profileLink>, not your regular login password.', 'peak-publisher'),
                                        {
                                            profileLink: createElement('a', {
                                                href: 'https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password',
                                                target: '_blank',
                                                rel: 'noreferrer',
                                            }),
                                        }
                                    ),
                                    __next40pxDefaultSize: true,
                                })
                            )
                        ]
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


