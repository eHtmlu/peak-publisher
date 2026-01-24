=== Peak Publisher ===
Plugin Name: Peak Publisher
Author: eHtmlu
Author URI: https://www.wppeak.com/
Contributors: eHtmlu
Donate link: https://www.paypal.com/donate/?hosted_button_id=2G6L8NWVXZ4T4
Tags: publish, plugins, self-hosted, updates, server
Requires at least: 5.8
Requires PHP: 8.1
Tested up to: 6.9
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self‑host your plugin repository. Manage releases, serve updates, and streamline your workflow — all inside WordPress.

== Description ==

Peak Publisher turns your WordPress site into your own plugin update server. It’s built for agencies, product teams, and developers who want to create and ship their own custom plugins and want full control over distribution, versioning, and updates — without relying on third‑party services.

With a modern, task‑focused admin UI, you can add new plugins and releases via drag & drop, validate packages automatically, and publish or draft releases with one click. Your client plugins point to your Peak Publisher site via a standard `Update URI`, so WordPress will discover and install updates directly from you.

**With this solution, you can have your own self-hosted plugin update server in just 5 minutes, allowing you to centrally manage your plugins and deploy updates with incredible ease.**

== KEY FEATURES ==

🚀 **Clean admin UI with a guided “Add New Plugin” flow**
A short and focused user interface guides you through the entire process to deploy your first plugin with amazing ease within minutes.

☝️ **Drag & drop a ZIP or simply the whole plugin folder**
You can drop a ZIP file, but the easiest way is to simply drop the entire raw plugin folder or the folder's contents. The ZIP file will then be created automatically for you.

✅ **Automatic validation: headers, version, Update URI**
Peak Publisher automatically checks each new release for required headers, proper semantic versioning, consistent update URIs, and more to ensure everything is correct.

🧽🫧 **Auto‑cleanup of workspace artifacts (e.g. .git, node_modules)**
To provide clean packages and reduce package size, Peak Publisher automatically removes development files and operating system artifacts from your uploads using patterns that you can configure. (optional)

🔐 **Optional restriction via IP/domain whitelist**
Packages are stored in a private, server-protected directory with no direct web access. By default, access via the API is still possible from anywhere. Using IP or domain whitelisting, you can restrict access to update metadata and downloads.

= Some More Features =

📈 **Analytics:** You can always see how many active installations there are.
📄 **Readme.txt:** Provide your users with a description, changelog, tested up to and more.

== HOW IT WORKS ==

1. **Install Peak Publisher**
   on a dedicated WordPress site (recommended)
   or any site you control.
2. **Follow the "Add New Plugin" flow**
   add the **`Update URI`** header and the bootstrap code to your plugin.
3. **Upload your plugin**
   drag & drop the zipped plugin or the plugin folder.
4. **Peak Publisher validates your upload**
   and shows you the validation result.
5. **Click "Add new plugin"**
   to finish the process.
6. **Drop your next release with increased version number**
   once you have one ready.

== WHO IS IT FOR? ==

- Agencies that deliver custom plugins to multiple clients
- Product teams with private/proprietary extensions
- Creative plugin developers who want to deploy updates quickly and easily

== Privacy ==

Peak Publisher does not collect personal data, does not track usage, and does not use third‑party services. All files are stored on your server in a protected directory. 

== Screenshots ==

1. A fresh installation of Peak Publisher looks like this.
2. Settings dialog (General): We recommend using standalone mode, which you can activate here.
3. Settings dialog (Uploads): By default, your uploads are cleaned up. We recommended keeping this settings.
4. Settings dialog (Security): Here you can restrict access to your plugins to specific IP addresses.
5. Standalone mode deactivates several admin menu items as well as the entire front end.
6. When you click on the "Add New Plugin" button, you will see this first step, which instructs you to add the required plugin headers.
7. The second step instructs you to add the small bootstrap code to your plugin, which checks for updates.
8. Once you have added the headers and bootstrap code, you can simply drop the plugin folder into Peak Publisher.
9. For large plugins, the progress bar helps you track the status of the upload.
10. After uploading, the plugin is automatically analysed and cleaned up if necessary.
11. Once all steps are complete, you will see whether everything is correct.
12. Once the new plugin has been added, the plugin's details page is displayed.
13. The plugin will now also be displayed in the overview of all plugins.
14. To upload a new plugin or a new plugin version, simply drag a plugin folder into Peak Publisher.
15. Peak Publisher automatically recognises whether a new upload is a new plugin or a new version of an existing plugin.
16. All releases are listed in the plugin's details view.

== Frequently Asked Questions ==

= Does this replace wordpress.org? =
No. Peak Publisher is a private/self‑hosted alternative for your own plugins and use cases where .org is not applicable.

= How do client sites find updates? =
Your client plugin includes an `Update URI` header that points to your Peak Publisher site. WordPress core will call the exposed endpoints to retrieve update metadata and packages.

= What data is collected? =
None. Peak Publisher does not track users or send telemetry. All communication happens between the client WordPress site and your Peak Publisher instance.

= Can I restrict access to downloads? =
Yes. You can optionally configure an IP/domain whitelist for the public endpoints. For stricter access control, consider placing your Peak Publisher site behind VPN, a reverse proxy, or adding your own authentication layer.

= Do you support semantic versioning? =
Yes. The validator recognizes major/minor/patch successions and warns on unexpected jumps.

== Changelog ==

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
