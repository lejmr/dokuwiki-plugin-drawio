<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Extension\Event;

if (!defined('DOKU_INC')) die();

/**
 * DokuWiki Plugin drawio (Admin Component)
 *
 * The save path (action.php) gives a diagram its .drawio source lazily, the
 * first time someone next saves it - a diagram nobody touches stays at risk
 * of losing its source forever (see helper.php for why that matters). This
 * is the maintainer-requested other half: an optional, admin-triggered task
 * that walks the whole media tree once and gives every diagram it can a
 * source right now, instead of waiting for someone to open it.
 *
 * It only ever adds a .drawio that does not exist yet. It never overwrites
 * one, and it never touches the image - see helper.php's extract*() for
 * where the XML actually comes from, and _process() below for why an
 * existing source always wins.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */
class admin_plugin_drawio extends AdminPlugin
{
    /**
     * How many diagrams one request inspects (and, if asked, converts).
     *
     * This is the whole answer to "what happens on a wiki ten times bigger
     * than tested": nothing bad, it just takes ten times as many clicks. The
     * scan itself (search_media(), stat calls only) comfortably covers a
     * media tree of thousands of files in one request - it is done in full
     * on every load, which is how the summary counts below are always exact,
     * not just for the current batch. What does not scale is reading and
     * parsing file *bytes*, which only happens for diagrams still missing a
     * source (already-sourced ones are skipped after a single file_exists()),
     * so this caps that to $batchSize per request. That bounds both wall
     * time (no request can run past PHP's timeout, however big the wiki) and
     * memory (only one diagram's bytes are ever held at once - nothing here
     * accumulates a batch's worth of file content in memory at the same
     * time). A bigger wiki does not fail, it just needs more clicks of
     * "convert this batch"; there is no background job queue here because
     * this plugin does not have one anywhere else, and adding one for an
     * optional, one-off admin task is more machinery than the task is worth.
     *
     * ponytail: fixed per-request ceiling with manual resume via the offset
     * field, rather than a job queue or auto-continuing JS. Revisit if an
     * admin ever has to click through hundreds of batches by hand.
     *
     * A protected property rather than a constant so tests can shrink it
     * without needing a wiki with hundreds of media files to prove
     * pagination works.
     *
     * @var int
     */
    protected $batchSize = 200;

    /** @var helper_plugin_drawio */
    protected $helper;

    protected $offset = 0;
    protected $converting = false;

    public function __construct()
    {
        $this->helper = $this->loadHelper('drawio', false);
    }

    /**
     * Superuser only, not just a manager.
     *
     * Every other admin task in DokuWiki core that writes acts on what the
     * calling manager already has rights over (revert.php checks
     * auth_quickaclcheck() per page before touching it, for instance). This
     * one is different: it walks and writes into every namespace on the
     * wiki in one pass, including ones the calling account might only have
     * indirect (wildcard) rights to reach a page in but was never granted
     * explicit upload rights on for other users' diagrams. Restricting the
     * page itself to real admins - who could reach every namespace's media
     * anyway, ACL check or not (see auth_aclcheck_cb() in core) - means this
     * never grants a manager account more than they already effectively
     * have, instead of quietly becoming a wiki-wide write tool for anyone
     * with manager rights on one small corner of it.
     */
    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    public function getMenuSort()
    {
        return 510;
    }

    /**
     * Reads the request; writes nothing. The actual write happens in html(),
     * because the same pass that decides whether a diagram can be converted
     * (reading its bytes) is also the one that converts it - splitting that
     * into a handle()-then-html() pair would mean reading every diagram's
     * bytes twice.
     */
    public function handle()
    {
        global $INPUT;
        $this->offset = max(0, $INPUT->int('offset'));
        $this->converting = $this->_conversionRequested();
    }

