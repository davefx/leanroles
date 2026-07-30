# User Tags

A user tag is a label that behaves like a role to the whole of WordPress — it
appears in `$user->roles`, it answers `current_user_can()`, `WP_User_Query`
finds it — while granting no capability and never being written to the
autoloaded `{prefix}user_roles` option.

WordPress offers no primitive for saying *this user is a wholesale customer*
without granting them permissions. There are only roles. That is why membership
and commerce plugins keep inventing them: they have no alternative. Every
invented role is copied into an option that is unserialized and held in memory
by every PHP worker on every request, and the sites that end up with forty of
them are exactly the sites whose traffic is mostly logged in, where page caching
never gets a look in.

This library is the missing alternative, and it is free for anyone to bundle.

## Using it

Copy the directory into your plugin — `git subtree`, a submodule, or a plain
copy, whichever suits your release process — and include one file:

```php
require_once __DIR__ . '/libraries/user-tags/user-tags.php';
```

That is the whole integration. Do not require anything else and do not autoload
`src/` — see *Composer* below.

Then guard your calls, the way you would with any bundled library:

```php
add_action( 'user_tags_ready', function () {
    user_tags_register( 'wholesale', array( 'name' => 'Wholesale customer' ) );
} );

if ( function_exists( 'user_tags_add' ) ) {
    user_tags_add( $user_id, 'wholesale' );
}
```

From that point the tag is indistinguishable from a role to everything else:

```php
current_user_can( 'wholesale' );                    // true
in_array( 'wholesale', wp_get_current_user()->roles, true );
get_users( array( 'role' => 'wholesale' ) );
```

## API

| Function | Does |
|---|---|
| `user_tags_register( $slug, $args )` | Create a tag. `name`, `description`, `color`, `legacy_role`. |
| `user_tags_unregister( $slug )` | Delete a tag and every assignment of it. |
| `user_tags_exists( $tag )` | Is this slug a tag? |
| `user_tags_all()` | The catalogue. |
| `user_tags_get( $user_id )` | Tags a user carries. |
| `user_tags_has( $user_id, $tag )` | Does a user carry one? |
| `user_tags_add( $user_id, $tags )` | Add, leaving the rest alone. |
| `user_tags_remove( $user_id, $tags )` | Remove. |
| `user_tags_set( $user_id, $tags )` | Replace outright. |
| `user_tags_users( $tag, $args )` | Everyone carrying a tag, by index seek. |
| `user_tags_assign_by_role( $tag, $role, … )` | Bulk assign, in resumable batches. |
| `user_tags_is_ready()` / `user_tags_version()` / `user_tags_supports( $f )` | What you are running against. |
| `user_tags_diagnostics()` | Which copy is active, and what else was seen. |
| `user_tags_uninstall()` | Erase everything. Never called for you. |

Hooks: `user_tags_ready`, `user_tags_added`, `user_tags_removed`. Filters:
`user_tags_inject_as_roles` (turn the role shim off), `user_tags_protected_slugs`.

## Where the data lives

| What | Where | Why |
|---|---|---|
| The catalogue | Taxonomy `user_tag` | Outside autoload. A rename is one `UPDATE`. |
| Per-tag config | `termmeta` | No invented tables. |
| Assignments | `term_relationships` | `term_taxonomy_id` is indexed, so "everyone with tag X" is a seek. |
| Read mirror | usermeta `{prefix}user_tags` | Rides on the metadata cache `WP_User_Query` already primes, so the hot path costs no extra query. |

The taxonomy is the source of truth; the mirror is derived and rebuildable. The
mirror key is blog-prefixed because usermeta is global on a network while term
relationships are not.

**No custom tables, no `dbDelta`, no schema version, no migrations.** That is a
deliberate constraint, and the section below explains why.

## Lessons taken from Action Scheduler

