<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This file contains a class that provides functions for downloading/displaying a QR code.
 *
 * @package     qtype
 * @subpackage  qrcoded
 * @copyright   2017 T Gunkel
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_qrsub\local;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Symfony\Component\HttpFoundation\Response;
use DOMDocument;
use core\datalib;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/qrsub/thirdparty/vendor/autoload.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Displays QR code.
 *
 * @package     assign
 * @subpackage  qrcodea
 * @copyright   2021 Knowledge One <nicolas.dalpe@knowledgeone.ca>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qrcodegenerator {
    /**
     * QR code is saved in this file.
     * @var string
     */
    protected $file;

    /**
     * Output file type.
     * 2 - png, 1 - svg
     * @var int
     */
    protected $format;
    protected $defaultformat = 1;

    /**
     * Size of qrcode (downloaded image).
     * Only for png.
     * @var int
     */
    protected $size;
    protected $defaultsize = 300;

    /**
     * Filepath for logo.
     * @var string
     */
    protected $logopath;
    protected $defaultlogopathsvg = '/local/qrsub/pix/concordialogo.svg';
    protected $defaultlogopathpng = '/local/qrsub/pix/concordialogo.png';
    protected $logofileareapng = 'local_qrsub_logo_png';
    protected $logofileareasvg = 'local_qrsub_logo_svg';

    /**
     * Course for which the qrcode is created.
     * @var \stdClass
     */
    protected $course;

    /**
     * The quiz cmid used to generate the QR Code.
     * @var int
     */
    protected $quizcmid;

    /**
     * The quiz id used to generate the QR Code.
     * @var int
     */
    protected $quizid;

    /**
     * Defines the QR Code cache location.
     * @var string
     */
    protected $cache;

    public $modulename = 'local_qrsub';

    /**
     * qrcodegenerator constructor.
     * @param int $format File type 1 for SVG and 2 for PNG
     * @param int $size Image size in pixel.
     * @param int $courseid Course for which the qrcode is created
     * @param int $quizcmid The current quiz cmid.
     * @param int $quizid The quiz id the QR Code should link to.
     */
    public function __construct($qrcodeoptions) {

        // Set the cache location.
        $this->set_cache();

        $this->course = get_course($qrcodeoptions->courseid);

        // Set image format.
        $this->set_format(get_config($this->modulename, 'qrcode_format'));

        // Set the QR Code size.
        $this->set_size(get_config($this->modulename, 'qrcode_size'));

        // Set the asignment cmid.
        $this->set_quizid($qrcodeoptions->quizid);

        // Set the logo path in the middle/center of the QR Code.
        $this->set_logopath();

        // Path of the cached QR Code.
        $this->file = sprintf(
            '%s/course-%d_quiz-%d_size-%d.%s',
            $this->get_cache(),
            $this->course->id,
            $this->get_quizid(),
            $this->get_size(),
            ($this->get_format() == 1) ? 'svg' : 'png'
        );
    }

    public function set_cache() {
        global $CFG;
        $this->cache = $CFG->localcachedir . '/' . $this->modulename;
    }

    public function get_cache() {
        return $this->cache;
    }

    /**
     * Set the logo in the middle/center of the QR Code.
     */
    public function set_logopath() {
        global $CFG;

        $logopath = $this->get_logopath();

        // If get_logopath returns null it means no image has been set,
        // in this case set it to the default one.
        if (is_null($logopath)) {
            if ($this->get_format() == 1) {
                $this->logopath = $CFG->dirroot.$this->defaultlogopathsvg;
            } else {
                $this->logopath = $CFG->dirroot.$this->defaultlogopathpng;
            }
        } else {
            $this->logopath = $logopath->path;
        }
    }

    /**
     * Generates logo file path and hash.
     * @return string file path and hash
     */
    private function get_logopath() {
        global $CFG;
        $logo = new \stdClass();

        if ($this->format == 2) {
            $filearea = $this->logofileareapng;
            $filepath = pathinfo(get_config($this->modulename, 'qrcode_logo_png'), PATHINFO_DIRNAME);
            $filename = pathinfo(get_config($this->modulename, 'qrcode_logo_png'), PATHINFO_BASENAME);
        } else {
            $filearea = $this->logofileareasvg;
            $filepath = pathinfo(get_config($this->modulename, 'qrcode_logo_svg'), PATHINFO_DIRNAME);
            $filename = pathinfo(get_config($this->modulename, 'qrcode_logo_svg'), PATHINFO_BASENAME);
        }

        $fs = get_file_storage();
        $file = $fs->get_file(
            \context_system::instance()->id,
            $this->modulename,
            $filearea,
            0,
            $filepath,
            $filename
        );

        if ($file) {
            $logo->hash = $file->get_contenthash();
            $logo->path = $CFG->dataroot . '/filedir/' .
                substr($logo->hash, 0, 2) . '/' .
                substr($logo->hash, 2, 2) . '/' .
                $logo->hash;

            return $logo;
        }
        return null;
    }

    /**
     * Set the logo format in the center of the QR Code.
     *
     * @param int $format The image format to display. 1 = svg, 2 = png
     */
    public function set_format($format) {

        // Get the int value of the passed format.
        $format = (int)$format;

        if ($format === 1 || $format === 2) {
            $this->format = $format;
        } else {
            $this->format = $this->defaultformat;
        }
    }

    /**
     * Get the logo file format.
     *
     * @return int 0 or 1.
     */
    public function get_format() {
        return $this->format;
    }

    /**
     * Set the QR Code size in pixels.
     *
     * @param int $size The width and height of the QR Code to display.
     */
    public function set_size($size) {

        if (is_numeric($size)) {
            $this->size = (int)$size;
        } else {
            $this->size = $this->defaultsize;
        }
    }

    /**
     * Get the QR Code size.
     *
     * @return int QR Code size.
     */
    public function get_size() {
        return $this->size;
    }

    /**
     * Set the quiz cmid to link to.
     *
     * @param int $quizid The quiz cmid.
     */
    public function set_quizid($quizid) {
        $this->quizid = (int)$quizid;
    }

    /**
     * Get the quiz cmid.
     */
    public function get_quizid() {
        return $this->quizid;
    }

    /**
     * Creates the QR code if it doesn't exist.
     */
    public function create_image($url = '') {
        global $CFG;

        // Checks if QR code already exists.
        if (file_exists($this->file)) {
            // File exists in cache.
            return;
        }

        // Checks if directory already exists.
        if (!file_exists(dirname($this->file))) {
            // Creates new directory.
            mkdir(dirname($this->file), $CFG->directorypermissions, true);
        }

        // Creates the QR code URL.
        if (empty($url)) {
            $url = new moodle_url('/local/qrsub/startsub.php', array(
                'id' => $this->get_quizid(),
                'action' => 'editsubmission'
            ));
        }

        // Create the QR Code obj.
        $qrcode = new QrCode($url->out(false));
        $qrcode->setSize($this->size);

        // Set advanced options.
        $qrcode->setMargin(10);
        $qrcode->setEncoding('UTF-8');
        $qrcode->setErrorCorrectionLevel(ErrorCorrectionLevel::HIGH);
        $qrcode->setForegroundColor(['r' => 0, 'g' => 0, 'b' => 0]);
        $qrcode->setBackgroundColor(['r' => 255, 'g' => 255, 'b' => 255]);

        // Png format.
        if ($this->format == 2) {
            if ($this->logopath !== null) {
                $qrcode->setLogoPath($this->logopath);
                $qrcode->setLogoWidth($this->size * 0.4);
            }
            $qrcode->setWriterByName('png');
            $qrcode->writeFile($this->file);
        } else {
            $qrcode->setWriterByName('svg');
            if ($this->logopath !== null) {
                $qrcodestring = $qrcode->writeString();
                $newqrcode = $this->modify_svg($qrcodestring);
                file_put_contents($this->file, $newqrcode);
            } else {
                $qrcode->writeFile($this->file);
            }
        }
    }

    /**
     * Outputs the QR code.
     */
    public function output_image($url = '') {
        $this->create_image($url);

        // Retuen the svg image content.
        if ($this->get_format() == 1) {
            $image = file_get_contents($this->file);
        } else {
            // Return the b64 png file content.
            $image = base64_encode(file_get_contents($this->file));
        }
        return $image;
    }

    /**
     * Inserts logo in the QR code (used for svg QR code).
     * @param string $svgqrcode QR code
     * @return string XML representation of the svg image
     */
    private function modify_svg($svgqrcode) {
        // Loads QR code.
        $xmldoc = new DOMDocument();
        $xmldoc->loadXML($svgqrcode);
        $viewboxcode = $xmldoc->documentElement->getAttribute('viewBox');
        $codewidth = explode(' ', $viewboxcode)[2];

        // Loads logo.
        $xmllogo = new DOMDocument();
        $xmllogo->load($this->logopath);

        $logotargetwidth = $codewidth * 0.4;

        $viewbox = $xmllogo->documentElement->getAttribute('viewBox');
        $viewboxbounds = explode(' ', $viewbox);
        $logowidth = $viewboxbounds[2];
        $logoheight = $viewboxbounds[3];

        // Calculate logo height from width.
        $logotargetheight = $logotargetwidth * ($logoheight / $logowidth);

        // Calculate logo coordinates.
        $logoy = ($codewidth - $logotargetheight) / 2;
        $logox = ($codewidth - $logotargetwidth) / 2;

        $xmllogo->documentElement->setAttribute('width', $logotargetwidth);
        $xmllogo->documentElement->setAttribute('height', $logotargetheight);
        $xmllogo->documentElement->setAttribute('x', $logox);
        $xmllogo->documentElement->setAttribute('y', $logoy);

        $node = $xmldoc->importNode($xmllogo->documentElement, true);

        $xmldoc->documentElement->appendChild($node);

        return $xmldoc->saveXML();
    }

    public static function reset_qrcode_cache() {
        global $CFG;

        // QR Code cache location.
        $cache = $CFG->localcachedir . '/local_qrsub/';

        // Cycle through the dir and delete all cached QR Code.
        foreach (new \DirectoryIterator($cache) as $file) {
            if($file->isDot()) continue;
            $filetodelete = $cache . $file->getFilename();
            $a = unlink($filetodelete);
        }
    }
}
