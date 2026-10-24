<?php

declare(strict_types=1);

namespace Pblsh\Tests\Unit\Data;

use Pblsh\Tests\Unit\TestCase;

/**
 * Two schemas, never more: the live one (rolled out to users) and the current one (what
 * the code creates). docs/data-schema.md declares the live schema. Without one there is
 * no migration code at all; with one, at most one migration exists — from exactly that
 * live version to the current schema (includes/upgrade.php, PBLSH_SCHEMA_VERSION = live + 1)
 * — rewritten when the current schema changes again, never chained.
 */
final class SchemaGuardTest extends TestCase {

    private const DECLARATION = PBLSH_PLUGIN_DIR . 'docs/data-schema.md';
    private const MIGRATION_MODULE = 'includes/upgrade.php';

    public function test_migration_code_exists_only_from_the_live_schema(): void {
        $live = self::live_version();

        // The migration artefacts this code base could produce.
        $named_artefacts = [];
        $schema_constants = [];
        $thresholds = [];
        $upgrade_functions = [];
        foreach (self::sources() as $path => $source) {
            if (preg_match('/Migrat/i', basename($path)) || preg_match('/\bclass\s+\w*Migrat\w*/i', $source)) {
                $named_artefacts[] = $path;
            }
            if (preg_match_all('/const\s+PBLSH_SCHEMA_VERSION\s*=\s*(\d+)/', $source, $found)) {
                $schema_constants[$path] = (int) end($found[1]);
            }
            if (preg_match_all('/(?:schema_version|SCHEMA_VERSION)[^;\n]*?(?:<=?|>=?|[!=]==?)\s*(\d+)/', $source, $found)) {
                $thresholds[$path] = array_map('intval', $found[1]);
            }
            if (preg_match_all('/function\s+(upgrade_schema_from_\w+)\s*\(/', $source, $found)) {
                foreach ($found[1] as $function) {
                    $upgrade_functions[] = $path . ': ' . $function . '()';
                }
            }
        }

        self::assertSame([], $named_artefacts, 'Migration classes or files are not how this plugin migrates: docs/data-schema.md declares the live schema, and the one migration from it lives in includes/upgrade.php.');

        if ($live === null || !file_exists(PBLSH_PLUGIN_DIR . self::MIGRATION_MODULE)) {
            // Nothing to migrate from: no live schema is declared (a development store is
            // regenerated, not migrated), or the current schema still is the live one.
            $reason = $live === null
                ? 'No live schema is declared in docs/data-schema.md, so there is nothing to migrate from: a development store is regenerated, not migrated.'
                : 'Without includes/upgrade.php the current schema is the live one declared in docs/data-schema.md — schema-version code has no place then.';
            self::assertSame([], $schema_constants, $reason);
            self::assertSame([], $thresholds, $reason);
            self::assertSame([], $upgrade_functions, $reason);
            return;
        }

        self::assertSame(
            [ self::MIGRATION_MODULE => $live + 1 ],
            $schema_constants,
            "PBLSH_SCHEMA_VERSION is defined once, in includes/upgrade.php, as the live schema version declared in docs/data-schema.md plus one ({$live} + 1): the one migration moves live to current, intermediate development states never get a version of their own."
        );
        self::assertSame(
            [ self::MIGRATION_MODULE . ': upgrade_schema_from_live()' ],
            $upgrade_functions,
            'Exactly one migration function exists, upgrade_schema_from_live() in includes/upgrade.php. When the current schema changes again before the release, rewrite it instead of chaining a second one.'
        );
        self::assertSame(
            [],
            $thresholds,
            'The migration compares the stored schema version with PBLSH_SCHEMA_VERSION only — a literal threshold would start it from an intermediate state instead of the live schema declared in docs/data-schema.md.'
        );
    }

    /**
     * The declared live schema version, or null while nothing is released.
     */
    private static function live_version(): ?int {
        self::assertFileExists(self::DECLARATION, 'Every project with persistent data declares its live schema in docs/data-schema.md.');
        $declaration = (string) file_get_contents(self::DECLARATION);
        self::assertMatchesRegularExpression('/^Live: (?:none|schema version \d+)/m', $declaration, 'docs/data-schema.md needs a line "Live: none" or "Live: schema version N (...)".');
        preg_match('/^Live: (?:none|schema version (\d+))/m', $declaration, $found);
        return isset($found[1]) && $found[1] !== '' ? (int) $found[1] : null;
    }

    /**
     * @return array<string, string> Relative path => source of every PHP file the plugin ships.
     */
    private static function sources(): array {
        $sources = [ 'peak-publisher.php' => (string) file_get_contents(PBLSH_PLUGIN_FILE) ];
        foreach ([ 'includes', 'classes' ] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(PBLSH_PLUGIN_DIR . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php') {
                    $sources[substr($file->getPathname(), strlen(PBLSH_PLUGIN_DIR))] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($sources);
        return $sources;
    }
}