    /**
     * Whether this request may write, following exactly the CSRF rule
     * SECURITY.md documents for this plugin's ajax endpoint: POST only, and
     * the session's own security token, read from POST alone rather than
     * core's checkSecurityToken() (which also accepts the token via GET/
     * $_REQUEST) - a token in a query string ends up in browser history,
     * server logs and the Referer header, none of which a write action
     * should be discoverable from. A plain link, an <img>, a prefetch or a
     * cached page can therefore never trigger a conversion - only a POST
     * from the form this class itself renders, carrying that session's
     * token, can. forAdminOnly() already means only a logged-in superuser
     * ever reaches this code, so - unlike action.php's _check_token() -
     * there is no anonymous-user bypass to mirror here.
     *
     * @return bool
     */
    private function _conversionRequested()
    {
        global $INPUT;
        if ($INPUT->server->str('REQUEST_METHOD') !== 'POST') return false;
        if (!$INPUT->post->bool('convert')) return false;
        return hash_equals(getSecurityToken(), $INPUT->post->str('sectok'));
    }

    public function html()
    {
        if (!$this->helper) {
            echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
            echo '<div class="error">helper_plugin_drawio is missing.</div>';
            return;
        }

        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';

        // Every diagram in the wiki, id => its (as yet maybe nonexistent)
        // source id. Cheap: search_media() only stat()s each file, and this
        // is the pass that makes the summary counts below always exact for
        // the whole tree, not just for whatever batch is on screen.
        $diagrams = $this->_findDiagrams();
        $total = count($diagrams);
        $ids = array_keys($diagrams);

        $withSource = 0;
        foreach ($diagrams as $id => $srcId) {
            if ($this->_hasSource($srcId)) $withSource++;
        }
        $withoutSource = $total - $withSource;

        printf(
            '<p>' . hsc($this->getLang('summary')) . '</p>',
            $total,
            $withSource,
            $withoutSource
        );

        if ($total === 0) {
            echo '<p>' . hsc($this->getLang('none_found')) . '</p>';
            return;
        }

        $window = array_slice($ids, $this->offset, $this->batchSize);
        $windowEnd = $this->offset + count($window);
        $nextOffset = $this->offset + $this->batchSize;
        $hasMore = $nextOffset < $total;

        if ($window === []) {
            echo '<p>' . hsc($this->getLang('done')) . '</p>';
        } else {
            printf(
                '<h2>' . hsc($this->getLang('batch_heading')) . '</h2>',
                $this->offset + 1,
                $windowEnd,
                $total
            );

            $rows = [];
            $convertedIds = [];
            foreach ($window as $id) {
                $row = $this->_process($id, $diagrams[$id], $this->converting);
                $rows[] = $row;
                if ($row['status'] === 'converted') $convertedIds[] = $id;
            }
            $this->_renderTable($rows);

            // Same reindexing problem action.php's save path has, and the
            // same fix, applied to this task's own writes - a bulk
            // conversion that gives hundreds of diagrams a source but never
            // makes any of their words searchable would be the identical
            // bug wearing a different hat. See _reindexConverted()'s own
            // docblock for why this runs once per batch rather than once
            // per diagram.
            if ($convertedIds) {
                $this->_reindexConverted($convertedIds);
            }
        }

        $this->_renderForm($this->offset, $nextOffset, $hasMore);
    }

    /**
     * Every diagram in the media tree: png/svg ids that helper::sourceID()
     * recognises, mapped to the source id it would have.
     *
     * Reuses core's own media walker (search_media(), inc/search.php)
     * instead of a hand-rolled directory recursion - it already does id
     * validation, ACL enforcement and depth handling correctly, and it is
     * the exact same code the media manager itself lists files with. ACL is
     * left enabled (not $opts['skipacl']): a real admin bypasses it anyway
     * (auth_aclcheck_cb() returns AUTH_ADMIN for a superuser regardless of
     * any rule), so this walks nothing a manager-facing ACL check would have
     * hidden from this account - it is exactly what the media manager would
     * show the same user.
     *
     * @return array media id => source id
     */
    private function _findDiagrams()
    {
        global $conf;
        $found = [];
        search($found, $conf['mediadir'], 'search_media', ['depth' => 0]);

        $diagrams = [];
        foreach ($found as $item) {
            $srcId = $this->helper->sourceID($item['id']);
            if ($srcId !== '') $diagrams[$item['id']] = $srcId;
        }
        return $diagrams;
    }

