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
- **Private repositories:** the config holds a [SecretsMan](https://github.com/kmbrimble/unraid-secretsman) reference such as
  `!secret gitea/reader`, never a token. Tokens never reach a command line, log or the UI.
- **Syncs** at install (if the array is up), when the array starts, on a cron schedule you set, and from a *Sync now* button.
- **Heads-up validator:** after each sync it flags template fields that still point at GitHub (`github.com`,
  `raw.githubusercontent.com`, `ghcr.io`, ...). Informational only.

Settings > Utilities > **Flightplan**.

## Install

Add this URL in the Unraid Plugins tab ("Install Plugin"):

```
https://raw.githubusercontent.com/kmbrimble/unraid-flightplan/main/flightplan.plg
```

## Notes

- A Gitea source needs the Unraid host to be able to reach that Gitea (and a token if sign-in is required). If it cannot, point
  the source at the repository's public GitHub mirror (`forge: github`), which needs no token.
- Templates are plain CA XML: a Docker template needs `<Repository>`; a plugin template needs `<Plugin>true</Plugin>` and
  `<PluginURL>`.
- Uninstalling removes the folders Flightplan created (only those) and keeps `flightplan.json` on flash.

## Licence

GPL-2.0 — see [LICENSE](LICENSE).
