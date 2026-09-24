// WporgImportFacts Component - the pre-import facts of the wporg import screen:
// identity card and positive facts on top, problems as one prominent warning
// panel below ("something is off + the way out"). Heuristic facts warn, they
// never block; the import action itself lives in the host's footer.
// The state/relation interpretation mirrors WporgImportTable's renderRowHint
// (one-line register) and upload-checks' checkWporgOwnershipHint (checklist
// register) — extend them together.
// The texts speak to "you"; when multi-account support arrives, reconsider naming
// the checked account explicitly (the flow may auto-pick a different account than
// the user intended).
const WporgImportFacts = ({
    slug = '',
    accessStatus = '',
    accessMessage = '',
    directoryHint = null,
    // The wordpress.org account the probe ran with — named in the foreign-repo
    // warning so the mismatch is concrete.
    username = '',
    // Whether the host's import is running — the work unit (identity card, or
    // the celebration box in the fresh states) then shows the shared importing
    // stripes; the celebration box tints them in its own gold.
    importing = false,
    // Errors of the last attempt (the import, or the destination refresh after
    // it): [{ code, message }] as reported by the host — code and message travel
    // together.
    errors = [],
} = {}) => {
    const { __, _n, sprintf } = wp.i18n;
    const { createElement, createInterpolateElement } = wp.element;
    const { FaqLink, NoticeBox } = Pblsh.Components;
    const { getSvgIcon, getGeopatternIconUrl, formatRelativeTime } = Pblsh.Utils;

    const hint = directoryHint && typeof directoryHint === 'object' ? directoryHint : {};
    // "Fresh" only means the directory has never seen a release — the repository
    // may still be years old (approved, never shipped). Only a recent creation
    // justifies the "freshly approved" framing and the celebration.
    const RECENTLY_CREATED_DAYS = 90;
    const createdTime = hint.created ? new Date(hint.created).getTime() : NaN;
    const isRecentlyCreated = !isNaN(createdTime) && Date.now() - createdTime <= RECENTLY_CREATED_DAYS * 24 * 60 * 60 * 1000;


    // Relative time of the repository creation commit — recency is the whole point
    // of the fresh states.
    const formatCommitTime = (iso) => formatRelativeTime(iso) ?? __('recently', 'peak-publisher');

    // The relative time wrapped in <time datetime> — machine-readable, with the
    // precise localized moment as native hover tooltip.
    const withCommitTime = (text, iso) => {
        return createInterpolateElement(text, { time: createElement('time', Pblsh.Utils.getTimeTooltipProps(iso)) });
    };

    // Celebration box — the fresh states' hero element, sitting where the other
    // states show the identity card (matching outer spacing). The own plugin
    // speaks personally ("your") and adds the invitation; an owner relation
    // implies a parsed creation commit, so the creation time is known there.
    const celebrationBox = (() => {
        if (accessStatus !== 'ok' || hint.state !== 'fresh') return null;
        const isOwn = hint.relation === 'owner';
        const title = isOwn
            ? (isRecentlyCreated
                ? __('Hooray — your plugin has recently been approved! 🎉', 'peak-publisher')
                : __('Your plugin has been approved, but not yet released.', 'peak-publisher'))
            : (isRecentlyCreated
                ? __('The plugin has recently been approved! 🎉', 'peak-publisher')
                : __('The plugin has been approved, but not yet released.', 'peak-publisher'));
        const desc = isOwn
            ? withCommitTime(
                sprintf(__('wordpress.org created your repository <time>%s</time>. Import the still empty state and ship your very first release.', 'peak-publisher'), formatCommitTime(hint.created)),
                hint.created,
            )
            : (isNaN(createdTime)
                ? __('wordpress.org created the repository, but it is still empty.', 'peak-publisher')
                : withCommitTime(
                    sprintf(__('wordpress.org created the repository <time>%s</time>.', 'peak-publisher'), formatCommitTime(hint.created)),
                    hint.created,
                ));
        return createElement('div', { className: 'pblsh--wporg-import-facts__celebrate' + (importing ? ' pblsh--importing-stripes' : '') },
            createElement('span', { className: 'pblsh--wporg-import-facts__celebrate-icon', 'aria-hidden': 'true' },
                getSvgIcon('shimmer', { size: 24 })),
            createElement('div', { className: 'pblsh--wporg-import-facts__celebrate-text' },
                createElement('strong', { className: 'pblsh--wporg-import-facts__celebrate-title' }, title),
                createElement('span', { className: 'pblsh--wporg-import-facts__celebrate-desc' }, desc),
            ),
        );
    })();

    // Identity card of the repository, shown for every non-fresh state: the
    // strongest safeguard against a wrong slug — the user sees WHICH plugin
    // lives under it before importing anything. Published brings the full
    // directory listing; closed and unknown fall back to slug and a generated
    // geopattern icon (the directory's own fallback for plugins without icons).
    // The whole card links to the live directory page — the natural place to
    // verify "is this really my plugin?" with screenshots, description and author.
    const identityCard = accessStatus === 'ok' && ['published', 'closed', 'unknown'].includes(hint.state) ? createElement('a', {
        className: 'pblsh--wporg-import-facts__plugin' + (importing ? ' pblsh--importing-stripes' : ''),
        href: 'https://wordpress.org/plugins/' + encodeURIComponent(slug) + '/',
        target: '_blank',
        rel: 'noreferrer',
    },
        createElement('img', {
            className: 'pblsh--wporg-import-facts__plugin-icon',
            src: hint.icon || getGeopatternIconUrl(slug),
            alt: '',
        }),
        createElement('div', { className: 'pblsh--wporg-import-facts__plugin-text' },
            createElement('strong', { className: 'pblsh--wporg-import-facts__plugin-name' }, hint.name || slug),
            hint.description ? createElement('span', { className: 'pblsh--wporg-import-facts__plugin-desc' }, hint.description) : null,
        ),
        createElement('span', { className: 'pblsh--wporg-import-facts__plugin-external', 'aria-hidden': 'true' },
            getSvgIcon('open_in_new', { size: 16 })),
    ) : null;

    // Every positive fact fits one compact line below identity card (published)
    // or celebration box (fresh) — importable content and the account relation,
    // separated by middle dots. A not_listed relation is no positive: it speaks
    // in the slug panel instead.
    const relationFacts = {
        owner: __('You are the owner of this plugin', 'peak-publisher'),
        committer: __('You are a committer of this plugin', 'peak-publisher'),
        listed: __('You are listed as a contributor', 'peak-publisher'),
    };
    const factsLineParts = (() => {
        if (accessStatus !== 'ok') return [];
        if (hint.state === 'published') {
            // The card itself already proves the plugin was found.
            return [
                Number.isFinite(hint.release_count)
                    ? sprintf(_n('%d release ready for import', '%d releases ready for import', hint.release_count, 'peak-publisher'), hint.release_count)
                    : null,
                relationFacts[hint.relation] || null,
            ].filter(Boolean);
        }
        if (hint.state === 'fresh') {
            return [
                __('Empty state ready for import', 'peak-publisher'),
                relationFacts[hint.relation] || null,
            ].filter(Boolean);
        }
        return [];
    })();
    const factsLine = factsLineParts.length > 0 ? createElement('div', { className: 'pblsh--wporg-import-facts__facts-line' },
        createElement('span', { className: 'pblsh--wporg-import-facts__facts-line-icon', 'aria-hidden': 'true' },
            getSvgIcon('check_bold', { size: 20 })),
        createElement('span', { className: 'pblsh--wporg-import-facts__facts-line-text' }, factsLineParts.join(' · ')),
    ) : null;

    // One pattern for "what this means + the way out": a prominent panel below
    // the facts. Warning when action is likely needed; info when the reader most
    // probably belongs here — an unreleased repo's slug is not public, so whoever
    // hits it almost certainly has a legitimate connection to the plugin.
    // Shorthand fixing the screen's spacing class — content structure (block
    // elements) is the caller's job, exactly as with any other NoticeBox.
    const panel = (variant, title, ...children) => createElement(NoticeBox, {
        variant,
        title,
        className: 'pblsh--wporg-import-facts__panel',
    }, ...children);
    const statePanel = (() => {
        // The one dead end of the screen (import disabled) — the stop octagon.
        if (accessStatus === 'not_found') {
            return panel('error',
                __('Not found on wordpress.org', 'peak-publisher'),
                createElement('p', null,
                    sprintf(__('There is no plugin %s on wordpress.org SVN.', 'peak-publisher'), slug)),
                createElement('p', null,
                    __('Please check the slug in the publish path at the top. If the slug is correct and your plugin was just approved, its repository may not exist yet — try again in a few minutes.', 'peak-publisher')));
        }
        // Transient probe failure — trying the import anyway stays possible.
        if (accessStatus === 'error') {
            return panel('warning',
                __('Access check failed', 'peak-publisher'),
                createElement('p', null,
                    accessMessage || __('Could not verify wordpress.org access for this slug.', 'peak-publisher')),
                createElement('p', null,
                    __('You can still try the import.', 'peak-publisher')));
        }
        if (accessStatus !== 'ok') return null;
        // Closed speaks in its own warning panel below the card; its relation is
        // always unknown, so this branch must come first.
        if (hint.state === 'closed') {
            const closedDate = hint.closed_date ? new Date(hint.closed_date) : null;
            const closedDateText = closedDate && !isNaN(closedDate.getTime()) ? closedDate.toLocaleDateString() : null;
            return panel('warning',
                __('Closed in the plugin directory', 'peak-publisher'),
                closedDateText ? createElement('p', null,
                    sprintf(__('wordpress.org closed this plugin on %s.', 'peak-publisher'), closedDateText)) : null,
                hint.reason ? createElement('p', null, createInterpolateElement(
                    sprintf(__('Reason: <em>%s</em>', 'peak-publisher'), hint.reason),
                    { em: createElement('em') },
                )) : null,
                createElement('p', null,
                    // "Commit to SVN", not "publish": closed is the one case
                    // where the commit and the public appearance split — that
                    // split is the sentence's whole message.
                    __('You can still import the plugin here. If your account has commit access, you can usually still commit updates to SVN — but wordpress.org will only distribute them once the plugin has been reopened.', 'peak-publisher')),
            );
        }
        // No resolvable relation (fresh without a parseable creation commit, or
        // the info API was unreachable) — the honest "not verified" info.
        if (!hint.relation || hint.relation === 'unknown') {
            return panel('info',
                __('Account connection not verified', 'peak-publisher'),
                createElement('p', null,
                    __('Could not determine how this plugin relates to your account. Your credentials were accepted — whether you can publish is decided by wordpress.org at publish time.', 'peak-publisher')),
            );
        }
        if (hint.relation !== 'not_listed') return null;
        // Fresh: no remedy is prescribed — whether the right move is switching the
        // account or being added as a committer depends on the team's workflow.
        return hint.state === 'fresh'
            ? panel('info',
                __('Created for another account', 'peak-publisher'),
                createElement('p', null, createInterpolateElement(
                    sprintf(__('Your configured account is <mine>%1$s</mine> — but wordpress.org created this repository for <owner>%2$s</owner>. You can import it anyway. Publishing updates will work if your account has been added as a committer; otherwise connect an account that has commit access.', 'peak-publisher'), username || '?', hint.owner || '?'),
                    { owner: createElement('strong'), mine: createElement('strong') },
                )),
            )
            : panel('warning',
                __('Your account doesn’t seem to be associated with this plugin', 'peak-publisher'),
                createElement('p', null,
                    __('No evidence was found that you manage this plugin. Please check the slug in the publish path at the top. You can still import — publishing updates requires commit access, which wordpress.org verifies at publish time.', 'peak-publisher')),
            );
    })();

    const importErrors = Array.isArray(errors) ? errors.filter(Boolean) : [];
    if (importErrors.length === 0 && !identityCard && !celebrationBox && !factsLine && !statePanel) return null;

    return createElement(wp.element.Fragment, null,
        createElement('div', { className: 'pblsh--wporg-import-facts__header' },
            createElement('h4', { className: 'pblsh--wporg-import-facts__title' },
                __('Import the current state', 'peak-publisher')),
            // The "why" of the screen lives in the FAQ, not in explanatory text.
            createElement(FaqLink, { faqKey: 'whyImport' }, __('Why is an import needed?', 'peak-publisher')),
        ),
        identityCard,
        celebrationBox,
        factsLine,
        statePanel,
        // Errors of the last attempt render last, next to the footer action that
        // caused them. The human message leads; the machine code stays visible
        // below for support cases. The title follows the code: when the import
        // succeeded and only the destination refresh after it failed, the panel
        // must not claim a failed import — a second click resumes cleanly.
        ...importErrors.map((error, index) => createElement(NoticeBox, {
            key: index,
            variant: 'error',
            title: error?.code === 'wporg_target_refresh_failed'
                ? __('Could not refresh the publish destination', 'peak-publisher')
                : __('Import failed', 'peak-publisher'),
            className: 'pblsh--wporg-import-facts__panel',
            error,
        })),
    );
};

lodash.set(window, 'Pblsh.Components.WporgImportFacts', WporgImportFacts);