    /**
     * A source counts as present only if it is a real, non-empty file - a
     * zero byte .drawio is treated as absent, the same rule action.php's
     * _source_xml() applies on the read path (see issue #66).
     */
    private function _hasSource($srcId)
    {
        $fl = mediaFN($srcId);
        return file_exists($fl) && filesize($fl) > 0;
    }

    /**
     * Classify one diagram, and convert it if $convert is set.
     *
     * @param string $id     the diagram's media id (png or svg)
     * @param string $srcId  its (maybe nonexistent) source id
     * @param bool   $convert
     * @return array ['id' => ..., 'status' => ...]
     */
    private function _process($id, $srcId, $convert)
    {
        // Never overwrite - it may be newer than the image (a hand-edited
        // .drawio, or a previous run of this very task), and the read path
        // (action.php's _source_xml()) already prefers whatever source
        // exists over the image regardless of which is older. Overwriting
        // it here on a hunch that the image is more current would be a
        // silent data loss this task has no business causing.
        if ($this->_hasSource($srcId)) {
            return ['id' => $id, 'status' => 'has_source'];
        }

        $fl = mediaFN($id);
        $bytes = @file_get_contents($fl);
        if ($bytes === false) {
            return ['id' => $id, 'status' => 'unreadable'];
        }

        $ext = strtolower(pathinfo($id, PATHINFO_EXTENSION));
        $xml = $ext === 'png'
            ? $this->helper->extractPngXml($bytes)
            : $this->helper->extractSvgXml($bytes);
        // done with the image bytes - nothing below needs them, and nothing
        // holds onto a whole batch's worth of file content at once (see
        // $batchSize's docblock).
        unset($bytes);

        if ($xml === '') {
            return ['id' => $id, 'status' => 'no_xml'];
        }

        if (!$convert) {
            return ['id' => $id, 'status' => 'recoverable'];
        }

        // Re-check immediately before writing: another save (or another run
        // of this same task, e.g. two admin tabs) could have written a
        // source in the time between the check above and here.
        if ($this->_hasSource($srcId)) {
            return ['id' => $id, 'status' => 'has_source'];
        }

        $srcFl = mediaFN($srcId);
        // The namespace directory already exists - a diagram only reaches
        // here because its image is already sitting in it - so, unlike
        // action.php's 'save' handler, there is nothing to create first.
        if (!io_saveFile($srcFl, $xml)) {
            return ['id' => $id, 'status' => 'write_failed'];
        }

        // Same event, same data shape action.php fires for a saved source
        // (mimetype hardcoded there too - core has no registered mimetype
        // for .drawio - so this matches it exactly rather than calling
        // mimetype() and getting back false). $overwrite is always false:
        // the check two lines up refused to reach here otherwise. No attic
        // copy and no media changelog entry, for the same reason
        // action.php's save handler skips both for the source: the image
        // already carries both, and its attic copies carry the XML that was
        // embedded in it at the time.
        $data = [basename($srcFl), $srcFl, $srcId, 'application/xml', false, null];
        Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $data, null, false);

