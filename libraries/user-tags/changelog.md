# Changelog

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
