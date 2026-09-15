# Reviewing this work

Everything is on `feature/all` — every stream merged, i.e. what `master` looks
like when all of it lands.

## One command

```sh
git checkout feature/all
docker compose up --build        # http://localhost:8080  (admin / admin)
```

Then open <http://localhost:8080/doku.php?id=drawio>. That page is the
click-through protocol: sixteen sections, each saying what you should see.
Sections marked *auto* are also covered by the test suite — skim those.

**Spend your time on 1, 2, 8, 9, 11, 12, 13, 14, 15 and 16.** Those need a
human, a real browser, or the real draw.io editor: the editor round trip,
draft isolation, media manager usage and the edit button, an empty diagram
staying repairable, ODT export, the stored `.drawio` source, the identity
model, the advisory lock, and the bulk conversion in the admin menu.

## The tests

```sh
bin/test.sh            # DokuWiki stable
bin/test.sh master     # development branch
bin/test.sh oldstable
node _test/script.test.js
```

122 PHP tests and 19 JavaScript checks, green on all three DokuWiki branches.
No PHP needed on the host.

## What landed

**First wave — open pull requests, merge into `master` in this order.** #68
first; the rest are stacked on it and GitHub retargets them automatically.

| PR | Fixes | What |
|---|---|---|
| #68 | #67 | dev environment, test suite, CI |
| #69 | #26 | draft of one diagram overwrote another |
| #70 | #36 | media event so gitbacked sees diagrams |
| #71 | #15 #31 #41 #23 | syntax: empty name, placeholders, linkonly, size/title |
| #72 | #10 | media manager usage |

**Second wave — folded into this branch.** #73, #74 and #75 show as merged
because their commits landed in the integration branch they targeted; #76 is
open. Their descriptions are still the record of what changed and why.

| PR | Fixes | What |
|---|---|---|
| #73 | #7 | ODT export; the deprecated `resolve_mediaid()` is gone |
| #74 | #16 | the plugin was breaking other plugins' JavaScript; hardening |
| #75 | — | CI actually runs the JS test; two flaws in the date gate |
| #76 | — | security audit: CSRF, an ACL bypass, stored content, packaging |

**Third wave — in this branch, no pull request yet.**

| What | Why it matters |
|---|---|
| The diagram's XML is now the source of truth in a sibling `.drawio` | The XML used to live only inside the image; any tool that rewrote the image destroyed it and the diagram became uneditable forever |
| Bulk conversion in the admin menu | Old diagrams gain a source when saved; this converts a whole wiki at once, dry run first |
| Advisory lock against concurrent editing | Two people editing one diagram used to mean one of them silently lost their work |
| Page caching restored | Every page with a diagram was re-rendered on every view, forever |
| PDF export | Verified to need no code — dw2pdf goes through the xhtml path, ACL gate included |

Say the word and I will split the third wave into per-topic pull requests
against `master` once the first wave is in.

## Before releasing

```sh
bin/bump-date.sh
```

CI fails on `master` while shipped code is newer than that date — that is what
kept #67 alive for four years.

## Still open afterwards

- **#30** interactive embedded diagram — a rewrite, not a fix. The security
  work recorded the constraint: SVG must come through `fetch.php`, where
  DokuWiki's content security policy applies, never inlined into the page.
- **#64**, **#66** — fixed in code; close them once a release carries the fix.
