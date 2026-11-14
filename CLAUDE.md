# Flightplan — CLAUDE.md

Flightplan keeps Kieren's own Docker and plugin templates in Community Applications (CA). It mirrors CA-style template XMLs
from git repos into CA's Private Apps folder (`/boot/config/plugins/community.applications/private/<folder>/`) so they
install from the Apps tab instead of by pasted `.plg`/GitHub links. GPL-2.0, written from scratch (clean room: an existing
GPL-3.0 plugin inspired the idea; its code was never fetched or read, and must not be).

`PLAN.md` at the repo root is gitignored (it names LAN hosts and addresses) and is never committed. This file is derived from
the repo as it stands plus the verified facts below; where they conflict, re-derive from the repo.

## Forge and releases

**Gitea is primary; GitHub is a read-only push mirror.** Develop against `https://git.kiztigs.com/kmbrimble/unraid-flightplan`
(`origin`, via the gt credential helper; use `gt`, not `gh`, for issues/PRs). `github.com/kmbrimble/unraid-flightplan` receives
every push (`sync_on_commit`, plus a 10-minute sweep) and **must never be pushed to directly**: the mirror force-syncs and
prunes refs. GitHub has Issues/Wiki/Projects off (set when the repo was created); turning Pull requests off there is a manual UI
step (the fine-grained token has no Administration permission).

- `main` is protected on Gitea: PR only, required check `CI / lint-and-test (pull_request)`, butler-bot merges its own green PRs.
- CI is `.gitea/workflows/ci.yml` (lint, tests, **release-readiness**: if `&version;` in `flightplan.plg` has no `v<version>`
  tag yet, the build must be reproducible and `&md5;` must already equal its md5). The first run on a bump fails with
  `set &md5; to <value>`; put that value in the PR.
- Release = version-bump PR (also `###<version>` in `<CHANGES>`, `CHANGELOG.md`, `&md5;`), merge, then
  `git tag v<version> <merge-sha> && git push origin v<version>` on Gitea. The tag is mirrored and
  `.github/workflows/release.yml` rebuilds, requires the same md5 and publishes the GitHub Release with the `.txz`.
  Nothing writes back. See the `forge-release` skill.
- **Where installed servers fetch the plugin from: GitHub** (`raw.githubusercontent.com/.../main/flightplan.plg` and the GitHub
  release asset), exactly like Dormouse. Plugin installs stay on GitHub so they never depend on the homelab being up.
- **Where the host reads templates from: Gitea, through Caddy (set up 14 Nov 2026).** Gitea requires sign-in even for public
  repos, so every Gitea source needs a token (a SecretsMan reference). The path that works, and what it depends on (all outside
  this repo):
  1. the host's `/boot/config/go` appends `172.18.0.201 git.kiztigs.com` to `/etc/hosts` (host DNS is public-only);
  2. Caddy's proxynet IP is pinned to 172.18.0.201 in its dockerMan template and NetMan row (the caddy repo's CLAUDE.md);
  3. Caddy's `@git_lan_only` matcher includes 172.18.0.1, the host as Caddy sees it;
  4. a read-only token: SecretsMan key `flightplan/gitea-token`, account `flightplan-bot` (restricted bot, collaborator read on
     `kmbrimble/unraid-templates` only, scope `read:repository`).
  If a Gitea sync fails with 403 or DNS errors, check those four in that order. The old workaround (`base_url` = Gitea's Docker
  address, or the per-source "Resolve IP" field) is not needed. `forge: github` against a public push mirror remains the
  token-free alternative.

## Non-negotiable constraints

1. **Versions are compared with `strcmp`, not semver.** `1.0.10` sorts before `1.0.9`. Keep every component single-digit or
   zero-padded. CI/release refuse a version that does not sort after the previous tag (`scripts/version-sorts-after.php`).
2. **A `FILE` block with no `<MD5>` is never overwritten.** The only checksum is the `.txz`'s `<MD5>`, checked by the plugin
   manager. No bash-level md5 check anywhere.
3. **`plugin/README.md` stays in the stock shape** (`**Flightplan**`, blank line, one paragraph, no headings); a test enforces it.
4. **Every script a `.plg` `FILE` block invokes has a shebang (or is run via `php`/`bash <path>`) and is gated on `[[ -f ]]`,
   never `-x`.** Documented exception: `plugin/event/started` is packaged executable because emhttpd's own dispatcher checks
   `-x` (build script `chmod +x`, test checks the packaged mode).
5. **The .plg has no bare `&`** (use nested `if`, or put the logic in a script file such as `after-install.sh`), no literal
   `<word>` in comments/CHANGES, and element names are case-sensitive. A test parses the `.plg` and scans for these.
6. **Config/state split.** `flightplan.json` (sources, schedule) lives on flash, is tiny and written only when it changes.
   Everything that changes per sync (SHAs, per-source results, log) lives in `/tmp/flightplan/` (RAM): no flash wear, and a
   resync once per boot because it starts empty. Staging lives on flash next to CA's folder (`.../flightplan/.stage/`) so the
   final swap is a plain `rename`, never a cross-filesystem copy; it is NOT inside `private/`, which CA globs.
