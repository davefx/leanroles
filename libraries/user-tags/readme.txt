=== User Tags Lib ===
Contributors: davefx
Tags: users, roles, capabilities, segmentation, performance
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
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

== Installation ==

As a plugin: install and activate it, then open **Users → Tags**. Nothing else
is needed and nothing is written to the role option.

Inside your own plugin: copy `user-tags/` in — `git subtree`, a submodule or a
plain copy — and require one file from your main plugin file, before your own
bootstrap:

    require_once __DIR__ . '/libraries/user-tags/user-tags.php';

Do not autoload `src/` with Composer: the autoloader would map a class to
whichever copy registered it first, which need not be the copy the registry
chose. The winning bootstrap requires its own files.

Bundled, the screens stay off unless you ask for them:

    add_filter( 'user_tags_enable_admin', '__return_true' );

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

== Upgrade Notice ==

= 1.3.3 =
Packaging only. No change to how the library behaves.

= 1.3.2 =
Packaging only. No change to how the library behaves.

= 1.3.1 =
Fixes the screens vanishing when another plugin bundles an identical copy.

= 1.3.0 =
Installed as a plugin, the screens now appear without a filter being set.

== Changelog ==

= 1.3.3 =
* Clears the wordpress.org Plugin Check. The findings were annotations rather
  than defects — `php://temp` is a memory stream, so WP_Filesystem has nothing
  to say about it, and every handler on the tag screen runs after the
  dispatcher's `check_admin_referer()`, which PHPCS cannot see across. The
  export's `what` parameter is now unslashed and sanitised on the way in rather
  than only compared against a literal.
* `Tested up to: 7.1`. The directory treats a stale value as an error and hides
  the plugin from search until it is current.

= 1.3.2 =
* Packaging for the wordpress.org directory: the plugin name is `User Tags Lib`,
  because the directory turns that name into the permalink once and permanently
  and `user-tags` belongs to another plugin. `user-tags-lib` is the slug the text
  domain was already named for. Nothing in the library behaves differently, and
  it still calls itself User Tags everywhere a slug is not involved.
* An Installation section, and a changelog that reaches the stable tag.

= 1.3.1 =
* A copy installed as a plugin counts as standalone even when it lost a version
  tie. Two plugins bundling the identical version means the second registration
  is recorded as a duplicate rather than given a seat; if the loser was the
  plugin the site owner installed, its screens disappeared for no visible
  reason. Duplicates are considered now, as 1.3.0 said they were.

= 1.3.0 =
* Activated as a plugin, it registers its screens by itself. The default for
  `user_tags_enable_admin` is now whether a copy is installed as a plugin in its
  own right — its directory sitting directly inside `wp-content/plugins` rather
  than further down inside somebody else's plugin. Bundled, the default is still
  off, which is the case the filter exists for.
* `user_tags_diagnostics()` reports `standalone`.

= 1.2.2 =
* The admin page slug is `user-tags` again. Renaming the text domain had
  swallowed it, because the two were the same string, and the only symptom was a
  menu link pointing somewhere that did not exist.

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
