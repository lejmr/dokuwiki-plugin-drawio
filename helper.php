<?php
/**
 * DokuWiki Plugin drawio (Helper Component)
 *
 * Where a diagram's XML source lives, and how to get it back out of a
 * diagram that only ever had it embedded in the exported image.
 *
 * A draw.io export carries its own source inside itself - a PNG text chunk,
 * an SVG content= attribute - which is why the plugin worked for years
 * without storing anything else. It also means the source is gone the moment
 * anything rewrites that file (an image optimiser, a format conversion, a
 * backup that re-encodes media), and the diagram is a flat picture nobody can
 * edit again. So the source is now kept beside the image as an ordinary media
 * file: ns:plan.png gets ns:plan.drawio, in the same namespace, under the
 * same ACL.
 *
 * This is a helper rather than three private methods in action.php because
 * the extraction half is what an admin task for bulk-converting old diagrams
 * needs, and that task should not have to reimplement a PNG chunk walker.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('DOKU_INC')) die();

class helper_plugin_drawio extends DokuWiki_Plugin
{
    /**
     * Whether $media_id's extension is one of the formats a diagram is
     * rendered in.
     *
     * The single home for this question. It used to be answered three
     * different ways in three places - a lowercased in_array() here, an
     * un-lowercased one in action.php's save gate, and a regex in the draft
     * gate - each carrying a comment telling the reader to keep the other
     * two in step. A third rendering format now has to be added in exactly
     * one place: here (and, separately, the draft gate's regex - see its own
     * comment for why that one stays a regex).
     *
     * @param string $media_id
     * @return bool
     */
    public function isDiagramExtension($media_id)
    {
        return in_array(strtolower(pathinfo($media_id, PATHINFO_EXTENSION)), ['png', 'svg'], true);
    }

    /**
     * The ACL path (namespace-wildcard id) that governs access to a
     * diagram's media file.
     *
     * Media has no per-file ACLs, only per-namespace ones - this is the body
     * of core's mediaAclPath() (inc/auth.php), inlined because that helper
     * does not exist on oldstable (missing from 2025-05-14b "Librarian");
     * inc/media.php in that release spells the same expression out inline at
     * its own call sites.
     *
     * The two callers ask different questions with this same path -
     * syntax.php's ODT export asks auth_quickaclcheck() >= AUTH_READ,
     * action.php's ajax handler asks auth_aclcheck() >= AUTH_UPLOAD (or
     * higher, to overwrite) - which is why this returns the path rather than
     * an answer.
     *
     * @param string $media_id
     * @return string
     */
    public function mediaAclPath($media_id)
    {
        return ltrim(getNS($media_id) . ':*', ':');
    }

    /**
     * The media id of the XML source belonging to a diagram.
     *
     * Derived from the diagram id, never from anything the client sent - the
     * ajax handler is the only writer of .drawio files and it gets the name
     * from here, so there is no request in which a caller names one.
     *
     * Design decision (not an accident of the naming scheme): a diagram's
     * identity is its id without the extension - ns:plan - not ns:plan.png or
     * ns:plan.svg separately. The extension only names which rendering format
     * a given image is; both formats are renderings of the very same diagram
     * and both read and write the very same source, ns:plan.drawio, which is
     * why the extension is replaced here rather than appended. Saving either
     * ns:plan.png or ns:plan.svg overwrites ns:plan.drawio - that is expected,
     * not a collision to guard against, and the save path never refuses a
     * write because a source already exists (see action.php's 'save' - the
     * only gate on writing is the ACL check, run once, before either format is
     * looked at).
     *
     * What this deliberately does NOT do: saving one format never regenerates
     * the other format's image. A stale sibling image is left exactly as it
     * was, and catches up the next time someone saves *in that format* - the
     * alternative (writing an image nobody asked for, in a shape nobody
     * reviewed) is a bigger surprise than a stale picture, so it was
     * considered and rejected.
     *
     * Drafts (action.php's draft_save/draft_get/draft_rm, and the matching
     * localStorage key in script.js) are the one place that deliberately
     * does NOT follow this identity: they are keyed per rendering format
     * (ns:plan.png / ns:plan.svg), not per diagram (ns:plan), even though a
     * draft's payload is format-independent XML - so the two formats of one
     * diagram can hold divergent drafts. That is intentional, not an
     * inconsistency to fix: keying a draft by format is the safer default
     * (it cannot surface a PNG draft inside an SVG editor), so it stays that
     * way even though it disagrees with the source/lock identity above.
     *
     * The one real hazard: a wiki that already has ns:plan.png and
     * ns:plan.svg as two genuinely *different* diagrams (both existed before
     * this feature, so both still only have their source embedded in their
     * own image) has that difference silently erased the first time either
     * one is next saved - whichever is saved first writes ns:plan.drawio, and
     * from then on both formats open from it. There is no way to detect this
     * case from the server side (both images are just bytes); it is called
     * out in docker/seed/pages/drawio.txt and README.md instead.
     *
     * @param string $media_id e.g. 'ns:plan.png'
     * @return string          e.g. 'ns:plan.drawio', or '' if this is not a diagram
     */
    public function sourceID($media_id)
    {
        if (!$this->isDiagramExtension($media_id)) return '';
        $ext = strtolower(pathinfo($media_id, PATHINFO_EXTENSION));
        return substr($media_id, 0, -strlen($ext)) . 'drawio';
    }

    /**
     * The id of the *other* rendering of the same diagram - png for an svg,
     * svg for a png.
     *
     * Used by action.php's delete/rename cascade to decide whether a shared
     * .drawio source may follow the rendering being deleted or moved: it may
     * only do so once neither rendering needs it under its old name any
     * more. See that file's _media_delete_sibling()/_move_sibling() for the
     * reasoning; this is purely the id computation, kept here next to
     * sourceID() because it is the same "one diagram, two renderings"
     * identity, not a new one.
     *
     * @param string $media_id e.g. 'ns:plan.png'
     * @return string          e.g. 'ns:plan.svg', or '' if this is not a diagram
     */
    public function otherRenderingID($media_id)
    {
        if (!$this->isDiagramExtension($media_id)) return '';
        $ext = strtolower(pathinfo($media_id, PATHINFO_EXTENSION));
        $other = ($ext === 'png') ? 'svg' : 'png';
        return substr($media_id, 0, -strlen($ext)) . $other;
    }

    /**
     * The rendering ids a .drawio source could belong to, in preference
     * order.
     *
     * The reverse of sourceID(): 'ns:plan.drawio' -> ['ns:plan.png',
     * 'ns:plan.svg']. Media manager's "Edit with draw.io" button has to open
     * *some* rendering when the source itself is clicked - script.js's
     * whole editor flow (get_png/get_svg, save's extension gate, lock's id)
     * is keyed to a png/svg id, never to the source - so a click on the
     * source has to resolve to one before any of that runs.
     *
     * png first, deliberately: it is the fixed default this plugin already
     * falls back to everywhere else an extension has to be chosen for it -
     * syntax.php appends '.png' to an extensionless {{drawio>...}} call, and
     * script.js's own edit_cb() does the same for an id with no extension at
     * all. Consistent with that default rather than inventing a second one
     * here. action.php's 'resolve_source' action prefers whichever of these
     * two actually exists on disk over this order - see its own comment.
     *
     * Pure id computation, same as otherRenderingID() - no filesystem
     * access, callers decide what "exists" means for their own purpose.
     *
     * @param string $src_id e.g. 'ns:plan.drawio'
     * @return array         e.g. ['ns:plan.png', 'ns:plan.svg'], or [] if
     *                        $src_id is not itself a .drawio id
     */
    public function renderingCandidates($src_id)
    {
        if (strtolower(pathinfo($src_id, PATHINFO_EXTENSION)) !== 'drawio') return [];
        $base = substr($src_id, 0, -strlen('drawio'));
        return [$base . 'png', $base . 'svg'];
    }

    /**
     * Whether this looks like the XML draw.io hands out.
     *
     * Not a validator for the XML itself - it is the same class of check as
     * the PNG signature and the <svg> prologue in action.php: the bytes have
     * to be the kind of thing the name claims, so that a .drawio file cannot
     * be used to park arbitrary content in the media directory.
     *
     * A UTF-8 BOM, an XML declaration and any number of comments may come
     * before the root element - all optional, in that order - and a real
     * save must not be refused for having one. Verified against genuine
     * exports in the wild (not just this plugin's own output): standalone
     * .drawio/.xml files saved by the desktop/web app routinely start with
     * '<?xml version="1.0"?>' before '<mxfile ...>' (e.g. ngxs/store's
     * docs/assets/actions-fsm.drawio, MIT). The old regex anchored straight
     * on '<(mxfile|mxGraphModel)' and rejected every one of them - a rejected
     * save is a user losing work, not a caught attack. The SVG check three
     * lines away in action.php already tolerates the same prolog for exactly
     * this reason.
     *
     * @param string $xml
     * @return bool
     */
    public function isDiagramXml($xml)
    {
        return (bool) preg_match(
            '/^(?:\xEF\xBB\xBF)?\s*(<\?xml\b[^>]*\?>\s*)?(<!--.*?-->\s*)*<(mxfile|mxGraphModel)[\s>]/is',
            (string) $xml
        );
    }

    /**
     * Dig the diagram XML out of a draw.io 'xmlpng' export.
     *
     * draw.io stores it in an uncompressed tEXt (or, less often, a compressed
     * zTXt) chunk keyed 'mxfile' - older exports used 'mxGraphModel'. The
     * value is usually URL-encoded, but plenty of exports in the wild store
     * it raw, so both are accepted.
     *
     * The bytes are a user-supplied file, so the walk is bounded by the
     * actual string length at every step rather than by the lengths the file
     * claims for itself.
     *
     * @param string $bytes contents of the png
     * @return string       the xml, or '' if there is none
     */
    public function extractPngXml($bytes)
    {
        $len = strlen($bytes);
        if ($len < 8 || strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) return '';

        $pos = 8;
        // a chunk is: 4 byte length, 4 byte type, length bytes of data, 4 byte crc
        while ($pos + 12 <= $len) {
            $size = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            // a truncated file, or a length that would run past the end: stop,
            // rather than trusting it and reading nothing useful anyway
            if ($size < 0 || $pos + 12 + $size > $len) return '';
            if ($type === 'IEND') return '';

            if ($type === 'tEXt' || $type === 'zTXt') {
                $data = substr($bytes, $pos + 8, $size);
                $nul = strpos($data, "\0");
                if ($nul !== false) {
                    $keyword = substr($data, 0, $nul);
                    if ($keyword === 'mxfile' || $keyword === 'mxGraphModel') {
                        $value = substr($data, $nul + 1);
                        if ($type === 'zTXt') {
                            // zTXt puts a compression-method byte before the
                            // stream, which the PNG spec says is zlib-wrapped
                            // deflate (what gzuncompress() expects). Real
                            // exports in the wild are not all spec-compliant
                            // though: verified against a genuine zTXt chunk
                            // (itzg/mc-router's docs/example-deployment.
                            // drawio.png, MIT) whose stream is raw deflate
                            // with no zlib header/checksum - gzuncompress()
                            // fails on it and used to just lose the diagram.
                            // gzinflate() reads the same raw format, so it is
                            // tried as a fallback rather than a replacement:
                            // most real chunks are the spec-compliant kind.
                            $compressed = substr($value, 1);
                            $value = @gzuncompress($compressed);
                            if ($value === false) $value = @gzinflate($compressed);
                        }
                        $xml = $this->decodeChunkValue($value);
                        if ($xml !== '') return $xml;
                    }
                }
            }
            $pos += 12 + $size;
        }
        return '';
    }

    /**
     * Dig the diagram XML out of a draw.io 'xmlsvg' export.
     *
     * It sits in a content= attribute on the root <svg> element, XML-escaped.
     * Only the root element's attribute counts: a content= deeper in the
     * drawing is part of the picture, not the document's source. Matched with
     * a regex rather than a parser because the file is untrusted and may well
     * not be well-formed - and because loading it into DOM/SimpleXML pulls in
     * entity handling this has no business doing.
     *
     * @param string $bytes contents of the svg
     * @return string       the xml, or '' if there is none
     */
    public function extractSvgXml($bytes)
    {
        if (!preg_match('/<svg\b[^>]*>/i', (string) $bytes, $root)) return '';
        if (!preg_match('/\scontent\s*=\s*(["\'])(.*?)\1/is', $root[0], $m)) return '';
        $xml = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
        return $this->isDiagramXml($xml) ? $xml : '';
    }

    /**
     * The largest XML this plugin will ever embed into an image - the same
     * 2 MiB the 'save' handler in action.php already caps a .drawio at on
     * the way in, reused here (rather than duplicated as a second literal)
     * as the ceiling for the delete-time attic embedding in action.php's
     * _embed_source_into_attic(): a .drawio written through this plugin's
     * own save path can never exceed it, so this only ever bites a source
     * that reached the media directory some other way.
     */
    const MAX_EMBED_XML_BYTES = 2 * 1024 * 1024;

    /**
     * Write $xml into $bytes as a draw.io 'xmlpng' export would, replacing
     * any XML the image already carries.
     *
     * Used by action.php's _embed_source_into_attic() when a diagram's last
     * rendering is deleted, to fold its separate .drawio source into the
     * archived attic copy of the image instead of archiving the source as a
     * file of its own - see that function's own docblock for why.
     *
     * Shape matched against this branch's own real-drawio-export.png
     * fixture (verified with a PNG chunk walk - see this class's own test
     * suite): an uncompressed tEXt chunk, keyword 'mxfile', value the XML
     * URL-encoded, sitting directly after IHDR and before the first IDAT.
     * Uncompressed on purpose, even though extractPngXml() also reads the
     * zTXt form real exports sometimes use: a real draw.io *export* can
     * choose either, but what this writes only ever has to round-trip
     * through this plugin's own extractPngXml() and stay a well-formed
     * draw.io source the real editor can open - and every embed.diagrams.net
     * version this plugin has been verified against opens an uncompressed
     * tEXt 'mxfile' chunk exactly as readily as a compressed one, so there is
     * nothing simpler that only complicates the writer. Placed right after
     * IHDR (not appended near IEND) to match that same fixture's own chunk
     * order, on the chance that some tool - draw.io itself included - reads
     * only the first text chunk it finds.
     *
     * Any existing tEXt/zTXt chunk keyed 'mxfile' or 'mxGraphModel' is
     * dropped, not kept alongside the new one - two disagreeing copies of a
     * diagram's source in one file is worse than one, and extractPngXml()
     * itself only ever returns the first match it finds, so a stale second
     * chunk would just be dead weight at best.
     *
     * @param string $bytes contents of the png
     * @param string $xml   the diagram source to embed
     * @return string       the new png bytes, or '' if $bytes is not a well
     *                      formed PNG (bad signature, truncated chunk, or no
     *                      IHDR as the very first chunk) or $xml is too long
     */
    public function embedPngXml($bytes, $xml)
    {
        $xml = (string) $xml;
        if ($xml === '' || strlen($xml) > self::MAX_EMBED_XML_BYTES) return '';

        $len = strlen($bytes);
        if ($len < 8 || strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) !== 0) return '';

        // IHDR is always the very first chunk (PNG spec) - read it, and only
        // it, before inserting the new chunk right after.
        if ($len < 20) return '';
        $size = unpack('N', substr($bytes, 8, 4))[1];
        if (substr($bytes, 12, 4) !== 'IHDR' || $size < 0 || 8 + 12 + $size > $len) return '';
        $ihdrEnd = 8 + 12 + $size;
        $ihdr = substr($bytes, 8, $ihdrEnd - 8);

        $data = "mxfile\0" . rawurlencode($xml);
        $newChunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));

        // Copy every remaining chunk as-is, dropping a stale source chunk -
        // see this method's own docblock for why.
        $pos = $ihdrEnd;
        $rest = '';
        while ($pos + 12 <= $len) {
            $chunkSize = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            if ($chunkSize < 0 || $pos + 12 + $chunkSize > $len) return ''; // truncated/corrupt
            $chunk = substr($bytes, $pos, 12 + $chunkSize);
            $pos += 12 + $chunkSize;

            if ($type === 'tEXt' || $type === 'zTXt') {
                $chunkData = substr($chunk, 8, $chunkSize);
                $nul = strpos($chunkData, "\0");
                $keyword = $nul !== false ? substr($chunkData, 0, $nul) : '';
                if ($keyword === 'mxfile' || $keyword === 'mxGraphModel') continue; // stale, dropped
            }

            $rest .= $chunk;
            if ($type === 'IEND') break;
        }

        return substr($bytes, 0, 8) . $ihdr . $newChunk . $rest;
    }

    /**
     * Write $xml into $bytes as a draw.io 'xmlsvg' export would, replacing
     * any XML the image already carries.
     *
     * Used the same way, and for the same reason, as embedPngXml() above -
     * see that method's own docblock.
     *
     * Shape matched against this branch's own real-drawio-export.svg
     * fixture: a content="..." attribute on the root <svg> element, holding
     * the XML XML-escaped, with "\n"/"\r" written as their own character
     * references ('&#10;'/'&#13;') rather than left as literal bytes
     * (verified against that fixture - a literal newline inside an
     * attribute is legal XML, normalised to a space by any conformant
     * parser, which would silently corrupt the embedded source; a real
     * draw.io export never lets that happen, and neither does this).
     * Encoded individually, not as a single reference for a collapsed
     * "\r\n", so a source that genuinely contains a CRLF still round-trips
     * byte for byte. extractSvgXml()'s html_entity_decode(..., ENT_QUOTES |
     * ENT_XML1, ...) decodes each numeric reference back to its own
     * character, so the round trip is exact.
     *
     * @param string $bytes contents of the svg
     * @param string $xml   the diagram source to embed
     * @return string       the new svg bytes, or '' if $bytes has no root
     *                      <svg> element or $xml is too long
     */
    public function embedSvgXml($bytes, $xml)
    {
        $xml = (string) $xml;
        if ($xml === '' || strlen($xml) > self::MAX_EMBED_XML_BYTES) return '';

        $bytes = (string) $bytes;
        if (!preg_match('/<svg\b[^>]*>/i', $bytes, $root, PREG_OFFSET_CAPTURE)) return '';
        $tag = $root[0][0];
        $tagStart = $root[0][1];

        $escaped = htmlspecialchars($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
        // \r and \n each become their own character reference (not a single
        // '&#10;' for a collapsed "\r\n") so a source that genuinely
        // contains a CRLF round-trips byte for byte - html_entity_decode()
        // on the read side decodes each numeric reference back to its own
        // character, reassembling the original pair exactly.
        $escaped = str_replace(["\r", "\n"], ['&#13;', '&#10;'], $escaped);

        if (preg_match('/\scontent\s*=\s*(["\']).*?\1/is', $tag, $m, PREG_OFFSET_CAPTURE)) {
            $newTag = substr($tag, 0, $m[0][1]) . ' content="' . $escaped . '"'
                . substr($tag, $m[0][1] + strlen($m[0][0]));
        } else {
            $selfClosing = substr($tag, -2) === '/>';
            $insertAt = strlen($tag) - ($selfClosing ? 2 : 1);
            $newTag = substr($tag, 0, $insertAt) . ' content="' . $escaped . '"' . substr($tag, $insertAt);
        }

        return substr($bytes, 0, $tagStart) . $newTag . substr($bytes, $tagStart + strlen($tag));
    }

    /**
     * The ids of pages whose relation_media metadata lists $media_id -
     * straight from DokuWiki's own metadata index, not a live scan of every
     * page's syntax. That matters for a caller deciding what to reindex: this
     * only ever knows what the index itself already knows, so a page that has
     * never been indexed at all (brand new, or indexing has genuinely never
     * run for it) will not appear here - but that is also a page with nothing
     * stale to fix, since core indexes a page's own content, including which
     * media it embeds, the moment it is first saved. The gap this closes is
     * the other one: a page that *was* indexed, correctly recording that it
     * embeds this media id, before the diagram had a source to extract text
     * from at all.
     *
     * dokuwiki\Search\MetadataSearch::mediause() is the current API for this.
     * ft_mediause() is its deprecated wrapper on stable/master - but oldstable
     * (2025-05-14b "Librarian") predates the MetadataSearch class entirely and
     * only ever had the (not deprecated there) function ft_mediause(), so
     * calling the class unconditionally would break there. Verified directly
     * against .cache/dokuwiki-{stable,master,oldstable}: ft_mediause() exists,
     * with the identical ($id, $ignore_perms=false) signature, in all three;
     * MetadataSearch only exists on stable/master. Picking at runtime keeps
     * this plugin working on all three without a hard dependency on either.
     *
     * $ignore_perms is always true here: the question this answers is "which
     * pages need reindexing", not "which pages may the caller read" - the ACL
     * that actually matters for what ends up in a page's index is the
     * diagram's own namespace, already checked by action.php's
     * _index_diagrams() before any diagram text is added to anything.
     *
     * @param string $media_id
     * @return string[] page ids
     */
    /**
     * Re-index one page so the search index reflects the diagram XML its
     * syntax now resolves to. Indexer::addPage() is the current API;
     * idx_addPage() is only kept for oldstable, where the class exists
     * but has no addPage() yet.
     *
     * @param string $page
     * @return bool
     */
    public function reindexPage($page)
    {
        if (method_exists('dokuwiki\\Search\\Indexer', 'addPage')) {
            try {
                return (new \dokuwiki\Search\Indexer())->addPage($page, true);
            } catch (\Exception $e) {
                return false;
            }
        }
        return idx_addPage($page, false, true);
    }

    public function pagesUsing($media_id)
    {
        if (class_exists('dokuwiki\\Search\\MetadataSearch')) {
            return (new \dokuwiki\Search\MetadataSearch())->mediause($media_id, true);
        }
        return ft_mediause($media_id, true);
    }

    /**
     * Discard every cached render of $page that could embed a diagram's
     * bytes, so a format like ODT - which inlines the image, unlike xhtml -
     * picks up what a diagram save just wrote instead of a stale copy.
     *
     * The defect this exists to fix: DokuWiki caches a page's rendered
     * output per format (dokuwiki\Cache\CacheRenderer, inc/Cache/
     * CacheRenderer.php) and invalidates it when the page's own source or
     * metadata changes - but saving a diagram (action.php's 'save' handler)
     * or giving one a source (admin.php's bulk-conversion task) writes media
     * files only, never the page, so a cache built before either stays
     * "valid" forever as far as p_cached_output() (inc/parserutils.php) can
     * tell. Verified live exactly as the maintainer found it: an ODT export
     * kept embedding an hour-old picture at the old file size after the
     * on-disk diagram had changed, until the cache directory was cleared by
     * hand; after clearing it, the same export embedded the current bytes at
     * the right size.
     *
     * Which formats: xhtml is deliberately left alone. Its rendered output
     * only ever contains a stable fetch.php URL for a diagram (see
     * syntax.php), never the image bytes themselves, so a cached xhtml
     * render is still correct after the diagram changes - fetch.php serves
     * the current file regardless of what the page cache says. Every other
     * format is purged, not just 'odt': dw2pdf's 'pdf' mode and any other
     * renderer inline whatever a page embeds the same way ODT does, and this
     * plugin has no business knowing every renderer a wiki might have
     * installed.
     *
     * How: DokuWiki does not offer a "purge one page, every format" call.
     * The two things core does offer are either too broad or too narrow for
     * that job - touching cachedir/purgefile (inc/File/PageFile.php, done on
     * every normal page save) invalidates every cached render of every page
     * in the whole wiki, not just the handful that embed this diagram; and
     * dokuwiki\Cache\CacheRenderer::removeCache() (inc/Cache/Cache.php) only
     * ever expires the one mode it was built for, which means naming every
     * renderer's mode by hand. Instead this reuses how core names a cache
     * file at all: getCacheName() (inc/pageutils.php) is
     * $conf['cachedir']/x/md5($data).$ext, and CacheParser's constructor
     * (inc/Cache/CacheParser.php) builds $data from the page's source file
     * plus an environment key (CacheRenderer::getEnvironmentKey() returns
     * DOKU_BASE for every mode except 'metadata') - never from the mode
     * itself, which is only ever the appended extension. So every
     * renderer's cache for the same page and the same environment shares
     * one md5 and differs only by extension; metadata's cache, whose
     * environment key is empty instead of DOKU_BASE, hashes differently and
     * is never matched here, which is exactly right - this is about
     * embedded bytes, not metadata. One CacheRenderer instance gives the
     * shared prefix; glob() finds whichever extensions actually exist
     * (nothing to enumerate, nothing to guess); and each match is expired
     * through a real CacheRenderer's removeCache() - the same unlink() core
     * itself uses to drop a cache file, not a hand-rolled delete.
     *
     * Failure: every caller wraps this in its own try/catch - a purge that
     * fails must never turn an already-successful diagram save (or a
     * successful batch of conversions) into a failed request. Cost: one
     * CacheRenderer + one glob() + zero or more unlink()s per page, run
     * inside whatever cap the caller already applies to its own page list
     * (action.php's $reindexPageCap for a save; uncapped, like its own
     * reindex, for admin.php's already-batched bulk task).
     *
     * @param string $page page id
     */
    public function purgeDiagramPageCache($page)
    {
        $cache = new \dokuwiki\Cache\CacheRenderer($page, wikiFN($page), 'xhtml');
        $prefix = substr($cache->cache, 0, -strlen('.xhtml'));

        foreach ((glob($prefix . '.*') ?: []) as $file) {
            $ext = substr($file, strlen($prefix) + 1);
            if ($ext === 'xhtml') continue; // still correct - see docblock above
            (new \dokuwiki\Cache\CacheRenderer($page, wikiFN($page), $ext))->removeCache();
        }
    }

    /**
     * A png chunk's value is either the xml itself or a URL-encoded copy of
     * it. rawurldecode() rather than urldecode(): encodeURIComponent() never
     * emits '+', so treating one as a space would corrupt a diagram that
     * legitimately contains it.
     */
    private function decodeChunkValue($value)
    {
        if ($this->isDiagramXml($value)) return $value;
        $decoded = rawurldecode((string) $value);
        return $this->isDiagramXml($decoded) ? $decoded : '';
    }

    /**
     * The readable words inside a diagram's stored XML, for the search
     * index - see action.php's _index_diagrams() for the ACL rule that
     * decides *whether* a given diagram's text is allowed to reach here at
     * all; this only does the extraction once that has already said yes.
     *
     * draw.io stores each page of a diagram as one <diagram> element inside
     * <mxfile>, and its content is either the mxGraphModel XML directly (an
     * older export, or a real draw.io export saved with compression turned
     * off - verified against this branch's own real-drawio-export.svg
     * fixture) or, far more commonly in the wild, base64(deflate_raw(
     * encodeURIComponent(xml))) - verified against real-drawio-export.png
     * and real-drawio-export-ztxt.png, both of which use the compressed
     * form. Both are handled; which one a given <diagram> uses is told apart
     * by whether its trimmed content starts with '<' - plain XML always
     * does, and base64 alphabet never contains '<'.
     *
     * A label's actual text sits in each element's value="..." attribute,
     * HTML-escaped, and - for anything beyond a single line - containing
     * real markup ("<div>Bytecode</div><div>Compiler<br></div>" for a
     * two-line label, verified against real-drawio-export.png). Tags are
     * stripped after the entities are decoded, not before, so an escaped
     * "&lt;" that is part of someone's actual label text is not mistaken
     * for markup, and a real tag is not left in the indexed text as a
     * literal "<div>". Entities are decoded twice, with the full HTML5
     * table (ENT_HTML5) rather than just the five XML ones: a shape whose
     * style says html=1 has its value interpreted as HTML by draw.io
     * itself, so a real export double-escapes an entity that is part of
     * that HTML - "API:&amp;amp;nbsp;socket()" in real-drawio-export.svg
     * for a label whose actual text is "API:" + a non-breaking space.
     * html_entity_decode() only ever undoes one level (PHP does not decode
     * recursively), so a single call leaves the inner "&amp;nbsp;" behind
     * as a literal, unreadable "&nbsp;" in the index; a second call is a
     * no-op on already-plain text (an entity needs a leading "&" and a
     * trailing ";" to be decoded at all, so ordinary text - including a
     * literal "&&" that survived the first pass - is left alone by the
     * second).
     *
     * A block-level boundary in that markup - "</div><div>", "<br>", "<p>",
     * a list or table row - is where draw.io breaks one line from the next,
     * so it becomes a space before tags are stripped: leaving it out runs
     * two separate lines together into one unsearchable word ("Bytecode"
     * and "Compiler" becoming "BytecodeCompiler", the exact shape of the
     * defect that shipped - two labels indexed as one token because nothing
     * sat between them). An *inline* tag (draw.io's other real-world case,
     * "<b>mc</b>.your.domain" - both fixtures above) is left bare instead,
     * because it sits mid-word on purpose and turning it into a space would
     * split one word into two ("mc" / ".your.domain") that a search for the
     * whole word would then miss.
     *
     * Separate values - separate shapes, or a shape and, once collected, a
     * tooltip - already get their own leading space below; nothing here
     * needs to add another one for that boundary.
     *
     * Bounded three ways, because the input is an untrusted file (or, once
     * the admin migration task exists, a great many of them, unattended):
     * gzinflate()'s own $length argument caps decompression output so a
     * small deflate stream cannot be crafted to expand into gigabytes of
     * memory (a save through this plugin already caps the XML at 2 MiB
     * before it is written - see action.php's 'save' handler - but that cap
     * does not protect a .drawio placed directly into data/media by hand or
     * by another tool); and the returned text itself is capped, because the
     * point of indexing is findability, not reproducing the diagram in the
     * index - a page whose diagram runs to hundreds of labels is exactly as
     * findable with the first few hundred as with all of them, and the cap
     * is what stops a wiki with a handful of huge diagrams from growing an
     * unbounded index. 20000 characters is generous against real exports
     * (this branch's own fixtures extract to a few hundred) while still
     * being a small, fixed amount of extra work per page indexed.
     *
     * @param string $xml the diagram's stored source (a whole .drawio file)
     * @return string      space-separated words, '' if there is nothing to index
     */
    public function diagramIndexText($xml)
    {
        if (!preg_match_all('/<diagram\b[^>]*>(.*?)<\/diagram>/is', (string) $xml, $diagrams)) return '';

        $cap = 20000;
        $out = '';
        foreach ($diagrams[1] as $content) {
            if (strlen($out) >= $cap) break;

            $content = trim($content);
            if ($content === '') continue;

            if ($content[0] !== '<') {
                // compressed: base64 -> raw deflate -> URL-encoded XML
                $decoded = base64_decode($content, true);
                if ($decoded === false) continue;
                $inflated = @gzinflate($decoded, 5 * 1024 * 1024);
                if ($inflated === false) continue;
                $content = rawurldecode($inflated);
            }

            if (!preg_match_all('/\bvalue\s*=\s*(["\'])(.*?)\1/is', $content, $values)) continue;
            foreach ($values[2] as $value) {
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // block-level markup is a line break in draw.io's own rich
                // text - turn it into a space before the remaining (inline)
                // tags are stripped bare - see this method's own docblock.
                $decoded = preg_replace('#</?(?:div|p|li|tr|table|ul|ol|h[1-6])\b[^>]*>|<br\s*/?>#i', ' ', $decoded);
                $text = trim(preg_replace('/ {2,}/', ' ', strip_tags($decoded)));
                if ($text === '') continue;
                $out .= ' ' . $text;
                if (strlen($out) >= $cap) break;
            }
        }

        return trim(substr($out, 0, $cap));
    }
}
