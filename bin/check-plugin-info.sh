#!/bin/sh
# Guard against the class of bug from issue #67: shipping a code change without
# bumping the date in plugin.info.txt, which makes DokuWiki's updater believe
# every install is already up to date.
#
# With --warn a stale date is reported but does not fail: bumping the date is a
# release act, so every feature branch would otherwise have to touch the same
# line and conflict with its siblings. CI is strict on master, which is the
# branch a release is cut from.
set -eu

cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
INFO=plugin.info.txt
fail=0
warn_only=0
[ "${1:-}" = "--warn" ] && warn_only=1

for field in base author email date name desc url; do
    grep -qE "^$field[[:space:]]+\S" "$INFO" || { echo "MISSING field '$field' in $INFO"; fail=1; }
done

value() { sed -nE "s/^$1[[:space:]]+(.*[^[:space:]])[[:space:]]*\$/\1/p" "$INFO"; }

[ "$(value base)" = "drawio" ] || { echo "FAIL base must be 'drawio', got '$(value base)'"; fail=1; }

date=$(value date)
echo "$date" | grep -qE '^[0-9]{4}-[0-9]{2}-[0-9]{2}$' || { echo "FAIL date '$date' is not YYYY-MM-DD"; fail=1; }

# Newest commit touching shipped plugin code (not tooling, tests or docs).
code=$(git log -1 --format=%cs -- '*.php' '*.js' '*.css' \
    ':(exclude)bin' ':(exclude)_test' ':(exclude)docker' ':(exclude).github' 2>/dev/null || true)

if [ -n "$code" ] && [ "$date" \< "$code" ]; then
    echo "plugin.info.txt date ($date) is older than the last code change ($code)."
    echo "     Run bin/bump-date.sh before releasing, or installed wikis never see the update."
    [ "$warn_only" -eq 1 ] || fail=1
fi

[ "$fail" -eq 0 ] && echo "OK $INFO (date $date)"
exit "$fail"
