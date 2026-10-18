# Data schema

Live: schema version 0 (Peak Publisher 1.3.1, released 2026-04-04 on wordpress.org)

Two schemas exist, never more. The **live schema** is the one publicly rolled out — the data on
users' sites: Peak Publisher 1.3.1 knows no schema version option (`pblsh_schema_version` absent
= live), keeps release posts with status `publish`/`draft` whose content is the upload state
of finalize, md5 or sha256 content hashes over the workspace directory, and assets manifest
entries `{filename, revision, resolution, local}`. The **current schema** is what the
code in this repository creates today:

- **Posts:** `pblsh_plugin` (self-hosted plugin, `post_name` = slug, status Public/Draft = the
  distribution switch), `pblsh_wporg_plugin` (wporg marker; `post_content` = the regenerable SVN
  cache `{revision, release_count, fetched_at, trunk_readme}`, `trunk_readme` = `{stable_tag,
  file_name, screenshots, tested}` of trunk's readme), `pblsh_release` (child of the
  plugin, `post_title` = version, `post_name` from `get_release_slug()`; `post_content` = the
  snapshot `{plugin_data, plugin_info, plugin_readme_txt}` on both channels — self-hosted
  derived from the release ZIP (`release_snapshot_from_zip()`, `includes/releases.php`), wporg
  from the tag, with `tag_revision` there; `plugin_data` carries `TestedUpTo` and, on every
  WordPress version, `RequiresPlugins` beside WordPress's headers (`read_plugin_headers()`);
  `post_date` = when the version
  was first published: self-hosted the upload, kept by a Replace; wporg the first creation of
  its tag in SVN (`wporg_tag_publication_times()`), kept on every later change of the tag;
  status always `publish`), `pblsh_wporg_account` (a connected wordpress.org account:
  `post_title` = the username in the spelling the commit history proves or the user entered,
  unique without regard to case; `post_author` = who connected it; `post_date` = when; status
  always `publish`; `includes/wporg_accounts.php`).
- **Post meta:** plugin `_pblsh_current_release` (version string of the current release, `''` =
  none; written only by finalize, the flip and the migration), the assets manifest
  `assets_icons` / `assets_banners` / `assets_screenshots` (keyed by filename, one entry per
  slot: `{filename, revision, resolution, filesize, width, height}` — revision is the time of
  the last change; `includes/assets.php`), `assets_banners_color` (the average color of the
  first banner file, for the generated icon),
  `_pblsh_installations` (24-hour counting cache, regenerable, not declared), `_pblsh_downloads`
  (the download history: UTC day `Y-m-d` → delivered release ZIPs, unbounded, absent = none
  yet; its total is the sum of its days; `includes/functions.php`); both plugin types
  `_pblsh_operations` (the operations log: every write Peak Publisher made to the published
  state, newest first, unbounded — `{operation, at, user {id, login}, username, revision,
  details}`; wporg the SVN commits with account and revision, self-hosted the edits of a
  release after its upload with username and revision null; `includes/operations_log.php`);
  marker: the same
  manifest metas and `assets_banners_color` describing the assets mirror (revision = the SVN
  revision the file was last changed in; regenerable from SVN), `_pblsh_wporg_assets`
  (`{revision, other_files, color_source, blueprints, pending}` — the revision of `assets/` the
  mirror holds, `0` = no directory, `null` = never pulled; `blueprints` = the mirror of
  `assets/blueprints/`, regenerable: `{revision, blueprint_files, other_files, file}` — the
  directory's revision, `null` = none; the files wordpress.org's import takes there and the
  others; `file` = `blueprint.json` `{revision, filesize, contents}`, `contents` `null` over
  wordpress.org's 100 KB, `file` `null` = none; `pending` = the working copy, user work: slot id →
  `{action: put|copy|delete, file (put), from {slot, filename, revision} (copy), base {filename,
  revision}|null, ext, filesize, width, height, at, user {id, login}}`, and for the Live Preview
  `blueprint` → `{action: put|delete, contents (put), base {filename, revision}|null, at, user
  {id, login}}`; stored slashed — the blueprint's JSON keeps its backslashes;
  `includes/wporg_assets.php`),
  `_pblsh_downloads` (the download history from the stats API, merged day by day: UTC day
  `Y-m-d` → downloads; the API's window of 730 days moves on, what it leaves behind stays
  here and is not regenerable; `includes/wporg_directory.php`),
  `_pblsh_wporg_account_username`, `_pblsh_wporg_directory` (the directory cache: the daily info API figures and the
  check's record — the stamp `{version, last_updated, assets: filename → revision}` of the last completed check, the
  blueprints of `assets/blueprints/` among the assets, when that was and the last failure since —, the day's claim of
  the download history's fetch and the last check of the Live Preview's public switch `{public, checked_at}`,
  regenerable, not declared), `_pblsh_wporg_import` (the import forecast
  `{expected_at, reason, assets, stable_touched_at, computed_at}`, regenerable, not declared); release `_pblsh_zip_path`,
  `_pblsh_directory_content_hash` (sha256 over the paths and file contents of the shipped
  tree, `content_hash_from_entries()` in `includes/releases.php`; `''` when the ZIP is
  missing), `_pblsh_upload_state` (both channels: the upload state `$data` of finalize,
  verbatim — the forensic record of the upload; before schema 1 the self-hosted release
  post's content); account `_pblsh_password` (the
  AES-256-GCM ciphertext of the SVN password, `includes/encryption.php`), `_pblsh_verdict`
  (`{verified_at, rejected_at}` — wordpress.org's latest verdict about the credentials, null =
  never / not since), `_pblsh_avatar_url` (the resolved Gravatar URL, `''` = unresolved).
