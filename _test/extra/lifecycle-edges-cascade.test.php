<?php

/**
 * Delete/move cascade edge cases: a source surviving until its last
 * rendering goes, a failed delete not cascading, core's own media_delete()
 * and the move plugin's rename event driving the same cascade as the
 * ajax endpoints.
 *
 * @group plugin_drawio
 * @group plugins
 */
require_once __DIR__ . '/../acl.inc.php';

class action_plugin_drawio_lifecycle_edges_cascade_test extends DokuWikiTest
{
    use drawio_acl_test_helper;

    protected $pluginsEnabled = ['drawio'];

    /** @var array captured MEDIA_UPLOAD_FINISH event data, one entry per fired event */
    protected $firedEvents = [];

    /** @var array captured MEDIA_DELETE_FILE ids, one entry per fired event */
    protected $deletedMediaIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->firedEvents = [];
        $this->deletedMediaIds = [];

        // auth_aclcheck()/auth_quickaclcheck() read this even with ACL disabled
        global $USERINFO;
        $USERINFO = ['grps' => []];

        // TestRequest::execute() reads/restores $_SESSION; nothing else starts a
        // session in this standalone test run
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }

        global $EVENT_HANDLER;
        $EVENT_HANDLER->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'captureMediaUploadFinish');
        // AFTER the plugin's own _media_delete_sibling(), which is also
        // registered AFTER - so this always sees whatever it cascaded too.
        $EVENT_HANDLER->register_hook('MEDIA_DELETE_FILE', 'AFTER', $this, 'captureMediaDeleteFile');
    }

    public function captureMediaUploadFinish(Doku_Event $event, $param)
    {
        $this->firedEvents[] = $event->data;
    }

    public function captureMediaDeleteFile(Doku_Event $event, $param)
    {
        $this->deletedMediaIds[] = $event->data['id'];
    }

    /** The XML the editor hands to script.js on its 'save' event. */
    protected function diagramXml($marker = 'x')
    {
        return '<mxfile host="embed"><diagram id="' . $marker . '">' . $marker . '</diagram></mxfile>';
    }

    protected function put($mediaId, $content = 'bytes')
    {
        $file = mediaFN($mediaId);
        io_makeFileDir($file);
        file_put_contents($file, $content);
        return $file;
    }

    /** Fire MEDIA_DELETE_FILE the same shape core's media_delete() builds it. */
    protected function fireMediaDeleteFile($mediaId, $unlinked = true)
    {
        $data = [
            'id' => $mediaId,
            'name' => noNS($mediaId),
            'path' => mediaFN($mediaId),
            'size' => 0,
            'unl' => $unlinked,
            'del' => false,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('MEDIA_DELETE_FILE', $data, null, false);
    }

    /** Drive the move plugin's own event shape (helper/op.php's moveMedia()). */
    protected function fireMoveMedia($srcId, $dstId)
    {
        $data = [
            'opts' => ['ns' => getNS($srcId), 'name' => noNS($srcId), 'newns' => getNS($dstId), 'newname' => noNS($dstId)],
            'affected_pages' => [],
            'src_id' => $srcId,
            'dst_id' => $dstId,
        ];
        \dokuwiki\Extension\Event::createAndTrigger('PLUGIN_MOVE_MEDIA_RENAME', $data, null, false);
    }

    /**
     * The maintainer's decision: deleting a diagram's only rendering deletes
     * its source too - "delete" means the diagram is gone, not "gone except
     * for a file nobody asked to keep". media_delete() is used for the
     * cascade (not unlink()), so this is also proof it fires
     * MEDIA_DELETE_FILE for the source - a backup plugin hears about it
     * exactly the way it hears about the image.
     */
    public function testDeletingTheOnlyRenderingDeletesItsSource()
    {
        // media_delete() (the cascade's own delete path) recomputes ACL
        // itself and requires AUTH_DELETE, which ACL-disabled test runs
        // never grant (auth_quickaclcheck() caps out at AUTH_UPLOAD with
        // useacl off) - so this needs it explicitly, same as
        // testDeletingViaCoreMediaDeleteCascadesLive() below.
        $this->enableAcl(['test:* @ALL 16']);
        $this->put('test:delone.drawio', $this->diagramXml());
        $this->assertFileExists(mediaFN('test:delone.drawio'));

        $this->fireMediaDeleteFile('test:delone.png');

        $this->assertFileDoesNotExist(mediaFN('test:delone.drawio'));
        $this->assertContains(
            'test:delone.drawio',
            $this->deletedMediaIds,
            'the cascade must go through media_delete(), so MEDIA_DELETE_FILE fires for the source too - a backup plugin hears about it the same way it hears about the image'
        );
    }

    /**
     * The one case that must NOT cascade: ns:plan.png and ns:plan.svg are
     * two renderings of the same diagram and share one source (see
     * helper.php's sourceID()). Deleting just the .png has not deleted "the
     * diagram" - the .svg is still there and still needs ns:plan.drawio.
     */
    public function testDeletingOneRenderingLeavesTheSourceForTheOther()
    {
        $this->put('test:deltwo.svg', 'svg-bytes');
        $this->put('test:deltwo.drawio', $this->diagramXml());

        $this->fireMediaDeleteFile('test:deltwo.png');

        $this->assertFileExists(mediaFN('test:deltwo.drawio'), 'the surviving .svg rendering still needs this source');
    }

    /**
     * Deleting the second rendering, once the first is already gone, must
     * then delete the source - the cascade looks at what is on disk right
     * now, so it does not matter which rendering was deleted first.
     */
    public function testDeletingTheSecondRenderingThenDeletesTheSource()
    {
        $this->enableAcl(['test:* @ALL 16']);
        $this->put('test:delboth.drawio', $this->diagramXml());
        // .png already gone (simulating the earlier delete above)
        $this->fireMediaDeleteFile('test:delboth.svg');

        $this->assertFileDoesNotExist(mediaFN('test:delboth.drawio'));
    }

    /** A failed/no-op delete (unl=false) must not cascade to the source. */
    public function testAFailedImageDeleteDoesNotCascade()
    {
        $this->put('test:delfail.drawio', $this->diagramXml());
        $this->fireMediaDeleteFile('test:delfail.png', false);
        $this->assertFileExists(mediaFN('test:delfail.drawio'));
    }

    /**
     * Deleting the source directly must not reach out and delete the image.
     * The image is not made worthless by losing its source - it still opens
     * from its own embedded XML, exactly as it always did before this
     * feature existed (see action.php's _source_xml()) - so a small text
     * file's deletion silently destroying somebody's picture would be the
     * more surprising direction, and it does not happen.
     */
    public function testDeletingTheSourceDirectlyDoesNotTouchTheImage()
    {
        $this->put('test:delsrc.png', 'png-bytes');
        $this->fireMediaDeleteFile('test:delsrc.drawio');
        $this->assertFileExists(mediaFN('test:delsrc.png'));
    }

    /** End to end, through the real core delete path (ACL granted), not just a synthetic event. */
    public function testDeletingViaCoreMediaDeleteCascadesLive()
    {
        $this->enableAcl(['test:* @ALL 16'], 'alice');
        $this->put('test:delreal.png', 'png-bytes');
        $this->put('test:delreal.drawio', $this->diagramXml());

        $result = media_delete('test:delreal.png', AUTH_DELETE);

        $this->assertSame(DOKU_MEDIA_DELETED, $result & DOKU_MEDIA_DELETED);
        $this->assertFileDoesNotExist(mediaFN('test:delreal.png'));
        $this->assertFileDoesNotExist(mediaFN('test:delreal.drawio'), 'the source must go with the image through the real delete path too');
    }

    /** Same "still needed under its old name" rule as the delete cascade. */
    public function testMovingOneRenderingLeavesTheSourceForTheOther()
    {
        $this->put('test:mv2.svg', 'svg-bytes');
        $this->put('test:mv2.drawio', $this->diagramXml('shared'));

        $this->fireMoveMedia('test:mv2.png', 'ns2:mv2moved.png');

        $this->assertFileExists(mediaFN('test:mv2.drawio'), 'the untouched .svg rendering still needs this source');
        $this->assertFileDoesNotExist(mediaFN('ns2:mv2moved.drawio'));
    }

    /** Renaming to a namespace/name that already has its own source must not clobber it. */
    public function testMovingNeverClobbersAnExistingSourceAtTheDestination()
    {
        $this->put('test:mv3.png', 'png-bytes');
        $this->put('test:mv3.drawio', $this->diagramXml('mover'));
        $this->put('test:mv3dest.drawio', 'unrelated-existing-source');

        $this->fireMoveMedia('test:mv3.png', 'test:mv3dest.png');

        $this->assertSame('unrelated-existing-source', file_get_contents(mediaFN('test:mv3dest.drawio')));
    }

    /** Renaming the source directly must not reach for the image - same asymmetry as delete. */
    public function testMovingTheSourceDirectlyDoesNotTouchTheImage()
    {
        $this->put('test:mv4.png', 'png-bytes');
        $this->put('test:mv4.drawio', $this->diagramXml());

        $this->fireMoveMedia('test:mv4.drawio', 'test:mv4renamed.drawio');

        $this->assertFileExists(mediaFN('test:mv4.png'), 'the image must stay exactly where it was');
        // the source itself is core's/the move plugin's own job to move - this
        // plugin only refuses to *cascade from* a source move into the image
    }

    public function testMovingTheSourceFiresMediaUploadFinishForBackupPlugins()
    {
        $this->put('test:mv5.png', 'png-bytes');
        $this->put('test:mv5.drawio', $this->diagramXml('evented'));

        $this->fireMoveMedia('test:mv5.png', 'ns2:mv5.png');

        $this->assertCount(1, $this->firedEvents);
        $this->assertSame('ns2:mv5.drawio', $this->firedEvents[0][2]);
    }

}
