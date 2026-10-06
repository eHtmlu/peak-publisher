// Pure helpers for the assets tab (Pblsh.AssetsUtils): the vocabulary of the slots, their
// limits and files — shared by the boxes and the alerts; no state, like
// utils-installations.js. Sizes and limits are binary units, like wordpress.org's.
lodash.set(window, 'Pblsh.AssetsUtils', (() => {
    const { __, _n, sprintf } = wp.i18n;

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
    function getMetaLine(entry) {
        const parts = [];
        if (entry.width && entry.height) parts.push(entry.width + '×' + entry.height + ' px');
        const size = formatSize(entry.filesize);
        if (size) parts.push(size);
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

    // A wordpress.org plugin whose assets were never read from SVN (the first pull failed):
    // nothing can be built on them, the tab only shows — the notice says so beside its
    // Refresh link.
    const getNotSyncedNotice = () => __('The assets of this plugin have not been read from wordpress.org yet, so they cannot be changed here.', 'peak-publisher');

    // Files of assets/ that no slot of the tab shows — they stay on wordpress.org untouched.
    function getOtherFilesText(count) {
        return sprintf(_n(
            '%d other file in assets/ is not managed here (localized or right-to-left variants, unused sizes, blueprints)',
            '%d other files in assets/ are not managed here (localized or right-to-left variants, unused sizes, blueprints)',
            count, 'peak-publisher'
        ), count);
    }

    // The working copy of a wordpress.org plugin — the bar while it holds changes.
    const getPendingBarText = (count) => sprintf(_n('%d change not on wordpress.org yet', '%d changes not on wordpress.org yet', count, 'peak-publisher'), count);
    const getConflictBarText = (count) => sprintf(_n('%d conflict — resolve it to commit', '%d conflicts — resolve them to commit', count, 'peak-publisher'), count);
    const getCommittingText = () => __('Committing to wordpress.org…', 'peak-publisher');
    const getCommitsAsText = (username) => sprintf(__('Commits as "%s"', 'peak-publisher'), username);
    const getNoAccountCommitText = () => __('Committing assets needs your wordpress.org account — connect one under Settings › wordpress.org. Your changes are kept until then.', 'peak-publisher');
    const getClosedAssetsNotice = () => __('The plugin is closed on wordpress.org — its page shows a generated icon and no banner until it is reopened.', 'peak-publisher');
    // The commit's receipt; <revision /> is the WporgRevisionLink's slot (createInterpolateElement).
    const getCommittedText = () => __('Committed to wordpress.org in <revision />.', 'peak-publisher');
    const getShowWporgStateText = (showing) => showing ? __('Show my changes', 'peak-publisher') : __("Show wordpress.org's state", 'peak-publisher');

    // The commit's confirm with the tally the commit message will carry: puts are updated,
    // copies (move, swap) moved, deletes deleted — counted from the server's wporg.slots.
    function getCommitConfirmText(slots) {
        const counts = { put: 0, copy: 0, delete: 0 };
        Object.values(slots || {}).forEach((slot) => { if (slot.pending) counts[slot.pending]++; });
        const total = counts.put + counts.copy + counts.delete;
        const parts = [
            counts.put && sprintf(_n('%d updated', '%d updated', counts.put, 'peak-publisher'), counts.put),
            counts.copy && sprintf(_n('%d moved', '%d moved', counts.copy, 'peak-publisher'), counts.copy),
            counts.delete && sprintf(_n('%d deleted', '%d deleted', counts.delete, 'peak-publisher'), counts.delete),
        ].filter(Boolean);
        return sprintf(_n('Commit %d change to wordpress.org?', 'Commit %d changes to wordpress.org?', total, 'peak-publisher'), total) + '\n' + parts.join(', ');
    }

    const getDiscardConfirmText = (count) => sprintf(_n('Discard %d change? The assets return to their state on wordpress.org.', 'Discard %d changes? The assets return to their state on wordpress.org.', count, 'peak-publisher'), count);

    // The band's Discard of an upload: the file is lost with its change — a move or a delete is only taken back, unasked.
    const getDiscardUploadConfirmText = () => __('Discard the uploaded file? The slot returns to its state on wordpress.org.', 'peak-publisher');

    // A wordpress.org plugin's delete waits for the commit; a self-hosted one is gone at once.
    const getDeleteConfirmText = (isWporg) => isWporg
        ? __('Delete this asset? It is removed from wordpress.org with the next commit.', 'peak-publisher')
        : __('Delete this asset?', 'peak-publisher');

    // A box's band: what the working copy does to this slot.
    function getBandText(kind) {
        return {
            pending: __('Not on wordpress.org yet', 'peak-publisher'),
            delete: __('Will be deleted on wordpress.org', 'peak-publisher'),
            conflict: __('Changed here and on wordpress.org', 'peak-publisher'),
        }[kind];
    }

    return {
        getExpectedText, getTooLargeText, getMetaLine, getSwapConfirmText, getCaptionsSourceText, getPositionsHint, getNoCaptionsText, getNotSyncedNotice, getOtherFilesText,
        getPendingBarText, getConflictBarText, getCommittingText, getCommitsAsText, getNoAccountCommitText, getClosedAssetsNotice, getCommittedText, getShowWporgStateText,
        getCommitConfirmText, getDiscardConfirmText, getDiscardUploadConfirmText, getDeleteConfirmText, getBandText,
    };
})());
