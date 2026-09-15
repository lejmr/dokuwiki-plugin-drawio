---
name: validated-change
description: How a change to this plugin gets built, proven and shipped without breaking anything and without the maintainer validating by hand - use for every fix, feature, fork integration or release, whichever model runs it
---

# Validated change

This repository lies dormant for long stretches and is maintained through
agents. Everything below exists because of concrete failures in September
2026: green test suites in front of a media manager that threw on load,
"verified end to end" reports written by the same agent that built the
thing, checklists describing a wiki that existed only in the agent's head,
a search feature nobody had ever run in a browser. The rule that fixes all
of them is the same one: **"done" is defined before the work, as something
a shell command can contradict, and is checked from an empty machine by
someone other than the builder.**

The process is mechanical on purpose so that a cheaper model can run it.
The strong model is only needed for two things: writing the validation
plan (step 1) and the final consistency pass. Everything else is
executing instructions and reporting literal output.

## Roles (may be different agents; must be different roles)

| Role | Does | Must not |
|---|---|---|
| **Planner** (strong model) | Turns the request into a brief + a validation plan with evidence rules and stop rules (template below) | Write code |
| **Implementer** (cheap model) | Works in its own worktree, writes code + one golden test per user-visible behaviour + extra tests, runs the suites, mutation-checks new tests | Push, open PRs, comment on issues, touch other worktrees, install anything into the maintainer's wiki, `cp -R` a worktree |
| **Sceptic** (cheap model, different agent) | Reads the *diff and the evidence*, not the report; tries to break it; adds the test that would have caught what it found | Approve on the strength of prose |
| **Executor** (any model) | Runs the validation plan from a fresh container, reports every step with literal output, stops at the first failure in a stop-rule phase | Plant files, reindex, purge caches, "fix" a step to make it pass |
| **Maintainer** | Only the steps that need a real draw.io editor, in batches of five with a "Good =" column | Everything the machine can do |

## The rules that are not negotiable

1. **Evidence is literal output** - a status code, a DOM excerpt, a `sha256`,
   a test runner's `OK (n tests)` line. "Verified", "works", "tested" without
   the output underneath is a claim, not evidence, and is treated as absent.
2. **Fresh container, nothing planted.** Validation starts with
   `docker compose down -v && docker compose up --build -d`. A step that only
   passes after a file was copied in, a page was reindexed or a cache was
   purged by hand has FAILED, and the fix goes into the code or the seed, not
   into the checklist.
3. **Open it in a browser.** Any change to `script.js`, `syntax.php`'s xhtml
   output, `conf/`, `docker/seed` or the media manager is loaded in headless
   Chrome (`DEVELOPMENT.md` -> "Verifying a change"), anonymous *and* logged
   in. Pass = zero console lines matching
   `Uncaught|TypeError|ReferenceError|SyntaxError` **and** the named positive
   DOM element present. A clean console with the element missing is a fail
   (function hoisting makes a dead script look alive). One `ls` of the
   machine would have found the tool; not looking cost a million tokens of
   review that could not execute JavaScript.
4. **One golden test per feature**, in `_test/golden/`, proving the happy
   path a user takes end to end - real draw.io exports as fixtures
   (`_test/real-drawio-export*`), not hand-built bytes. Edge cases go to
   `_test/extra/`. A new test is **mutation-checked**: remove the fix, the
   test must fail, restore. Every write path asserts the whole data
   directory delta (`_test/data-snapshot.inc.php`): these files and no
   others may change.
5. **Three DokuWiki branches**: `bin/test.sh stable`, `master`, `oldstable`,
   plus both JS tiers, before anything is called done. Core APIs differ
   between them (deprecations, missing methods) - never assume.
6. **Security claims are re-attacked, not re-read.** Every `SECURITY.md`
   item has a step in the validation plan that tries the hole from an
   anonymous session with a valid token. Never weaken a rejection to make
   a feature pass; narrow it and add the bypass test (see the `<use>`
   two-href case in `_test/extra/validation-save.test.php`). No exploit
   recipes in public PR text - describe by class.
7. **Repository hygiene.** One worktree per agent
   (`git worktree add -b <branch> ../<dir> origin/master`), never `cp -R`.
   Squash-only merges. Nobody but the maintainer (or an explicitly
   authorised session) pushes, opens PRs, merges, closes or comments on
   issues, or releases. Releases only through *Actions -> Release*.
8. **Freeze during validation.** No new feature work until the current
   batch is green; a failed batch is fixed and re-validated from scratch
   before the maintainer sees the next list.

## Red flags - stop and re-check when you catch yourself thinking these

- "The tests pass, so it works." (They passed in front of #16 and #37.)
- "I verified it end to end." - in PHPUnit, with fixtures you planted.
- "The checklist step needs X to exist first, I'll just create it." - then
  the seed is wrong; fix `docker/seed`, not the wiki.
- "I'll install plugin Y into the dev wiki to test Z." - it broke the
  maintainer's admin page last time. Use a throwaway compose project.
- "The label concatenation is probably caused by ..." - diagnose with a
  real export, not a theory. The theory was wrong.
- "Chained reviewers agreed." - reviewers reading prose converge on the
  prose. Ask for the command output.

## Templates

### Brief for an implementer

```
Repo, worktree instructions (own worktree from origin/master), files to read first
(DEVELOPMENT.md, REVIEW.md, the modules touched).
Task: <issue numbers + one paragraph of the user-visible behaviour>.
Root cause, not symptom: grep every caller before editing the shared path.
Deliver: code + golden test per behaviour + extra tests; run
`bin/test.sh stable|master|oldstable`, both JS tiers, mutation check.
Browser verification if rule 3 applies (own compose project, own port, tear down).
Commit on the branch with trailers. Do NOT push/PR/comment.
Report: diff summary, literal test and browser output lines, mutation result,
what you adopted vs did differently and why, open questions.
```

### Validation plan (written by the planner, executed by the executor)

Follow the phase structure in `validation-plan.md` next to this file
(M0 static -> M1 suites -> M2 fresh wiki -> M3 browser per context ->
M4 server-side path replay -> M5 SECURITY.md re-attack -> M6 log diff),
adding a step per new behaviour with: the exact command, the expected
literal evidence, and whether failure stops the run. Conventions (jars,
token extraction, ajax helper, headless Chrome flags) are in that file -
reuse them, do not reinvent.

### Batch for the maintainer

Five rows max: `# | where | do | Good =`. Only steps that need the real
editor. Everything the machine proved is listed above the table in two
lines. The maintainer answers `n good / m ko: ...`; a ko goes back to
implementer -> sceptic -> executor before the next batch.

## Model tiering that worked

- Plan + final consistency pass: strong model (Fable/Opus high).
- Implementation, sceptic, executor: Sonnet - the instructions above are
  what makes that safe; without them the tier does not matter.
- The maintainer never runs a tool; they click and answer good/ko.
