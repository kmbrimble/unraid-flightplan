# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are compared as plain strings by the Unraid plugin manager; keep every component single-digit or zero-padded.

## [0.2.1]

### Changed
- Validator: your own `ghcr.io` images are `moveme` (the `intended` class is gone); images in Gitea's container registry are never flagged.
- The legacy GitHub release workflow was removed; Gitea is the only publisher.

## [0.2.0]

### Changed
- Delivery moved from GitHub to Gitea: `pluginURL`, the `.txz` URL and `support` point at git.kiztigs.com. Releases are Gitea
  releases, published by `.gitea/workflows/release.yml` through the shared `scripts/gitea-release.sh`. The GitHub mirror and its
  release workflow remain but installed servers no longer depend on them.
- The GitHub-reference validator classifies each hit: `moveme` (your own account, belongs on git.kiztigs.com), `intended` (your own
  `ghcr.io` image in `Repository`) or `third` (upstream). New per-source `move_total`. Still informational only.

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
