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
 * Get the status of the hybrid question within an attempt.
 *
 * @module     local_qrsub/attempt_status
 * @package    local_qrsub
 * @copyright  2021 Knowledge One Inc. {@link http://knowledgeone.ca}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/str', 'core/ajax', 'core/log'],
    function ($, str, ajax, Log) {

    return {

        /**
         * Initialize the status refresh.
         *
         * @param int attemptid The attempt id to get the status of.
         */
        init: function(attemptid) {

            let attempt_unproctored_internal = setInterval(
                function () { get_attempt_status(attemptid); }, 1000
            );

            function get_attempt_status(attemptid) {

                // Send the new order to the server.
                var promises = ajax.call([{
                    methodname: 'local_qrsub_attempt_status',
                    args: { 'attemptid': attemptid }
                }]);

                // Process the server response.
                promises[0].done(function (response) {
                    if (response.status == 'no_attempt_yet') {

                        // No attempt yet. Let's wait a bit more.
                        str.get_strings([
                            { 'key': 'no_attempt_yet', component: 'local_qrsub' }
                        ]).done(function (attempt_status_string) {
                            display_hybrid_question_status(attempt_status_string[0]);
                        });
                    } else if (response.status == 'exam_finished') {

                        // Clear the interval to stop the refresh.
                        clearInterval(attempt_unproctored_internal);

                        // Display the new status to the student.
                        str.get_strings([
                            { 'key': 'exam_finished', component: 'local_qrsub' }
                        ]).done(function (attempt_status_string) {
                            display_qrsub_attempt_status(attempt_status_string[0]);
                            display_hybrid_question_status('');

                            // Hide the QR Code.
                            $(".k1-qrcode").hide();
                        });
                    } else {

                        display_hybrid_question_status(response.status);
                    }
                }).fail(function () {
                    Log.info('get_attempt_status fail');
                });
            }

            /**
             * Update the hybrid question status.
             * @param str status The new status to display.
             */
            function display_hybrid_question_status(status) {
                let e = $("#hybrid_status");
                if (e.text() != status) {
                    e.html(status);
                }
            }

            /**
             * Update the exam status.
             * @param str status The new status to display.
             */
            function display_qrsub_attempt_status(status) {
                let e = $("#qrsub_attempt_status");
                if (e.text() != status) {
                    e.html(status);
                }
            }

        } // init()
    };
});
