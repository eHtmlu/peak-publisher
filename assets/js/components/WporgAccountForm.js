// WporgAccountForm Component - shared wordpress.org account form.
// One form for every host: the Settings account card (edit state), the upload
// overlay's access gate, and the import area's empty state. Saves through the
// accounts store (single data source) and reports back via onSaved(spelling): what
// the save found out about the username's spelling (null when nothing), for the
// host to show through WporgAccountSpellingNotice in the step that follows.
const WporgAccountForm = ({
    onSaved = null,
    onCancel = null,
} = {}) => {
    const { __ } = wp.i18n;
    const { useState, useEffect, createElement, createInterpolateElement } = wp.element;
    const { useSelect } = wp.data;
    const { Button, TextControl } = wp.components;
    const { showAlert, getSvgIcon } = Pblsh.Utils;
    const { FaqLink, NoticeBox } = Pblsh.Components;
    const PASSWORD_MANAGER_IGNORE_PROPS = {
        autoComplete: 'off',
        'data-lpignore': 'true',
        'data-1p-ignore': 'true',
        'data-bwignore': 'true',
        'data-dashlane-disabled-on-field': 'true',
        'data-form-type': 'other',
    };

    const accounts = useSelect((select) => select('pblsh/wporg-accounts').getAccounts(), []);
    const loaded = useSelect((select) => select('pblsh/wporg-accounts').isLoaded(), []);
    const loading = useSelect((select) => select('pblsh/wporg-accounts').isLoading(), []);
    const loadError = useSelect((select) => select('pblsh/wporg-accounts').getLoadError(), []);
    const saving = useSelect((select) => select('pblsh/wporg-accounts').isSaving(), []);
    const storage = useSelect((select) => select('pblsh/wporg-accounts').getStorage(), []);

    const storedAccount = accounts[0] || null;
    const storedUsername = storedAccount ? String(storedAccount.username || '') : '';
    // A stored password that no longer decrypts (key material changed) or that
    // wordpress.org rejected must be re-entered.
    const reentryRequired = !!storedAccount && !storedAccount.password_usable;
    const rejected = !!storedAccount && storedAccount.password_usable && !!storedAccount.credentials_rejected;
    // Editing keeps the stored password when the field stays empty; connecting needs one.
    const passwordRequired = !storedAccount || reentryRequired || rejected;
    const storageStatus = storage?.status || 'ok';
    const storageMessage = storage?.message || '';

    const [username, setUsername] = useState('');
    const [password, setPassword] = useState('');
    const [testingCredentials, setTestingCredentials] = useState(false);
    const [credentialTestStatus, setCredentialTestStatus] = useState(null);

    useEffect(() => {
        // Seed (and re-seed after saves) from the authoritative server state.
        setUsername(storedUsername);
        setPassword('');
        setCredentialTestStatus(null);
    }, [storedAccount && JSON.stringify(storedAccount)]);

    const setUsernameField = (value) => {
        setUsername(value);
        setCredentialTestStatus(null);
    };

    const setPasswordField = (value) => {
        setPassword(value);
        setCredentialTestStatus(null);
    };

    // Tests what is typed — only a typed password can be tested here; the stored one
    // is tested from the account card.
    const handleTestCredentials = async () => {
        try {
            setTestingCredentials(true);
            setCredentialTestStatus(null);
            const result = await window.Pblsh.API.testWporgCredentials(username || '', password || '');
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
        // The save endpoint verifies the credentials against wordpress.org, settles the
        // username's spelling and records the verdict — a rejected password surfaces as
        // save error.
        try {
            const res = await window.Pblsh.Controllers.WporgAccounts.save(storedAccount ? storedAccount.id : null, {
                username: username || '',
                password: password || '',
            });
            // Success feedback is the host's visible transition: the gate refreshes the
            // target, the settings card returns to its view state, the import area loads.
            if (typeof onSaved === 'function') {
                await onSaved(res && res.spelling ? res.spelling : null);
            }
        } catch (e) {
            showAlert(e && e.message ? e.message : __('Saving the account failed.', 'peak-publisher'), 'error');
        }
    };

    // Without the loaded accounts the form would not know whether it connects or edits.
    const canSubmit = loaded && storageStatus === 'ok' && username.trim() !== '' && (password !== '' || !passwordRequired) && !saving;
    const canTest = canSubmit && password !== '';

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
            storageStatus !== 'ok' ? createElement(NoticeBox, {
                variant: 'error',
                className: 'pblsh--wporg-account-form__notice',
                title: __('Credential storage is not available', 'peak-publisher'),
            },
                storageMessage,
            ) : null,
            // Without the list the form cannot know whether it connects or edits: the
            // failed load is told here, with the way to try again.
            !loaded && loadError ? createElement(NoticeBox, {
                variant: 'error',
                className: 'pblsh--wporg-account-form__notice',
                title: __('The wordpress.org accounts could not be loaded', 'peak-publisher'),
                error: loadError,
            },
                createElement('p', null, createElement(Button, {
                    isLink: true,
                    disabled: loading,
                    // The store keeps the failure for this notice; nothing else to report.
                    onClick: () => window.Pblsh.Controllers.WporgAccounts.fetch().catch(() => {}),
                }, __('Try again', 'peak-publisher'))),
            ) : null,
            reentryRequired ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-form__notice' },
                __('The stored password can no longer be decrypted because the encryption key material changed. Please re-enter it.', 'peak-publisher'),
            ) : null,
            rejected ? createElement(NoticeBox, { variant: 'error', className: 'pblsh--wporg-account-form__notice' },
                __('wordpress.org rejected the stored password. Please re-enter it.', 'peak-publisher'),
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
                    // While editing, the field is empty and stays so unless a new password
                    // replaces the stored one — the help line says so, a placeholder would
                    // make the field look filled.
                    placeholder: 'svn_…',
                    help: passwordRequired ? null : __('Leave empty to keep the stored password.', 'peak-publisher'),
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
                    disabled: !canTest || testingCredentials,
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