7. **Ownership marker.** Every folder Flightplan creates holds `.flightplan-owned` (JSON with `"tool":"flightplan"`). It only
   ever replaces or prunes a folder carrying that marker; any other folder with the same name (compared case-insensitively,
   because flash is FAT) is a per-source error and is left untouched. The pre-existing `DockerHub` folder is the standing example.
8. **Missing or unreadable config prunes nothing and syncs nothing.** A valid, saved, *empty* source list is different: it
   means "I removed everything" and prunes marker-owned folders. The `.plg` therefore never creates a default config.
9. **Failure never destroys the last good copy:** failed lookup/download, unusable archive or zero usable templates leaves the
   existing folder byte-for-byte as it was, and the new SHA is not recorded as synced.
10. **Secrets.** A source holds `!secret ns/key` (SecretsMan), never a token; the config validator rejects anything else
    without echoing the submitted value. The token is resolved in process through the installed SecretsMan library
    (`require_once` of `src/secretsman.php`, then `secretsman_load_store(secretsman_default_store_path())` and
    `secretsman_lookup`; the same entry points the `hl` CLI uses to write the store), held in a PHP string and sent by the PHP
    curl extension as a header. It is never on a command line, in the environment, in a file, in the log, in the status JSON or
    in an error message. The header is sent only to the origin of the first URL, never to a redirect target (GitHub's zipball
    redirects to another host). Redirects are followed manually for exactly that reason. Do not add a code path that shells
    out to `curl`/`wget` with a credential.
11. **UI follows DESIGN.md.** No colour, spacing, radius or shadow values outside the vendored `plugin/app/assets/tokens.css`;
    changes go upstream to kmbrimble/design-system and are re-vendored (header on each file names the commit). The UI is plain
    JS with no build step, so nothing needs Node in the md5 path. All dynamic text goes through `textContent`.
12. **CLI-vs-web rules** (from SecretsMan/Dormouse): urlencoded POST only (the app sends the CSRF token as body field and
    `X-CSRF-Token`; the endpoint does no CSRF check of its own, `local_prepend.php` consumes it); functions reachable from both
    SAPIs (`lib.php`, `flightplan-api.php`) never reference `STDOUT`/`STDERR`/`$argv` (a test greps for it); only
    `flightplan-sync.php` prints.
13. **Cron expressions** go into root's crontab: `fp_cron_valid()` is a strict 5-field grammar (digits and `* , - /` only,
    bounds-checked) enforced on save. The `.cron` file on flash is rewritten only when it differs, and regenerated from config
    by `after-install.sh` at every install (the RAM cron dir is wiped each boot).
14. **Boot ordering.** Plugins install before the array, Docker (Gitea) and `/mnt/user` (the SecretsMan store) exist. The install
    step therefore starts a sync only if `var.ini` shows `mdState="STARTED"`, otherwise `event/started` runs
    `flightplan-sync.php --boot` detached, which retries at 0/30/90/180/300 s (network-ish failures only; a bad config or an
    empty result is not retried). `after-install.sh` always exits 0: a network failure must never fail an install.

## Behaviour (as built)

- **Change detection:** one API call for the branch head SHA (plus one for the default branch when none is configured). If the
  SHA equals the last good one *and* the folder still exists and is owned, nothing is downloaded. Gitea: `GET /api/v1/repos/o/r`,
  `.../branches/<b>` (`commit.id`), `.../archive/<sha>.zip`. GitHub: `GET /repos/o/r`, `.../branches/<b>` (`commit.sha`),
  `.../zipball/<sha>` (302 to a CDN host). Verified against the live Gitea (archive at a SHA, top-level `<repo>/` directory).
- **Archive handling:** read in memory with `ZipArchive` (nothing is extracted to disk by path), drop the single top-level
  directory, apply `subpath`, keep `*.xml`, skip `ca_profile.xml`, any dot-directory or dot-file, names with unsupported
  characters, files over 1 MiB, and anything with `..`/absolute/backslash names. Duplicate basenames (case-insensitive, FAT):
  shallowest path wins, then byte order; each skip is logged and stored in the source's `notes`.
- **Template check:** each file must be well-formed XML with root `Container` and no DOCTYPE/ENTITY; others are skipped with
  a note. A template CA would ignore (no `Repository`, or `<Plugin>true` with no `PluginURL`) is shipped with a warning.
- **Validator:** the URL-bearing tags (`PluginURL`, `Repository`, `TemplateURL`, `Icon`, `Support`, `Project`, `ReadMe`,
  `Registry`, `DonateLink`, `WebUI`) are matched for `github.com`, `*.githubusercontent.com` and `ghcr.io`. Free text
  (`Overview`, `Changes`) is deliberately not scanned. Informational only; never blocks. Userinfo is stripped before display.
- **One lock** (`/tmp/flightplan/sync.lock`, `flock`) for cron, boot, install and the button. "Sync now" spawns a detached
  `flightplan-sync.php --manual`; the page polls `status`.
- **Remove step** deletes only marker-owned folders, the cron entry, staging and RAM state, and keeps `flightplan.json`
  (no credentials in it) so a reinstall restores the sources. Delete it by hand to wipe it.