        return ['id' => $id, 'status' => 'converted'];
    }

    /**
     * Reindex, once each, every page that embeds any diagram this batch
     * just gave a source to - see action.php's _reindex_diagram_pages() for
     * the same mechanism on the save path; this is its bulk-conversion
     * counterpart, so the same defect (a diagram getting a source that
     * nothing ever makes searchable) does not survive in this task just
     * because it writes sources a different way.
     *
     * Once per batch, not once per diagram or once per whole run: this
     * task's entire job is converting up to $batchSize diagrams in one
     * request, and diagrams sharing the same handful of embedding pages is
     * the common case, not the exception - reindexing per diagram would
     * reindex the same page over and over within a single batch for no
     * benefit. Collecting every affected page id across the batch and
     * reindexing the unique set once, after all of the batch's writes are
     * done, does the identical job for a fraction of the work. Not once per
     * *run* either (i.e. not deferred past this batch to some final step):
     * there is no final step - an admin may stop clicking "convert" after
     * any batch, and every batch that ran a write must leave the index
     * caught up with what it wrote, the same way it must leave nothing else
     * half done.
     *
     * No cap on how many pages this reindexes, unlike action.php's
     * $reindexPageCap: that cap exists because a normal user's save request
     * has to return promptly. This is the opposite shape - an admin-
     * triggered, already-batched task ($batchSize diagrams per request,
     * see its own docblock) where a slow batch is an accepted, visible cost
     * the operator chose by clicking "convert", not a surprise sprung on an
     * ordinary save. The per-row set_time_limit(30) reset _renderTable()
     * already does for parsing diagram bytes is extended here, per page,
     * for the same PHP-timeout reason.
     *
     * Each page's reindex is wrapped in its own try/catch, same reasoning
     * as action.php: one broken page must not stop the rest, and must
     * never turn a batch of successful conversions (already written to
     * disk by this point) into a fatal error on the admin's screen.
     *
     * @param string[] $convertedIds media ids converted in this batch
     */
    private function _reindexConverted(array $convertedIds)
    {
        $pages = [];
        foreach ($convertedIds as $id) {
            $ids = [$id];
            $other = $this->helper->otherRenderingID($id);
            if ($other !== '') $ids[] = $other;
            foreach ($ids as $mid) {
                foreach ($this->helper->pagesUsing($mid) as $page) {
                    $pages[$page] = true;
                }
            }
        }

        foreach (array_keys($pages) as $page) {
            @set_time_limit(30);
            try {
                $this->helper->reindexPage($page);
            } catch (\Throwable $e) {
                // one page's reindex failing must not stop the rest, or
                // turn a successful batch of conversions into a fatal error
            }
            // Same stale-cache problem action.php's save path has, and the
            // same fix - see helper::purgeDiagramPageCache() for the full
            // reasoning (which formats, why xhtml is skipped, and why a
            // glob over CacheRenderer's own naming scheme rather than a
            // hand-rolled delete). A source appearing for the first time
            // does not itself change any embedding page's bytes, but a
            // wiki running this task is exactly the wiki whose ODT exports
            // were stale before there was a source to extract from - this
            // is the only path that stops any of them staying stale after
            // the migration that fixes that runs.
            try {
                $this->helper->purgeDiagramPageCache($page);
            } catch (\Throwable $e) {
                // one page's purge failing must not stop the rest, or turn
                // a successful batch of conversions into a fatal error
            }
        }
    }

    private function _renderTable(array $rows)
    {
        echo '<table class="inline"><tr><th>' . hsc($this->getLang('col_diagram'))
            . '</th><th>' . hsc($this->getLang('col_status')) . '</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>' . hsc($row['id']) . '</td><td>'
                . hsc($this->getLang('status_' . $row['status'])) . '</td></tr>';
            // A big batch runs a while; keep the connection alive rather
            // than risk PHP's own execution time limit on top of the batch
            // size already bounding how much work one request does (the
            // same belt-and-braces reset revert.php's admin task uses).
            @set_time_limit(30);
        }
        echo '</table>';
    }

    private function _renderForm($offset, $nextOffset, $hasMore)
    {
        echo '<form action="" method="post"><div class="no">';
        echo '<input type="hidden" name="offset" value="' . (int) $offset . '" />';
        formSecurityToken();
        echo '<button type="submit" name="convert" value="1">'
            . hsc($this->getLang('btn_convert')) . '</button> ';
        echo '</div></form>';

        if ($hasMore) {
            echo '<p><a href="' . wl('', ['do' => 'admin', 'page' => 'drawio', 'offset' => $nextOffset])
                . '">' . hsc($this->getLang('btn_continue')) . '</a></p>';
        }
        if ($offset > 0) {
            echo '<p><a href="' . wl('', ['do' => 'admin', 'page' => 'drawio'])
                . '">' . hsc($this->getLang('btn_restart')) . '</a></p>';
        }
    }
}
