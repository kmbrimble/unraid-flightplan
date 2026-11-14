# Flightplan

> Development happens on a private forge; this repository is a read-only mirror. Releases are published here.

*"File your templates once."*

Flightplan is an Unraid plugin that keeps your **own** Docker containers and plugins in Community Applications. It mirrors
CA-style template XML files from git repositories into CA's *Private Apps* folder, so they install from the Apps tab instead
of by pasting a `.plg` or GitHub link.

- **Sources:** any number of repositories (Gitea or GitHub), each with an optional branch and subpath. The expected use is one
  central templates repository.
- **Only downloads when something changed:** it asks the forge for the branch head SHA and fetches the archive at that SHA only
  when the SHA differs from the last good sync.
- **Safe by construction:** folders it creates carry an ownership marker and only those are replaced or removed. A failed
  download or an empty result keeps what is already there; a missing or unreadable source list removes nothing.
- **Private repositories:** the config holds a [SecretsMan](https://git.kiztigs.com/kmbrimble/unraid-secretsman) reference such as
  `!secret gitea/reader`, never a token. Tokens never reach a command line, log or the UI.
- **Syncs** at install (if the array is up), when the array starts, on a cron schedule you set, and from a *Sync now* button.
- **Heads-up validator:** after each sync it flags template fields that still point at GitHub (`github.com`,
  `raw.githubusercontent.com`, `ghcr.io`, ...), separating your own account (move to `git.kiztigs.com`) from your own ghcr.io images
  (they stay) and other people's projects. Informational only.

Settings > Utilities > **Flightplan**.

## Install

Add this URL in the Unraid Plugins tab ("Install Plugin"):

```
https://git.kiztigs.com/kmbrimble/unraid-flightplan/raw/branch/main/flightplan.plg
```

## Notes

- The plugin itself and its updates come from Gitea, so the host must be able to reach `git.kiztigs.com` (LAN only). A Gitea source
  needs the same, plus a token if the repository is private. `forge: github` against a public mirror remains a token-free option.
- Templates are plain CA XML: a Docker template needs `<Repository>`; a plugin template needs `<Plugin>true</Plugin>` and
  `<PluginURL>`.
- Uninstalling removes the folders Flightplan created (only those) and keeps `flightplan.json` on flash.

## Licence

GPL-2.0 — see [LICENSE](LICENSE).
