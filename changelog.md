# Changelog

## 1.3.0

**Activated as a plugin, it now registers its screens by itself.** Somebody who
installs User Tags from wordpress.org asked for its interface; making them
discover a filter first would be a plugin that appears to do nothing.

The default for `user_tags_enable_admin` is now whether a copy is installed as a
plugin in its own right — its directory sitting directly inside
`wp-content/plugins` rather than further down inside somebody else's plugin. When
only bundled, the default is still off, which is the case the filter exists for.

Every registered copy is considered, not just the one that booted: if the plugin
is active, its screens should not disappear because another plugin happened to
bundle a newer copy that runs instead.

`user_tags_diagnostics()` reports `standalone`.

## 1.2.2

The admin screen's page slug is `user-tags` again. Renaming the text domain to
`user-tags-lib` had swallowed it, because they were the same string, and the only
symptom was a menu link pointing at `users.php?page=user-tags-lib`. A test now
pins the two apart: one is a URL, the other has to match a wordpress.org slug.

## 1.2.0

**An optional admin interface.** Screens ship with the library but load only if a
consumer asks:

```php
add_filter( 'user_tags_enable_admin', '__return_true' );
```

Users → Tags for creating and editing tags with CSV import and export, a column
and filter links on the users list, bulk assign and remove, and checkboxes on the
user profile. Nothing appears, and nothing is even read from disk, until the
filter says so.

They live here rather than in a separate package on purpose. Two bundleable
packages with independent version numbers produce a compatibility matrix — a site
running the screens from one release against the data layer of another, because
two plugins bundled different pairs. One package cannot drift out of step with
itself.

**Translations.** `languages/` carries the `.pot` and a Spanish translation, and
the library loads them itself on `init`, preferring
`wp-content/languages/plugins/` so a site or the wordpress.org platform can win.

**The text domain is now `user-tags-lib`.** It has to match a wordpress.org slug
for that platform to have anything to translate, and `user-tags` is taken. The
file also carries plugin headers now, so it can be activated on its own — which
is how Action Scheduler's strings reach a site at all.

Anything calling `__( '…', 'user-tags' )` against this library should move to
`user-tags-lib`. Nothing else changed.

## 1.1.0

Relicensed to GPL-3.0-or-later, with the full licence text in `LICENSE` — the
previous file carried only the notice, so GitHub reported the repository as
having no detectable licence.

Copies already obtained under 1.0.x keep their GPL-2.0-or-later grant. A plugin
that is GPLv2-only cannot bundle this version; one that is "GPLv2 or later" can.

## 1.0.1

Reword the slug-collision message so it explains the refusal rather than
referring to a product tier. No functional change.

## 1.0.0

First release, extracted from LeanRoles 0.1.0.

The tag engine — taxonomy catalogue, usermeta mirror, the four capability
injection hooks, `WP_User_Query` integration, CSV, bulk assignment — is now a
library any plugin can bundle. LeanRoles is a consumer of it like anybody else.

Identifiers are neutral, so no plugin stores its segments under another
product's name:

| Was, in LeanRoles 0.1.0 | Is now |
|---|---|
| taxonomy `leanroles_tag` | `user_tag` |
| usermeta `_leanroles_tags` | `{prefix}user_tags` |
| option `leanroles_tag_catalogue` | `user_tags_catalogue` |
| `leanroles_tag_added` / `_removed` | `user_tags_added` / `user_tags_removed` |
| `leanroles_inject_as_roles` | `user_tags_inject_as_roles` |
| `leanroles_*()` functions | `user_tags_*()` |

LeanRoles 0.1.0 was never distributed, so there is no migration path and none is
needed. The `leanroles_*()` functions remain as aliases.
