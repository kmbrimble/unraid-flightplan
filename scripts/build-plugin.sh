#!/bin/bash
# Build dist/flightplan-<version>.txz from plugin/, packaged at the path Unraid unpacks .txz files to:
# usr/local/emhttp/plugins/flightplan/. Deterministic: the same source gives the same bytes on every runner,
# because the .plg's <MD5> is committed before the tag and the release workflow rebuilds and requires it to match.
set -euo pipefail

VERSION="${1:?usage: build-plugin.sh <version>}"
NAME="flightplan"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/plugin"
OUT_DIR="$REPO_ROOT/dist"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

INSTALL_ROOT="$WORK_DIR/usr/local/emhttp/plugins/$NAME"
mkdir -p "$INSTALL_ROOT"
cp -a "$PLUGIN_DIR/." "$INSTALL_ROOT/"

# emhttpd gates event/* scripts on the executable bit itself (documented exception to the -f rule in CLAUDE.md).
chmod +x "$INSTALL_ROOT/event/started"

mkdir -p "$OUT_DIR"
TXZ="$OUT_DIR/$NAME-$VERSION.txz"
rm -f "$TXZ"

# Sorted names, fixed mtime, numeric owner 0:0, normalised modes (umask-independent), and single-threaded xz
# (xz 5.6+ defaults to multi-threaded, which changes block-header bytes and so the md5).
export LC_ALL=C
unset XZ_DEFAULTS XZ_OPT
tar -C "$WORK_DIR" --sort=name --mtime='UTC 2020-01-01' --owner=0 --group=0 --numeric-owner \
    --mode='u=rwX,go=rX' -I 'xz -6 -T1' -cf "$TXZ" usr

echo "built: $TXZ"
echo "md5: $(md5sum "$TXZ" | awk '{print $1}')"
