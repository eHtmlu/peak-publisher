// UpgradeNotice Component - the one-time notice after the schema upgrade that replaced
// release drafts with the current-release pointer (includes/upgrade.php). Addressed to
// the site operator, so it is one per site, not per user; dismissing deletes the stored
// facts. One section per topic the migration reported (includes/upgrade.php); the
// drafts section names every former draft, because it is now downloadable by version —
// the operator decides whether that is fine or the release goes. Owns its state: the
// facts come with PblshData, the dismissal goes to the API.
lodash.set(window, 'Pblsh.Components.UpgradeNotice', ({ onOpenPlugin }) => {
    const { __, _n, sprintf } = wp.i18n;
    const { createElement, useState } = wp.element;
    const { Button } = wp.components;
    const { showAlert } = Pblsh.Utils;
    const { NoticeBox } = Pblsh.Components;

    const [notice, setNotice] = useState(PblshData.upgradeNotice || null);
    if (!notice) return null;

    const dismiss = async () => {
        try {
            await Pblsh.API.dismissUpgradeNotice();
            setNotice(null);
        } catch (error) {
            showAlert(error.message, 'error');
        }
    };

    const pluginLink = (entry, label) => createElement(Button, {
        isLink: true,
        onClick: () => onOpenPlugin(entry.plugin_id),
    }, label);
    const joinLinks = (entries, labelOf) => entries.flatMap((entry, index) => [
        index > 0 && ', ',
        pluginLink(entry, labelOf(entry)),
    ]);
    const topicTitle = (title) => createElement('strong', { className: 'pblsh--upgrade-notice__topic' }, title);

    // One renderer per topic key of the notice (includes/upgrade.php, one migration
    // section each); a topic absent from the facts renders nothing.
    const renderReleaseDrafts = (facts) => {
        const formerDrafts = Array.isArray(facts.former_drafts) ? facts.former_drafts : [];
        const withoutCurrent = Array.isArray(facts.plugins_without_current) ? facts.plugins_without_current : [];
        return [
            topicTitle(__('Release drafts are gone', 'peak-publisher')),
            createElement('p', null,
                __('Releases no longer have a status: every release is listed and downloadable by version, and the one marked Current is what sites receive. "Make current release" in the release list changes it.', 'peak-publisher'),
            ),
            formerDrafts.length > 0 && createElement('p', null,
                sprintf(
                    _n(
                        '%d release that was a draft is now listed and downloadable by version. It is not offered as an update because it is not the current release.',
                        '%d releases that were drafts are now listed and downloadable by version. They are not offered as updates because they are not the current release.',
                        formerDrafts.length,
                        'peak-publisher'
                    ),
                    formerDrafts.length
                ),
            ),
            formerDrafts.length > 0 && createElement('p', null,
                __('Former drafts:', 'peak-publisher'), ' ',
                ...joinLinks(formerDrafts, (entry) => entry.plugin + ' ' + entry.version),
                '. ',
                __('If a version must not be reachable, download its ZIP and delete the release.', 'peak-publisher'),
            ),
            withoutCurrent.length > 0 && createElement('p', null,
                __('Plugins without a current release:', 'peak-publisher'), ' ',
                ...joinLinks(withoutCurrent, (entry) => entry.plugin),
                ' — ',
                __('make one of their releases current.', 'peak-publisher'),
            ),
        ];
    };

    const topics = notice.topics && typeof notice.topics === 'object' ? notice.topics : {};
    const sections = [
        topics.release_drafts && renderReleaseDrafts(topics.release_drafts),
    ].filter(Boolean);
    if (sections.length === 0) return null;

    return createElement(NoticeBox, { variant: 'info', className: 'pblsh--upgrade-notice', title: __('Peak Publisher was updated', 'peak-publisher') },
        ...sections.flat(),
        createElement(Button, {
            isSecondary: true,
            onClick: dismiss,
            __next40pxDefaultSize: true,
        }, __('Got it', 'peak-publisher')),
    );
});
