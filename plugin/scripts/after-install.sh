#!/bin/bash
# Called by the .plg install step after the package is unpacked. Never fails the install: every path exits 0.
#  - re-registers the cron entry from the flash config (the RAM cron dir is wiped every boot, and the install
#    step runs at every boot, so this is what brings the schedule back);
#  - starts the first sync in the background only if the array is already up. At boot the install runs before
#    the array, Docker (Gitea) and the SecretsMan store are available, so it defers to event/started instead.
EMHTTP="${FLIGHTPLAN_EMHTTP:-/usr/local/emhttp/plugins/flightplan}"
VAR_INI="${FLIGHTPLAN_VAR_INI:-/var/local/emhttp/var.ini}"
S="$EMHTTP/scripts/flightplan-sync.php"
[[ -f "$S" ]] || exit 0
php "$S" --register-cron || true
if grep -q '^mdState="STARTED"' "$VAR_INI" 2>/dev/null; then
  nohup setsid php "$S" --install > /dev/null 2>&1 &
  echo "flightplan: first sync started in the background"
else
  echo "flightplan: array not started yet; the first sync runs from the started event"
fi
exit 0
