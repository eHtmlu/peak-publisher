// WporgRevisionLink Component - an SVN revision as the receipt of a commit on wordpress.org:
// "r3670259", linked to that changeset on plugins.trac.wordpress.org, which shows date,
// author, message and every path the commit touched. A revision appears only where a commit
// is the subject — the assets tab's commit notice, the Stable tag flip, the wordpress.org
// side of a conflict — never as a figure of a file at rest. A sentence carries it as the
// <revision /> slot of createInterpolateElement. The r-notation is Subversion's and
// wordpress.org's own (commit messages, Trac links).
const WporgRevisionLink = ({ revision }) => {
    const { __ } = wp.i18n;
    const { createElement } = wp.element;

    return createElement('a', {
        href: 'https://plugins.trac.wordpress.org/changeset/' + revision,
        target: '_blank',
        rel: 'noreferrer',
        title: __('Open this commit on wordpress.org (Trac)', 'peak-publisher'),
    }, 'r' + revision);
};

lodash.set(window, 'Pblsh.Components.WporgRevisionLink', WporgRevisionLink);
