# Security

## Reporting a vulnerability

Please do **not** open a public issue for a security problem. A public report
tells everyone running this plugin about the hole before there is a version
that fixes it, and wikis using this plugin are updated by hand.

Instead use GitHub's private reporting:
[**Report a vulnerability**](https://github.com/lejmr/dokuwiki-plugin-drawio/security/advisories/new).
If that is unavailable to you, mail the plugin author at the address in
`plugin.info.txt`.

This is a spare-time project, so please be patient, and say in your report how
long you intend to wait before disclosing.

## What this plugin is responsible for

The plugin stores diagrams as ordinary DokuWiki media files and edits them
through an embedded draw.io editor. That means:

- **Access to a diagram is access to the media file.** Permissions come from
  DokuWiki's ACL on the media's namespace, the same as any uploaded image. The
  plugin must never grant more than DokuWiki itself would.
- **The editor is a third party.** By default the plugin loads the editor from
  `embed.diagrams.net` in an iframe; the diagram is sent to it and comes back
  from it. Wikis that cannot accept that should point the `url` setting at a
  self-hosted draw.io. The interactive viewer (`?interactive`, or the
  `interactive` config default) is the same trade-off: it loads a script from
  `viewer.diagrams.net` by default, admin-configurable via `viewer_url` the
  same way, and only ever fetched on a page that actually renders one.
- **Diagram content is user content.** A diagram is an image file whose bytes
  come from whoever edited it, so it is treated as untrusted on the way in and
  on the way out.

## Hardening in this release

An audit of the plugin's own code — ACL handling, the ajax endpoint, the wiki
syntax, and what the repository ships — found the following. Every item is
fixed, each with a regression test that fails without the fix. Details are
deliberately kept to the class of problem rather than a recipe.

### High

| | |
|---|---|
| **Cross-site request forgery** | The ajax endpoint accepted requests without DokuWiki's security token, and answered `GET`, so a link a logged-in user clicked could act with their rights. Every action is now POST-only and token-checked. |
| **Write into an ACL-protected namespace** | Permissions were evaluated against a different id than the one the file was written to, which let a user with rights in a parent namespace write, read back and delete a file inside a denied child namespace. Permissions are now derived from the namespace of the resolved file, the way DokuWiki core does it. |
| **ODT export ignored read permissions** | The ODT export embedded a diagram's bytes without checking whether the person exporting may read it, so a page referencing a protected diagram exported its contents. The export now honours read access. *(This one never reached a release; it was introduced and caught during the same development cycle.)* |

### Medium

| | |
|---|---|
| **Unvalidated draft writes** | The draft endpoint wrote its request body to disk with no validation, size limit or name restriction. |
| **Stored bytes did not have to match the file type** | A save could store arbitrary content under a `.png` or `.svg` name. Content is now checked against the type the name claims. |
| **Existence of protected media was observable** | Rendering revealed whether a diagram existed in a namespace the viewer had no access to. DokuWiki core does not leak this; the plugin did. |
| **Unsanitised markup inserted into the page** | Saving an SVG diagram injected its markup directly into the wiki page's DOM, bypassing the content security policy that protects media served normally. |
| **Development files shipped to production** | The repository's development environment — including a seeded password file and ACL — was installed into `lib/plugins/drawio/`, where it was served as plain text. Those files are no longer part of a release archive, and the credential is generated at container start instead of being stored. |

### Low

Consistency and supply-chain hardening: uniform validation across the write
paths, no directory creation for unrecognised requests, third-party CI actions
and downloads pinned to immutable references instead of movable tags, minimal
workflow permissions, and the executable bit removed from files that are not
executable.

## What is not covered

- **The plugin does not sanitise SVG.** Saved SVG is checked for the markers
  DokuWiki core checks for, which is not the same as sanitising it. Protection
  against a malicious SVG rests on DokuWiki serving media with a restrictive
  content security policy. If you serve `data/media` directly from a web server,
  or strip that header in a proxy, you have removed that protection.
- **Anonymous writes are not CSRF-checked**, following DokuWiki's own rule that
  a request without a logged-in user has no session to abuse. On a wiki that
  grants anonymous upload, anyone can call the endpoint directly anyway.
- **Bugs in DokuWiki itself** are out of scope here; report those to DokuWiki.
