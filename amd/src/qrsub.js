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
 * General functions.
 *
 * @module     local_qrsub/qrsub
 * @package    local_qrsub
 * @copyright  2021 Knowledge One Inc. {@link http://knowledgeone.ca}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery'],
    function ($) {

        return {

            /**
             * Function being called by the js_call_amd()
             */
            init: function () {

                // Simply move the attempt info block above the question.
                $("section[data-region='blocks-column']").insertBefore("#region-main").css("display", "block");

            } // init()
        };
    });
