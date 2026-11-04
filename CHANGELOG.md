# Changelog

All notable changes to the SURF Trashbin app are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.2.0]

### Added
- Support for Nextcloud 35 and 36 (`max-version` raised from 34 to 36). No
  change to the trashbin logic was needed: every API the app uses is unchanged
  on `stable35` and `master`. Nextcloud 35 and 36 need PHP 8.3 or newer.
- End-to-end test (`tests/e2e/`) that builds the functional account sharing
  scheme in the official Nextcloud Docker image and checks delete, restore and
  permanent delete for every party, including a project user without quota.
- Psalm static analysis at error level 3, against the `nextcloud/ocp` of the
  oldest and the newest supported Nextcloud.
- GitHub Actions: lint, code style, psalm and the end-to-end test on every pull
  request, and monthly against the newest Nextcloud release.

### Fixed
- **Restoring a file deleted from a regular share (not a functional account
  folder) failed with HTTP 500.** Such a restore also finds two trashbin rows
  (the sharer's and the deleter's), so `handleRestoreNode()` took it for a
  functional account restore and called `unlink()` and `deleteItems()` with
  the `null` functional account row. The file itself was restored. It now
  returns early when none of the rows belongs to a functional account; the
  end-to-end test covers this.
- A delete whose functional account trashbin row cannot be found is now logged
  and skipped, instead of throwing a `TypeError`.
- `ShareMapper::getProjectOwnerUID()` returned `null` despite its `string`
  return type when the functional account has no project owner share, so a
  delete in such a folder threw a `TypeError`. It now returns `''`, and the
  owner copy is skipped and logged.
- A node deleted without a logged-in user no longer fails on
  `getUser()->getUID()`.
- `FileCacheMapper` and `TrashbinMapper` logged under the app id of
  `files_trashbin`; they now log under `surf_trashbin`.

### Changed
- Code style is checked with `nextcloud/coding-standard` (php-cs-fixer)
  instead of PSR-12 phpcs; dev tools are installed with
  `bamarni/composer-bin-plugin` and left out of the app package.

## [0.1.1]

### Fixed
- **Data-loss fix (3/3) — permanent delete by one party cascaded into every
  other party's trashbin copy, by design.** `TrashbinHook::permanentDelete()`
  unconditionally unlinked the f_account's master trashbin node *and* the other
  party's copy (owner or user, whichever the deleter wasn't), and deleted every
  `oc_files_trash` row for that item's id+timestamp regardless of which party's
  row it was. So permanently deleting your *own* trashbin copy could destroy an
  *already-complete* copy belonging to someone else, and — if a copy from the
  f_account to another party was still in flight — threw an **uncaught
  `CopyRecursiveException`** in Nextcloud's own code (HTTP 500 on the original
  move-to-trash request) and could leave every party's trashbin empty. This
  wasn't a race to patch around: each party's trashbin copy must be independent
  of what any other party does with theirs, and permanent-delete was never
  specified (see README.md) to cascade to anyone else — only *restore* is.
  `permanentDelete()` no longer touches the f_account's copy or any other
  party's copy at all; Nextcloud's own permanent-delete already removes the
  caller's own row/file before this hook runs, so there's nothing left for it
  to do here. The f_account's master copy is left in place for Nextcloud's
  normal age-based trashbin expiry to eventually reclaim. Reproduced end-to-end
  on Nextcloud 33/34 with a 40-file folder deleted by a project user while a
  copy to another party was still running.
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
