# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are compared as plain strings by the Unraid plugin manager; keep every component single-digit or zero-padded.

## [0.1.1]

### Fixed
- Fresh install or reinstall left the cron schedule inactive until cron was next reloaded: `update_cron` only includes plugins
  already listed in `/var/log/plugins`, and the plugin manager adds this plugin there after the install script returns.
  `after-install.sh` now also starts a detached, bounded helper that re-registers the schedule once the plugin is registered.
  Found while exercising install, remove and reinstall on the real host.

## [0.1.0]

### Added
- First release: mirrors CA-style template XMLs from Gitea or GitHub repositories into Community Applications' Private Apps
  folder, with change detection by branch head SHA, ownership-marked folders, build-then-swap, prune of removed sources only,
  and keep-existing on any failure.
- SecretsMan references for private repositories; tokens are resolved in process and never on argv, in logs or in the UI.
- Settings > Utilities > Flightplan page (house design system): sources editor, schedule, Sync now, status panel.
- Sync at install (array up), on array start (with retries), on a cron schedule, and by button.
- Validator listing template fields that still reference GitHub (informational).
