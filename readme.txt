=== LeanRoles ===
Contributors: davefx
Tags: roles, capabilities, performance, users, multisite
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Measures what your role configuration costs on every request, and adds user tags: a role-shaped label that grants nothing and weighs nothing.

== Description ==

WordPress keeps every role in one autoloaded option, with each role's capabilities copied out in full, because core has no inheritance. On a membership or commerce site with forty roles that option is read, unserialized and held in memory by every PHP worker on every request — and those are exactly the sites whose traffic is mostly logged in, so page caching never gets a look in.

LeanRoles does two things about it.

**It measures.** The auditor is strictly read-only. It takes the size of your role option with `LENGTH()` in the database rather than re-serializing it in PHP, times a real `unserialize()` of it on your machine after a warm-up pass, measures the resident footprint with the result kept alive, and works out what that costs in concurrent workers. It identifies roles that grant no effective permission, roles nobody holds, roles with identical capability sets, and capabilities it cannot account for — while being explicit that "unrecognised" is not "orphaned", because custom code checks capabilities no scanner can see.

**It offers the missing primitive.** There is no way in WordPress to say "this user is a wholesale customer" without granting them permissions. There are only roles. That is why membership plugins keep inventing them: they have no alternative. A LeanRoles tag is that alternative. It appears in `$user->roles`, answers `current_user_can()`, and can be filtered on in `WP_User_Query` — and it is never written to the autoloaded option. Third-party code cannot tell the difference.

= What it does =

* The auditor, with real measurements and `wp leanroles audit --format=json`
* User tags: create, assign individually or in bulk, filter, a users-list column, CSV import and export
* The tag engine as a standalone library, `libraries/user-tags/`, that any plugin can bundle so tags cost them nothing to adopt
* WP-CLI: create a tag, assign it in bulk by role, delete a role with reassignment, take and restore role-option backups

= What it does not do =

It does not convert roles into tags for you. What it gives you are three
independent primitives — create a tag, assign it in bulk, delete a role with
reassignment — which compose into a conversion done by hand, by someone who has
read the audit and accepts the risk.

Before deleting a role it tells you how many capabilities that role grants and
how many users hold it. It does not tell you which of those users will actually
notice the difference: answering that means computing effective capabilities per
user, before and after, and the plugin does not do it. Take a backup and check
for yourself.

== Installation ==

1. Upload the plugin to `wp-content/plugins/leanroles` and activate it.
2. Open **LeanRoles → Audit**. Nothing on that screen writes anything.

== Screenshots ==

1. The audit. Everything on this page is read — the size of the role option taken with LENGTH() in the database, and what it is costing you.
2. Measured cost. A real unserialize() of your own option, timed on your own machine, and what that works out to in workers and object-cache bandwidth.
3. Every role, heaviest first, with what it grants, what it denies, how many deprecated level_N entries it carries, and how many users hold it.
4. Users → Tags. Create and edit tags, with CSV import and export.
5. The users list, with a Tags column, filter links, and bulk assign and remove.
6. Tags on the user profile.

== Frequently Asked Questions ==

= Is the auditor safe to run on a production site? =

Yes, and that is the point of it. It reads. The only things it writes are a twelve-hour cache of `count_users()` and, on activation, a restore point of your role option.

= What happens to my tags if I deactivate the plugin? =

They stop being injected. Because they grant no capabilities, nothing about what your users can do changes. The assignments stay in the database and come back when you reactivate.

= Can my plugin use user tags without depending on LeanRoles? =

Yes, and that is the point. The tag engine is a self-contained library that
ships inside this plugin and can be copied into yours: one `require_once` and
you have `user_tags_add()`, `user_tags_get()` and the rest. If several plugins
bundle it, the newest copy wins and they all share one set of tags. Removing
LeanRoles does not remove them. See `libraries/user-tags/readme.md`.

= Will `current_user_can('my_tag')` work? =

Yes. So will `in_array( 'my_tag', $user->roles )`, `get_users( array( 'role' => 'my_tag' ) )` and `WP_Roles::is_role()`.

= Does it work on multisite? =

The auditor understands it: role options are per site and it reads the right one. Tags are per site too, since term relationships live in each site's own tables. Network-wide operations are not in this version.

