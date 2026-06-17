# Upgrading & future Nextcloud support

This document tracks the Nextcloud APIs this app depends on that are
**deprecated or internal**, the risk they carry for future major versions, and
the recommended migration path. None of these are broken on Nextcloud 31–34
(verified against the `stable33`/`stable34` server source and end-to-end on a
live NC 33.0.4 instance), so **no action is required today** — this is a
forward-looking checklist for whoever bumps the app to NC 35+.

## How the app is loaded (important context)

The app must be loaded on the **WebDAV/Sabre request path** (`remote.php`),
because the Files trashbin UI performs delete / restore / permanent-delete
through it. That path only loads apps declared with an app type of
`filesystem`, `logging`, or `authentication`. This app therefore declares:

```xml
<types>
    <filesystem/>
</types>
```

Removing this declaration silently breaks permanent-delete cleanup (the slot for
the `\OCP\Trashbin`/`delete` hook is never registered on the WebDAV path). Keep
it.

## Deprecated / internal API surfaces

### 1. Legacy hook: `Util::connectHook('\OCP\Trashbin', 'delete', …)`

- **Where:** `lib/AppInfo/Application.php` (constructor) →
  `lib/Hooks/TrashbinHook.php::permanentDelete()`.
- **State:** `@deprecated 21.0.0`. Still fully functional on NC 33 and 34 —
  `apps/files_trashbin/lib/Trashbin.php` still emits
  `\OC_Hook::emit('\OCP\Trashbin', 'delete', ['path' => …])` on permanent delete,
  and `OC_Hook` still dispatches it.
- **Why it is still here:** there is **no typed event** for *permanent delete*
  in the trashbin app. `apps/files_trashbin/lib/Events/` only contains
  `BeforeNodeRestoredEvent`, `MoveToTrashEvent`, and `NodeRestoredEvent` — none
  for permanent deletion.
- **Why the registration is in the constructor (not `boot()`):** `IBootstrap::boot()`
  is not invoked for this app on the WebDAV request path; the `App` constructor
  always runs when the app is loaded. Moving the `connectHook` call into `boot()`
  silently breaks permanent-delete. Do not move it.
- **Migration path (NC 35+ if the hook is ever removed):** switch to the generic
  storage-layer event `OCP\Files\Events\Node\NodeDeletedEvent`, registered via
  `IRegistrationContext::registerEventListener()`. Caveat: that event fires for
  **every** node deletion, so the listener must filter on the node path being
  under `…/files_trashbin/files/` to replicate the precisely-scoped legacy hook.
  This is a behaviour change with added complexity — only do it when forced.

### 2. Internal class: `\OC\Files\View`

- **Where:** `lib/Service/TrashbinService.php` and `lib/Hooks/TrashbinHook.php`
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

### 3. Direct SQL / table access

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
   instance — unit/source checks alone do **not** catch the WebDAV-loading and
   hook-dispatch issues that only surface at runtime.
5. Check whether `\OCP\Trashbin`/`delete` is still emitted and whether
   `\OC\Files\View` is still present; if either is gone, follow the migration
   paths above.