- **Options:** `pblsh_settings` (settings), `pblsh_secret_salt`, `pblsh_encryption_context_id`,
  `pblsh_schema_version` (autoloaded), `pblsh_upgrade_notice` (facts of the migration until
  dismissed), `pblsh_wporg_plugin_listings` (the directory listings of the other plugins the
  Live Preview names — `{slug: {name, icon, state, checked_at}}`, a cache, regenerable;
  `includes/wporg_directory.php`), `pblsh_schema_migration_lock` (only while the migration runs),
  `pblsh_wporg_deploy_lock_{slug}` (only while an operation holds the plugin's write lock:
  `{key, blog_id, lock_blog_id, acquired_at, alive_at, username, operation, wporg_slug,
  plugin_id}`; a commit renews `alive_at`, a lock without it moving for 5 min is taken over;
  `classes/WporgOperations.php`).
- **Files** under `wp-content/uploads/pblsh-peak-publisher/`:
  `plugins/{slug}/releases/{slug}.{version}.zip` (an entry for every directory, empty ones
  included), `plugins/{slug}/assets/`,
  `wporg-plugins/{slug}/mirror/assets/` (the slot files of a wporg plugin's SVN `assets/`,
  regenerable), `wporg-plugins/{slug}/pending/assets/` (the files of the working copy's puts
  under their canonical names, user work), `encryption-key.php`, `tmp/` (upload working directories, transient: the files, `cache.json`
  with the upload state, and from finalize on `finalize.json` — `{started_at, alive_at, phase,
  done, total, response}`, `includes/uploads.php` —, the only file left after a successful
  finalize; the first upload started 24 hours or more after it removes the directory,
  `maybe_cleanup_tmp_uploads()`).

`uninstall.php` removes the secrets when WordPress deletes the plugin (Plugins screen, WP-CLI): the account posts,
`pblsh_encryption_context_id` and `encryption-key.php`; everything else stays for a reinstall.

Everything between live and current is development state and disposable: development and test
data are regenerated, never migrated. Migration code moves data from the live schema to the
current one only: `includes/upgrade.php`, one migration function, threshold
`get_option('pblsh_schema_version') < PBLSH_SCHEMA_VERSION` (option absent = live). When the
current schema changes again before the next release (for example the self-hosted assets
manifest of the assets concept), **that one** migration is rewritten, never a second one
chained; `PBLSH_SCHEMA_VERSION` stays 1 until the release.

`tests/Unit/Data/SchemaGuardTest.php` enforces this (`vendor/bin/phpunit` after
`composer install`): no classes or files with "Migrat" in their name; while
`includes/upgrade.php` does not exist, the current schema is the live one and no schema-version
code exists; once the module exists, `PBLSH_SCHEMA_VERSION` is defined exactly there and exactly
as live + 1, `upgrade_schema_from_live()` is the only migration function, and the schema version
is never compared with any other number.

At a release, set the `Live:` line to what shipped, for example
`Live: schema version 1 (Peak Publisher 1.4.0, released 2026-xx-xx on wordpress.org)`. From then
on every storage change moves data from exactly that version to the current schema; intermediate
development states never get a migration of their own.
