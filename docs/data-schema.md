# Data schema

Live: schema version 0 (Peak Publisher 1.3.1, released 2026-04-04 on wordpress.org)

Two schemas exist, never more. The **live schema** is the one publicly rolled out — the data on
users' sites: Peak Publisher 1.3.1 knows no schema version option (`pblsh_schema_version` absent
= live) and keeps release posts with status `publish`/`draft`. The **current schema** is what the
code in this repository creates today:

- **Posts:** `pblsh_plugin` (self-hosted plugin, `post_name` = slug, status Public/Draft = the
  distribution switch), `pblsh_wporg_plugin` (wporg marker; `post_content` = the regenerable SVN
  cache `{revision, release_count, fetched_at, trunk_readme}`), `pblsh_release` (child of the
  plugin, `post_title` = version, `post_name` from `get_release_slug()`; `post_content` = for
  self-hosted the upload state `$data` of finalize, for wporg the tag snapshot
  `{tag_revision, plugin_data, plugin_info, plugin_readme_txt}`; status always `publish`).
- **Post meta:** plugin `_pblsh_current_release` (version string of the current release, `''` =
  none; written only by finalize, the flip and the migration), the assets manifest
  `assets_icons` / `assets_banners` / `assets_screenshots` (`AssetManager`),
  `_pblsh_installations` (24-hour counting cache, regenerable, not declared); marker
  `_pblsh_wporg_account_username`; release `_pblsh_zip_path`, `_pblsh_directory_content_hash`,
  wporg `_pblsh_upload_state` (the deploy's upload state).
- **Options:** `pblsh_settings` (settings incl. `wporg_accounts` with AES-256-GCM encrypted
  passwords and verdict stamps), `pblsh_secret_salt`, `pblsh_encryption_context_id`,
  `pblsh_schema_version` (autoloaded), `pblsh_upgrade_notice` (facts of the migration until
  dismissed), `pblsh_schema_migration_lock` (only while the migration runs).
- **Files** under `wp-content/uploads/pblsh-peak-publisher/`:
  `plugins/{slug}/releases/{slug}.{version}.zip`, `plugins/{slug}/assets/`,
  `encryption-key.php`, `tmp/` (upload working directories, transient).

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
