// WporgAccessGate Component - shared wordpress.org onboarding and access gate
const WporgAccessGate = ({
    variant = 'access_blocked',
    slugSummary = '',
    username = '',
    accessStatus = '',
    message = '',
    errors = [],
    isBusy = false,
    primaryDisabled = false,
    onPrimary = null,
} = {}) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;
    const { Button, Spinner } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;

    const normalizedErrors = Array.isArray(errors) ? errors.filter(Boolean) : [];

    const getVariantTitle = () => {
        if (variant === 'credentials_required') return __('wordpress.org account required', 'peak-publisher');
        if (variant === 'import_required') return __('Import current wordpress.org state', 'peak-publisher');
        return __('wordpress.org access required', 'peak-publisher');
    };

    const getVariantDescription = () => {
        if (variant === 'credentials_required') {
            return __('Add a wordpress.org account before deploying ZIP releases.', 'peak-publisher');
        }
        if (variant === 'import_required') {
            return __('Peak Publisher needs the current plugin state before this ZIP can be deployed.', 'peak-publisher');
        }
        return WporgAccessGate.describeBlockingReason(accessStatus)
            || __('Peak Publisher could not verify wordpress.org access for this deploy.', 'peak-publisher');
    };

    const getIconName = () => {
        if (variant === 'import_required') return 'download';
        if (variant === 'credentials_required') return 'wordpress';
        return 'chat_alert';
    };

    const renderDetail = (label, value, key) => {
        if (!value) return null;
        return createElement('div', { key, className: 'pblsh--wporg-access-gate__detail' },
            createElement('span', { className: 'pblsh--wporg-access-gate__detail-label' }, label),
            createElement('span', { className: 'pblsh--wporg-access-gate__detail-value' }, value),
        );
    };

    const renderIssue = (error, index) => {
        const issueTitle = error?.title || error?.code || __('Error', 'peak-publisher');
        const issueMessage = error?.message || error?.desc || String(error || '');
        return createElement('div', { key: 'error-' + index, className: 'pblsh--wporg-access-gate__issue is-error' },
            createElement('span', { className: 'pblsh--wporg-access-gate__issue-icon' }, getSvgIcon('close_circle', { size: 20 })),
            createElement('span', { className: 'pblsh--wporg-access-gate__issue-text' },
                createElement('strong', null, issueTitle),
                issueMessage ? createElement('span', null, issueMessage) : null,
            ),
        );
    };

    const primaryLabel = variant === 'import_required' ? __('Import and continue', 'peak-publisher') : '';
    const primaryBusyLabel = variant === 'import_required' ? __('Importing...', 'peak-publisher') : '';
    const hasPrimaryAction = !!primaryLabel && typeof onPrimary === 'function';

    return createElement('section', {
        className: ['pblsh--wporg-access-gate', 'is-' + variant].filter(Boolean).join(' '),
    },
        createElement('div', { className: 'pblsh--wporg-access-gate__main' },
            createElement('span', { className: 'pblsh--wporg-access-gate__icon' },
                isBusy ? createElement(Spinner) : getSvgIcon(getIconName(), { size: 32 }),
            ),
            createElement('div', { className: 'pblsh--wporg-access-gate__copy' },
                createElement('h3', { className: 'pblsh--wporg-access-gate__title' }, getVariantTitle()),
                createElement('p', { className: 'pblsh--wporg-access-gate__description' }, getVariantDescription()),
            ),
        ),
        (slugSummary || username || message) ? createElement('div', { className: 'pblsh--wporg-access-gate__details' },
            renderDetail(__('Plugin', 'peak-publisher'), slugSummary, 'slug'),
            renderDetail(__('Account', 'peak-publisher'), username, 'username'),
            renderDetail(__('Status', 'peak-publisher'), message, 'message'),
        ) : null,
        normalizedErrors.length > 0 ? createElement('div', { className: 'pblsh--wporg-access-gate__issues' },
            normalizedErrors.map(renderIssue),
        ) : null,
        hasPrimaryAction ? createElement('div', { className: 'pblsh--wporg-access-gate__actions' },
            createElement(Button, {
                isPrimary: true,
                onClick: onPrimary,
                disabled: isBusy || primaryDisabled,
                __next40pxDefaultSize: true,
            }, isBusy && primaryBusyLabel ? primaryBusyLabel : primaryLabel),
        ) : null,
    );
};

// Shared wporg-state helpers for every gate host (DropOverlay, Settings, AdditionProcess)
WporgAccessGate.describeBlockingReason = (reason) => {
    const { __ } = wp.i18n;
    if (reason === 'wporg_no_credentials' || reason === 'no_credentials') {
        return __('No configured wordpress.org account is available.', 'peak-publisher');
    }
    if (reason === 'wporg_no_write_access' || reason === 'no_write_access') {
        return __('The selected account cannot commit to this plugin.', 'peak-publisher');
    }
    if (reason === 'wporg_not_found' || reason === 'not_found') {
        return __('The plugin was not found on wordpress.org.', 'peak-publisher');
    }
    return '';
};

WporgAccessGate.getVariant = ({ accessStatus = '', blockingReason = '', preDeployRequired = false, hasImportUsername = false } = {}) => {
    const needsCredentials = blockingReason === 'wporg_no_credentials'
        || accessStatus === 'no_credentials'
        || (preDeployRequired && !hasImportUsername);

    if (preDeployRequired) return needsCredentials ? 'credentials_required' : 'import_required';

    const accessBlocked = (accessStatus && accessStatus !== 'ok' && accessStatus !== 'not_checked') || !!blockingReason;
    if (accessBlocked) return needsCredentials ? 'credentials_required' : 'access_blocked';

    return null;
};

lodash.set(window, 'Pblsh.Components.WporgAccessGate', WporgAccessGate);
