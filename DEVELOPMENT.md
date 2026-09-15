# Development

## Run a wiki with the plugin

```sh
docker compose up --build      # http://localhost:8080  (admin / admin)
```

The repository is mounted into the container, so editing a `.php` or `.js` file
and reloading the browser is enough - no rebuild, no restart.

The wiki comes pre-installed (no setup wizard) and ships a test page at
<http://localhost:8080/doku.php?id=drawio> that is meant to be clicked through
after a change.

| | |
|---|---|
| different DokuWiki release | `DW_VERSION=oldstable docker compose up --build` (`stable`, `oldstable`, `master`, or a tag such as `2025-05-14b`) |
| different port | `DW_PORT=9000 docker compose up` |
| throw the wiki away | `docker compose down -v` |

## Run the tests

```sh
bin/test.sh              # DokuWiki stable
bin/test.sh master       # development branch
bin/test.sh oldstable
```

No PHP needed on the host - the script runs PHPUnit in a container against a
real DokuWiki checkout (cached in `.cache/`, safe to delete).

`bin/run-tests.sh` is the same thing without docker and is what CI runs.

## Before releasing

```sh
bin/check-plugin-info.sh   # CI runs this too
bin/bump-date.sh           # sets the date DokuWiki's updater compares against
```

A stale date in `plugin.info.txt` means installed wikis never see the update
(issue #67), so CI fails the build when plugin code is newer than that date.

## CI

`.github/workflows/ci.yml` runs the metadata check, `php -l`, and the test suite
against **master, stable and oldstable** on every push and once a week - the
weekly run is what catches a new DokuWiki release breaking the plugin.
