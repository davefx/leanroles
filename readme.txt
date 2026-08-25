=== LeanRoles ===
Contributors: davefx
Tags: roles, capabilities, performance, users, multisite
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.3
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
* WP-CLI: create a tag, assign it in bulk by role, edit or delete a role with reassignment, move a role configuration between sites, take and restore role-option backups

= Licensing, and the one external service =

LeanRoles uses [Freemius](https://freemius.com) for licensing, payment and automatic updates on its paid plan. Freemius is a third-party service, and this is the only thing in the plugin that talks to anything outside your site.

**Nothing is sent anywhere unless you say so.** The plugin asks once, on activation, and skipping is a real option: skip it and the plugin works exactly as it does otherwise. Nothing about the auditor or user tags depends on it.

If you do opt in, what is shared is:

* your WordPress user's name and email address;
* the site's homepage URL and title, its language, and the WordPress and PHP versions;
* which version of LeanRoles is running, its SDK version, and whether it is active or has been uninstalled.

Two further items are optional and stay off unless you tick them: the list of other plugins and themes installed on the site, and the newsletter.

Activating a licence key sends the key and the site URL, so the licence can be checked and updates delivered to you.

Freemius' [terms](https://freemius.com/terms/) and [privacy policy](https://freemius.com/privacy/) cover what they do with it.

= What it does not do =

It does not convert roles into tags for you. What it gives you are three independent primitives — create a tag, assign it in bulk, delete a role with reassignment — which compose into a conversion done by hand, by someone who has read the audit and accepts the risk.

Before deleting a role it tells you how many capabilities that role grants and how many users hold it. It does not tell you which of those users will actually notice the difference: answering that means computing effective capabilities per user, before and after, and the plugin does not do it. Take a backup and check for yourself.

== Installation ==

1. Upload the plugin to `wp-content/plugins/leanroles` and activate it.
2. Open **LeanRoles → Audit**. Nothing on that screen writes anything.

== Screenshots ==

1. The audit. Everything on this page is read — the size of the role option taken with LENGTH() in the database, and what it is costing you.
2. Measured cost. A real unserialize() of your own option, timed on your own machine, and what that works out to in workers and object-cache bandwidth. 3. Every role, heaviest first, with what it grants, what it denies, how many deprecated level_N entries it carries, and how many users hold it. 4. Users → Tags. Create and edit tags, with CSV import and export. 5. The users list, with a Tags column, filter links, and bulk assign and remove. 6. Tags on the user profile.

== Frequently Asked Questions ==

= Is the auditor safe to run on a production site? =

Yes, and that is the point of it. It reads. The only things it writes are a twelve-hour cache of `count_users()` and, on activation, a restore point of your role option.

= What happens to my tags if I deactivate the plugin? =

They stop being injected. Because they grant no capabilities, nothing about what your users can do changes. The assignments stay in the database and come back when you reactivate.

= Can my plugin use user tags without depending on LeanRoles? =

Yes, and that is the point. The tag engine is a self-contained library that ships inside this plugin and can be copied into yours: one `require_once` and you have `user_tags_add()`, `user_tags_get()` and the rest. If several plugins bundle it, the newest copy wins and they all share one set of tags. Removing LeanRoles does not remove them. See `libraries/user-tags/readme.md`.

= Will `current_user_can('my_tag')` work? =

Yes. So will `in_array( 'my_tag', $user->roles )`, `get_users( array( 'role' => 'my_tag' ) )` and `WP_Roles::is_role()`.

= Does it work on multisite? =

The auditor understands it: role options are per site and it reads the right one. Tags are per site too, since term relationships live in each site's own tables. Network-wide operations are not in this version.

= Does an object cache solve the underlying problem? =

It helps with query time and nothing else. Memory, unserialization and — with Redis — bandwidth all get worse, not better, because `alloptions` lives under a single key and crosses the wire whole on every request. The audit detects your drop-in and tones its findings down if that drop-in already compresses or splits the blob.

== Development ==

The distributed plugin has no dependencies and no build step: what is in `src/` and `assets/` is what runs. Composer is used only for the test suite, and `.distignore` keeps all of it out of the release.

    composer install
    ./tests/bin/start-db.sh      # throwaway MariaDB on 127.0.0.1:3307
    ./tests/bin/install.sh       # WordPress + the test library
    composer test                # single site
    composer test:multisite      # network
    composer test:matrix         # WordPress 5.9, 6.5 and latest
    composer lint                # standards + PHP 7.4 compatibility

Every test runs against a real WordPress install and a real database, because the risky parts of this plugin are all seams it does not own — the short-circuit contract of the metadata filters, what `WP_User::set_role()` does on its way past, how `WP_User_Query` builds a role clause. See `tests/README.md`.

== Upgrade Notice ==

= 0.5.3 =
The rest of the line breaks. 0.5.2 fixed the paragraphs and missed the lists.

= 0.5.2 =
The plugin listing was showing paragraphs broken mid-sentence. Presentation only; the plugin is unchanged.

= 0.5.1 =
A long bulk run that stopped making progress would repeat for ever instead of stopping. It now gives up and says where, and the run stays resumable. Paid plans only; nothing else changes.

= 0.5.0 =
`wp leanroles tag export` and `wp leanroles role export` no longer take `--file`; both print to standard output, so redirect them instead. Translations now come from translate.wordpress.org rather than being bundled.

= 0.4.0 =
Adds editing a role and moving a role configuration between sites, both from WP-CLI. Spanish translation brought up to date.

= 0.3.0 =
Adds licensing through Freemius for the paid plan. It asks before sending anything, and skipping leaves the plugin fully working.

= 0.2.2 =
Housekeeping in what the archive contains. No functional change.

= 0.2.1 =
Fixes the Users → Tags menu link, which led nowhere in 0.2.0.

= 0.2.0 =
The tag screens moved to the bundled library and now live under Users → Tags rather than under the LeanRoles menu. Spanish translation added.

= 0.1.0 =
First release.

== Changelog ==

= 0.5.3 =

The other half of 0.5.2. Unwrapping the paragraphs left the bulleted lists wrapped, because their continuation lines are indented and the pass that fixed the paragraphs mistook that indentation for a code block. Thirteen breaks survived, all inside lists. There is now a test that reads this file and fails if any line continues the one above it, so the next one cannot reach the directory.

= 0.5.2 =

Presentation only, and none of it in the plugin. The readme's paragraphs were hard-wrapped at eighty columns, and wordpress.org keeps every line ending as a line break, so the listing showed text broken mid-sentence wherever a paragraph had been wrapped — and read correctly wherever one happened to have been written as a single long line. They are all single lines now. The listing also has a banner, which it did not before.

= 0.5.1 =

`wp leanroles bulk` could not stop. It ran passes until one reported itself finished, and if a pass ever stopped making progress there was nothing to end the loop — a command that never returns, or a cron worker pinned until the host kills it. It now abandons the run when a pass moves nobody, says which user it stopped at, and leaves the run resumable rather than undoing what it had done.

This affects the paid plans only. Nothing in this build changes.

= 0.5.0 =

`wp leanroles tag export` and `wp leanroles role export` no longer take `--file`. Both print to standard output, so redirect them where you want them: `wp leanroles role export > roles.json`. The plugin now writes to the filesystem nowhere at all.

Every string the plugin ships, including those in the bundled User Tags library, now uses one text domain, so all of them can be translated through translate.wordpress.org. Compiled translations are no longer bundled — the directory serves those, and community translations reach you the moment they are approved rather than the next time this plugin is released.

= 0.4.0 =

**Two primitives the free tier was missing.**

`wp leanroles role update` changes a role's display name and what it grants. Command line only, and that is the product decision rather than an omission: a screen of capability checkboxes would put this in the category of role editors, and this is an auditor. It keeps three things apart that are easy to conflate — granting a capability, denying one, and dropping it altogether — because a denied capability still occupies a row in the option, which is the thing being measured.

`wp leanroles role export` and `import` move a configuration between sites. Not the same as a restore point: a backup comes back to the site it came from, an export leaves, so it records where it came from and when. Protected roles are never touched in either direction, so a file that simply omits `administrator` cannot be a way to delete it.

**Spanish is complete again.** The catalogue had fallen behind the code by about a third.

= 0.3.0 =

**Licensing, through Freemius.** The SDK ships in `libraries/freemius/` and handles the paid plan, licence activation and automatic updates.

It is the only part of the plugin that contacts anything outside your site, and it asks first. The opt-in on activation is skippable, and skipping costs you nothing: the auditor and user tags neither know nor care. What is shared if you do opt in, and what stays optional, is set out under *Licensing, and the one external service* above.

The SDK is required explicitly rather than autoloaded, for the same reason as the User Tags library: Freemius also arbitrates between every copy installed on a site and boots the newest, and an autoloader binding a class to a copy other than the one that won is the failure that pattern exists to avoid.

The plugin's test suite now loads it the way WordPress does, from inside `wp-content/plugins`. Loaded from anywhere else the SDK cannot find the plugin's main file, resolves it to the plugins directory, and warns on every read of the plugin headers.

= 0.2.2 =

Housekeeping in what ships, with no functional change.

The bundled library's `readme.txt` no longer travels in the archive. It is written for the wordpress.org directory, and a second directory readme inside one plugin is something a reviewer has to stop and work out. Its `readme.md`, which documents bundling the library, stays — whoever goes looking in `libraries/user-tags/` wants that one.

The library is 1.3.4, which is called User Tags again. The name had been changed to claim a wordpress.org permalink for it; it is not being listed there separately, so the name was buying nothing.

= 0.2.1 =

**The Users → Tags link works.** The archive published as 0.2.0 carried an earlier build of the bundled library, in which renaming the text domain had swallowed the admin page slug — the two were the same string. The menu item pointed at a page that did not exist, and clicking it said you were not allowed to access it. Nothing was wrong with your permissions.

The library is now 1.3.3, which also means: activated on its own it registers its own screens, so a site can install User Tags without LeanRoles; and the whole plugin clears the wordpress.org Plugin Check. That last one found a handful of things worth fixing here too — a stale `Tested up to`, a hidden file inside the archive, three unescaped integers in the audit's worker-model inputs, and `is_writable()` where core's `wp_is_writable()` belongs.

= 0.2.0 =

**The tag screens moved to the bundled library.** They now sit under **Users → Tags**, which is where an administrator looks for something that belongs to users, rather than under the LeanRoles menu. Behaviour is the same; the code is now shared.

The library is [User Tags](https://github.com/davefx/user-tags), bundled in `libraries/user-tags/` and free for any plugin to adopt. LeanRoles asks it for the screens the same way any other consumer would:

    add_filter( 'user_tags_enable_admin', '__return_true' );

**Spanish translation.** `languages/` carries the `.pot` and `es_ES`, and the plugin loads its own text domain rather than waiting for one to be loaded for it, so the strings arrive whichever way the plugin was installed.

The LeanRoles menu now holds the audit and nothing else.


= 0.1.0 =

First release.

**The auditor.** Strictly read-only, so it can be left on a client site without asking anyone first. It measures rather than estimates:

* Size of the role option taken with `LENGTH()` in the database, not by re-serializing in PHP — the two produce different strings, and the difference is exactly the sort of small dishonesty that gets a performance plugin disbelieved.
* A real `unserialize()` of your own option, timed on your own machine after a warm-up pass.
* Resident footprint measured with the result kept alive, and the ratio of in-memory to on-disk bytes.
* What that costs in concurrent PHP workers, and in object-cache bandwidth, from inputs you can adjust.
* Roles that grant no effective permission, roles nobody holds, roles with identical capability sets, subset relationships, deprecated `level_N` entries, and a conservative lower bound on what inheritance could remove.
* Capabilities it cannot account for — reported as *unrecognised*, never as *orphaned*, because custom code checks capabilities no scanner can see.
* Detection of the `object-cache.php` drop-in, with the findings toned down when the drop-in already compresses or shards the blob.

**User tags.** A label that behaves like a role to the whole of WordPress — it appears in `$user->roles`, answers `current_user_can()`, and `WP_User_Query` finds it — while granting no capability and never being written to the autoloaded role option. Create and edit tags, assign them individually or in bulk from the users list, filter by them, CSV import and export.

The tag engine ships as a standalone library, `libraries/user-tags/`, that any plugin can bundle. See https://github.com/davefx/user-tags

**WP-CLI.**

* `wp leanroles audit` — including `--format=json` for aggregating across sites
* `wp leanroles tag create|delete|list|assign|remove|users|export|import`
* `wp leanroles role delete --reassign=<slug>` with a `--dry-run`
* `wp leanroles backup create|list|restore`

Bulk assignment runs in batches, releases the users it touched, and reports the last id it reached so an interrupted run can be resumed rather than restarted.

**Requirements.** WordPress 5.9+, PHP 7.4+. Multisite aware. No dependencies and no build step.
