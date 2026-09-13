# Reviewing this work

Everything from tonight is on `review/all` — the four fix branches merged on top
of the dev environment, i.e. what `master` looks like after merging all the open
pull requests.

## One command

```sh
git checkout review/all
docker compose up --build        # http://localhost:8080  (admin / admin)
```

Then open <http://localhost:8080/doku.php?id=drawio>. That page is the click-through
protocol: ten sections, each saying what you should see, in the order that makes
sense. Sections marked *auto* are also covered by the test suite — skim those and
spend the time on 1, 2, 8 and 9, which need a human and the real draw.io editor.

## The tests

```sh
bin/test.sh            # DokuWiki stable
bin/test.sh master     # development branch
bin/test.sh oldstable
```

22 tests, green on all three. No PHP needed on the host.

## The pull requests

They are stacked, so merge them in this order:

| PR | Fixes | Notes |
|---|---|---|
| #68 | #67 | dev environment, test suite, CI |
| #69 | #26 | draft of one diagram overwrote another |
| #70 | #36 | media event so gitbacked sees diagrams |
| #71 | #15 #31 #41 #23 | syntax: empty name, placeholders, linkonly, size/title |
| #72 | #10 | media manager usage |

#69 and #70 are independent of each other; #72 sits on top of #71.

`review/all` is for looking at the result as a whole — it is not meant to be
merged itself. Once the pull requests are in, delete it.

## Still open afterwards

- **#30** interactive embedded diagram — a rewrite, not a fix.
- **#7** ODT export — needs the odt plugin's renderer.
- **#16** CKGEdit conflict — third-party, not reproducible without their setup.
- **#64**, **#66** — already fixed in the code; they can be closed once a release
  with the new `plugin.info.txt` date is out.

## Before releasing

```sh
bin/bump-date.sh
```

CI fails on `master` while shipped code is newer than that date — that is what
kept #67 alive for four years.
