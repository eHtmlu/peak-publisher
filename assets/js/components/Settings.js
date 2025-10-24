// Settings Component
lodash.set(window, 'Pblsh.Components.Settings', ({ onClose } = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, createElement, createInterpolateElement } = wp.element;
    const { Button, Panel, PanelBody, ToggleControl, TextareaControl, SelectControl, RadioControl } = wp.components;
    const { showAlert } = Pblsh.Utils;
    const { getSettings, saveSettings } = Pblsh.API;

    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState({
        standalone_mode: false,
        auto_add_top_level_folder: false,
        auto_remove_workspace_artifacts: false,
        wordspace_artifacts_to_remove: [],
        ip_whitelist: [],
    });
    const [currentSection, setCurrentSection] = useState('general');

    useEffect(() => {
        let mounted = true;
        (async () => {
            try {
                const data = await getSettings();
                if (!mounted) return;
                setSettings({
                    standalone_mode: data.standalone_mode || false,
                    auto_add_top_level_folder: data.auto_add_top_level_folder || false,
                    auto_remove_workspace_artifacts: data.auto_remove_workspace_artifacts || false,
                    wordspace_artifacts_to_remove: getTextareaFromList(Array.isArray(data.wordspace_artifacts_to_remove) ? data.wordspace_artifacts_to_remove : []),
                    ip_whitelist: getTextareaFromList(Array.isArray(data.ip_whitelist) ? data.ip_whitelist : []),
                });
            } catch (e) {
                showAlert(e.message, 'error');
            } finally {
                if (mounted) setLoading(false);
            }
        })();
        return () => { mounted = false; };
    }, []);

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

    const handleSave = async () => {
        setSaving(true);
        try {
            const payload = {
                standalone_mode: settings.standalone_mode || false,
                auto_add_top_level_folder: settings.auto_add_top_level_folder || false,
                auto_remove_workspace_artifacts: settings.auto_remove_workspace_artifacts || false,
                wordspace_artifacts_to_remove: normalizeListFromTextarea(settings.wordspace_artifacts_to_remove),
                ip_whitelist: normalizeListFromTextarea(settings.ip_whitelist),
            };
            await saveSettings(payload);
            if (typeof onClose === 'function') onClose();
        } catch (e) {
            showAlert(e.message, 'error');
        } finally {
            setSaving(false);
        }
    };

    if (loading) {
        return createElement('div', { className: 'pblsh--loading' },
            createElement('div', { className: 'pblsh--loading__spinner' }),
        );
    }
    const sections = [
        { id: 'general', title: __('General', 'publisher'), icon: 'cog' },
        { id: 'uploads', title: __('Uploads', 'publisher'), icon: 'cloud_upload' },
        { id: 'security', title: __('Security', 'publisher'), icon: 'security' },
    ];

    const renderSection = () => {
        if (currentSection === 'general') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('General', 'publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Standalone mode', 'publisher'),
                            help: [
                                __('Attention: This disables the frontend, several admin menus and other features not needed for Publisher. However, you can simply deactivate standalone mode again at any time, and everything will be back.', 'publisher'),
                            ],
                            checked: settings.standalone_mode,
                            onChange: (val) => setField('standalone_mode', val),
                            __next40pxDefaultSize: true,
                        }),
                        createElement('p', null, createElement('strong', null, __('Publisher can be used within any WordPress website, but it\'s highly recommended to use a separate WordPress installation for Publisher from the start so that the plugin update URL doesn\'t have to change later. Changing the URL later may require a lengthy transition period.', 'pblsh'))),
                    ),
                ),
            );
        }
        if (currentSection === 'uploads') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('Automatic cleanup of your uploads', 'publisher')),
                    //createElement('h3', null, __('Fix top-level folder', 'publisher')),
                    //createElement('p', null, __('If the top-level folder is missing in the ZIP file, it will be generated automatically. When the plugin is installed, WordPress adds this top-level folder unchanged to the /wp-content/plugins/ directory.', 'publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(ToggleControl, {
                            label: __('Add top-level folder if missing', 'publisher'),
                            help: [
                                __('Highly recommended if you usually give the plugin folder and the main file the same name, which is recommended by WordPress anyway. If enabled and the top-level folder in the ZIP file is missing, it will be added automatically and named based on the plugin\'s main file.', 'pblsh'),
                                createElement('br', null),
                                createElement('br', null),
                                __('If this option is disabled and the top-level folder is missing in a plugin ZIP file, WordPress will install the plugin in the /wp-content/plugins/[zip file name]/... folder, which is often not intended.', 'publisher'),
                            ],
                            checked: settings.auto_add_top_level_folder,
                            onChange: (val) => setField('auto_add_top_level_folder', val),
                            __next40pxDefaultSize: true,
                        }),
                        createElement('hr'),
                        createElement(ToggleControl, {
                            label: __('Remove workspace artifacts', 'publisher'),
                            help: [
                                __('Keeps your installation files small and clean by removing files and folders of your operating system and development environment.', 'publisher'),
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
                                label: __('Files and folders to remove', 'publisher'),
                                help: [
                                    __('One file or folder name per line (no paths). Examples: .git, .svn', 'publisher'),
                                    createElement('br', null),
                                    __('Use * to match any sequence of characters. Examples: *.bak, .env.*', 'publisher'),
                                    createElement('br', null),
                                    createInterpolateElement(__('For more special patterns check out the <a>PHP fnmatch documentation</a>.', 'publisher'),
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
                    ),
                ),
            );
        }
        if (currentSection === 'security') {
            return createElement(wp.element.Fragment, null,
                createElement('section', { className: 'pblsh--settings--main__section' },
                    createElement('h2', null, __('Restrict access to the plugins', 'publisher')),
                    createElement('div', { className: 'pblsh--settings--main__section-content' },
                        createElement(TextareaControl, {
                            label: __('Whitelist of allowed IP addresses or domain names (one per line)', 'publisher'),
                            help: [
                                createElement('strong', null, __('SECURITY NOTICE:', 'publisher')),
                                ' ',
                                __('Domain names are resolved to the IP address, and only the IP address can be reliably verified. So, there is a risk that a website on the same server could pretend to be the legitimate website. Never store sensitive data directly in the plugin files.', 'publisher'),
                                //createElement('br', null),
                                //createElement('br', null),
                                //__('CIDR notation is also allowed (e.g. 192.168.1.0/24)', 'publisher'),
                            ],
                            value: settings.ip_whitelist,
                            placeholder: __('Leave blank to allow access from anywhere', 'publisher'),
                            onChange: (val) => setField('ip_whitelist', val),
                            rows: 6,
                            __next40pxDefaultSize: true,
                        })
                    ),
                ),
            );
        }
        return null;
    };

    return createElement('div', { className: 'pblsh--settings' },
        createElement('div', { className: 'pblsh--settings__inner' },
            createElement('div', { className: 'pblsh--settings--sidebar' },
                createElement('div', { className: 'pblsh--settings--sidebar__nav' },
                    sections.map(section => 
                        createElement('div', {
                            key: section.id,
                            className: `pblsh--settings--sidebar__nav-item ${currentSection === section.id ? 'pblsh--settings--sidebar__nav-item--active' : ''}`,
                            onClick: () => setCurrentSection(section.id)
                        },
                            Pblsh.Utils.getSvgIcon(section.icon),
                            createElement('span', { className: 'pblsh--settings--sidebar__nav-title' }, section.title)
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
                            }, __('Cancel', 'publisher')),
                            ' ',
                            createElement(Button, {
                                isPrimary: true,
                                onClick: handleSave,
                                isBusy: saving,
                                __next40pxDefaultSize: true,
                            }, __('Save settings', 'publisher')),
                        ),
                    )
                )
            )
        )
    );
});


