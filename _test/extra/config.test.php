<?php

use dokuwiki\plugin\config\core\Setting\SettingMulticheckbox;

/**
 * conf/metadata.php's toolbar_possible_extension setting must save cleanly.
 *
 * Repro: Admin -> Configuration Settings -> change anything in the drawio
 * section -> save. That used to log, three times:
 *   PHP Warning: Undefined array key "other" in .../SettingMulticheckbox.php
 *
 * Cause: the setting was declared multicheckbox with '_other' => 'never', so
 * SettingMulticheckbox::html() never rendered an "other" text field and a
 * browser's POST never carries one - but core's array2str() reads
 * $input['other'] unconditionally on every save regardless of '_other'.
 * That is a bug in core we cannot patch from a plugin (see conf/metadata.php
 * for the fix actually applied: '_other' => 'always' plus a '_pattern' that
 * keeps the restriction to png/svg core's 'never' used to give for free).
 *
 * @group plugin_drawio
 * @group plugins
 */
class config_metadata_plugin_drawio_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['drawio'];

    /** @return array the setting's own params, exactly as conf/metadata.php declares them */
    protected function settingParams()
    {
        $meta = [];
        include __DIR__ . '/../../conf/metadata.php';
        $params = $meta['toolbar_possible_extension'];
        array_shift($params); // index 0 is the class name - config/Configuration.php does the same
        return $params;
    }

    /**
     * A browser only ever submits an "other" field if the form actually
     * rendered one. Rendering is what regresses this: with the old
     * '_other' => 'never' the field never appears, so a save's $input never
     * has an 'other' key and core's array2str() throws reading it. Render
     * the real form (via the real config admin plugin, exactly like
     * saving the settings page does) and confirm the field is there, so
     * a save always carries one.
     */
    public function testTheRenderedFormAlwaysHasAnOtherField()
    {
        /** @var admin_plugin_config $configPlugin */
        $configPlugin = plugin_load('admin', 'config');
        $setting = new SettingMulticheckbox('toolbar_possible_extension', $this->settingParams());
        $setting->initialize('png,svg');

        [, $html] = $setting->html($configPlugin, false);

        $this->assertStringContainsString(
            'name="config[toolbar_possible_extension][other]"',
            $html,
            'without this field in the form, the browser never submits an \'other\' key and the save throws'
        );
    }

    /**
     * The actual repro: saving the config page with the form the plugin now
     * renders (an 'other' field always present, per the field-presence test
     * above) must not warn - whether or not the free-text box was touched.
     */
    public function testSavingWithTheRenderedFormsFieldsDoesNotWarn()
    {
        $setting = new SettingMulticheckbox('toolbar_possible_extension', $this->settingParams());
        $setting->initialize('png,svg');

        $changed = $setting->update(['png', 'other' => '']);

        $this->assertTrue($changed, 'unchecking svg is a real change and must be accepted');
    }

    /** Also cover the no-op save: every checkbox left exactly as it was. */
    public function testResavingTheSameValueDoesNotWarn()
    {
        $setting = new SettingMulticheckbox('toolbar_possible_extension', $this->settingParams());
        $setting->initialize('png,svg');

        $changed = $setting->update(['png', 'svg', 'other' => '']);

        $this->assertFalse($changed, 'nothing actually changed');
    }

    /**
     * The restriction to png/svg that '_other' => 'never' used to give the
     * UI for free must survive the fix: typing anything else into the (now
     * present) "other" field must be rejected, not silently merged into the
     * stored value - script.js/action.php only ever handle png and svg.
     */
    public function testAnythingOutsidePngAndSvgIsRejected()
    {
        $setting = new SettingMulticheckbox('toolbar_possible_extension', $this->settingParams());
        $setting->initialize('png,svg');

        $changed = $setting->update(['png', 'other' => 'evil']);

        $this->assertFalse($changed, 'a value outside png/svg must not be accepted');
    }

    /** png and svg together, via the checkboxes, must still be accepted. */
    public function testBothChoicesTogetherAreAccepted()
    {
        $setting = new SettingMulticheckbox('toolbar_possible_extension', $this->settingParams());
        $setting->initialize('png');

        $changed = $setting->update(['png', 'svg', 'other' => '']);

        $this->assertTrue($changed);
    }
}