## Host facts that shaped this (checked live, read-only over ssh, 14 Nov 2026)

- Unraid 7.3.4, PHP 8.4.27 CLI with `curl`, `zip`, `json`, `SimpleXML`, `dom`; `jq`, `git`, `curl`, `tar`, `xz`, `unzip`, `flock`,
  `timeout` present; **no python3**. The engine is PHP only and uses nothing else.
- CA is installed; `private/` already contains a `DockerHub` folder that is not ours.
- The real SecretsMan library (`/usr/local/emhttp/plugins/unraid-secretsman/src/secretsman.php`) resolves a reference on the
  host through `fp_token_resolve()` (piped over ssh stdin, nothing written; printed only a length check).
- Gitea through its public name WAS unreachable from the host until the 14 Nov 2026 change above; it now is, and a real sync of
  `kmbrimble/unraid-templates` works on the host (9 templates, second sync skipped on the unchanged SHA). GitHub's anonymous
  limit is 60 API calls/hour/IP; a source costs 1 call when a branch is set (2 without).

## Infrastructure this repo depends on but does not contain

A reviewer seeing "nothing in this repo implements X" for any of these is wrong:
- **Community Applications** (globs `private/*/*.xml`, skips a template with no Repository, `Plugin`+`PluginURL` templates work).
- **SecretsMan** (store, `0600` check, `secretsman_*` library) and **homelab-access** (`hl`, which writes the store).
- **The webGui** (nginx `auth_request` session gate, `local_prepend.php` CSRF, `update_cron`, `emhttp_event` dispatching `event/*`).
- **The Gitea reverse proxy and LAN DNS**, and the Gitea→GitHub push mirror.
- Exposure: LAN-only, behind the Unraid webGui login. Not internet-facing. The data users rely on is CA's `private/` folder
  (the `DockerHub` folder there is foreign and must never be touched) and `flightplan.json`.

## Repo layout

```
flightplan.plg                  entities, CHANGES, install/remove FILE blocks
plugin/                         installed tree (-> /usr/local/emhttp/plugins/flightplan/)
  README.md                     stock one-paragraph description (Plugins tab)
  Flightplan.page               Menu="Utilities" (Settings > Utilities), thin iframe host (same pattern as SecretsMan)
  app/index.php + app/assets/   web app shell; vendored tokens/base/components.css; app.css; app.js
  scripts/lib.php               the engine (config, HTTP, forges, archive, validator, swap, prune, state)
  scripts/flightplan-sync.php   CLI: --cron --manual --install --boot --register-cron --unregister-cron --uninstall
  scripts/flightplan-api.php    web endpoint: status | save | sync
  scripts/after-install.sh      install-time: re-register cron, start first sync if the array is up
  event/started                 emhttpd hook -> flightplan-sync.php --boot (packaged executable)
scripts/build-plugin.sh         deterministic .txz (sorted, fixed mtime, umask-proof, xz -T1)
scripts/check-md5.sh            build + require the .plg md5 (CI and release)
scripts/version-sorts-after.php strcmp guard
tests/run.php                   hand-rolled assert runner; tests/fixtures/fake-forge.php (php -S fake Gitea/GitHub)
.gitea/workflows/ci.yml         lint + test + release-readiness (Gitea runner)
.github/workflows/release.yml   on a mirrored v* tag: guard, rebuild, md5, GitHub Release (dry run via dispatch)
```

## Test command

```
php tests/run.php
```
Needs PHP 8.4 with `curl` and `zip` (this dev container lacks them by default: `apt-get install php8.4-curl php8.4-zip`; CI
installs them). The suite starts two `php -S` fake forges and runs the real CLI/endpoint as subprocesses. The token test samples
`/proc/*/cmdline` and `environ` *while the sync holds the token* and fails if it did not get enough samples, and requires that
the fake forge actually received the credential, so it cannot pass vacuously. One test uses the real SecretsMan library when
`/projects/unraid-secretsman` exists and prints a `SKIPPED:` line otherwise (the stub mirrors the three function signatures).
Mutation-checked once (14 Nov 2026): removing the redirect-origin check, the owned-only prune, the missing-config
guard or the SHA skip each turns a test red.

## Verified on the host (14 Nov 2026) and still not verified

Verified on the real host: install through `plugin install` from the GitHub raw URL, `plugin remove` (owned folder, cron entry,
staging and RAM state gone; `flightplan.json` and the foreign `DockerHub` folder byte-identical), reinstall, sync of a Gitea
source with a SecretsMan token, skip on unchanged SHA, the page and the status/sync actions through nginx with a real webGui
session and CSRF token, CA listing the 9 templates as Private Apps, and the token absent from flash, syslog, RAM state and the
status JSON. Install/remove/reinstall found one bug (0.1.1: cron stayed inactive after a fresh install because `update_cron` only
sees plugins the plugin manager has already registered).

Not verified: a boot-time `event/started` run and an upgrade-in-place on the host (no reboot allowed in testing); the page
rendered in a real browser inside the webGui (HTTP 200 and the endpoints were checked, not the iframe's appearance); a GitHub
source against the live API from the host.
