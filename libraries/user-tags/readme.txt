=== User Tags ===
Contributors: davefx
Tags: users, roles, capabilities, segmentation, performance
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A label that behaves like a role to the whole of WordPress while granting no capability and never touching the autoloaded role option.

== Description ==

WordPress offers no primitive for saying *this user is a wholesale customer*
without granting them permissions. There are only roles. That is why membership
and commerce plugins keep inventing them: they have no alternative.

Every invented role is copied into a single autoloaded option that is
unserialized and held in memory by every PHP worker on every request. The sites
that end up with forty of them are exactly the sites whose traffic is mostly
logged in, where page caching never gets a look in.

A user tag is the missing alternative. It appears in `$user->roles`, it answers
`current_user_can()`, `WP_User_Query` finds it — and it is never written to that
option. Third-party code cannot tell the difference.

= For plugin developers =

This is primarily a library for other plugins to bundle, in the manner of Action
Scheduler: drop the directory in, require one file, and the newest copy on the
site wins.

    require_once __DIR__ . '/libraries/user-tags/user-tags.php';

    if ( function_exists( 'user_tags_add' ) ) {
        user_tags_add( $user_id, 'wholesale' );
    }

Full API and bundling notes: https://github.com/davefx/user-tags

= As a plugin =

Activated on its own it gives you the same thing, with the screens switched on:
**Users → Tags** for creating and editing tags with CSV import and export, a
column and filter links on the users list, bulk assignment, and checkboxes on the
user profile.

= Where the data lives =

A taxonomy for the catalogue, term relationships for the assignments, and one
usermeta key as a read mirror. No custom tables, no schema version, no
migrations, and nothing added to the autoloaded options.

== Frequently Asked Questions ==

= Will `current_user_can( 'my_tag' )` work? =

Yes. So will `in_array( 'my_tag', $user->roles, true )`,
`get_users( array( 'role' => 'my_tag' ) )` and `WP_Roles::is_role()`.

= Do tags grant any permission? =

None. That is the entire point.

= What happens if two plugins bundle this? =

The newest copy boots and the rest stay dormant. They share one set of tags.
`user_tags_diagnostics()` reports which copy is active and which others were
seen.

= Does deactivating it remove my tags? =

No. They stop being injected, and because they grant no capability nothing about
what your users can do changes. The assignments stay in the database.
`user_tags_uninstall()` removes them, and is never called for you.

= Does it work on multisite? =

Yes. Tags are per site.

== Changelog ==

= 1.2.0 =
* Optional admin screens, behind the `user_tags_enable_admin` filter.
* Spanish translation, and the library now loads its own text domain.
* Text domain is `user-tags-lib`.

= 1.1.0 =
* Relicensed to GPL-3.0-or-later.

= 1.0.1 =
* Reword the slug-collision message.

= 1.0.0 =
* First release, extracted from LeanRoles.
