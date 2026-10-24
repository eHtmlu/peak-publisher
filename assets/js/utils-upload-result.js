// Pure helpers for the upload result view — no state; shared by the upload checks
// engine (upload-checks.js) and GlobalDropOverlay.js.
lodash.set(window, 'Pblsh.UploadResultUtils', (() => {
    const { __ } = wp.i18n;

    function formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const value = Number(bytes) || 0;
        if (value <= 0) return '0 B';
        const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        return (value / Math.pow(1024, index)).toFixed(2) + ' ' + units[index];
    }

    function getReleaseContext(meta = {}) {
        const related = meta?.related_releases || {};
        const previousRelease = related?.previous || false;
        const nextRelease = related?.next || false;
        const latestRelease = related?.latest || false;
        const existingRelease = related?.existing || false;

        const previousReleaseVersion = previousRelease?.normalized_version ? previousRelease.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        const pluginVersion = meta?.plugin_info?.normalized_version ? meta.plugin_info.normalized_version.split('.').map((v) => isNaN(Number(v)) ? -1 : Number(v)) : [];
        const naturalSuccessors = [];

        if (previousReleaseVersion.length > 0 && pluginVersion.length > 0) {
            while (previousReleaseVersion.length < pluginVersion.length) {
                previousReleaseVersion.push(0);
            }
            while (pluginVersion.length < previousReleaseVersion.length) {
                pluginVersion.push(0);
            }

            for (let i = 0; i < previousReleaseVersion.length; i++) {
                naturalSuccessors.push([...previousReleaseVersion.slice(0, i), previousReleaseVersion[i] + 1, ...(new Array(pluginVersion.length - i - 1).fill(0))].join('.'));
            }
        }

        const isNaturalSuccessor = naturalSuccessors.includes(pluginVersion.join('.'));
        // The release kind is a relation to the previous release — without one
        // there is nothing to classify (a release-less plugin is in its
        // new-plugin moment instead; see getReleasePresentation).
        const releaseKind = {
            major: meta.existing_plugin && previousRelease
                && pluginVersion[0] > previousReleaseVersion[0],
            minor: meta.existing_plugin && previousRelease
                && pluginVersion[0] === previousReleaseVersion[0] && pluginVersion[1] > previousReleaseVersion[1],
            patch: meta.existing_plugin && previousRelease
                && pluginVersion[0] === previousReleaseVersion[0] && pluginVersion[1] === previousReleaseVersion[1] && pluginVersion[2] > previousReleaseVersion[2],
        };
        releaseKind.unknown = meta.existing_plugin && !releaseKind.major && !releaseKind.minor && !releaseKind.patch;

        return {
            previousRelease,
            nextRelease,
            latestRelease,
            existingRelease,
            hasReleaseData: !!(previousRelease || nextRelease || latestRelease || existingRelease),
            previousReleaseVersion,
            pluginVersion,
            naturalSuccessors,
            isNaturalSuccessor,
            releaseKind,
        };
    }

    function getReleasePresentation(meta, releaseContext, isWporg, isClosedWporg = false) {
        const existingRelease = releaseContext.existingRelease || false;
        const releaseKind = releaseContext.releaseKind || {};
        let state = 'invalid';

        // Closed plugins take the honest verb: the commit still works, but
        // wordpress.org distributes nothing while closed — "publish"/"replace"
        // would overpromise (same register exception as the closed warnings).
        const wporgPublishLabel = isClosedWporg
            ? __('Commit to wordpress.org SVN', 'peak-publisher')
            : __('Publish on wordpress.org', 'peak-publisher');
        const wporgReplaceLabel = isClosedWporg
            ? __('Commit to wordpress.org SVN', 'peak-publisher')
            : __('Replace on wordpress.org', 'peak-publisher');

        if (meta.plugin_ok) {
            // Both identity moments share the solid green — the color carries
            // the moment, the wording carries the truth: NEW PLUGIN creates an
            // entry that exists nowhere yet, FIRST RELEASE gives an already
            // existing entry (imported wporg marker, or a release-less plugin)
            // its first content.
            if (!meta.existing_plugin) {
                state = 'new_plugin';
            } else if (!releaseContext.hasReleaseData) {
                state = 'first_release';
            } else if (existingRelease) {
                state = 'replace_release';
            } else if (releaseKind.major) {
                state = 'new_major_release';
            } else if (releaseKind.minor) {
                state = 'new_minor_release';
            } else if (releaseKind.patch) {
                state = 'new_patch_release';
            } else {
                state = 'new_release';
            }
        }

        const presentations = {
            invalid: {
                classNames: ['pblsh--upload-result--invalid'],
                type: __('Invalid', 'peak-publisher'),
                submitLabel: '',
            },
            new_plugin: {
                classNames: ['pblsh--upload-result--newplugin'],
                type: __('New Plugin', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add Plugin', 'peak-publisher'),
            },
            first_release: {
                classNames: ['pblsh--upload-result--firstrelease'],
                type: __('First Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add First Release', 'peak-publisher'),
            },
            replace_release: {
                classNames: ['pblsh--upload-result--releasereplacement'],
                type: __('Replace Existing Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgReplaceLabel : __('Replace Existing Release', 'peak-publisher'),
            },
            new_major_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newmajor'],
                type: __('New Major Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add Major Release', 'peak-publisher'),
            },
            new_minor_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newminor'],
                type: __('New Minor Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add Minor Release', 'peak-publisher'),
            },
            new_patch_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newpatch'],
                type: __('New Patch Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add Patch Release', 'peak-publisher'),
            },
            new_release: {
                classNames: ['pblsh--upload-result--newrelease', 'pblsh--upload-result--newunknown'],
                type: __('New Release', 'peak-publisher'),
                submitLabel: isWporg ? wporgPublishLabel : __('Add Release', 'peak-publisher'),
            },
        };
        return presentations[state];
    }

    return { formatBytes, getReleaseContext, getReleasePresentation };
})());
