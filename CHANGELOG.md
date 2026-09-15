# Changelog

All notable changes to the drawio plugin are recorded here. This file is
written for someone running the plugin, not for someone patching its code —
see the pull requests linked from each release on GitHub for the technical
detail.

## [2026-09-15]

The first release to come out of an actual release process. It carries a
long backlog of fixes plus three larger pieces of work: diagrams now keep
their editable source next to the image, a security audit closed several
holes, and editing got noticeably safer for more than one person at a time.

### Added

- A development environment (`docker compose up`) and a test suite that runs
  against DokuWiki **master**, **stable** and **oldstable** in CI, on every
  pull request and once a week — so a DokuWiki release that breaks the
  plugin is caught before a user reports it.
- ODT export.
- An "edit with draw.io" button in the media manager.
- A diagram's draw.io XML is now stored as its own source file (a sibling
  `.drawio` next to the image) instead of living only inside the exported
  image. See **Upgrading** below — this is the change to plan around.
- **Admin → Draw.io: convert old diagrams**, a bulk task that gives every
  existing diagram a stored source in one pass, with a dry run first.
- An advisory lock: opening a diagram someone else already has open now
  warns who, and how long ago, instead of silently letting a second save
  overwrite the first.
- Page caching for pages containing a diagram, which had been disabled
  entirely since the diagram's own security check needed a per-viewer
  decision; that check no longer needs one, so ordinary DokuWiki caching
  applies again.
- Diagram text is searchable: the labels of a diagram are indexed with the
  pages that embed it, and a save re-indexes those pages straight away.
- Sized diagrams (`{{drawio>plan?200}}`) download a resized copy instead of
  shrinking the full image in the browser.
- Settings: `ui` picks the editor's interface (`kennedy`, `min`, `atlas`,
  `dark`, `sketch`, `simple`); `edit_button` shows an "Edit with draw.io"
  button under every diagram; `top_offset` pushes the editor down under a
  template's fixed top navbar (#50).
- Viewing a page "at" a time (`?at=<timestamp>`) shows the diagram as it
  was at that time, like DokuWiki does for images.
- Deleting a diagram's image deletes its source too; the deleted revision
  is archived to the media attic as the image with the XML embedded, so a
  restore brings the diagram back editable. Renaming with the move plugin
  moves the source along.

### Fixed

- The updater never offered this release to installed wikis (#67) — CI now
  refuses to ship code without a matching date in `plugin.info.txt`.
- A saved draft could overwrite the wrong diagram's draft (#26).
- Diagrams didn't trigger the media-changed event other plugins (e.g.
  gitbacked) rely on to notice a file changed (#36).
- Several syntax edge cases: an empty diagram name, placeholder handling,
  `linkonly`, and size/title being dropped on save (#15, #31, #41, #23).
- The media manager's usage/reference display for diagrams (#10).
- ODT export existed but wasn't reachable from the interface (#7).
- The plugin was breaking other plugins' JavaScript in the media manager
  (#16).
- ...and on any page without `JSINFO` at all, such as the Snippets plugin's
  popup (#37).
- A large diagram could not be saved when the browser's local storage was
  full (#32, #57).
- An empty diagram saved by draw.io (a 1×1 px image) could never be opened
  again - every diagram now keeps a minimum clickable area (#62).
- A diagram using mathematical typesetting could not be saved as SVG (#49).
- ODT export was cached per page rather than per viewer, so the first
  export decided what every later exporter got.
- The placeholder image shipped with the plugin carried a stray draw.io
  diagram since 2020.
- Diagram names containing dots would not open (adopted from the
  Thulium-Drake fork).

### Security

A full audit of the plugin's own code found and fixed a cross-site request
forgery hole in the ajax endpoint, a namespace ACL bypass that let a write
land in a denied child namespace, an existence oracle that revealed whether
protected media existed, unvalidated draft writes, content that didn't have
to match the file type it was stored under, unsanitised SVG markup inserted
into the page, and development-only files (including a seeded credential)
that were shipping inside the installed plugin. Every item has a regression
test. Full details, and what is deliberately still out of scope, are in
[SECURITY.md](SECURITY.md).

## Upgrading

**Diagrams now save their XML source separately from the image.** Until a
given diagram has that source file, its only copy of the editable XML is
still the one embedded inside the image — exactly as before this release.
That means **anything that rewrites the image file destroys the diagram**:
an image optimiser, a format conversion, a backup tool that re-encodes
files it touches. This was already true before this release; the difference
is that it is now avoidable.

A diagram gets its source file automatically the next time it is opened and
saved. If you would rather not wait for that to happen one page at a time,
run **Admin → Draw.io: convert old diagrams** right after upgrading — it
does a dry run first and never overwrites an existing source, so it is safe
to run more than once. Doing this promptly, rather than "eventually", is the
whole point: every diagram without a source is one rewrite away from being
gone for good.
