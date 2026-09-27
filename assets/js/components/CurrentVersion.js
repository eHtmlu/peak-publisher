// CurrentVersion Component - a plugin's current release as the list cell and the editor
// header show it: the version sites receive, or what they get instead ("trunk" on
// wordpress.org, nothing self-hosted) with a state glyph whose tooltip explains why.
// One rendering for both surfaces; the wording comes from Pblsh.CurrentReleaseUtils.
const CurrentVersion = ({ plugin }) => {
    const { createElement } = wp.element;
    const { Tooltip } = wp.components;
    const { getSvgIcon } = Pblsh.Utils;
    const { getCurrentReleaseIssue } = Pblsh.CurrentReleaseUtils;

    if (plugin && plugin.version) {
        return createElement('span', { className: 'pblsh--current-version' }, plugin.version);
    }
    const issue = getCurrentReleaseIssue(plugin);
    // wordpress.org falls back to trunk whenever the pointer does not name an existing tag;
    // while the pointer is unknown nothing can be claimed.
    const label = plugin && plugin.hosting_type === 'wporg' && issue && plugin.current_release_state !== 'unknown'
        ? 'trunk'
        : '\u2014';
    if (!issue) {
        return createElement('span', { className: 'pblsh--current-version' }, label);
    }
    return createElement('span', { className: 'pblsh--current-version pblsh--current-version--' + issue.variant },
        label,
        createElement(Tooltip, { text: issue.fact },
            createElement('span', { className: 'pblsh--current-version__glyph', tabIndex: 0, 'aria-label': issue.fact },
                getSvgIcon(issue.variant === 'warning' ? 'alert' : 'information_outline', { size: 16 })
            )
        ),
    );
};

lodash.set(window, 'Pblsh.Components.CurrentVersion', CurrentVersion);
