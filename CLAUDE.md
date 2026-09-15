# drawio plugin - context for agents

Work here follows the user-level **drive-change** skill (acceptance table of
exact outputs first; planner / implementer / sceptic / executor / maintainer;
fresh-container validation; maintainer batches of five; snapshot-tree PRs;
changelog; release button). This file only supplies what that skill leaves to
the project:

- **Development guide:** `DEVELOPMENT.md` - dev wiki (`docker compose up`),
  test commands, the "Verifying a change" rules (headless Chrome, which
  contexts to open), CI, releasing, coming back after a long time.
- **Fresh environment:** `docker compose down -v && docker compose up --build -d`
  (port `DW_PORT`, default 8080; admin/admin). Seeds: `docker/seed/`. The
  walkthrough page `whatsnew` must stay doable from scratch.
- **Test tiers:** `bin/test.sh <stable|master|oldstable> [golden]` (PHP,
  three DokuWiki branches), `node _test/golden/script.test.js` and
  `node _test/extra/script.test.js`. Golden = one happy path per feature,
  real draw.io exports as fixtures (`_test/real-drawio-export*`); extra =
  everything else. Whole-data-directory deltas: `_test/data-snapshot.inc.php`.
- **Validation plan:** `_test/validation-plan.md` (machine phases M0-M6 with
  conventions and stop rules; add a step per acceptance row).
- **Landing:** `bin/merge-stages.sh <stages> <bodies>` - topical squash PRs
  from snapshot trees, verified against master after every merge.
- **Release:** *Actions -> Release -> Run workflow* only; needs a
  `## [YYYY-MM-DD]` section in `CHANGELOG.md`; the dokuwiki.org block goes
  to the job summary, never to release notes.
- **Security:** findings and policy in `SECURITY.md`; no public issues, no
  exploit recipes in PR text; xhtml output is viewer-independent by design
  (see `syntax.php` render()).
- **Maintainer:** Miloš Kozák; reads Czech, repository is English; decisions
  up front, tests only editor steps, answers `n good / m ko`.
