# Draw.io Plugin for DokuWiki

Automatically generated documentation (I guess the DokuWiki community likes this form of documentation)

```
Draw.io integration

All documentation for this plugin can be found at
https://www.dokuwiki.org/plugin:drawio and https://github.com/lejmr/dokuwiki-plugin-drawio

If you install this plugin manually, make sure it is installed in
lib/plugins/drawio/ - if the folder is called different it
will not work!

Please refer to http://www.dokuwiki.org/plugins for additional info
on how to install plugins in DokuWiki.

----
Copyright (C) Milos Kozak <milos.kozak@lejmr.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

See the LICENSING file for details
```

## Before you open a PR or an Issue

I am very grateful for all contribution, but before you open a PR or an Issue please make sure your contribution works. Very important aspect of all contributions is that they are solving problem that anybody can have not that you are solving your particular problem which you do not want to support on your own, so you rather choose to dump it over to the upstream repository. I am sorry I am not keen to support your technical dept. When you are opening an issue for a missing functionality, please reconcile whether are you willing to implement the feauture or not. Don't get it that you must implement it all the time, sometimes you have a great idea that should be implemented, but you don't have the right skill-set. In such a case open an issue and detail the feature, so that the feature can be reviewed and implemented by somebody else. In special cases I can commit to implement it. Generally, this is a non-profit area for me, so please do not expect I am going to be implementing anything for free. 

Regarding to bugs. I prefer pull-requests rather than issues, as I am not going to be fixing any bug unless the bug is breaking my personal wiki page.

I am using labels for Issue for the ones that I would like to be implemented by somebody else. The un-labeled issues became stale or removed.


## Roadmap

This project is completely OpenSource and maintained in my free time, so I can NOT provide any ETA when certain functionality is implemented. The future tasks i would like to be working on are labeled as *enhacement* in the Issues page of this project: 
[Enhancements](https://github.com/lejmr/dokuwiki-plugin-drawio/issues?q=is%3Aopen+is%3Aissue+label%3Aenhancement)

I will be extremelly happy if I receive any pull request with a bug fix or a new feature. 


## How to extend this plugin?

In order to make the development as simple as possible, I prepared a Docker compose file. Using the *docker compose* command one can simply start its development environment locally. 

This is how to start local development server:

```docker compose up --build```
  
Wait until server is started, and feel free to login using *admin:admin* credentials (generated on first start; override with DW_ADMIN_PASSWORD) and go on and develop. The development server is available at address http://localhost:8080


## ODT export support

Diagrams are embedded into a page's ODT export ("Export to ODT") the same way
a plain `{{image.png}}` would be, provided the third-party
[odt plugin](https://www.dokuwiki.org/plugin:odt) is installed - without it,
DokuWiki never asks this plugin to render that format, so nothing changes for
wikis that don't have it. A diagram that hasn't been drawn yet is simply left
out of the export rather than embedding the on-wiki placeholder image.

## SVG support related notes

In order to enable svg drawing support make sure:
* You have installed [svgEmbed Plugin](https://www.dokuwiki.org/plugin:svgembed#Uploading_SVG_files_via_Media_Manager)
* You enabled svg extension in DokuWiki's Draw.io configuration 
* You added `svg image/svg+xml` line to conf/mime.local.conf

## A diagram's identity is its name, not its extension

`ns:plan.png` and `ns:plan.svg` are two *renderings* of one diagram, not two
diagrams that happen to share a name. The extension only says which format an
image is exported as; both formats are read from and saved to the same XML
source (`ns:plan.drawio`), so editing either one edits that diagram, and
saving either overwrites the shared source. Saving one format never creates
or touches the other format's image file - a stale sibling image just stays
stale until someone next saves in that format.

**The one hazard this creates:** if a wiki already has `plan.png` and
`plan.svg` as two genuinely *different* diagrams (drawn before this plugin
stored a shared source, so each only has its XML embedded in its own image),
that difference is silently merged the first time either one is next saved -
whichever is saved first becomes `plan.drawio`, and both formats open from it
from then on. The plugin has no way to detect this case from the file bytes
alone; rename one of the two diagrams first if your wiki has this shape.

## Converting old diagrams to a stored source, all at once

A diagram picks up its `.drawio` source lazily, the first time it is next
saved - see above. A diagram nobody happens to save stays without one, and so
stays at risk of losing its editable XML if something ever rewrites its image
(an optimiser, a format conversion, a re-encoding backup).

Admins (real superusers, not just managers) can convert every diagram at once
instead of waiting: **Admin → Draw.io: convert old diagrams**. It always shows
a dry run first - how many diagrams already have a source, how many can be
recovered from their image, and how many cannot be recovered at all - and only
writes anything once you press *Convert this batch*. It never overwrites a
source that already exists.

On a large wiki the page works through the media tree in batches (a few
hundred diagrams per click) rather than trying to do everything in one
request; keep clicking *Scan next batch* / *Convert this batch* until it
reports everything has been scanned. Diagrams it cannot recover a source for
(no draw.io XML was ever embedded in the image to begin with) are reported,
not silently skipped - there is nothing to convert for those, and nothing this
task can do about it.