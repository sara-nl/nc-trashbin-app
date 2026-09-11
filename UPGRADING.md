# Upgrading & future Nextcloud support

This document tracks the Nextcloud APIs this app depends on that are
**deprecated or internal**, the risk they carry for future major versions, and
the recommended migration path. None of these are broken on Nextcloud 31–34
(verified against the `stable33`/`stable34` server source and end-to-end on a
live NC 33.0.4 instance), so **no action is required today** — this is a
forward-looking checklist for whoever bumps the app to NC 35+.

## How the app is loaded (important context)

The app must be loaded on the **WebDAV/Sabre request path** (`remote.php`),
because the Files trashbin UI performs delete / restore through it. That path
only loads apps declared with an app type of `filesystem`, `logging`, or
`authentication`. This app therefore declares:

```xml
<types>
    <filesystem/>
</types>
```

Removing this declaration silently breaks the delete-to-owner copy and the
restore cascade (the typed event listeners are never registered on the WebDAV
path). Keep it.

Note: this app does **not** hook into permanent delete. An earlier version did,
via the legacy `Util::connectHook('\OCP\Trashbin', 'delete', …)` hook, to
cascade a permanent delete to the other parties' trashbin copies. That cascade
was removed — see CHANGELOG.md — because it violated each party's trashbin
copy being independent, and could race an in-flight copy into the f_account's
master copy. Do not re-add it without re-reading that history.

## Deprecated / internal API surfaces

### 1. Internal class: `\OC\Files\View`

- **Where:** `lib/Service/TrashbinService.php`
  (`new \OC\Files\View(…)`; `unlink`, `is_dir`, `mkdir`, `file_exists`,
  `getDirectoryContent`, `resolvePath`, and the
  `resolvePath() → IStorage → getUpdater()->update()` chain).
- **State:** `@internal since 33.0.0` (note: **internal**, not `@deprecated`).
  No runtime warning, no removal scheduled in 33/34. All methods exist with
  backward-compatible signatures on both branches.
- **Why it is still here:** the app does low-level trashbin file manipulation
  (including a raw `copy()` plus a cache `update()` for the zero-quota case) that
  has no clean equivalent in the public Node API today.
- **Migration path (NC 35+):** move to `OCP\Files\IRootFolder` and the
  `Folder`/`File`/`Node` API. Validate the zero-quota copy path carefully — that
  is the part most coupled to `View` and the storage `Updater`.

### 2. Direct SQL / table access

- **Where:** `lib/Db/FileCacheMapper.php` (raw SQL against `oc_filecache` /
  `oc_storages`), `lib/Db/TrashbinMapper.php`, `lib/Db/ShareMapper.php`.
- **State:** works on 33/34. `QBMapper`, `IDBConnection::getQueryBuilder()`,
  `executeQuery()`, `executeStatement()` are all non-deprecated. NC 34 adds a
  soft `@note` suggesting `getTypedQueryBuilder()` but does **not** deprecate the
  current methods.
- **Risk:** the raw SQL hardcodes the `oc_` table prefix in places (e.g.
  `join oc_storages`, `from oc_filecache`) instead of using `*PREFIX*`
  consistently. This breaks on any installation with a non-default DB table
  prefix.
- **Migration path:** replace remaining hardcoded `oc_` prefixes with `*PREFIX*`
  (or QueryBuilder), so the app works regardless of the configured table prefix.

## Quick checklist when bumping to a new Nextcloud major

1. Raise `<nextcloud max-version>` in `appinfo/info.xml`.
2. Confirm the PHP range in `info.xml` and `composer.json` still matches the
   server's required PHP.
3. Bump `nextcloud/ocp` in `composer.json` to the new `dev-stableXX`.
4. Re-run the end-to-end flow (delete → restore → permanent-delete) on a live
   instance — unit/source checks alone do **not** catch WebDAV-loading issues
   that only surface at runtime. Confirm a permanent delete by one party still
   leaves every other party's trashbin copy untouched (see CHANGELOG.md).
5. Check whether `\OC\Files\View` is still present; if it's gone, follow the
   migration path above.
