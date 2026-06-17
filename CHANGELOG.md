# Changelog

All notable changes to the SURF Trashbin app are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