= Does an object cache solve the underlying problem? =

It helps with query time and nothing else. Memory, unserialization and — with Redis — bandwidth all get worse, not better, because `alloptions` lives under a single key and crosses the wire whole on every request. The audit detects your drop-in and tones its findings down if that drop-in already compresses or splits the blob.

== Development ==

The distributed plugin has no dependencies and no build step: what is in `src/`
and `assets/` is what runs. Composer is used only for the test suite, and
`.distignore` keeps all of it out of the release.

    composer install
    ./tests/bin/start-db.sh      # throwaway MariaDB on 127.0.0.1:3307
    ./tests/bin/install.sh       # WordPress + the test library
    composer test                # single site
    composer test:multisite      # network
    composer test:matrix         # WordPress 5.9, 6.5 and latest
    composer lint                # standards + PHP 7.4 compatibility

Every test runs against a real WordPress install and a real database, because
the risky parts of this plugin are all seams it does not own — the short-circuit
contract of the metadata filters, what `WP_User::set_role()` does on its way
past, how `WP_User_Query` builds a role clause. See `tests/README.md`.

== Upgrade Notice ==

= 0.2.0 =
The tag screens moved to the bundled library and now live under Users → Tags
rather than under the LeanRoles menu. Spanish translation added.

= 0.1.0 =
First release.

== Changelog ==

= 0.2.0 =

**The tag screens moved to the bundled library.** They now sit under
**Users → Tags**, which is where an administrator looks for something that
belongs to users, rather than under the LeanRoles menu. Behaviour is the same;
the code is now shared.

The library is [User Tags](https://github.com/davefx/user-tags), bundled in
`libraries/user-tags/` and free for any plugin to adopt. LeanRoles asks it for the
screens the same way any other consumer would:

    add_filter( 'user_tags_enable_admin', '__return_true' );

**Spanish translation.** `languages/` carries the `.pot` and `es_ES`, and the
plugin loads its own text domain rather than waiting for one to be loaded for
it, so the strings arrive whichever way the plugin was installed.

The LeanRoles menu now holds the audit and nothing else.


= 0.1.0 =

First release.

**The auditor.** Strictly read-only, so it can be left on a client site without
asking anyone first. It measures rather than estimates:

* Size of the role option taken with `LENGTH()` in the database, not by
  re-serializing in PHP — the two produce different strings, and the difference
  is exactly the sort of small dishonesty that gets a performance plugin
  disbelieved.
* A real `unserialize()` of your own option, timed on your own machine after a
  warm-up pass.
* Resident footprint measured with the result kept alive, and the ratio of
  in-memory to on-disk bytes.
* What that costs in concurrent PHP workers, and in object-cache bandwidth, from
  inputs you can adjust.
* Roles that grant no effective permission, roles nobody holds, roles with
  identical capability sets, subset relationships, deprecated `level_N` entries,
  and a conservative lower bound on what inheritance could remove.
* Capabilities it cannot account for — reported as *unrecognised*, never as
  *orphaned*, because custom code checks capabilities no scanner can see.
* Detection of the `object-cache.php` drop-in, with the findings toned down when
  the drop-in already compresses or shards the blob.

**User tags.** A label that behaves like a role to the whole of WordPress —
it appears in `$user->roles`, answers `current_user_can()`, and `WP_User_Query`
finds it — while granting no capability and never being written to the
autoloaded role option. Create and edit tags, assign them individually or in
bulk from the users list, filter by them, CSV import and export.

The tag engine ships as a standalone library, `libraries/user-tags/`, that any
plugin can bundle. See https://github.com/davefx/user-tags

**WP-CLI.**

* `wp leanroles audit` — including `--format=json` for aggregating across sites
* `wp leanroles tag create|delete|list|assign|remove|users|export|import`
* `wp leanroles role delete --reassign=<slug>` with a `--dry-run`
* `wp leanroles backup create|list|restore`

Bulk assignment runs in batches, releases the users it touched, and reports the
last id it reached so an interrupted run can be resumed rather than restarted.

**Requirements.** WordPress 5.9+, PHP 7.4+. Multisite aware. No dependencies and
no build step.
