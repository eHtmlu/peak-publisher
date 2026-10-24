// WporgAccountSpellingNotice Component - what an account save found out about the
// username's spelling (WporgAccountForm's onSaved argument): the proven spelling it
// saved instead of the entered one, or the lowercase hint for an unproven entry with
// capital letters. Renders nothing without a finding. One text in one place; the host
// that ran the save shows it in the step that follows — the settings card, the upload
// dialog's next step, the add-new flow above the import table. The username stands in
// bold, as the import facts show account names.
const WporgAccountSpellingNotice = ({ spelling = null, className = '' } = {}) => {
    const { __, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement } = wp.element;
    const { NoticeBox } = Pblsh.Components;

    if (!spelling || !spelling.username) {
        return null;
    }
    if (spelling.status === 'corrected') {
        return createElement(NoticeBox, { variant: 'info', className },
            createInterpolateElement(
                sprintf(__('Saved as <name>%s</name>, the spelling of your commits on wordpress.org.', 'peak-publisher'), spelling.username),
                { name: createElement('strong') },
            ),
        );
    }
    if (spelling.status === 'unverified') {
        return createElement(NoticeBox, { variant: 'warning', className },
            createInterpolateElement(
                sprintf(__('wordpress.org usernames are usually lowercase (<name>%s</name>). SVN commits need your username\'s exact spelling.', 'peak-publisher'), spelling.username),
                { name: createElement('strong') },
            ),
        );
    }
    return null;
};

lodash.set(window, 'Pblsh.Components.WporgAccountSpellingNotice', WporgAccountSpellingNotice);
