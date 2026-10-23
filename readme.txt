=== Peak Publisher ===
Plugin Name: Peak Publisher
Author: wppeak.com
Author URI: https://www.wppeak.com/
Contributors: eHtmlu
Donate link: https://www.wppeak.com/donate/
Tags: publish, plugins, self-hosted, updates, wordpress.org
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One place for all your plugins: publish to wordpress.org without SVN, self‑host the rest, and serve updates — all inside WordPress.

== Description ==

Peak Publisher is one place for all your plugins, wherever they are hosted. Plugins on wordpress.org are published by dropping the plugin folder — Peak Publisher talks to SVN for you, builds the tag, sets the Stable tag and commits once. Plugins for clients, teams and products are self‑hosted: your WordPress site becomes their update server, and installed copies receive updates directly from you via a standard `Update URI`.

https://www.youtube.com/watch?v=HtbrkzKiIUE

Both channels share one task‑focused admin UI: add plugins and releases via drag & drop, let every upload be validated, decide with one switch which release your sites receive, manage icons, banners, screenshots and the Live Preview, keep Tested up to current, and see installations, downloads and ratings in one list.

**Publish your first plugin in minutes — to wordpress.org or to your own update server — and every next release with a single drop.**

== KEY FEATURES ==

🚀 **Clean admin UI with a guided “Add New Plugin” flow**
The flow starts with the channel and asks only what is still needed: for wordpress.org where your plugin stands (not submitted yet, approved, or already published — then it is imported with its releases and assets), for self‑hosted the headers and the bootstrap code to add.

☝️ **Drag & drop a ZIP or simply the whole plugin folder**
You can drop a ZIP file, but the easiest way is to drop the raw plugin folder or the folder's contents — anywhere in Peak Publisher. It recognizes whether the drop is a new plugin or a new version of an existing one.

📦 **Publish to wordpress.org without an SVN client**
Peak Publisher reads trunk and the tags, creates the new tag as a copy of trunk or the previous tag plus what changed, updates trunk, writes the Stable tag and commits once — large plugins in seconds, not minutes. Every commit is recorded with its revision and links to its changeset on Trac.

✅ **Automatic validation with a checklist that explains itself**
Headers, version format, Update URI, Tested up to, Requires Plugins, readme.txt — every finding is a line of the checklist with what it means and, where you have a choice, a decision to confirm.

🎯 **The current release is a decision, not a status**
On every upload a switch says whether the new version is what sites receive. “Make current release” promotes or rolls back any version later. On wordpress.org this is the Stable tag — written for you, never forgotten — and the current release cannot be deleted by accident.

🖼️ **Assets by wordpress.org's rules — on both channels**
Icons, banners and screenshots with the right names, sizes and byte limits; screenshot captions come from the current release's readme. On wordpress.org, your changes collect in a working copy and go up in one commit; changes made elsewhere are shown side by side instead of being overwritten.

▶️ **Live Preview**
Edit the blueprint of your wordpress.org plugin with fields for landing page, theme and plugins — or as JSON — and test it in WordPress Playground before you commit. The required plugins of the current release are kept in the preview, and a status line tells you whether wordpress.org has imported the file and whether the preview is public.

🧪 **Tested up to per release**
See which version was tested with which WordPress, raise it with one click, and let the next upload carry the highest known value. A status column in the plugin list shows what needs attention.

📈 **Figures for both channels**
Self‑hosted: unique installations of the last 24 hours and downloads per day. wordpress.org: active installs, downloads and rating from the directory, refreshed daily — the download history is kept beyond the directory's window.

🧽🫧 **Auto‑cleanup of workspace artifacts (e.g. .git, node_modules)**
To provide clean packages and reduce package size, Peak Publisher removes development files and operating system artifacts from your uploads using patterns that you can configure. (optional)

🔐 **Optional restriction via IP/domain whitelist**
Self‑hosted packages are stored in a private, server‑protected directory with no direct web access. By default the API is reachable from anywhere; with an IP or domain whitelist you restrict access to update metadata and downloads.

🔑 **Encrypted wordpress.org credentials**
The SVN password is stored encrypted (AES‑256‑GCM) with a key outside the database — see the FAQ.

= Some More Features =

