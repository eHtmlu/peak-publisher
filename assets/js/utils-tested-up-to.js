// Pure helpers for Tested up to — the vocabulary every surface speaks: the state of a value
// against the WordPress stable branch (Pblsh.Utils.getStableBranch(), from core's own version
// check; the checklist judges against the branch its facts recorded), the checklist's
// findings about an upload, the editor field's acceptance, the status item of a plugin. No
// state; wording in one place, like utils-current-release.js.
// User-facing texts say "the first two numbers" for x.y, never "major and minor": in WordPress's
// own vocabulary 7.1 is a major release and 7.1.3 a minor one.
lodash.set(window, 'Pblsh.TestedUpToUtils', (() => {
    const { __, sprintf } = wp.i18n;

    const majorMinor = (value) => {
        const match = /^(\d+)\.(\d+)/.exec(String(value || ''));
        return match ? [ Number(match[1]), Number(match[2]) ] : null;
    };
    const compare = (a, b) => (a[0] !== b[0] ? a[0] - b[0] : a[1] - b[1]);
    // The branch after a branch — "stable + 0.1" as wordpress.org computes it, rolling over at
    // x.9 to (x+1).0; the one definition the client has.
    const nextBranch = (branch) => (branch[1] >= 9 ? [ branch[0] + 1, 0 ] : [ branch[0], branch[1] + 1 ]);
    const nextBranchOf = (stableBranch) => {
        const branch = majorMinor(stableBranch);
        return branch ? nextBranch(branch).join('.') : '';
    };

    // 'none' | 'behind' (below the branch) | 'current' (the branch or the one after it —
    // wordpress.org accepts stable + 0.1) | 'ahead' (higher: wordpress.org ignores the value).
    // Without a known branch every value is current: nothing to judge against.
    function getState(value, stableBranch) {
        const v = majorMinor(value);
        const s = majorMinor(stableBranch);
        if (!v) return 'none';
        if (!s) return 'current';
        if (compare(v, s) < 0) return 'behind';
        return compare(v, nextBranch(s)) > 0 ? 'ahead' : 'current';
    }

    // What a missing value does where it shows: wordpress.org's plugin page carries the "not
    // tested with the latest releases" warning; the sites' update screen says a small "Not
    // tested" on both channels — nothing else in core reads the value. The checklist's line
    // for an upload without a value, and for a readme value wordpress.org drops (describeAhead()).
    function describeNoValue(isWporg) {
        return isWporg
            ? __('wordpress.org warns visitors that the plugin has not been tested with the latest WordPress releases and may no longer be maintained; the update screen says “Not tested”.', 'peak-publisher')
            : __('Sites say no more than “Not tested” on their update screen.', 'peak-publisher');
    }

    // Whether wordpress.org's plugin page warns visitors for a value: three branches behind,
    // its is_plugin_outdated() — below stable − 0.2, computed as it computes it, so 6.9 passes
    // at 7.1 and 6.8 does not. The same notice as for no value at all.
    function wporgWarnsVisitors(value, stableBranch) {
        const v = majorMinor(value);
        const s = majorMinor(stableBranch);
        return !!(v && s) && compare(v, majorMinor((Number(stableBranch) - 0.2).toFixed(1))) < 0;
    }

    // The judgement when what is distributed has no value, on both channels: whose value is
    // missing — the current release's, else trunk's (wordpress.org distributes trunk then).
    function describeMissingValue(plugin) {
        return plugin.version
            ? sprintf(__('Version %s has no Tested up to.', 'peak-publisher'), plugin.version)
            : __('trunk/readme.txt has no Tested up to.', 'peak-publisher');
    }

    // Where a release's value comes from, for the tooltip of the value — the place, nothing more:
    // readme.txt is the documented place of the value, and no sentence here may suggest the
    // plugin header were the preferred one.
    function describeSource(source) {
        return {
            header: __('From the plugin header', 'peak-publisher'),
            readme: __('From readme.txt', 'peak-publisher'),
            trunk: __('From trunk/readme.txt', 'peak-publisher'),
        }[source] || '';
    }

    // The field's acceptance: null when the value may be saved, else the sentence under the
    // field. Only the format refuses — a value above the next WordPress version is allowed, as
    // the upload allows it, and gets the hint and a confirm instead (getFieldHint()). The
    // example is the current WordPress version: never aging, and mostly the value to type.
    function getFieldError(value, stableBranch) {
        if (!/^\d+\.\d$/.test(String(value || '').trim())) {
            /* translators: %s: the current WordPress version, major.minor */
            return sprintf(__('A WordPress version like %s — the first two numbers only.', 'peak-publisher'), stableBranch);
        }
        return null;
    }

    // The field's warning for a value that does not exist yet, by channel and by where the
    // release's value is written (the source it has, the readme without one): the judgement and
    // the consequence, two lines like the checklist's; null otherwise.
    function getFieldHint(value, source, stableBranch, isWporg) {
        return getState(value, stableBranch) === 'ahead' ? describeAhead(value, source === 'header' ? 'header' : 'readme', stableBranch, isWporg) : null;
    }

    // Whether a new value is lower than the one before (both major.minor), for the field's hint.
    function isLower(value, before) {
        const v = majorMinor(value);
        const b = majorMinor(before);
        return !!(v && b) && compare(v, b) < 0;
    }

    // A value in the current branch or the next one: the judgement, which needs no second line.
    function describeCurrent(value, stableBranch) {
        const s = majorMinor(stableBranch);
        return !s || compare(majorMinor(value), s) === 0
            ? sprintf(__('Tested up to %s, the current WordPress version.', 'peak-publisher'), value)
            : sprintf(__('Tested up to %s, the upcoming WordPress version.', 'peak-publisher'), value);
    }

    // A value below the current WordPress version: the judgement and, as a second line, what
    // follows. Core's update screen says "Not tested" and the plugin details popup warns on
    // every site newer than the value — both channels, the public API serves the value as
    // wordpress.org does. The directory ranks by distance, and its plugin page warns visitors
    // from three branches behind (wporgWarnsVisitors()).
    function describeBehind(value, stableBranch, isWporg) {
        const judgement = sprintf(__('Tested up to %1$s, an older WordPress version (%2$s is current).', 'peak-publisher'), value, stableBranch);
        const effects = [
            sprintf(__('Sites on %s say “Not tested” on their update screen and warn in the plugin details.', 'peak-publisher'), stableBranch),
            isWporg && (wporgWarnsVisitors(value, stableBranch)
                ? __('wordpress.org ranks the plugin lower in search and warns visitors that the plugin has not been tested with the latest WordPress releases and may no longer be maintained.', 'peak-publisher')
                : __('wordpress.org ranks the plugin lower in search.', 'peak-publisher')),
        ].filter(Boolean).join(' ');
        return [ judgement, effects ];
    }

    // A value above the next WordPress version: the judgement and, as a second line, what
    // follows — by channel and by where the value comes from. wordpress.org's readme parser
    // drops such a value: the plugin page shows none and warns visitors, sites get none. A
    // plugin header value its import takes as it is, and its search ranks the plugin by the
    // distance of the value, so a far-off value sinks it. A value that reaches sites — every
    // self-hosted one, a header value on wordpress.org — counts there as tested with every
    // WordPress up to it, releases that do not exist yet included: core compares, nothing more.
    function describeAhead(value, source, stableBranch, isWporg) {
        const next = nextBranchOf(stableBranch);
        const judgement = sprintf(__('Tested up to %1$s, a WordPress version that does not exist yet (%2$s is next).', 'peak-publisher'), value, next);
        if (isWporg && source !== 'header') {
            return [ judgement, __('wordpress.org drops this value and treats the plugin as untested:', 'peak-publisher') + ' ' + describeNoValue(true) ];
        }
        const sites = sprintf(__('Sites take the value as it is and count the plugin as tested with every WordPress up to %s, including releases that do not exist yet.', 'peak-publisher'), value);
        return [ judgement, isWporg ? __('wordpress.org shows the value as it is, but its search ranks the plugin far down for it.', 'peak-publisher') + ' ' + sites : sites ];
    }

    // The places of the upload's Tested up to, one line after the judgement of the value
    // (facts = the target's tested_up_to facts of the analysis): the readme first, also when it
    // is a file without the line; the plugin header when it carries one; the predecessor when
    // its value wins — and, when the carry ran, what the upload is set to: the highest,
    // trimmed to major.minor. Without a readme file nothing is said about one — a self-hosted
    // plugin may ship without it, the header is its place then.
    function describeSources(facts, readmeFound) {
        const { upload, predecessor, carry } = facts;
        const items = [];
        if (readmeFound) {
            items.push(upload.readme ? sprintf(__('readme.txt says Tested up to %s', 'peak-publisher'), upload.readme) : __('readme.txt is missing its Tested up to', 'peak-publisher'));
        }
        if (upload.header) {
            items.push(items.length
                ? sprintf(__('the plugin header says %s', 'peak-publisher'), upload.header)
                : sprintf(__('The plugin header says Tested up to %s', 'peak-publisher'), upload.header));
        }
        if (carry && carry.from === 'predecessor') {
            items.push(sprintf(__('version %1$s of this plugin says %2$s', 'peak-publisher'), predecessor.version, predecessor.value));
        }
        /* translators: between the places of the Tested up to; there is a space after the comma */
        const places = items.join(__(', ', 'peak-publisher')) + '.';
        if (!carry) return places;
        // "The highest" only among several places; a single place is carried only for its form.
        const sentences = [ places, items.length > 1
            ? sprintf(__('Set to the highest, %s.', 'peak-publisher'), carry.value)
            : sprintf(__('Set to %s.', 'peak-publisher'), carry.value) ];
        if (carry.normalized) {
            sentences.push(sprintf(__('Only the first two numbers, since every %s.x counts the same anyway.', 'peak-publisher'), carry.value));
        }
        return sentences.join(' ');
    }

    // The checklist's findings about an upload, judged on the value after the carry (facts =
    // the target's tested_up_to facts of the analysis). One finding, one line — the effects of
    // a value behind the current branch are a line of their own, so the judgement stands alone
    // like the ok line.
    function getFindings({ facts, isWporg, stableBranch, becomesCurrent }) {
        const upload = facts.upload || {};
        const value = facts.carry ? facts.carry.value : (upload.value || '');
        // The carry sets the value where it already is and creates no line: a place without a
        // value stays without one. After it the lines agree — and without it they agree
        // already, so the header against the readme is never a finding; a readme without the
        // line is the checklist's own concern (its places line).
        const header = facts.carry ? (upload.header ? facts.carry.value : '') : upload.header;
        const findings = [];
        if (!value) return findings;
        const state = getState(value, stableBranch);
        if (state === 'behind') {
            const [ judgement, effects ] = describeBehind(value, stableBranch, isWporg);
            findings.push({ key: 'behind', text: judgement });
            findings.push({ key: 'behind_effects', text: effects });
        }
        if (state === 'ahead') {
            const [ judgement, effects ] = describeAhead(value, header ? 'header' : 'readme', stableBranch, isWporg);
            findings.push({ key: 'ahead', text: judgement });
            findings.push({ key: 'ahead_effects', text: effects });
        }
        if (becomesCurrent && facts.current && facts.current.value && isLower(value, facts.current.value)) {
            // After the judgement line: "this" is the value above.
            findings.push({ key: 'regression', text: sprintf(__('This falls below the current release (version %1$s), which says Tested up to %2$s.', 'peak-publisher'), facts.current.version, facts.current.value) });
        }
        return findings;
    }

    // The status item of a plugin for the list and the editor (null when nothing to say):
    // behind and ahead on both channels, none only on wordpress.org — self-hosted without a
    // value is a quiet state. The fact is the judgement alone — the list's tooltip, the
    // editor's tooltips and its notice's first line; the consequence (the checklist's second
    // line) and the remedy only the editor's notice adds, the remedy naming the pencil of the
    // current release's row. Without a current release (wordpress.org distributes trunk) there
    // is no row to name: the current release's notice right above says what to do.
    function getStatusItem(plugin, stableBranch) {
        const tested = plugin && plugin.tested_up_to;
        if (!tested) return null;
        const state = getState(tested.value, stableBranch);
        const remedy = (text) => (plugin.version ? sprintf(text, plugin.version) : null);
        if (state === 'behind') {
            const [ judgement, effects ] = describeBehind(tested.value, stableBranch, plugin.hosting_type === 'wporg');
            return {
                variant: 'warning',
                label: sprintf(__('Not tested with WordPress %s', 'peak-publisher'), stableBranch),
                fact: judgement,
                consequence: effects,
                remedy: plugin.version ? sprintf(__('Test the plugin with WordPress %1$s, then raise the value with the pencil of version %2$s below.', 'peak-publisher'), stableBranch, plugin.version) : null,
            };
        }
        if (state === 'ahead') {
            const [ judgement, effects ] = describeAhead(tested.value, tested.source, stableBranch, plugin.hosting_type === 'wporg');
            return {
                variant: 'warning',
                label: __('Tested up to does not exist yet', 'peak-publisher'),
                fact: judgement,
                consequence: effects,
                remedy: remedy(__('Correct it with the pencil of version %s below.', 'peak-publisher')),
            };
        }
        if (state === 'none' && plugin.hosting_type === 'wporg') {
            return {
                variant: 'warning',
                label: __('No Tested up to', 'peak-publisher'),
                fact: describeMissingValue(plugin),
                consequence: describeNoValue(true),
                remedy: remedy(__('Set it with the pencil of version %s below.', 'peak-publisher')),
            };
        }
        return null;
    }

    return { getState, wporgWarnsVisitors, describeNoValue, describeMissingValue, describeSource, describeCurrent, describeSources, getFieldError, getFieldHint, isLower, getFindings, getStatusItem };
})());
