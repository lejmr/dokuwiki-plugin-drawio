# Validation plan — drawio plugin (machine phases M0–M6)

Executor rules (non-negotiable):
- Run from the worktree given in your brief. Never write under the repo, never plant files by hand, never reindex or purge caches by hand. If a step only passes with such help, it FAILS.
- Evidence = literal command output (status, body excerpt, DOM excerpt), not paraphrase.
- Browser pass = zero CONSOLE lines matching `Uncaught|TypeError|ReferenceError|SyntaxError` AND the named positive DOM evidence. A clean console with the positive missing is a FAIL (hoisting trap).
- Logged-in browser loads: use an injected page in the docroot that POSTs the login via fetch() (get `sectok` from the login form first) and then sets `location.href` to the target. Gate: `grep -c 'Logged in as'` ≥ 1, else the load does not count.
- Stop rule: abort on any M0/M1/M2 failure, any M3 console error, M3.9 = 0, any M5 failure, or M4.5/M4.13/M4.21 failure. Report every step that ran, with verdicts.

Conventions:
```
W=http://localhost:PORT ; J=jar admin ; A=jar anon ; CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
login: GET $W/doku.php?id=start&do=login → sectok → POST u=admin&p=admin&do=login&sectok=…
tok(jar): grep '"sectok":"…"' from $W/doku.php?id=whatsnew
ajax(jar,action,image,…): POST $W/lib/exe/ajax.php call=plugin_drawio action= imageName= sectok=$TOK [content= xml=]
xml(label): <mxfile host="probe"><diagram id="d1" name="Page-1"><mxGraphModel><root><mxCell id="0"/><mxCell id="1" parent="0"/><mxCell id="2" value="LABEL" style="rounded=0;" vertex="1" parent="1"><mxGeometry x="20" y="20" width="120" height="60" as="geometry"/></mxCell></root></mxGraphModel></diagram></mxfile>
PNG = data:image/png;base64,<base64 of _test/real-drawio-export.png> ; PNG2 = …-ztxt.png ; SVG = …real-drawio-export.svg ; BLANK = blank-image.png
browse(url): $CHROME --headless=new --disable-gpu --no-sandbox --user-data-dir=<fresh> --timeout=15000 --enable-logging=stderr --v=1 --dump-dom URL   # wrap in `perl -e 'alarm 60; exec @ARGV'`: Chrome may not exit after dumping; exit 142 with a full DOM still counts
```

