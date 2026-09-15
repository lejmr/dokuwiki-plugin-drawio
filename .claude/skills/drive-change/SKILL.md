---
name: drive-change
description: The whole path from a feature request or issue to a merged, released change - roles, evidence rules, fresh-container validation, maintainer batches, snapshot-tree PRs, changelog and the release button - use for every fix, feature, fork integration or release, whichever model runs it
---

# Drive a change

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

## Step zero: from a vague request to exact outputs - nothing is built before this

Every request arrives vague: "search should work", "make diagrams
interactive", "fix the fork's issue". The lesson of September 2026 is that
the work must not start from that sentence. It starts from an **acceptance
table** that turns the sentence into outputs a shell command or a click can
contradict, and everything downstream - the brief, the golden tests, the
validation steps, the maintainer's batch - is derived row by row from it.

| # | Behaviour (one sentence, user's words) | How it is observed | Expected literal output | Checked by |
|---|---|---|---|---|
| 1 | Words drawn in a diagram are found by wiki search | `curl "$W/doku.php?do=search&q=<label>"` after saving through the plugin's own ajax `save`, no reindex by hand | body contains `id=<embedding page>` | machine (M4) |
| 2 | Opening a diagram from the media manager works on the `.drawio` too | headless Chrome on `?do=media&image=ns:plan.drawio` | `<button …>Edit with draw.io` inside `ul.actions`, 0 console errors | machine (M3) |
| 3 | Two people editing one diagram are warned | second window opens the same diagram | editor shows "edited by admin" | maintainer (batch) |

Rules for the table:

- **One row per behaviour the user would notice**, in the user's words, not
  the implementation's ("links inside the diagram are clickable", not "emit
  data-mxgraph").
- **The expected output is literal**: a string in a body, an element in the
  DOM, a file that exists with a given sha, a status code, a runner's `OK`
  line. "Works", "is displayed", "is correct" are not outputs.
- **The observation names the exact command or click**, from a fresh
  container, with nothing planted. If a row cannot be observed that way,
  the row is wrong, not the environment - rewrite it until it can.
- **Every row is assigned**: machine (which validation phase) or maintainer
  (which batch step). Maintainer rows are only those that need the real
  editor; everything else is machine.
- **Negative rows exist too** - what must NOT happen (anonymous export
  does not contain the restricted diagram; a page without the feature
  loads no extra script; the HTML is byte-identical for every viewer).
- The table is shown to the maintainer together with the decision list
  (flow step 1). His "ok" on the table is the specification; a row he
  strikes is out of scope; a row he adds is in. After that it does not
  change silently - a change to the table is a message to him.
- **Golden tests are the machine rows, one to one.** A row without a test
  is a missing test; a test without a row is testing the implementation.
- The validation plan's new steps are the machine rows with their
  commands; the batch page is the maintainer rows with their "Good =".

If a request cannot be turned into rows, it is not ready to be built -
say so and ask the one question that unblocks it, instead of guessing and
building something that only looks done.

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

## The flow: from a request to a release

This is the whole path, in order. Nothing is skipped because "it's small".

1. **Intake.** The maintainer arrives with a request (an issue, a fork, a
   sentence). Before any code: read the issue thread and the forks
   (`gh api repos/<owner>/<repo>/forks?sort=newest`, and the closed/stale
   issues - the stale bot used to close good reports), and write back in
   one message: what will be built, what will NOT (with a reason), and the
   decisions only the maintainer can make (each with a recommendation).
   The maintainer answers once; those answers are recorded in the brief.
   A feature is frozen while a validation batch is open (rule 8).
2. **Acceptance table + brief + validation plan** (planner). The
   acceptance table (step zero) comes first and is agreed with the
   maintainer; then one brief per implementer that carries the table's
   rows as the definition of done, and a validation-plan delta for the
   executor (the machine rows with command, expected literal evidence,
   stop-or-not). Independent topics get
   independent implementers in their own worktrees, in parallel.
3. **Implement** (implementer): code, golden + extra tests, mutation
   check, three DokuWiki branches, both JS tiers, browser rule if it
   applies, commits on the branch. Report with literal output.
4. **Sceptic**: reads the diff and the evidence, tries to break it, adds
   the missing test. What the sceptic found is fixed and committed before
   going on - the planner does not carry unresolved sceptic findings.
5. **Integrate**: merge the topic branches into one integration branch
   (`feature/<batch>`); resolve only test-file conflicts (both sides are
   usually independent blocks - keep both); run everything again.
6. **Validate from nothing** (executor): the demo wiki is rebuilt from
   scratch on the integration commit (`docker compose down -v && up
   --build -d`) and the validation plan runs M0-M6. Anything red goes back
   to step 3. Only when it is green does the maintainer hear about it.
7. **Batch for the maintainer**: a page in the demo wiki (`batch<N>`) with
   at most five editor-only steps and a "Good =" per step, plus the two
   lines of what the machine proved. The maintainer answers `n good / m
   ko: ...`. A ko is a bug: back to step 3, then 6, then a new batch.
8. **Land it**: topical PRs from snapshot trees with `bin/merge-stages.sh`
   (one commit per topic whose *tree* is the integration history at that
   boundary; squash-only; the script verifies master's tree after every
   merge and stops on mismatch). Titles carry `fixes #N, fixes #M` - one
   keyword per number, GitHub ignores the rest. Bodies describe by class,
   no exploit recipes. Only with the maintainer's explicit go.
9. **Changelog**: `CHANGELOG.md` gets a `## [YYYY-MM-DD]` section for the
   release day, written for people running the plugin. The release
   workflow refuses a version without one - that is the reminder, not a
   bug.
10. **Release**: *Actions -> Release -> Run workflow*. It bumps the date,
    tags, tests, builds the zip from `git archive`, publishes. The job
    summary carries the dokuwiki.org block (never the release notes). The
    maintainer pastes it onto the plugin page - the only manual step, and
    the one that actually reaches DokuWiki's updater.
11. **Close the loop**: `fixes` closed the issues; anything else gets one
    factual sentence (what fixed it, where it ships). Reopen stale-closed
    reports that are still valid rather than filing duplicates.

## Working with the maintainer (learned in two days, both directions)

- He decides, once, up front. Give him the decision list with a
  recommendation each; do not re-ask, do not drip questions. Record the
  answers in the brief and in `DEVELOPMENT.md`/`SECURITY.md` where they
  are policy.
- He tests only what needs a real editor, in batches of five, and answers
  good/ko. A list that reaches him before the machine has proven the rest
  from a fresh container is the single thing that destroyed trust here.
- Outward actions - push, PR, merge, closing/commenting issues, releasing,
  installing anything into his wiki - only with an explicit go for that
  action in that context. Squash-only merges. No local release scripts.
- Security findings go to `SECURITY.md` and one branch/PR, never public
  issues; PR text describes by class.
- Release notes are for users: no dokuwiki.org block, no process noise.
- When asked "did you test this?", the only good answer is the command and
  its output. When something was missed, quantify it (what one command
  would have caught it vs. what was spent) and turn it into a rule here.
- He reads Czech; the repository, commits, PRs and docs are English.

## Templates

### Brief for an implementer

```
Repo, worktree instructions (own worktree from origin/master), files to read first
(DEVELOPMENT.md, REVIEW.md, the modules touched).
Task: <issue numbers + one paragraph of the user-visible behaviour>.
Definition of done: the acceptance table rows assigned to you, verbatim - each
becomes one golden test; report each row with its literal output.
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
