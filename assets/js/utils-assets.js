// Pure helpers for the assets tab (Pblsh.AssetsUtils): the vocabulary of the slots, their
// limits and files — shared by the boxes and the alerts; no state, like
// utils-installations.js. Sizes and limits are binary units, like wordpress.org's.
lodash.set(window, 'Pblsh.AssetsUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;
    const { formatRelativeTime } = Pblsh.Utils;

    const MB = 1048576;
    // A limit: '1 MB', '4 MB', '10 MB' (whole binary MB).
    const formatLimit = (bytes) => (bytes / MB) + ' MB';
    // A file size for the box: '820 B', '12.3 KB', '1.4 MB'.
    const formatSize = (bytes) => {
        if (!bytes || bytes <= 0) return null;
        if (bytes < 1024) return bytes + ' B';
        if (bytes < MB) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / MB).toFixed(1) + ' MB';
    };
    // A file size against a limit, rounded up to one decimal like the server's message, so a
    // file just over the limit never reads as the limit itself: '1.1 MB', '820 KB'.
    const formatSizeOverLimit = (bytes) => {
        if (bytes < MB) return Math.ceil(bytes / 1024) + ' KB';
        const tenths = Math.ceil(bytes * 10 / MB);
        return (tenths % 10 === 0 ? String(tenths / 10) : (tenths / 10).toFixed(1)) + ' MB';
    };
    const typePlural = (type) => ({ icon: __('icons', 'peak-publisher'), banner: __('banners', 'peak-publisher'), screenshot: __('screenshots', 'peak-publisher') })[type];

    // The empty box's one line: "Expected: PNG/JPG/GIF · 128×128 px · max 1 MB" — the slash
    // joins the alternatives, the middle dot separates the requirements.
    function getExpectedText(slotDef) {
        const parts = [ (slotDef.exts || []).map((ext) => ext.toUpperCase()).join('/') ];
        if (slotDef.expectedW && slotDef.expectedH) parts.push(slotDef.expectedW + '×' + slotDef.expectedH + ' px');
        parts.push(sprintf(__('max %s', 'peak-publisher'), formatLimit(slotDef.maxBytes)));
        return __('Expected:', 'peak-publisher') + ' ' + parts.join(' · ');
    }

    // The client's check before an upload; the server keeps the same limit (asset_too_large).
    function getTooLargeText(slotDef, fileSize) {
        return sprintf(__('The file is %1$s — %2$s may be at most %3$s (the wordpress.org limit).', 'peak-publisher'), formatSizeOverLimit(fileSize), typePlural(slotDef.type), formatLimit(slotDef.maxBytes));
    }

    // The box's figures: "128×128 px · 12.3 KB" — muted fragments, · separated.
    // A wordpress.org file adds the SVN revision it was last changed in: "· r3670259".
    function getMetaLine(entry, withRevision = false) {
        const parts = [];
        if (entry.width && entry.height) parts.push(entry.width + '×' + entry.height + ' px');
        const size = formatSize(entry.filesize);
        if (size) parts.push(size);
        if (withRevision && entry.revision) parts.push('r' + entry.revision);
        return parts.join(' · ');
    }

    // A drop onto an occupied position swaps the two screenshots — nothing is lost.
    function getSwapConfirmText(fromN, toN) {
        return sprintf(__('Swap screenshots %1$d and %2$d?', 'peak-publisher'), fromN, toN);
    }

    // Where the screenshot captions come from (the server's captions_source: state, version,
    // fallback): the readme of the current release; on wordpress.org without a tag pointer the
    // trunk readme, which is what the plugin page shows then; else the latest release's, a
    // fallback. The pointer wording follows the editor header (utils-current-release.js).
    function getCaptionsSourceText(plugin, source) {
        const isWporg = plugin.hosting_type === 'wporg';
        switch (source.state) {
            case 'current':
                return sprintf(__('Captions from readme.txt of %s (current release)', 'peak-publisher'), source.version);
            case 'trunk':
            case 'none':
                if (isWporg) return __('Captions from readme.txt of trunk (wordpress.org distributes trunk)', 'peak-publisher');
                break;
            case 'tag_missing':
                if (isWporg) return sprintf(__('Captions from readme.txt of trunk (Stable tag %s does not exist — wordpress.org distributes trunk)', 'peak-publisher'), plugin.pointer);
                break;
            case 'unknown':
                if (source.version) return sprintf(__('Captions from readme.txt of %s (latest — trunk/readme.txt could not be read)', 'peak-publisher'), source.version);
                break;
        }
        // Self-hosted without a current release, and wordpress.org with an unreadable pointer and no tag.
        return source.version
            ? sprintf(__('Captions from readme.txt of %s (latest — no current release)', 'peak-publisher'), source.version)
            : __('Captions appear once a release with a readme.txt is published.', 'peak-publisher');
    }

    // wordpress.org renders captions by position: moving a screenshot leaves its caption behind.
    const getPositionsHint = () => __('Captions are bound to positions, not images — reordering screenshots does not move their captions.', 'peak-publisher');

    // Screenshots without a single caption: one line instead of the caption details.
    const getNoCaptionsText = () => __('No captions in readme.txt yet', 'peak-publisher');

    // Where the mirror of a wordpress.org plugin stands (the server's wporg block): the
    // revision of assets/ it holds and when it was last confirmed — the moment inside
    // <time></time> for the caller's exact-timestamp tooltip.
    function getSyncedText(wporg) {
        const checked = formatRelativeTime(wporg.listed_at);
        if (wporg.revision === null || !checked) return __('Not synced with wordpress.org yet', 'peak-publisher');
        if (wporg.revision === 0) return sprintf(__('wordpress.org has no assets/ directory for this plugin · checked <time>%s</time>', 'peak-publisher'), checked);
        return sprintf(__('Synced with wordpress.org · r%1$d · checked <time>%2$s</time>', 'peak-publisher'), wporg.revision, checked);
    }

    // Files of assets/ that no slot of the tab shows — they stay on wordpress.org untouched.
    function getOtherFilesText(count) {
        return sprintf(_n(
            '%d other file in assets/ is not managed here (localized or right-to-left variants, unused sizes, blueprints)',
            '%d other files in assets/ are not managed here (localized or right-to-left variants, unused sizes, blueprints)',
            count, 'peak-publisher'
        ), count);
    }

    return { getExpectedText, getTooLargeText, getMetaLine, getSwapConfirmText, getCaptionsSourceText, getPositionsHint, getNoCaptionsText, getSyncedText, getOtherFilesText };
})());
