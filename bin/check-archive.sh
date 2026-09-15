#!/bin/sh
# Guard against losing .gitattributes export-ignore in a bad merge (SECURITY.md,
# "Development files shipped to production" - Medium, fixed). That one file is
# the only thing keeping docker/, _test/, bin/ and .github/ - including a
# seeded password file under docker/ - out of a published release archive.
#
# Checked by building the exact archive GitHub's download link and any other
# `git archive`-based source (codeload.github.com/.../archive/*.zip) produces,
# and failing if any development path shows up in it. Not a check on
# .gitattributes' *content* - a check on what it actually achieves, so a
# rewritten or reordered .gitattributes that still does the job keeps passing.
set -eu

cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

fail=0
listing=$(git archive HEAD | tar -t)

for path in docker _test bin .github CLAUDE.md; do
    if echo "$listing" | grep -qE "^$path(/|\$)"; then
        echo "FAIL '$path' is present in \`git archive HEAD\` - export-ignore in .gitattributes is missing or broken"
        fail=1
    fi
done

[ "$fail" -eq 0 ] && echo "OK git archive HEAD ships no development paths"
exit "$fail"