## M0 static
- M0.1 `git status --porcelain` empty; branch/commit as briefed.
- M0.2 `bin/check-plugin-info.sh` → OK lines, exit 0.
- M0.3 `git archive HEAD | tar -t` contains plugin files only; none of docker/ _test/ bin/ .github/ REVIEW.md DEVELOPMENT.md docker-compose.yaml.
- M0.4 `git ls-files -s | awk '$1=="100755"'` → only bin/*.sh and docker/dev-entrypoint.sh.
- M0.5 ci.yml: every `uses:` pinned to a 40-hex SHA; `permissions: contents: read` present.
- M0.6 php -l on every *.php (via docker php image) → no errors. M0.7 `node --check script.js`.

## M1 suites
- M1.1 `node _test/script.test.js` exit 0 (report OK count).
- M1.2/3/4 `bin/test.sh stable|master|oldstable` → OK. Also `bin/test.sh stable golden` → OK, time it.

## M2 fresh wiki
- M2.1 `docker compose down -v; docker compose up --build -d` (DW_PORT as briefed).
- M2.2 whatsnew reachable within 120 s.
- M2.3 pages whatsnew and drawio contain their titles.
- M2.4 fetch.php media=wiki:sample.png → 200 image/png; media=wiki:sample.drawio → 404 (old-style, no source).
- M2.5 login works; `Logged in as` present; token 32 chars.
- M2.6 anonymous whatsnew JSINFO.plugin_drawio has zIndex,url,toolbar_possible_extension ["png","svg"],ui,editbutton,lockwarning,locktime,sectok.
- M2.7 `docker compose logs | grep -E 'PHP (Warning|Notice|Fatal|Deprecated)'` → baseline (expect empty).

## M3 browser, every context (anonymous unless stated)
- M3.1 whatsnew: imgs with onclick='edit(this);' for new:plan.png, wiki:sample.png, new:plan.svg; sized one has w=200&tok= (the sized <img> is emitted before the diagram exists - fetch.php just 404s until M4.3); taskrunner.php?id=whatsnew present.
- M3.2 drawio page: drawio-error span (§4), id='drawio_diagram.png' (§5), class='drawio-linkonly' (§6), two sized imgs.
- M3.3 `doku.php?id=scratch:toolbar&do=edit`: DOM has plugins/drawio/icon.png AND icon_png.png AND icon_svg.png (picker registered, not merely hoisted).
- M3.4 `doku.php?do=media&ns=wiki&image=wiki:sample.png&tab_details=view`: `<li class="drawio__mmbtn"><div class="no"><button type="button">Edit with draw.io` inside ul.actions; Delete/Upload buttons present (logged-in only - anonymous has no Delete).
- M3.5 `lib/exe/mediamanager.php?ns=wiki&image=wiki:sample.png`: no console error.
- M3.7 logged-in `doku.php?do=admin&page=drawio`: h1 "Draw.io: convert old diagrams"; "1 diagram(s) found. 0 already have a source. 1 do not yet." (counts may differ by seeded media—report literal); row wiki:sample.png → "source can be recovered"; button "Convert this batch".
- M3.8 logged-in `doku.php?do=admin&page=config`: `plugin____drawio____ui` select (6 options); `toolbar_possible_extension` checkboxes png+svg checked; no PHP warning in page.
- M3.9 `doku.php?do=search&q=walk-through` finds whatsnew (proves M3.1's view ran the indexer). If 0 → STOP.
- M3.10 (#107) logged-in whatsnew, Chrome with `--force-dark-mode --blink-settings=preferredColorScheme=0`, click new:plan.png: the editor iframe `src` has `ui=` and no `dark=`; screenshot of the editor is light (kennedy and atlas).

## M4 user's server-side path (admin jar unless stated)
- M4.1 get_auth new:plan.png → 200 `true`.
- M4.2 lock new:plan.png → `{"locked_by":null,"since":null}`.
- M4.3 save new:plan.png content=$PNG xml=xml(zzprobe1) → 200; fetch new:plan.png 200 sha = sha(real-drawio-export.png); fetch new:plan.drawio 200 contains zzprobe1.
- M4.4 get_png new:plan.png → JSON with content AND xml containing zzprobe1.
- M4.5 search q=zzprobe1 finds whatsnew immediately (no reindex).
- M4.6 search q=JsValue → 0 (sample has no source yet; `Repesentation` also appears as prose on whatsnew, so it is not a discriminating control).
- M4.7 get_png wiki:sample.png → content, NO xml key.
- M4.8 save old:legacy.png content=$PNG (no xml) → 200; fetch old:legacy.drawio → 404.
- M4.9 save old:legacy.png with xml(zzlegacy) → fetch old:legacy.drawio 200 contains zzlegacy.
- M4.10 save new:plan.svg content=$SVG xml(zzsvg1) → png sha unchanged; new:plan.drawio contains zzsvg1; get_png new:plan.png returns xml with zzsvg1.
- M4.11 resolve_source new:plan.drawio → `{"granted":true,"id":"new:plan.png"}`.
- M4.12 sized img src from M3.1 (unescape &amp;) → PNG width 200 (bytes 16..20), smaller than full.
- M4.13 ODT: export whatsnew → count Pictures/ ≥1 and draw:image ≥1; then save new:plan.png with $PNG2 xml(zzprobe2); export again; plan.png picture size differs from first export (no purge by hand).
- M4.14 save probe:orphan.png (xml zzorphan); delete via `doku.php?do=media&ns=probe` POST delete=probe:orphan.png sectok → both fetches 404; media_attic/probe has orphan.<ts>.png; no .drawio left.
- M4.15 save probe:pair.png and probe:pair.svg (xml each); delete pair.png → pair.drawio still 200; delete pair.svg → pair.drawio 404.
- M4.16 save probe:keep.png (xml); delete probe:keep.drawio → keep.png 200; get_png returns content, no xml.
- M4.17 admin negatives: GET convert=1&sectok=valid; POST convert=1 no token; POST wrong token → wiki:sample.drawio still 404; page never says "source written".
- M4.18 anonymous `do=admin&page=drawio` → no "Convert this batch".
- M4.19 save probe:plain.png content=$BLANK (no xml); dry run lists wiki:sample.png recoverable, probe:plain.png "no draw.io XML found", new:plan.png/old:legacy.png "already has a source".
- M4.20 POST convert=1 offset=0 sectok → wiki:sample.png "source written"; fetch wiki:sample.drawio 200; inflate (the seeded diagram is stored compressed) → contains JsValue; probe:plain.png unchanged; wiki:sample.png sha unchanged.
- M4.21 search q=JsValue finds whatsnew (no reindex by hand).
- M4.22 dry run again → "already has a source - left untouched"; convert again → sha of sample.drawio unchanged.
- M4.23 browse `doku.php?do=media&ns=new&image=new:plan.drawio&tab_details=view` → drawio__mmbtn present, console clean.
- M4.24 anonymous jar+token: lock new:plan.png → locked_by "admin". M4.25 admin lock → locked_by null (admin holds it; run M4.24 first, within locktime). M4.26 anon lock new:plan.svg → names admin.

## M5 SECURITY.md claims (release blockers)
- M5.1 restricted namespace exists via seed (`secret:* @ALL 0`); verify anonymous fetch secret:hidden.png → 403, admin → 200.
- M5.2 admin: save secret:hidden.png xml(zzsecret1) (overwrites seeded image; ok). Create public page pub:leak via editor form POST (do=save, wikitext with {{drawio>secret:hidden.png}} and {{drawio>new:plan.png}}, sectok); browse it anonymously (fires indexer); search q=leakpage finds pub:leak.
- M5.3 search q=zzsecret1 anonymous → 0 and admin → 0 (indexing is anonymous-readability based); positive control q=zzprobe2 finds whatsnew.
- M5.4 ODT pub:leak: anonymous export → Pictures count 1; admin export → 2.
- M5.5 anonymous vs admin `<img … secret:hidden.png …>` strings byte-identical; fetch secret:hidden.png and secret:nothere.png anonymous → 403 both.
- M5.6 anonymous+token on secret:hidden.png and secret:nothere.png: get_auth → false; save/draft_save/draft_get/get_png/lock/resolve_source → 403, identical for existing vs missing; no file written under secret/.
- M5.7 anonymous save with imageName secret:hidden.png, pub:..:secret:x.png, %20secret:x.png, secret::x.png → 403/400; secret/ unchanged.
- M5.8 admin: GET ajax with valid token → 403; POST no sectok → 403; POST wrong sectok → 403; positive control POST valid → 200 true.
- M5.9 anonymous POST without token get_auth new:plan.png → 200 (documented exemption).
- M5.10 draft_save new:plan.png: 2 MiB+1 body → 400; content=notjson → 400; {"lastModified":1} → 400; valid draft to new:plan.txt → 400; to new:plan.png.draft → 400; no *.draft created. Positive: valid {"lastModified":1,"xml":"<mxfile/>"} → 200, draft_get echoes, draft_rm 200, draft_get → {"content":"NaN"}.
- M5.11 save probe:t1.png content=$SVG; t2.svg content=$PNG; t3.svg with <script>; t4.svg with onload; t5.php $PNG; t6.png content=undefined; t7.png $PNG xml=<html> → all 400; none on disk; no .tmp files.
- M5.12 ajax bogus newns:zz.png; draft_get newns2:zz.png → no directories created.
- M5.14 save new:evil.drawio content=$PNG → 400; no file.

## M6
- M6.1 logs diff vs M2.7: nothing new from lib/plugins/drawio.
- M6.2 `docker compose down -v` and leave the worktree clean.

## Report format
Table per phase: step id | PASS/FAIL | literal evidence (≤3 lines). Then: findings (anything FAIL or surprising), and the exact state of the branch/commit validated.

## Run log
- 2026-09-14 commit 39cfd42: M5.4 PASS live (anon 1 / admin 2 / anon 1 pictures), M5.5–M5.12, M5.14, M6.1 PASS on a fresh container; M5.13 dropped (neither core nor this plugin sets a CSP on fetch.php - the step was a wrong assumption of the plan, not a finding).