📄 **Readme.txt:** Provide your users with a description, changelog, screenshots, tested up to and more — the "View details" popup of a self‑hosted plugin looks like the one of a wordpress.org plugin.
🧭 **Standalone mode:** Turn a dedicated site into a pure update server — frontend and unneeded admin menus off, with a redirect URL of your choice.
📋 **Operations log:** Every write Peak Publisher makes to your published plugins is recorded — on wordpress.org every commit with its account and revision.

== HOW IT WORKS ==

= Self‑hosted =

1. **Install Peak Publisher**
   on a dedicated WordPress site (recommended)
   or any site you control.
2. **Follow the "Add New Plugin" flow**
   choose Self‑hosted, then add the **`Update URI`** header and the bootstrap code to your plugin.
3. **Upload your plugin**
   drag & drop the zipped plugin or the plugin folder.
4. **Read the checklist**
   Peak Publisher validates your upload and shows you the result.
5. **Click "Add new plugin"**
   to finish the process.
6. **Drop your next release with an increased version number**
   once you have one ready — the switch "Set as current" decides whether sites receive it.

= wordpress.org =

1. **Connect your wordpress.org account**
   under Settings → wordpress.org with your username and SVN password.
2. **Follow the "Add New Plugin" flow**
   choose wordpress.org and say where your plugin stands: not submitted yet (the flow takes you to the submission page), approved but nothing published yet, or already published.
3. **Import or upload**
   a published plugin is imported with its releases, assets and figures; an approved one is published with its first upload.
4. **Drop your next release**
   Peak Publisher creates the tag, updates trunk, writes the Stable tag and commits once.
5. **Manage the rest in the editor**
   assets and the Live Preview, Tested up to, "Make current release" — each a commit under your account.

== WHO IS IT FOR? ==

- Plugin developers who publish on wordpress.org and want to leave the SVN client behind
- Agencies that deliver custom plugins to multiple clients
- Product teams with private/proprietary extensions
- Anyone with plugins on both sides who wants them in one list

== External services ==

Peak Publisher contacts wordpress.org as soon as you connect a wordpress.org account or import a wordpress.org plugin — for exactly those plugins, and only then. Without an account and without wordpress.org plugins in Peak Publisher, it contacts no external service, with the one exception of embedded videos named below. Client sites of your self‑hosted plugins talk to your Peak Publisher site only.

= wordpress.org =

Operated by the WordPress Foundation and Automattic. Privacy policy: https://wordpress.org/about/privacy/ — the terms for publishing plugins: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/

**When your server contacts it:**

* **api.wordpress.org** — the plugin directory's info API, to notice changes made elsewhere and to fetch the figures of your wordpress.org plugins: every 5 minutes while someone looks at the plugin list, during import, and for the Live Preview the listings of other plugins and themes; the download statistics once a day.
* **plugins.svn.wordpress.org** — the plugin repository. Reads without credentials during import and whenever the directory shows that something changed. Writes with your username and SVN password: publishing a release, "Make current release", deleting a release, committing assets and the Live Preview, setting Tested up to.
* **wordpress.org** — the plugin page, to check whether the Live Preview is public, when you look at the Live Preview; and the profile avatar lookup when you connect, edit or test the account.
* **core.svn.wordpress.org** — the default theme of the current WordPress branch, once per branch, when you look at a Live Preview.

**What is sent:** the slugs of your plugins, the files you publish (plugin files, assets, the blueprint), your account's username and — only to plugins.svn.wordpress.org, over HTTPS — its SVN password, and your server's IP address. Every request identifies itself as "PeakPublisher/{version}"; your site's address is never part of a request.

**In your browser:** the admin UI loads plugin icons from s.w.org and ps.w.org, theme screenshots from ts.w.org and the account's avatar from secure.gravatar.com (Gravatar, by Automattic — terms: https://wordpress.com/tos/, privacy: https://automattic.com/privacy/). These servers see your browser's IP address and, as referrer, your site's domain. The Live Preview's "Test" button opens playground.wordpress.net with the blueprint, and the download of a wordpress.org release comes from downloads.wordpress.org.

= Embedded videos in a readme =

