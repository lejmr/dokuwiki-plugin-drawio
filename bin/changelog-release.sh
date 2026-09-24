#!/bin/sh
# Make sure CHANGELOG.md has a '## [<version>]' section for the release:
# keep one that is already there, otherwise rename '## [Unreleased]' to it.
# Refuses if neither exists, or if Unreleased has no entries - the section
# becomes the release notes, and an empty one would ship an empty release.
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
v=$1
if grep -q "^## \[$v\]\$" CHANGELOG.md; then
    echo "CHANGELOG.md already has '## [$v]'"
    exit 0
fi
if ! grep -q '^## \[Unreleased\]$' CHANGELOG.md; then
    echo "::error::CHANGELOG.md has neither '## [$v]' nor '## [Unreleased]'"
    exit 1
fi
entries=$(awk '$0 == "## [Unreleased]" { f=1; next } f && /^## / { exit } f && /^- / { n++ } END { print n+0 }' CHANGELOG.md)
if [ "$entries" -eq 0 ]; then
    echo "::error::'## [Unreleased]' in CHANGELOG.md has no entries - nothing to release"
    exit 1
fi
sed -i.bak "s/^## \[Unreleased\]\$/## [$v]/" CHANGELOG.md && rm -f CHANGELOG.md.bak
echo "CHANGELOG.md: '## [Unreleased]' ($entries entries) -> '## [$v]'"
