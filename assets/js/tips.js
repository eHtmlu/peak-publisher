// Tips Registry - the in-app knowledge behind TipLink: generic plugin know-how
// that helps in the moment but is not about Peak Publisher itself (questions
// about the product belong to the plugin FAQ, see FaqLink). Each tip is a
// function returning { title, content } — the title is the dialog heading and
// doubles as the TipLink's default link text, so the link always promises
// exactly what the dialog delivers. Registered centrally so the same tip can be
// linked from any screen.
//
// Editorial line: tips supplement wordpress.org's own documentation, they do
// not mirror it. A tip earns its place by giving the reader something the
// docs would not — an insight from experience, or an early heads-up on a topic
// that bites late (a rejected name, a wasted review round) — so nobody has to
// read the full guidelines and FAQ first. Rules and numbers that live in those
// documents are linked, not repeated: they change over there, and a copy here
// would only fall behind. The intent differs as well: the review tips are
// about getting through fastest, not about reciting the team's regulations.
lodash.set(window, 'Pblsh.Tips', {

    // The six recommended headers explained one by one, grouped by what they
    // are for: presentation to people vs. compatibility checks by WordPress.
    recommendedHeaders: () => {
        const { __ } = wp.i18n;
        const { createElement, createInterpolateElement } = wp.element;

        return {
            title: __('6 highly recommended plugin headers', 'peak-publisher'),
            content: [
                createElement('p', { key: 'intro' },
                    __('Beyond the required headers, these six are highly recommended:', 'peak-publisher'),
                ),
                createElement('h2', { key: 'people-title' }, __('What people see', 'peak-publisher')),
                createElement('dl', { key: 'people-list', className: 'pblsh--plugin-headers' },
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Description')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('A short summary of what your plugin does. Every site that installs it shows this line next to the plugin\'s name.', 'peak-publisher')),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Author')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('The name behind the plugin, shown as "By …" in the plugin list. It tells site owners who built the plugin and who to ask.', 'peak-publisher')),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Author URI')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('Turns the author name into a link to your website.', 'peak-publisher')),
                        ),
                    ),
                ),
                createElement('h2', { key: 'wordpress-title' }, __('What WordPress checks', 'peak-publisher')),
                createElement('dl', { key: 'wordpress-list', className: 'pblsh--plugin-headers' },
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Requires at least')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('The minimum WordPress version your plugin needs. It lets WordPress warn and block installs on sites that are too old, instead of letting the plugin break them.', 'peak-publisher')),
                            createElement('p', null, createInterpolateElement(
                                __('<strong>Pro tip:</strong> State only the first two digits, like 6.4 — that automatically covers all of its minor releases (6.4.1, 6.4.2, …). Two digits are the minimum though: a bare 6 would not work.', 'peak-publisher'),
                                { strong: createElement('strong') },
                            )),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Tested up to')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('The latest WordPress version you have verified your plugin against. Sites use it to judge whether an update is safe.', 'peak-publisher')),
                        ),
                    ),
                    createElement('div', { className: 'pblsh--plugin-headers__group' },
                        createElement('dt', { className: 'pblsh--plugin-headers__term' }, createElement('code', null, 'Requires PHP')),
                        createElement('dd', { className: 'pblsh--plugin-headers__desc' },
                            createElement('p', null, __('The minimum PHP version your code needs. WordPress blocks activation on servers below it, preventing fatal errors from incompatible code.', 'peak-publisher')),
                        ),
                    ),
                ),
                createElement('p', { key: 'docs' },
                    createInterpolateElement(
                        __('A full list of headers can be found in the <a>WordPress documentation</a>.', 'peak-publisher'),
                        { a: createElement('a', { href: 'https://developer.wordpress.org/plugins/plugin-basics/header-requirements/', target: '_blank', rel: 'noreferrer' }) },
                    ),
                ),
            ],
        };
    },

    // What gets wordpress.org submissions rejected — the checks worth doing
    // BEFORE uploading. Facts verified against the plugin directory's own
    // submission code (meta repo: plugin-directory Trademarks class and upload
    // handler) — re-check there when the trademark list looks dated.
    wporgAcceptance: () => {
        const { __ } = wp.i18n;
        const { createElement, createInterpolateElement } = wp.element;

        return {
            title: __('Will your plugin be accepted?', 'peak-publisher'),
            content: [
                createElement('h2', { key: 'slug-title' }, __('The name becomes the slug — forever', 'peak-publisher')),
                createElement('p', { key: 'slug' }, __('The plugin name you submit determines the slug: your plugin\'s permanent URL on wordpress.org. Once approved, it can never be changed — so choose it carefully.', 'peak-publisher')),
                createElement('h2', { key: 'trademark-title' }, __('No trademarks in the name', 'peak-publisher')),
                createElement('p', { key: 'trademark' }, createInterpolateElement(
                    __('The directory checks the plugin name and its slug against a list of trademarks. Most of them must not lead the name: <code>google-xyz</code> is rejected, <code>xyz-for-google</code> is fine. The most common ones on that list:', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('ul', { key: 'trademark-list', className: 'pblsh--content-list' },
                    createElement('li', null, createInterpolateElement(
                        __('<strong>WordPress products:</strong> WooCommerce, Jetpack, Akismet, Gutenberg, bbPress, BuddyPress', 'peak-publisher'),
                        { strong: createElement('strong') },
                    )),
                    createElement('li', null, createInterpolateElement(
                        __('<strong>popular plugins:</strong> Elementor, Divi, Contact Form 7, Gravity Forms, Ninja Forms, Advanced Custom Fields, Easy Digital Downloads, Yoast', 'peak-publisher'),
                        { strong: createElement('strong') },
                    )),
                    createElement('li', null, createInterpolateElement(
                        __('<strong>big brands:</strong> Google, Amazon, Apple, Microsoft, Facebook, Instagram, WhatsApp, Twitter, YouTube, TikTok, PayPal, Stripe, Mailchimp, ChatGPT', 'peak-publisher'),
                        { strong: createElement('strong') },
                    )),
                ),
                createElement('p', { key: 'trademark-anywhere' }, createInterpolateElement(
                    __('Some are rejected anywhere in the name, not just at the front: WordPress, Gutenberg, WooCommerce, Facebook, Instagram, WhatsApp, and Yoast. The one exception is a name ending in <code>-for-woocommerce</code>. Two more surprises: the word <code>plugin</code> is not allowed anywhere in the name, and a <code>wp-</code> prefix is rejected for new submissions.', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('h2', { key: 'allowed-title' }, __('Not every plugin type is allowed', 'peak-publisher')),
                createElement('p', { key: 'allowed-intro' }, __('The directory rejects, among others:', 'peak-publisher')),
                createElement('ul', { key: 'allowed-list', className: 'pblsh--content-list' },
                    createElement('li', null, __('plugins whose free version cannot work on its own (demo shells for a paid product)', 'peak-publisher')),
                    createElement('li', null, __('plugins that mainly advertise or upsell another product', 'peak-publisher')),
                    createElement('li', null, __('libraries and frameworks without functionality of their own', 'peak-publisher')),
                ),
                createElement('p', { key: 'allowed-gpl' }, createInterpolateElement(
                    __('And everything — code and assets alike — must be <a>GPL-compatible</a>.', 'peak-publisher'),
                    { a: createElement('a', { href: 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#1-plugins-must-be-compatible-with-the-gnu-general-public-license', target: '_blank', rel: 'noreferrer' }) },
                )),
                createElement('p', { key: 'allowed-docs' }, createInterpolateElement(
                    __('The full set of rules is in the <guidelines>Detailed Plugin Guidelines</guidelines>; which plugin types are turned away outright is spelled out in the <faq>Plugin Developer FAQ</faq>.', 'peak-publisher'),
                    {
                        guidelines: createElement('a', { href: 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/', target: '_blank', rel: 'noreferrer' }),
                        faq: createElement('a', { href: 'https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/#are-there-plugins-you-dont-accept', target: '_blank', rel: 'noreferrer' }),
                    },
                )),
                createElement('h2', { key: 'check-title' }, __('Run Plugin Check before you upload', 'peak-publisher')),
                createElement('p', { key: 'check' }, createInterpolateElement(
                    __('The submission form runs the <a>Plugin Check</a> plugin against your ZIP and refuses it if the check fails. Run it locally first and you skip that round entirely.', 'peak-publisher'),
                    { a: createElement('a', { href: 'https://wordpress.org/plugins/plugin-check/', target: '_blank', rel: 'noreferrer' }) },
                )),
            ],
        };
    },

    // What happens after submitting — expectation management for the wait.
    wporgReview: () => {
        const { __ } = wp.i18n;
        const { createElement, createInterpolateElement } = wp.element;

        return {
            title: __('What to expect from the review', 'peak-publisher'),
            content: [
                createElement('h2', { key: 'wait-title' }, __('The wait varies — check the queue', 'peak-publisher')),
                createElement('p', { key: 'wait' }, __('Reviews are done by a small volunteer team, so the wait ranges from days to months. The submission page shows the current queue length, so you know what you are in for.', 'peak-publisher')),
                createElement('h2', { key: 'spam-title' }, __('Watch your spam folder', 'peak-publisher')),
                createElement('p', { key: 'spam' }, createInterpolateElement(
                    __('All review communication arrives by email from <code>plugins@wordpress.org</code> — and it notoriously lands in spam. Whitelist the address: a review whose emails go unanswered ends in rejection.', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('h2', { key: 'reply-title' }, __('Keep replies short, complete, and friendly', 'peak-publisher')),
                createElement('p', { key: 'reply' }, __('Every round trip costs days, sometimes weeks — so make each reply count: fix everything the email lists, then answer in that same thread with the corrected version. One complete reply, not one per issue, and never a fresh submission.', 'peak-publisher')),
                createElement('p', { key: 'reply-tone' }, __('Keep it brief, friendly, and matter-of-fact, and follow every request even where it seems petty or absurd. The reviewers are volunteers applying the same rules to everyone: arguing rarely changes the outcome, it only adds rounds.', 'peak-publisher')),
                createElement('h2', { key: 'queue-title' }, __('One submission at a time', 'peak-publisher')),
                createElement('p', { key: 'queue' }, __('New authors can only have one plugin in the review queue at once — plan accordingly if you have several in the drawer.', 'peak-publisher')),
                createElement('h2', { key: 'once-title' }, __('Only the first version is reviewed', 'peak-publisher')),
                createElement('p', { key: 'once' }, __('Once approved, every later update goes live without another review. The hurdle you are looking at is a one-time thing.', 'peak-publisher')),
            ],
        };
    },

    // Version-number guidance for the first release and beyond.
    versionNumbers: () => {
        const { __ } = wp.i18n;
        const { createElement, createInterpolateElement } = wp.element;

        return {
            title: __('4 facts about version numbers', 'peak-publisher'),
            content: [
                createElement('h2', { key: 'format-title' }, createInterpolateElement(
                    __('The recommended and most common format is <code>major.minor.patch</code>', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('p', { key: 'format-intro' }, createInterpolateElement(
                    __('This convention is called <a>semantic versioning</a>:', 'peak-publisher'),
                    { a: createElement('a', { href: 'https://semver.org/', target: '_blank', rel: 'noreferrer' }) },
                )),
                createElement('table', { key: 'format-table', className: 'pblsh--content-table' },
                    createElement('thead', null, createElement('tr', null,
                        createElement('th', { scope: 'col' }, createElement('code', null, 'major')),
                        createElement('th', { scope: 'col' }, createElement('code', null, 'minor')),
                        createElement('th', { scope: 'col' }, createElement('code', null, 'patch')),
                    )),
                    createElement('tbody', null, createElement('tr', null,
                        createElement('td', null, __('breaking changes', 'peak-publisher')),
                        createElement('td', null, __('new features', 'peak-publisher')),
                        createElement('td', null, __('bug fixes', 'peak-publisher')),
                    )),
                ),
                createElement('p', { key: 'format-note' }, __('When you publish a release, Peak Publisher derives its major, minor, and patch badges from this format. Technically, though, it handles any version format WordPress itself can compare.', 'peak-publisher')),
                createElement('h2', { key: 'start-title' }, createInterpolateElement(
                    __('It\'s okay to start at <code>1.0.0</code>', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('p', { key: 'start-modesty' }, createInterpolateElement(
                    __('From a purely technical standpoint, there\'s nothing wrong with version number <code>0.1.0</code>, but a plugin is never truly finished anyway. So don\'t be modest and dare to name your first version what it is: <code>1.0.0</code> — it might even boost your self-confidence 😉', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('h2', { key: 'growth-title' }, createInterpolateElement(
                    __('After <code>1.9</code> comes <code>1.10</code>', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('p', { key: 'growth-counters' }, createInterpolateElement(
                    __('Version numbers look like decimals, but they are not: every part is an independent counter that can grow beyond 9. And in version terms, <code>1.10</code> is higher than <code>1.9</code>, not lower. So never bump the first number just because the middle one looks "full" — a new major version is a statement about breaking changes, not about running out of digits.', 'peak-publisher'),
                    { code: createElement('code') },
                )),
                createElement('h2', { key: 'order-title' }, __('Ordering can surprise you', 'peak-publisher')),
                createElement('p', { key: 'order-intro' }, __('WordPress decides which version is newer by comparing position by position — which doesn\'t always give the result you\'d expect:', 'peak-publisher')),
                createElement('ul', { key: 'order-list', className: 'pblsh--content-list' },
                    createElement('li', null, createInterpolateElement(
                        __('<code>1.0</code> and <code>1.0.0</code> are not the same — the longer one counts as newer.', 'peak-publisher'),
                        { code: createElement('code') },
                    )),
                    createElement('li', null, createInterpolateElement(
                        __('<code>v1.2</code> is older than <code>0.1</code> — a letter in front sorts below any number.', 'peak-publisher'),
                        { code: createElement('code') },
                    )),
                ),
                createElement('p', { key: 'order-advice' }, createInterpolateElement(
                    __('So think twice before using letters or changing the number of positions along the way. Under the hood, WordPress hands this to PHP\'s <code>version_compare()</code> — for more details check out the <a>PHP documentation</a>.', 'peak-publisher'),
                    {
                        code: createElement('code'),
                        a: createElement('a', { href: 'https://www.php.net/manual/en/function.version-compare.php', target: '_blank', rel: 'noreferrer' }),
                    },
                )),
            ],
        };
    },

});