When the readme.txt of a self‑hosted plugin contains a video URL alone on a line, Peak Publisher asks the provider's oEmbed endpoint for the embed code — exactly as wordpress.org does, limited to its providers (YouTube, Vimeo, WordPress.com, WordPress.tv, Vine, SoundCloud, Instagram, Mixcloud, Cloudup) and without any account. The provider sees your server's IP address and the video URL. Change the list with the filter `pblsh_readme_oembed_providers`.

== Privacy ==

Peak Publisher does not track users and sends no telemetry. The only external service it talks to is wordpress.org, for the plugins you keep there, as described under "External services". For self‑hosted plugins, all communication happens between the client sites and your Peak Publisher site.

About the client sites of a self‑hosted plugin, Peak Publisher stores two things: the installation count keeps, per site, a salted and non‑reversible hash of its home URL, the installed version and when it was last seen — for 24 hours, and only while the count is switched on under Settings → Self‑hosted; the download count keeps one number per day. All files are stored on your server in a protected directory.

== Screenshots ==

1. A fresh installation: no plugins yet, the full WordPress menu still in place.
2. Settings: the recommended first step is standalone mode, which turns the site into a pure update server.
3. Standalone mode on: the front end and the menus Peak Publisher does not need are gone.
4. "Add New Plugin" starts with the channel — wordpress.org or self‑hosted — and what each means for your users.
5. Self‑hosted plugins need the Update URI header: the flow shows the exact line, with a copy button.
6. The bootstrap code that lets installed copies ask your site for updates, ready to copy.
7. The upload step: drop a ZIP, the plugin folder or the folder's files.
8. The upload runs with a progress bar, so large plugins stay transparent.
9. The checklist of the first upload: every finding explained, workspace artifacts cleaned, and "Set as current" decides what sites receive.
10. The plugin editor right after the first release: current release, installations, downloads and Tested up to in the header.
11. The plugin list with the first plugin.
12. From now on, drop anywhere in Peak Publisher to upload a new plugin or release.
13. The next release: the checklist recognizes the version step and shows what the installed release will be.
14. Every release listed and downloadable by version; the filled ring marks the current release.
15. "Make current release" promotes or rolls back any version with one click.
16. Tested up to per release: raise it with the pencil, right in the row.
17. Connect your wordpress.org account with its SVN credentials; the password is stored encrypted on your server.
18. For wordpress.org, say where your plugin stands: not submitted yet, approved, or already published.
19. Import your published wordpress.org plugins with their releases, assets and figures.
20. Self‑hosted and wordpress.org plugins side by side, each with its channel, current release, figures and a status column.
21. A wordpress.org release: the same drop, the same checklist, and the Stable tag set for you.
22. Publishing to wordpress.org shows its steps — reading, sending, saving — with no SVN client involved.
23. The editor of a wordpress.org plugin: active installs, downloads, Tested up to and rating from the directory.
24. Assets by wordpress.org's rules: icon, banner and screenshots, each with its place on the plugin page; pending changes go up in one commit.
25. Live Preview: landing page, theme and plugins as fields, the JSON in expert mode, and a Test button that opens Playground.
26. Settings: the wordpress.org account, verified, beside the self‑hosted options for the installation count and the whitelist.

== Frequently Asked Questions ==

= Does this replace wordpress.org? =
No — it works with it. For plugins on wordpress.org, Peak Publisher is the SVN client you no longer need: it publishes, manages assets and the Live Preview and keeps the Stable tag right, while wordpress.org stays the update server. For plugins that do not belong on wordpress.org — client work, private or proprietary extensions — Peak Publisher is the update server itself.

= How do client sites find updates? =
Self‑hosted: your plugin includes an `Update URI` header that points to your Peak Publisher site, and the bootstrap code makes WordPress core ask its endpoints for update metadata and packages. wordpress.org: as always, through wordpress.org.

= What data is collected? =
Nothing about people. Peak Publisher sends no telemetry, and its requests never carry your site's address. What it stores about the client sites of self‑hosted plugins and what it sends to wordpress.org is described under "Privacy" and "External services".

