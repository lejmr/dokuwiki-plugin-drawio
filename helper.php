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
}
