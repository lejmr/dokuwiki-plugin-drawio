# Reviewing this work

Everything is on `review/all` — every fix merged on top of the dev environment,
i.e. what `master` looks like once all the open pull requests are in.

## One command

```sh
git checkout review/all
docker compose up --build        # http://localhost:8080  (admin / admin)
```

Then open <http://localhost:8080/doku.php?id=drawio>. That page is the
click-through protocol: twelve sections, each saying what you should see.
Sections marked *auto* are also covered by the test suite — skim those and spend
the time on **1, 2, 8, 9, 11 and 12**, which need a human, a real browser, or
the real draw.io editor.

## The tests

```sh
bin/test.sh            # DokuWiki stable
bin/test.sh master     # development branch
bin/test.sh oldstable
node _test/script.test.js
```

37 PHP tests and 6 JavaScript checks, green on all three DokuWiki branches. No
PHP needed on the host.

## The pull requests

**First wave — open, review and merge these into `master` in this order.**
#68 first; the rest are stacked on it and GitHub retargets them automatically.

| PR | Fixes | What |
|---|---|---|
| #68 | #67 | dev environment, test suite, CI |
| #69 | #26 | draft of one diagram overwrote another |
| #70 | #36 | media event so gitbacked sees diagrams |
| #71 | #15 #31 #41 #23 | syntax: empty name, placeholders, linkonly, size/title |
| #72 | #10 | media manager usage |

**Second wave — already folded into `review/all`.** #73 (ODT export, #7), #74
(the plugin was breaking other plugins' JavaScript, #16, plus hardening) and #75
(CI runs the JS test) show as merged because their commits landed in
`review/all`, the branch they targeted. Their diffs and descriptions are still
worth reading; they are the record of what changed and why.

| PR | Fixes | What |
|---|---|---|
| #73 | #7 | ODT export, and the deprecated `resolve_mediaid()` is gone |
| #74 | #16 | plugin broke other plugins' JavaScript; save/permission hardening |
| #75 | — | CI actually runs the JS test; two flaws in the date gate |
| #76 | — | security audit: ACL, CSRF, stored content, packaging |

Once the first wave is in `master`, say the word and I will rebase the second
wave onto it as fresh pull requests, so you get the same per-topic review for
those four. That is a mechanical step on my side.

## What #74 turned up

Worth reading even if you skim the rest. The plugin has been silently breaking
**other plugins'** JavaScript in the media manager for years (#16 was blamed on
CKGEdit), `save` could truncate a live diagram to zero bytes, and it could write
a `.php` file into `data/media`. All pre-existing, all now covered by tests.

## Still open afterwards

- **#30** interactive embedded diagram — a rewrite, not a fix.
- **#64**, **#66** — already fixed in code; they can be closed once a release
  with the new `plugin.info.txt` date is out.

## Before releasing

```sh
bin/bump-date.sh
```

CI fails on `master` while shipped code is newer than that date — that is what
kept #67 alive for four years.