= What is the current release? =
The version sites receive. Releases have no status: every release is listed and downloadable by version, and the plugin points to the one that is current. Self‑hosted, Peak Publisher serves it; on wordpress.org it is the Stable tag in trunk/readme.txt, which Peak Publisher writes for you. Every upload decides with the switch "Set as current" — a higher version becomes current by default, a pre‑release or a hotfix of an older line does not — and "Make current release" in the release list changes it later, on both channels. The current release cannot be deleted; make another release current first.

= What happened to my release drafts? =
Peak Publisher 1.x used drafts to hold a release back. Since 2.0, every release is listed and downloadable by version, and only the current release is offered as an update. After the update, a notice names every former draft; if a version must not be reachable at all, download its ZIP and delete the release.

= How do I update Tested up to? =
In the editor, every release has a pencil in its Tested up to column. Self‑hosted, the readme.txt inside the release ZIP is updated in place; on wordpress.org, one commit writes the tag and, when trunk carries the same version, trunk — sites see the new value once wordpress.org has processed the commit. The next upload carries the highest known value — its own or the previous release's — into the readme line that already exists. readme.txt is the place for the value; if your main plugin file carries a `Tested up to` line as well, wordpress.org and Peak Publisher take that one, so keep both in step or leave the header line out.

= What is the Live Preview? =
A button on your plugin's wordpress.org page that opens WordPress Playground with your plugin installed and activated — a full WordPress in the visitor's browser. It is driven by `assets/blueprints/blueprint.json`, which Peak Publisher lets you edit with fields for the landing page, the theme and the plugins (or as JSON), test in Playground and commit together with the other assets. The preview installs the required plugins of the current release before your plugin, and a publish that changes the current release keeps them in step. Making the preview public is done on wordpress.org (Advanced View on the plugin page); Peak Publisher shows you whether it is.

= What does Peak Publisher change in my upload? =
Only what the checklist tells you: workspace artifacts are removed by the patterns in the settings (optional), readme.txt is stored as UTF‑8 without a BOM, the release ZIP gets the plugin's slug as its top‑level folder, a lower Tested up to is raised to the highest known value in the lines that already carry one, and on wordpress.org the Stable tag in trunk/readme.txt is set when the release becomes current.

= Can I restrict access to downloads? =
Yes. For self‑hosted plugins you can optionally configure an IP/domain whitelist for the public endpoints. For stricter access control, consider placing your Peak Publisher site behind a VPN or a reverse proxy, or adding your own authentication layer.

= Do you support semantic versioning? =
Yes. The validator recognizes major/minor/patch increments and warns on unexpected jumps.

= Which version numbers can I publish? =
Digits and dots, optionally followed by -alpha, -beta or -RC and a number, for example 1.2.0 or 1.2.0-beta1.

While wordpress.org does accept other arbitrary strings, it issues a warning if the format deviates from the one specified above.

WordPress compares versions with PHP's version_compare(). Its documentation lists the order dev < alpha < beta < RC < number < pl, but not that every word is matched by its first letters: 1.0.anton counts as 1.0.alpha, 1.0.banana as 1.0.beta, and 1.0.patch sorts above 1.0.1, while words matching nothing sort even below dev. Since such results cannot be anticipated from the documentation, and wordpress.org has adopted the stricter format as well, Peak Publisher applies it to both channels.

