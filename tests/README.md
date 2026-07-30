# LeanRoles test suite

543 tests, ~1,830 assertions, green on single site and multisite.
97.5% line coverage. Clean under WordPress Coding Standards.

Verified against **WordPress 5.9, 6.5 and 7.0** — the declared floor, the last
release before the autoload column changed, and latest — and against **PHP 7.4
upwards** by static analysis.

Every test runs against a real WordPress install and a real database, and the
WP-CLI commands run against the real `WP_CLI` class rather than a double.

That is deliberate. Almost everything worth getting wrong in this plugin lives
in a seam it does not own: the short-circuit contract of `get_metadata_raw()`,
what `WP_User::set_role()` does to a capabilities array on its way past, how
`WP_User_Query` assembles the clauses for a role argument, what
`register_taxonomy()` quietly stores on your behalf. Stubbing those seams would
only ever test one reading of them, and the reading is the risky part.

## Setup

Two commands, neither of which needs root or touches anything you already run.

```sh
composer install
./tests/bin/start-db.sh            # throwaway MariaDB on 127.0.0.1:3307
./tests/bin/install.sh             # WordPress + the test library, into tests/.wp/
```

`start-db.sh` keeps its data directory under `tests/.wp/mysql-data` and listens
on port 3307, so it cannot collide with — or damage — a server already running
on 3306. `./tests/bin/start-db.sh stop` shuts it down; `reset` throws the data
away and starts again.

To point at a database you already have:

```sh
./tests/bin/install.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>
```

The database must exist and **its contents will be destroyed**. Never aim this
at anything you care about.

## Running

```sh
composer test               # single site
composer test:multisite     # network
composer test:unit          # the pure-logic subset
composer test:coverage      # needs pcov or xdebug
composer test:matrix        # every supported WordPress version
composer lint               # coding standards + PHP 7.4 compatibility
composer check              # lint, then both suites
```

`composer test:matrix` installs each WordPress version under `tests/.wp/matrix/`
the first time it runs, then reuses them.

`tests/bootstrap.php` finds the test library at `WP_TESTS_DIR`, then
`tests/.wp/wordpress-tests-lib`, then `/tmp/wordpress-tests-lib`, so after
`install.sh` no environment variable is needed.

## Layout

| Path | What it covers |
|---|---|
| `unit/CapabilitiesTest` | The core capability catalogue, levels, the inert set |
| `unit/FormatTest` | Byte, duration and percentage rendering, including the boundaries |
| `unit/BenchmarkTest` | Element counting, capacity and bandwidth arithmetic, and that the measurement releases what it measured |
| `unit/StructureAlgebraTest` | Clone groups, subset pairs, the greedy inheritance estimate |
| `integration/RuntimeTest` | The four injection hooks — the technical core |
| `integration/StoreTest` | Assignment, the mirror, the reverse lookup, resumable bulk assignment |
| `integration/QueryTest` | `WP_User_Query` with tags in `role`, `role__in`, `role__not_in` |
| `integration/TaxonomyTest` | Tag CRUD, slug-collision refusals, and why the taxonomy is flat |
| `integration/CatalogueTest` | The pre-`init` cache, and that it is never autoloaded |
| `integration/CleanupTest` | Term relationships surviving user deletion |
| `integration/CsvTest` | Import, export, round trip |
| `integration/ApiTest` | The published function surface |
| `integration/AuditorTest` | The auditor end to end, and the read-only vow |
| `integration/SizeProbeTest` | Sizes measured in the database, not recomputed in PHP |
| `integration/StackProbeTest` | Drop-in detection, and what it refuses to claim |
| `integration/RolesTest` | Restore points, and `delete_role` |
| `integration/UsersListTest` | Column, filter links, bulk assignment, capability and nonce checks |
| `integration/AdminScreensTest` | Profile, tag and audit screens; escaping; permissions |
| `integration/AdminRenderingTest` | The screen branches that only appear in unusual states |
| `integration/PluginTest` | Bootstrap, activation, deactivation, menu |
| `integration/WiringTest` | What each `boot()` attaches, and the defensive branches |
| `integration/EdgeCasesTest` | The paths that only run when something has gone wrong |
| `integration/UninstallTest` | `uninstall.php`, executed rather than merely parsed |
| `integration/InvariantsTest` | Properties that hold everywhere: escaping, text domain, autoload, N+1 |
| `integration/CliTagCommandTest` | `wp leanroles tag` |
| `integration/CliAuditCommandTest` | `wp leanroles audit`, including the JSON aggregation contract |
| `integration/CliRoleBackupCommandTest` | `wp leanroles role` and `wp leanroles backup` |
| `integration/CliEdgeCasesTest` | Command failure paths, including a half-way interruption |
| `integration/MultisiteTest` | Per-site tags and network-wide erase. Skipped unless run under `test:multisite` |

The tag engine itself is no longer here. It lives in the bundled library at
`libraries/user-tags/`, which keeps its own suite — `TaxonomyTest`,
`CatalogueTest`, `StoreTest`, `RuntimeTest`, `QueryTest`, `CleanupTest`,
`CsvTest`, `DataShapeTest` and `VersionsTest` — and travels with it to its own
repository. `composer test` runs both, because the copy this plugin ships has to
be the copy that was tested.

