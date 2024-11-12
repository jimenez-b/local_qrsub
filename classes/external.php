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
 * Quiz external API
 *
 * @package    mod_quiz
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use local_qrsub\local\qrsub;
use local_qrsub\local\qrsub_attempt_info_block;

/**
 * Quiz external functions
 *
 * @package    mod_quiz
 * @category   external
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.1
 */
class local_qrsub_external extends external_api {

    /**
     * Describes the parameters for attempt_status.
     *
     * @return external_function_parameters
     * @since Moodle 3.1
     */
    public static function attempt_status_parameters() {
        return new external_function_parameters (
            array(
                'attemptid' => new external_value(PARAM_INT, 'Attempt instance id'),
            )
        );
    }

    /**
     * Return the status of the hybrid questions in an unproctored attempt.
     *
     * no_attempt_yet : The file upload attempt hasn't started yet.
     * exam_finished : The file upload attempt is completed.
     * if progress: The HTML containing the question status
     *
     * @param int $attemptid The attempt id to get the status from.
     * @return array status
     */
    public static function attempt_status($attemptid) {
        global $DB;

        $params = self::validate_parameters(
            self::attempt_status_parameters(),
            array('attemptid' => $attemptid)
        );

        $attemptobj = quiz_attempt::create($params['attemptid']);
        $attempt_state = $attemptobj->get_state();

        $status = 'no_attempt_yet';

        $qrsub = new qrsub();
        $has_hybrid = $qrsub->has_hybrid_question($attemptobj);

        if ($has_hybrid) {

            // If the attempt state is finished it can mean 2 diffrent scenarios:
            // 1 - The non-hybrid attempt (first attempt) is finished but the file upload hasn't begun yet.
            // 2 - Both attempt is finished.
            // To tell them apart, we check if there is file uploaded in the question_attempt_data.
            if ($attempt_state == 'finished') {

                // Check if there is a file uploaded in a hybrid question.
                if ($qrsub->question_attempt_has_uploaded_file($attemptobj)) {
                    $status = 'exam_finished';
                }
            } else if ($attempt_state == 'inprogress') {

                // Remove the default status.
                $status = '';
                $hybridinfo = new qrsub_attempt_info_block($attemptobj);
                $hybrids = $hybridinfo->get_questions();
                foreach ($hybrids as $hybrid) {
                    $status .= html_writer::tag(
                        'div',
                        $hybrid['name'] . ' ' . $hybrid['complete'],
                        array('class' => $hybrid['complete_css_class'])
                    );
                }
            }
        }

        return array('status' => $status);
    }

    /**
     * Describes the attempt_status return value.
     *
     * @return external_single_structure
     */
    public static function attempt_status_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_RAW, 'Attempt status')
            )
        );
    }

    /**
     * Describes the parameters for attempt_proctored.
     *
     * @return external_function_parameters
     */
    public static function attempt_proctored_parameters() {
        return new external_function_parameters(
            array(
                'instanceid' => new external_value(PARAM_INT, 'Course module instance id of the quiz to monitor'),
            )
        );
    }

    /**
     * Return the status of the hybrid questions in an proctored attempt.
     *
     * @param int $instanceid The attempt instance id.
     * @return array Hybrid question status.
     */
    public static function attempt_proctored($instanceid) {
        global $USER;

        $params = self::validate_parameters(
            self::attempt_proctored_parameters(),
            array('instanceid' => $instanceid)
        );

        $userattempts = quiz_get_user_attempts($params['instanceid'], $USER->id, 'all', true);
        if (count($userattempts) == 0) {
            $status = 'notstarted';
        } else if (count($userattempts) > 0) {
            $userattempt = end($userattempts);
            if ($userattempt->state == 'finished') {
                $status = 'exam_finished';
            } else {
                // Remove the default status.
                $status = '';
                $attemptobj = quiz_attempt::create($userattempt->id);
                $hybridinfo = new qrsub_attempt_info_block($attemptobj);
                $hybrids = $hybridinfo->get_questions();
                foreach ($hybrids as $hybrid) {
                    $status .= html_writer::tag(
                        'div',
                        $hybrid['name'] . ' ' . $hybrid['complete'],
                        array('class' => $hybrid['complete_css_class'])
                    );
                }
            }
        }

        return array('status' => $status);
    }

    /**
     * Describes the attempt_proctored return value.
     *
     * @return external_single_structure
     */
    public static function attempt_proctored_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_RAW, 'Attempt status')
            )
        );
    }
}
