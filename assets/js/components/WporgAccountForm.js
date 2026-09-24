// WporgAccountForm Component - shared wordpress.org account form.
// One form for every host: the Settings account card (edit state), the upload
// overlay's access gate, and the import area's empty state. Saves immediately
// through the settings store (single data source) and reports back via onSaved.
const WporgAccountForm = ({
    errorMessage = '',
    onSaved = null,
    onCancel = null,
} = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, createElement, createInterpolateElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, TextControl } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    const { FaqLink, NoticeBox } = Pblsh.Components;
    const PASSWORD_MASKED = Pblsh.Utils.WPORG_PASSWORD_MASKED;
    const PASSWORD_MANAGER_IGNORE_PROPS = {
        autoComplete: 'off',
        'data-lpignore': 'true',
        'data-1p-ignore': 'true',
        'data-bwignore': 'true',
        'data-dashlane-disabled-on-field': 'true',
        'data-form-type': 'other',
    };

    const serverSettings = useSelect((select) => select('pblsh/settings').getServer(), []);
    const saving = useSelect((select) => select('pblsh/settings').isSaving(), []);

    const storedAccount = (Array.isArray(serverSettings?.wporg_accounts) ? serverSettings.wporg_accounts : [])[0] || null;
    const storedUsername = storedAccount ? String(storedAccount.username || '') : '';
    const storedPasswordUsable = !!storedAccount?.password_usable;
    // A stored password that no longer decrypts (key material changed) must be re-entered.
    const reentryRequired = !!storedAccount?.has_password && !storedPasswordUsable;
    const storageStatus = serverSettings?.wporg_credentials?.storage_status || 'ok';
    const storageMessage = serverSettings?.wporg_credentials?.storage_message || '';

    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [testingCredentials, setTestingCredentials] = useState(false);
    const [credentialTestStatus, setCredentialTestStatus] = useState(null);

    useEffect(() => {
        // Seed (and re-seed after saves) from the authoritative server state.
        setUsername(storedUsername);
        setPassword(storedAccount?.has_password && storedPasswordUsable ? PASSWORD_MASKED : '');
        setCredentialTestStatus(null);
    }, [serverSettings && JSON.stringify(serverSettings.wporg_accounts)]);

    const setUsernameField = (value) => {
        setUsername(value);
        // A masked password belongs to the stored username — a different name needs a fresh password.
        if (password === PASSWORD_MASKED && value !== storedUsername) {
            setPassword('');
        }
        setCredentialTestStatus(null);
    };

    const setPasswordField = (value) => {
        setPassword(value);
        setCredentialTestStatus(null);
    };

    const handleTestCredentials = async () => {
        try {
            setTestingCredentials(true);
            setCredentialTestStatus(null);
            const result = await window.Pblsh.API.testSvnCredentials(username || '', password || '');
            if (result && result.status === 'ok') {
                setCredentialTestStatus({ type: 'success', message: __('Connection successful.', 'peak-publisher') });
            } else {
                setCredentialTestStatus({ type: 'error', message: __('Connection test failed.', 'peak-publisher') });
            }
        } catch (e) {
            setCredentialTestStatus({ type: 'error', message: e && e.message ? e.message : __('Connection test failed.', 'peak-publisher') });
        } finally {
            setTestingCredentials(false);
        }
    };

    const handleSave = async () => {
        // The save endpoint itself verifies new passwords against wordpress.org
        // and records the verdict — a rejected password surfaces as save error.
        try {
            // Clone the full server payload: the settings endpoint expects the complete
            // settings object, and only the account row changes here.
            const payload = JSON.parse(JSON.stringify(serverSettings || {}));
            delete payload.wporg_credentials;
            const accounts = Array.isArray(payload.wporg_accounts) ? payload.wporg_accounts : [];
            accounts[0] = {
                ...(accounts[0] && typeof accounts[0] === 'object' ? accounts[0] : {}),
                username: username || '',
                password: password || '',
            };
            payload.wporg_accounts = accounts;

            await window.Pblsh.Controllers.Settings.save(payload);
            // Success feedback is the host's visible transition: the gate refreshes the
            // target, the settings card returns to its view state, the import area loads.
            if (typeof onSaved === 'function') {
                await onSaved(username || '');
            }
        } catch (e) {
            showAlert(e && e.message ? e.message : __('Saving the account failed.', 'peak-publisher'), 'error');
        }
    };

    // Saving clones the full server payload — without loaded server settings a save
    // would reset every other setting to its default, so the form waits for them.
    const canSubmit = !!serverSettings && storageStatus === 'ok' && username.trim() !== '' && password !== '' && !saving;

    return createElement('div', { className: 'pblsh--wporg-account-form' },
        createElement('div', { className: 'pblsh--wporg-account-form__main' },
            createElement('div', { className: 'pblsh--wporg-account-form__intro' },
                // 48 = --account-form-icon-size in admin.css (the fields' indent derives from it).
                createElement('span', { className: 'pblsh--wporg-account-form__heading-icon', 'aria-hidden': 'true' }, getSvgIcon('wordpress', { size: 48 })),
                createElement('div', { className: 'pblsh--wporg-account-form__intro-text' },
                    createElement('h4', { className: 'pblsh--wporg-account-form__heading' },
                        __('Connect your wordpress.org account', 'peak-publisher')),
                    createElement('p', { className: 'pblsh--wporg-account-form__intro-line' },
                        __('To manage your plugins in the wordpress.org SVN repository.', 'peak-publisher')),
                ),
            ),
            errorMessage ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-form__notice' },
                errorMessage,
            ) : null,
            storageStatus !== 'ok' ? createElement(NoticeBox, {
                variant: 'error',
                className: 'pblsh--wporg-account-form__notice',
                title: __('Credential storage is not available', 'peak-publisher'),
            },
                storageMessage,
            ) : null,
            reentryRequired ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-form__notice' },
                __('The stored password can no longer be decrypted because the encryption key material changed. Please re-enter it.', 'peak-publisher'),
            ) : null,
            createElement('div', { className: 'pblsh--wporg-account-form__fields' },
                createElement('p', null,
                    createInterpolateElement(
                        // The link names its target: the profile page listing both
                        // SVN username and password.
                        __('Enter the <profileLink>SVN credentials</profileLink> of your wordpress.org account.', 'peak-publisher'),
                        {
                            profileLink: createElement('a', {
                                href: 'https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password',
                                target: '_blank',
                                rel: 'noreferrer',
                            }),
                        }
                    ),
                ),
                createElement(TextControl, {
                    label: __('SVN username', 'peak-publisher'),
                    value: username,
                    onChange: setUsernameField,
                    ...PASSWORD_MANAGER_IGNORE_PROPS,
                    __next40pxDefaultSize: true,
                }),
                createElement(TextControl, {
                    type: 'password',
                    label: __('SVN password', 'peak-publisher'),
                    value: password,
                    placeholder: 'svn_…',
                    onChange: setPasswordField,
                    disabled: storageStatus !== 'ok',
                    ...PASSWORD_MANAGER_IGNORE_PROPS,
                    __next40pxDefaultSize: true,
                }),
            ),
            // Test failures get their own row — inline next to the button only the short
            // success confirmation fits.
            credentialTestStatus && credentialTestStatus.type === 'error' ? createElement('div', {
                className: 'pblsh--wporg-account-form__status pblsh--wporg-account-form__status--error',
            }, credentialTestStatus.message) : null,
            createElement('div', { className: 'pblsh--wporg-account-form__actions' },
                credentialTestStatus && credentialTestStatus.type === 'success' ? createElement('span', {
                    className: 'pblsh--wporg-account-form__status pblsh--wporg-account-form__status--success',
                }, credentialTestStatus.message) : null,
                createElement(Button, {
                    isSecondary: true,
                    onClick: handleTestCredentials,
                    isBusy: testingCredentials,
                    disabled: !canSubmit || testingCredentials,
                    __next40pxDefaultSize: true,
                }, __('Test connection', 'peak-publisher')),
                typeof onCancel === 'function' ? createElement(Button, {
                    isTertiary: true,
                    onClick: onCancel,
                    disabled: saving,
                    __next40pxDefaultSize: true,
                }, __('Cancel', 'peak-publisher')) : null,
                createElement(Button, {
                    isPrimary: true,
                    onClick: handleSave,
                    isBusy: saving,
                    disabled: !canSubmit || testingCredentials,
                    __next40pxDefaultSize: true,
                }, __('Save account', 'peak-publisher')),
            ),
        ),
        createElement('div', { className: 'pblsh--wporg-account-form__bottom-line' },
            // Lock instead of the default help icon — the line is a security
            // reassurance, not a question; the semantic icon carries that.
            createElement(FaqLink, { faqKey: 'credentialStorage', icon: 'lock' },
                __('Your credentials will be securely stored on your server.', 'peak-publisher')),
        ),
    );
};

lodash.set(window, 'Pblsh.Components.WporgAccountForm', WporgAccountForm);