Action Scheduler is the reference for a bundled WordPress library and it earned
that position. It also accumulated a set of problems that are visible from the
outside, and this library is shaped around avoiding them.

**Custom tables were the expensive mistake.** Action Scheduler began by storing
actions as posts, moved to dedicated tables, and paid for years in migration
code, half-migrated sites and support load. Tables also mean the data outlives
every consumer with nothing to clean it up. This library stores everything in
core structures that already exist, so there is no schema to migrate, nothing to
create on activation, and nothing exotic left behind.

**Nobody can tell which copy is running.** With several plugins bundling the
same library, "which one is active?" takes a debugger to answer. Here
`user_tags_diagnostics()` reports the active version, the file that registered
it, every other copy seen, and any two copies that collided on a version number.

**The registry is the one thing that cannot be upgraded.** Whichever copy is
included first defines the arbitration class, so an old plugin can leave an old
registry in charge no matter how new the winning library is. Two things follow:
`src/Versions.php` is frozen — it collects and compares, nothing else, forever —
and its state lives in `$GLOBALS`, not in a class static, so a future registry
can read what an older one collected. Action Scheduler's is locked inside its
class and cannot be.

**Late registration should not lose.** If a copy registers after the boot hook
has fired — a plugin activated mid-request, a theme — booting on the spot hands
the request to whichever copy was included first, which is not the same thing as
the newest. This library falls through to the next early hook that has not fired
yet (`setup_theme`, `after_setup_theme`, `init`) so siblings arriving in the
same burst still get counted, and only boots on the spot when nothing early is
left to wait for. When that happens `booted_early` records that the decision was
made without a full view.

**Version strings are a bad way to ask about behaviour.** A consumer bundling a
newer copy can find itself running against an older one, because its own plugin
is the deactivated one. Comparing versions is a guess about what a version
contained; `user_tags_supports( 'user-query' )` is not. Feature names are added,
never removed or renamed.

**A library should not install an admin UI.** Action Scheduler adds a Tools
screen to every site that bundles it, which surprises owners who installed a
plugin for something else. This library ships data and API only. Screens belong
to whichever plugin wants them.

**Uninstall has no correct automatic answer.** Action Scheduler leaves its
tables behind forever, because no consumer can know it was the last: the
registry only sees copies loaded in the current request, and a plugin that is
deactivated today is still a plugin whose data this is. Guessing wrong destroys
somebody's segments. So the data outlives its consumers here too — but on
purpose, with `user_tags_uninstall()` as the documented way out, run
deliberately rather than as a side effect of removing one plugin.

**Composer autoloading and bundled libraries do not mix.** The autoloader maps a
class name to whichever copy it registered first, which need not be the copy the
registry chose, and the two disagreeing is very hard to see. The winning
bootstrap requires its files explicitly. Do not add this library to a `files` or
`classmap` autoload block.

## Getting it

```sh
git subtree add --prefix libraries/user-tags \
    https://github.com/davefx/user-tags.git 1.1.0 --squash
```

A subtree rather than a composer dependency, because the code has to be inside
your plugin's zip: WordPress users do not run `composer install`. Composer is
fine for development, but do not let it autoload `src/` — see above.

## Requirements

WordPress 5.9+, PHP 7.4+. No dependencies. Multisite aware: tags are per site.

GPL-3.0-or-later.

## Bundling checklist

1. Copy `libraries/user-tags/` into your plugin, keeping the directory intact.
2. `require_once` the entry file from your main plugin file, before your own
   bootstrap — the capability filters have to be attached before the first
   `WP_User` of the request is built.
3. Call `UserTags\Library::activate()` from your activation hook if you want the
   daily mirror housekeeping scheduled. It is shared and idempotent.
4. Do **not** call `user_tags_uninstall()` from your uninstall hook.
5. Feature-detect with `function_exists()` and `user_tags_supports()`.

When you update your bundled copy, bump nothing and change nothing else: the
registry sorts it out.
