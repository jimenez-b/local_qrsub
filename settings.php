<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin administration pages are defined here.
 *
 * @package     local_qrsub
 * @category    admin
 * @copyright   2021 KnowledgeOne <nicolas.dalpe@knowledgeone.ca>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/qrsub/lib.php');

if ($hassiteconfig) {
    // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedIf
    if ($ADMIN->fulltree) {
        // TODO: Define the plugin settings page - {@link https://docs.moodle.org/dev/Admin_settings}.

        $settings = new admin_settingpage('local_qrsub_settings', 'local_qrsub');
        $ADMIN->add('localplugins', $settings);

        ///////////////////////////////////////////////////////
        // QRMOOD-51 - As an IT, I want the exam refresh rate to be x sec
        //
        // Set the QR Code size in pixel.
        $settings->add(
            new admin_setting_configtext(
                'local_qrsub/exam_refresh_rate',
                new lang_string('exam_refresh_rate', 'local_qrsub'),
                new lang_string('exam_refresh_rate_help', 'local_qrsub'),
                2,
                PARAM_INT
            )
        );
        // QRMOOD-51

        // Set the QR Code size in pixel.
        $settings->add(
            new admin_setting_configtext(
                'local_qrsub/qrcode_size',
                new lang_string('qrcodesize', 'local_qrsub'),
                new lang_string('qrcodesize_help', 'local_qrsub'),
                300,
                PARAM_INT
            )
        );

        // Set the prefered file format.
        $settings->add(
            new admin_setting_configselect(
                'local_qrsub/qrcode_format',
                new lang_string('qrcodeformata', 'local_qrsub'),
                new lang_string('qrcodeformata_help', 'local_qrsub'),
                1,
                array('1' => 'SVG', '2' => 'PNG')
            )
        );

        // Set the SVG logo file.
        $svglogo = new admin_setting_configstoredfile(
            'local_qrsub/qrcode_logo_svg',
            new lang_string('qrcodelogosvg', 'local_qrsub'),
            new lang_string('qrcodelogosvg_help', 'local_qrsub'),
            'local_qrsub_logo_svg',
            0,
            array('maxfiles' => 1, 'accepted_types' => ['.svg'])
        );

        // Callback to empty the QR Code cache when the logo is updated.
        $svglogo->set_updatedcallback('local_qrsub_reset_qrcode_cache');
        $settings->add($svglogo);

        // Set the PNG logo file.
        $pnglogo = new admin_setting_configstoredfile(
            'local_qrsub/qrcode_logo_png',
            new lang_string('qrcodelogopng', 'local_qrsub'),
            new lang_string('qrcodelogopng_help', 'local_qrsub'),
            'local_qrsub_logo_png',
            0,
            array('maxfiles' => 1, 'accepted_types' => ['.png'])
        );

        // Callback to empty the QR Code cache when the logo is updated.
        $pnglogo->set_updatedcallback('local_qrsub_reset_qrcode_cache');
        $settings->add($pnglogo);
    }
}