= Which distribution channel should I choose? =
Choose wordpress.org if your plugin is free and open-source and you want it listed in the official WordPress plugin directory so that it can be easily found and installed by everyone — however, your plugin must first be [reviewed and approved](https://developer.wordpress.org/plugins/wordpress-org/) by the WordPress.org plugin team.

Otherwise choose Self-hosted — releases are then distributed directly from your own server, without any review process, and go live instantly. Note that self-hosted plugins are also publicly accessible via the update API by default; you can restrict access with the IP/domain whitelist in the settings.

= Can I use both distribution channels for one plugin? =
No. A plugin uses exactly one distribution channel — either wordpress.org or self-hosted — and this choice applies to all future releases.

Technically, the same code (apart from the `Update URI` header and the bootstrap code) could be shipped through both channels, but those would be two different plugins that merely share the same slug — a setup that is strongly discouraged.

The only sensible exception is a temporary overlap while migrating from self-hosted to wordpress.org (see the next question): during the transition, the same code with the same slug is published through both channels — although Peak Publisher still manages these as two separate plugins.

= Can I switch the distribution channel later? =
Switching away from wordpress.org is practically impossible, because wordpress.org's technical plugin requirements prevent redirecting installed copies to another update source.

Switching from self-hosted to wordpress.org, however, is possible, **provided that your plugin has been approved by the WordPress.org plugin team under the same slug it already uses.** Once approved, simply ship one last self-hosted release that removes the `Update URI` header and the bootstrap code — installed copies will then receive future updates from wordpress.org.

**Warning:** In theory, a migration under a different slug might also work, but it can result in installed copies no longer receiving updates or even being deactivated unintentionally. In short: Only do this if you enjoy taking big risks — even if you plan to test it extensively.

= How are my wordpress.org credentials stored? =
Your wordpress.org SVN password is encrypted with AES-256-GCM before it is saved to the WordPress database. The encryption key is generated automatically and stored as a protected file inside `wp-content/uploads/` — so a database dump alone does not expose your password. Note that key and encrypted data still live on the same server, which is the common approach for WordPress plugins.

You can optionally harden this: define a `PBLSH_ENCRYPTION_KEY` constant in your `wp-config.php` and its key material is used in addition to the key file — an attacker then needs both locations. Generate a value with `php -r "echo 'base64:' . base64_encode(random_bytes(32));"` and add it as `define('PBLSH_ENCRYPTION_KEY', 'base64:...');`. Since this changes the key material, you will be asked to re-enter your password once afterwards. The same applies when you remove the constant again.

Deleting Peak Publisher from the Plugins screen in WordPress removes the stored credentials and the key file; your plugins and releases stay. Removing the plugin folder by hand leaves everything in place.

= Why is an import needed to manage a wordpress.org plugin? =
Peak Publisher caches information about plugins, releases, and assets to ensure fast and smooth operation. To minimize the load on the wordpress.org SVN server, the data is permanently cached and only updated selectively when changes are made to the SVN repository. Therefore, the cache needs to be built once for each new plugin.

Of course, WordPress.org remains the update server and the primary data source. You can even manage the same plugin from multiple Peak Publisher instances and directly via SVN at the same time. Peak Publisher always retrieves the latest data automatically to display up-to-date information. Every commit it makes is marked "via Peak Publisher" in the SVN log.

= Which filters are there? =
* `pblsh_readme_oembed_providers` — the video providers a readme may embed (default: wordpress.org's list, see "External services").
* `pblsh_readme_shortcodes` — the shortcodes a readme may use (default: youtube, vimeo).
* `pblsh_enable_banner_svg` — adds a slot for banner.svg; off by default because wordpress.org imports the file but never shows it.

== Changelog ==

= 2.0.0 - 2026-10-22 =
**wordpress.org as a distribution channel**

* Connect a wordpress.org account (username and SVN password, stored encrypted) and import your published plugins with their releases, assets and figures
* Publish by drag & drop: the new tag is created as a copy of trunk or the previous tag plus what changed, trunk is updated and the Stable tag written — in one commit, large plugins in seconds
* "Make current release" and deleting a release work on wordpress.org too; the current release is protected from deletion
* Assets: icons, banners and screenshots mirrored from SVN, edited in a working copy and committed together; conflicts with changes made elsewhere are shown side by side
* Live Preview: edit the plugin's blueprint.json with fields for landing page, theme and plugins or as JSON, test it in Playground, see whether wordpress.org has imported it and whether the preview is public; the required plugins of the current release are kept in the preview
* Figures from the directory: active installs, downloads and rating, refreshed daily; the download history is kept beyond the directory's window
* Operations log: every commit Peak Publisher makes is recorded with its account and revision and links to its changeset on Trac
* The list and the editor follow changes made on wordpress.org through the directory, without touching SVN
* Publishing shows its steps and survives gateway timeouts; a second publish while one runs is refused and the running one followed instead

**Both channels**

* Release drafts are gone: every release is listed and downloadable by version; the current release — the one sites receive — is decided on every upload and changed with "Make current release" (see Upgrade Notice)
* Tested up to per release: shown in the list, the editor and the upload checklist, set with one click, carried to the highest known value at upload; the public API serves it
* Version numbers must follow the wordpress.org format: digits and dots, optionally -alpha, -beta or -RC with a number
* Assets follow wordpress.org's rules on both channels: slots, file names, byte limits; screenshot captions come from the current release's readme
* Requires Plugins: served by the public API in wordpress.org's format; a wordpress.org publish is refused when the import would reject the header
* The plugin's slug comes from the folder, ZIP or main file name; every release ZIP has the slug as its root (the setting "Add top-level folder if missing" is gone)
* readme.txt is always stored as UTF-8 without a BOM (the setting is gone)
* The readme parser and the icon generator are wordpress.org's current code: readmes render as the directory renders them, generated icons match its
* Daily download counts for self-hosted plugins, served as `downloaded` by the public API
* "Add New Plugin" starts with the channel and, for wordpress.org, with where the plugin stands; tips explain headers, review, captions, Live Preview and version numbers
* Status column in the plugin list, the channel and slug of every plugin, figures aligned with the name, the list sorted by name
* New page header and footer, the logo in the admin menu, relative times, settings grouped into General, Uploads, wordpress.org and Self-hosted
* Deleting Peak Publisher from the Plugins screen removes the wordpress.org accounts and the encryption key; plugins and releases stay
* New filters: `pblsh_readme_oembed_providers`, `pblsh_readme_shortcodes`, `pblsh_enable_banner_svg`

**Security**

* A readme's content is never read as a path or URL (before, a readme that began with http(s):// was fetched, and one consisting of a single path line was read from disk)
* Readme sections are rendered under wordpress.org's limits: no oEmbed discovery (before, every URL alone on a line was fetched by the server), embeds only from the provider list, no shortcodes of the site
* SVG assets must be free of active content — scripts, event handlers, javascript: links — on both channels: an upload is refused, and a file on wordpress.org that fails the rule is kept off this site, which serves its assets from its own origin

**Fixes**

* Installation count of self-hosted plugins
* Number of files and folders shown for an upload
* Release slug collisions between the two channels
* Plugin header lines crossing the 8 KB boundary are left alone
* curl_close(), deprecated in PHP 8.5, is no longer called; servers without curl_multi read wordpress.org one request at a time
* Release ZIPs keep empty folders
* Self-hosted releases can be built on WordPress before 6.4.4 again; since 1.3.1 the publish failed there

= 1.3.1 - 2026-04-04 =
* Fixed minor compatibility issues with WordPress 7.0

= 1.3.0 - 2026-03-21 =
* Added standalone redirect URL setting for redirecting frontend visitors in standalone mode
* Added visual highlighting of the update notification icon in the admin toolbar in standalone mode

= 1.2.0 - 2026-03-08 =
* Added asset management: upload, replace, reorder, and delete plugin icons, banners, and screenshots directly in the admin UI
   * Icons, banners, and screenshots are served to client sites via the wordpress.org-compatible API
   * Geopattern fallback icons for plugins without a custom icon (following the WordPress.org convention)
* Added permalink structure check: shows a notice when "Plain" permalinks are active (REST API requirement)

= 1.1.3 - 2026-02-12 =
* New bootstrap code (basicV2): multisite support and safe handling when update transient has no response/no_update keys
* Upload workflow detects basicV1 vs basicV2 bootstrap and shows the matching recommendation

= 1.1.2 - 2026-01-24 =
* Fixed description tab for plugins without a readme.txt (added previous solution as a fallback)

= 1.1.1 - 2026-01-22 =
* Fixed small issue in version number validation logic

= 1.1.0 - 2026-01-22 =
* Added Features:
 * Installation count (and option to disable)
 * Support for readme.txt files (to provide "view details" popup informations)
 * Deep linking in the admin UI
* Added auto cleanup for temporary files
* Fixed a problem with the number of files when creating ZIP archives on the client side (there was an unintentional limit of 100 files per folder).
* Refactoring

= 1.0.2 - 2025-12-22 =
* Fixed info about required PHP version

= 1.0.1 - 2025-12-22 =
* Fixed small compatibility issue

= 1.0.0 - 2025-12-22 =
Initial release.

== Upgrade Notice ==

= 2.0.0 =
Release drafts are gone: every release is listed and downloadable by version, and the one marked current is what sites receive. A notice after the update names every former draft. New: publish to wordpress.org without SVN.
