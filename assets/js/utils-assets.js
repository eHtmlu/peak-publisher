// Pure helpers for the assets tab (Pblsh.AssetsUtils): the vocabulary of the slots, their
// limits and files — shared by the boxes and the alerts; no state, like
// utils-installations.js. Sizes and limits are binary units, like wordpress.org's.
lodash.set(window, 'Pblsh.AssetsUtils', (() => {
    const { __, sprintf } = wp.i18n;

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

    // Where the screenshot captions come from — the readme of the release sites receive, else
    // the latest release's (the server's captions_source: state, version, fallback).
    function getCaptionsSourceText(source) {
        if (!source.version) return __('Captions appear once a release with a readme.txt is published.', 'peak-publisher');
        return source.fallback
            ? sprintf(__('Captions from readme.txt of %s (latest — no current release)', 'peak-publisher'), source.version)
            : sprintf(__('Captions from readme.txt of %s (current release)', 'peak-publisher'), source.version);
    }

    // wordpress.org renders captions by position: moving a screenshot leaves its caption behind.
    const getPositionsHint = () => __('Captions are bound to positions, not images — reordering screenshots does not move their captions.', 'peak-publisher');

    // Screenshots without a single caption: one line instead of the caption details.
    const getNoCaptionsText = () => __('No captions in readme.txt yet', 'peak-publisher');

    return { getExpectedText, getTooLargeText, getMetaLine, getSwapConfirmText, getCaptionsSourceText, getPositionsHint, getNoCaptionsText };
})());
