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
 * This script adds the session key to the URL because a valid
 * session key is mandatory to start a new quiz attempt.
 *
 * @package   local_qrsub
 * @copyright 2021 KnowledgeOne Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id

require_login();

// Generate the QR Code URL.
$url = new moodle_url('/local/qrsub/startattempt.php', array(
    'cmid' => $id,
    'page' => 0,
    'sesskey' => sesskey()
));

redirect($url->out(false));
