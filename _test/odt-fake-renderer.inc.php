<?php

/**
 * Stands in for the third-party odt plugin's renderer_plugin_odt_page, whose
 * _odtAddImage($src, $width, $height, $align, $title, $style, $returnonly)
 * takes $src as a filesystem path (mediaFN(), not a media id or URL) - see
 * https://github.com/LarsGit223/dokuwiki-plugin-odt ODT/ODTImage.php. Records
 * calls instead of building real ODT XML so these tests don't depend on that
 * plugin being installed.
 *
 * Shared (not duplicated) between _test/golden/render.test.php and
 * _test/extra/validation-render.test.php: unlike the small helper *methods*
 * this suite deliberately duplicates per file (see admin-batching.test.php's
 * docblock), a *class* can't be duplicated across two loaded test files
 * without a fatal redeclaration error, so this one lives in its own file
 * both require.
 */
class drawio_test_fake_odt_renderer extends Doku_Renderer
{
    public $addImageCalls = [];

    public function getFormat()
    {
        return 'odt';
    }

    public function _odtAddImage($src, $width = null, $height = null, $align = null, $title = null, $style = null, $returnonly = false)
    {
        $this->addImageCalls[] = compact('src', 'width', 'height', 'align', 'title');
    }
}
