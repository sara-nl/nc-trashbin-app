# Changelog

All notable changes to the SURF Trashbin app are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed
- **Data-loss fix (3/3) — permanent delete raced against an in-flight trashbin
  copy and destroyed everyone's copy.** When a project user deletes a large
  folder, both Nextcloud's own `Trashbin::copyFilesToUser()` (f_account → the
  deleting user) and `TrashbinService::handleDeleteNode()` (f_account → the
  project owner) copy the data from the f_account's trashbin in the background,
  which can take a while for a large folder. If the user (or their sync client)
  permanently deletes that trashbin item while either copy is still running,
  `TrashbinHook::permanentDelete()` unlinked the f_account's node out from under
  the in-progress copy, throwing an **uncaught `CopyRecursiveException`** in
  Nextcloud's own code (HTTP 500 on the original move-to-trash request) and
  leaving every party's trashbin empty with no data recoverable anywhere.
  Reproduced end-to-end on Nextcloud 33/34 with a 40×500MB folder.
  `permanentDelete()` now acquires the same kind of exclusive lock Nextcloud's
  own `move2trash()` holds on the f_account trashbin path before unlinking it;
  if the path is still locked by a concurrent copy it retries briefly and then
  aborts (leaving the f_account copy untouched and logging a warning) instead
  of unlinking blindly.
- **Data-loss fix (1/2) — phantom trashbin entries after a failed copy.**
  `TrashbinService::handleDeleteNode()` inserted the `oc_files_trash` row for the
  project owner (and, for zero-quota deleters, the session user) **before**
  copying the data from the f_account trashbin. When the copy failed
  (permissions, disk full, timeout, …) the row and a partial/empty folder were
  left behind: a *phantom* trashbin entry. Restoring or expiring that phantom
  triggered cleanup that unlinked the f_account's master copy — permanent data
  loss. The row is now inserted only after the copy fully succeeded, and a
  failed partial copy is removed again (`removeFailedCopy()`).
- **Data-loss fix (2/2) — background trashbin expiration cascaded into the
  f_account master copy.** The `\OCP\Trashbin`/`delete` hook is also emitted by
  the trashbin *background expiration job* (cron). For a zero-quota user, that
  job expires the user's entire trashbin **within minutes** of any deletion, and
  `TrashbinHook::permanentDelete()` then cascade-unlinked the f_account's (and
  project owner's) trashbin copies — destroying the only remaining data without
  any user action and without logging. Reproduced end-to-end on Nextcloud 34:
  data was destroyed 62 seconds after deletion. The cascade now only runs when a
  logged-in user explicitly deletes a trashbin item
  (`IUserSession::getUser() !== null`); background/CLI expiration no longer
  touches the other parties' copies.
- Failed low-level copies now log the actual failure reason (permission denied,
  disk full, …) captured via a temporary error handler — `@copy()` plus
  Nextcloud's error handler used to swallow it, making production incidents
  (Kibana: "Unable to copy") undiagnosable.
- `TrashbinService` logged under the app id of `files_trashbin` due to a wrong
  `Application` import; it now logs under `surf_trashbin`.

### Added
- Support for Nextcloud 33 and 34 (`max-version` raised from 32 to 34).
- Explicit `<php min-version="8.2" max-version="8.5"/>` dependency in `info.xml`,
  matching the PHP floor that Nextcloud 33/34 require.
- `<types><filesystem/></types>` declaration in `info.xml`. **This is a
  functional fix, not just metadata.** The Nextcloud WebDAV/Sabre entry point
  (`remote.php`), which the Files trashbin UI uses for delete / restore /
  permanent-delete, only loads apps of type `filesystem` (and
  `logging` / `authentication`). Without this type the app's bootstrap never ran
  on that request path, so the `\OCP\Trashbin`/`delete` hook slot was never
  registered and **permanent-delete cleanup silently failed** (the functional
  account and project-owner trashbin copies were left behind). Verified end-to-end
  on Nextcloud 33.0.4.

### Changed
- `lib/AppInfo/Application.php` now implements `IBootstrap` more idiomatically:
  the typed event listeners (`NodeDeletedEvent`, `NodeRestoredEvent`) are
  registered in `register()` via `IRegistrationContext::registerEventListener()`.
  The legacy `Util::connectHook('\OCP\Trashbin', 'delete', …)` registration is
  **kept in the constructor on purpose** — `IBootstrap::boot()` is not invoked
  for this app on the WebDAV request path, whereas the `App` constructor always
  runs when the app is loaded. (See `UPGRADING.md` for the longer-term plan.)
- `composer.json`: development baseline bumped to match supported servers —
  `nextcloud/ocp` `dev-stable30` → `dev-stable33`, `phpunit/phpunit` `^9` → `^10`,
  platform/`require` PHP `8.0` → `>=8.2 <=8.5`.

### Notes
- No change was required to the actual trashbin logic: every Nextcloud API the
  app calls (the legacy `\OCP\Trashbin` hook, `\OC\Files\View`,
  `OCA\Files_Trashbin\Events\NodeRestoredEvent`,
  `OCP\Files\Events\Node\NodeDeletedEvent`, `Util::computerFileSize`,
  `QBMapper`/`IDBConnection`) is still present and behaves the same on Nextcloud
  33 and 34. The only breaking issue was the app not being loaded on the WebDAV
  path; see **Added → `<types>`** above.

## [0.0.1]

### Added
- Initial release: enables restore and permanent-delete capabilities for the
  project owner of files/folders deleted by project users on Research Drive
  functional accounts (`f_account -> project-owner -> project-users`).