`VersionsTest` is the one genuinely new thing: it builds throwaway copies on
disk and checks that the newest wins, that registration order is irrelevant,
that `1.10.0` beats `1.9.0`, that a duplicate version is recorded rather than
swallowed, and that a copy arriving late does not lock out a newer sibling.

## Things the suite deliberately pins

A number of tests exist to stop a future change quietly undoing a decision:

- **The auditor writes nothing.** `AuditorTest` fingerprints the roles option,
  the user tables, the term tables and the non-transient options either side of
  a full run. This is the promise everything else rests on.
- **Tags never reach the database.** Several tests read the raw `usermeta` row
  rather than going through `get_user_meta()`, because going through the API
  would read back the very injection being tested.
- **The `set_role()` trap.** `WP_User::set_role()` walks `$this->roles` and
  unsets each entry from `$this->caps` before saving. Tags are in that list.
- **Shadowing, in both directions.** If a real role comes to own a tag's slug
  the tag is not injected — otherwise every tagged user would receive that
  role's capabilities. Equally the write filter must *not* strip a shadowed
  slug, or the plugin would delete a genuine role assignment instead.
- **Nothing is autoloaded.** `InvariantsTest` diffs the whole set of autoloaded
  option names across activation and tag creation. This is how the hierarchical
  taxonomy was caught: core writes a `{taxonomy}_children` option for those, at
  the default autoload.
- **Every string carries the plugin's text domain**, checked by tokenising all
  shipped files rather than by grep, with a guard test so a broken parser cannot
  make it pass by finding nothing.
- **Hostile tag names stay escaped** on every admin surface, with the name
  injected under `wp_insert_term()` because that function strips markup — the
  realistic case is a row that arrived by import or migration.
- **The users column costs no query per row.** The mirror exists precisely so it
  can ride on the metadata cache the list table already primed.
- **The "unrecognised is not orphaned" caveat** is asserted as literal text on
  the audit screen and in the CLI findings view, because credibility is the only
  asset a performance plugin has.
- **The CLI surface.** Renaming or dropping a subcommand is a breaking change to
  somebody's shell loop, so the documented set is asserted by name.
- **Nothing calls an API newer than the declared floor.** `InvariantsTest` scans
  the shipped code — comments stripped, so its own prose cannot trip it — for
  functions added after WordPress 5.9 and for any reach into
  `WP_Object_Cache::$cache`, which is an overloaded property before 6.1 where
  writing through it raises a notice and silently does nothing. That is how the
  batch cache flush was found to be broken on every release from 5.9 to 6.0.
- **A long bulk assignment releases the users it touched** and emits no PHP
  notice, so the memory ceiling that makes the command resumable actually holds.

## Static analysis

`composer lint` runs PHPCS with WordPress-Core, WordPress-Docs and
PHPCompatibilityWP. The compatibility ruleset is the point: the suite runs on
PHP 8.5, which proves nothing about the 7.4 floor in the plugin header.

Two `phpcs:disable` blocks remain, both where a run of placeholders is
interpolated into a query string. In each case the run is built from the length
of a private constant or from `count()` on an array of integers, never from
input, and the values still go through `prepare()`. The reason is written at the
site rather than left to be rediscovered.

## What is not covered, and why

The 2.5% is not a backlog. Each item below is either unreachable in-process or
unreachable by design.

| Code | Why |
|---|---|
| `exit;` after a redirect, and the `break;` that follows it | `exit` ends the process; the redirect is caught by a filter that throws first, so the line after it never runs. Unreachable in production too. |
| `TagsPage::do_export()` | Emits headers then `exit`s. The CSV it produces is covered directly by `CsvTest` and through `wp leanroles tag export`. |
| `TagsPage::do_import()` body | Guarded by `is_uploaded_file()`, which is true only for a real HTTP upload. The guard itself is tested; the body is `Csv::import_assignments()`, covered directly. |
| `Plugin::boot()`'s `is_admin()` and `defined( 'WP_CLI' )` branches | Neither is true in a test process. The classes they boot are booted and asserted directly in `WiringTest`. |
| `Plugin::register_cli()` | `WP_CLI::add_command()` bails unless the `WP_CLI` constant is defined, and defining it would change how WordPress itself behaves for every subsequent test in the process. The command surface is asserted instead. |
| A few `return $wp_error;` relays | They need `wp_insert_term()`, `wp_set_object_terms()` or `wp_delete_term()` to fail in ways the API cannot be made to fail from outside. One of them *is* forced, through the `pre_insert_term` filter, to prove the pattern. |
| `Store::flush_user_caches()` fallback | Only runs on an object cache without `flush_group`, which no supported WordPress ships with. |
| `defined( 'ABSPATH' ) \|\| exit;` and `if ( ! function_exists() )` guards | Evaluated at load, before any test exists. |

One test is reported risky on WordPress 5.9 only: that combination emits its own
deprecation notices on PHP 8.5, and PHPUnit attributes the output to whichever
test was running. It is WordPress 5.9's noise, not the plugin's, and the pairing
does not occur in the wild — 5.9-era sites run PHP 7.x or 8.0.

Two lines inside multi-line ternaries in `TagsPage` are reported uncovered
although the branch is exercised — the tests assert the resulting `error=`
redirect. That is a line-attribution artefact of the coverage driver, not a gap.
