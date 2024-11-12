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
 * Helper class.
 *
 * @package    local_qrsub
 * @copyright  2021 KnowledgeOne <nicolas.dalpe@knowledgeone.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_qrsub\local;

defined('MOODLE_INTERNAL') || die();

use lang_string;
use moodle_url;
use question_state_todo;
use quiz_attempt;
use stdClass;

/**
 * Helper class.
 *
 * @copyright  2021 KnowledgeOne <nicolas.dalpe@knowledgeone.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qrsub {

    /**
     * Replace the original mod/quiz path in the URL by local/qrsub
     *
     * @param str|moodle_url $URL The URL to modify.
     *
     * @return str The modified URL.
     */
    public static function replace_modquiz_path($url) {

        // Convert a moodle_url object into a string.
        if ($url instanceof moodle_url) {
            $url = $url->out(false);
        }

        if (!is_string($url)) {
            return $url;
        }

        if (strpos($url, 'mod/quiz') !== false) {
            $url = str_replace('mod/quiz', 'local/qrsub', $url);
        }

        return $url;
    }

    /**
     * Make a finished attempt inprogress.
     *
     * @param obj $quizobj The quiz object the attempt belongs to.
     */
    public static function set_quiz_attempt_inprogress(\quiz $quizobj) {
        global $DB, $USER;

        // Get all user attempts.
        $userattempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);

        // If no attempt yet, the student is taking a proctored exam and he is uploading his file in another
        // unproctored quiz, so don't reset anything and continue the normal attempt creation.
        if (count($userattempts) === 0) {
            return;
        }

        // Get the last user attempt.
        $lastattempt = end($userattempts);

        // Make sure the attempt is finished before modifying it.
        if ($lastattempt->state == 'finished' && $lastattempt->timefinish > 0) {

            // Make the attempt in progress.
            $newstatus = new stdClass();
            $newstatus->id = $lastattempt->id;
            $newstatus->state = 'inprogress';
            $newstatus->timefinish = 0;
            $DB->update_record('quiz_attempts', $newstatus);
        }
    }

    /**
     * Delete the question step and step data for all hybrid question in the quiz.
     *
     * @param int $currentattemptid The attempt id.
     */
    public static function set_attempt_questions_inprogress($currentattemptid) {
        global $DB, $USER;

        if (is_null($currentattemptid)) {
            return;
        }

        $attemptobj = quiz_attempt::create($currentattemptid);

        // Get the list of the question attempts.
        $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
        $attempts = $quba->get_attempt_iterator();
        foreach ($attempts as $question_attempt) {

            // Make sure we are on a hybrid question.
            $question_definition = $question_attempt->get_question(false);
            if (get_class($question_definition) == 'qtype_hybrid_question') {

                // Get the last step in the sequence (which marks the question as answered).
                $question_attempt_step = $question_attempt->get_last_step();

                // Get question state to make sure we delete the right step.
                $step_state = $question_attempt_step->get_state()->get_summary_state();

                // Get the user id owning the attempt.
                $user_id = $question_attempt_step->get_user_id();

                // Get the user id owning the attempt.
                $question_attempt_step_id = $question_attempt_step->get_id();

                if ($USER->id == $user_id && $step_state == 'needsgrading') {

                    // Delete the step so Moodle thinks that the question is unanswered.
                    $DB->delete_records('question_attempt_steps', array(
                        'id' => $question_attempt_step_id
                    ));

                    // Delete the step's data for data integrity.
                    $DB->delete_records('question_attempt_step_data', array(
                        'attemptstepid' => $question_attempt_step_id
                    ));
                }
            }
        }
    }

    /**
     * Give the student extra time to upload it's files.
     *
     * @param quiz $quizobj The quiz object (see quiz::create())
     *
     * @return bool true
     */
    public static function override_timelimit(\quiz $quizobj) {
        global $DB, $USER;

        $quiz = $quizobj->get_quiz();

        // Override data.
        $override = new stdClass();
        $override->userid = $USER->id;
        $override->timelimit = 3600;
        $override->quiz = $quizobj->get_quizid();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->attempts = null;
        $override->password = null;

        // Delete previous quiz override.
        $conditions = array(
            'quiz' => $quizobj->get_quizid(),
            'userid' => $USER->id,
            'groupid' => null
        );
        if ($oldoverride = $DB->get_record('quiz_overrides', $conditions)) {

            // There is an old override, so we merge any new settings on top of
            // the older override.
            // ie: if we already override on the number of attempt, we keep
            // this override and add the new time limit to it.
            $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password');
            foreach ($keys as $key) {
                if (is_null($override->{$key})) {
                    $override->{$key} = $oldoverride->{$key};
                }
            }

            // Set the course module id before calling quiz_delete_override().
            $quiz->cmid = $quizobj->get_cmid();

            // Deletes a quiz override from the database and clears any
            // corresponding calendar events.
            quiz_delete_override($quiz, $oldoverride->id);
        }

        // Create the event
        $override->id = $DB->insert_record('quiz_overrides', $override);

        // Get the course module to create the event.
        list($course, $cm) = get_course_and_cm_from_instance($quiz, 'quiz');

        // Trigger the override event.
        $event = \mod_quiz\event\user_override_created::create(array(
            'context' => \context_module::instance($cm->id),
            'other' => array('quizid' => $quiz->id),
            'objectid' => $override->id,
            'relateduserid' => $override->userid
        ));
        $event->trigger();

        // Efficiently update check state time on all open attempts
        quiz_update_open_attempts(array('quizid' => $quiz->id));

        // User override. We only need to update the calendar event for this user override.
        quiz_update_events($quiz, $override);

        return true;

    }

    /**
     * Find the next occurence of a hybrid question in a given attempt.
     *
     * @param quiz_attempt The quiz attempt object.
     * @param int $page The page to start from.
     *
     * @return array The page and slot # of the next hybrid question.
     */
    public static function get_next_hybrid_question(quiz_attempt $attemptobj, $page) {

        // Start at the next page.
        $page = $page + 1;

        // Get the slot of the current page.
        $slot = $attemptobj->get_slots($page);

        // Get the question type of the question in the slot.
        $a = $attemptobj->get_question_type_name($slot[0]);
        if ($a != 'hybrid') {
            while ($a != 'hybrid') {
                $page++;
                $slot = $attemptobj->get_slots($page);
                $a = $attemptobj->get_question_type_name($slot[0]);
            }
        }

        return array($slot, $page);
    }

    /**
     * Find the previous occurence of a hybrid question in a given attempt.
     *
     * @param quiz_attempt The quiz attempt object.
     * @param int $page The page to start from.
     *
     * @return array The page and slot # of the previous hybrid question.
     */
    public static function get_previous_hybrid_question(quiz_attempt $attemptobj, $page) {

        // Start at the next page.
        $page = $page - 1;

        // Get the slot of the current page.
        $slot = $attemptobj->get_slots($page);

        // Get the question type of the question in the slot.
        $a = $attemptobj->get_question_type_name($slot[0]);
        if ($a != 'hybrid') {
            while ($a != 'hybrid') {
                $page--;
                $slot = $attemptobj->get_slots($page);
                $a = $attemptobj->get_question_type_name($slot[0]);
            }
        }

        return array($slot, $page);
    }

    /**
     * Find the first occurence of a hybrid question in a given attempt.
     *
     * @param quiz_attempt The quiz attempt object.
     * @param int $page The page to start from.
     *
     * @return array The page and slot # of the first hybrid question.
     */
    public static function get_first_hybrid_question(quiz_attempt $attemptobj, $page) {

        // Get the slot of the current page.
        $slot = $attemptobj->get_slots($page);

        // Get the question type of the question in the slot.
        $a = $attemptobj->get_question_type_name($slot[0]);
        if ($a != 'hybrid') {
            while ($a != 'hybrid') {
                $page++;
                if ($attemptobj->is_last_page($page)) {
                    break;
                } else {
                    $slot = $attemptobj->get_slots($page);
                    $a = $attemptobj->get_question_type_name($slot[0]);
                }
            }
        }

        return array($slot, $page);
    }

    /**
     * Return true if an attempt contains a hybrid question.
     *
     * @param quiz_attempt The quiz attempt object.
     *
     * @return bool True if a hybrid question is found in the attempt.
     */
    public function has_hybrid_question(quiz_attempt $attemptobj) {

        // Get all the question slots in the attempt.
        $slots = $attemptobj->get_slots('all');

        // Return true if we find a hybrid question.
        foreach ($slots as $slot) {
            $questiontypename = $attemptobj->get_question_type_name($slot);
            if ($questiontypename == 'hybrid') {
                return true;
            }
        }

        return false;
    }

    public static function question_attempt_has_answers(\quiz $quizobj) {
        global $USER;

        $question_attempt_has_answers = false;

        $userattempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
        $userattempt = end($userattempts);
        if ($userattempt) {
            $attemptobj = quiz_attempt::create($userattempt->id);
            list($is_proctored, $upload_exam) = qrsub::is_exam_protored($attemptobj);
            if (!$is_proctored) {
                // Get all slot (question) in the current attempt.
                $allslots = $attemptobj->get_slots('all');

                // Cycle through all the slots in the attempt to find all hybrid question(s).
                foreach ($allslots as $key => $slot) {
                    $question_attempt = $attemptobj->get_question_attempt($slot);
                    if (!$question_attempt->get_state() instanceof question_state_todo) {
                        $question_attempt_has_answers = true;
                    }
                }
            }
        }

        return $question_attempt_has_answers;
    }

    /**
     * Whether an hybrid question has an uploaded file submitted.
     *
     * @param quiz_attempt The quiz attempt object.
     *
     * @return bool True if an hybrid question has an uploaded file.
     */
    public function question_attempt_has_uploaded_file($attemptobj) {

        $attempt_has_files = false;

        // Get all slot (question) in the current attempt.
        $allslots = $attemptobj->get_slots('all');

        // Cycle through all the slots in the attempt to find all hybrid question(s).
        foreach ($allslots as $slot) {

            if ('hybrid' == $attemptobj->get_question_type_name($slot)) {
                $question_attempt = $attemptobj->get_question_attempt($slot);
                $question_data = $question_attempt->get_last_qt_data();
                if (!empty($question_data['attachments'])) {
                    $attempt_has_files = true;
                    break;
                }
            }
        }

        return $attempt_has_files;
    }

    /**
     * Find if the current question is the last occurence of a hybrid question in a given attempt.
     *
     * @param quiz_attempt The quiz attempt object.
     * @param int $page The page to start from.
     *
     * @return array The page and slot # of the first hybrid question.
     */
    public static function is_last_hybrid_question(quiz_attempt $attemptobj, $currentslot) {

        // Get all slot in the current attempt.
        $allslots = $attemptobj->get_slots('all');

        // Cycle the remanining slots in the attempt to find an hybrid question.
        for ($i= $currentslot[0]; $i<count($allslots); $i++) {
            $slot = $attemptobj->get_slots($i);
            $qtypename = $attemptobj->get_question_type_name($slot[0]);
            if ($qtypename == 'hybrid') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the exam is proctored or not.
     *
     * @param quiz_attempt $attemptobj The attempt object.
     *
     * @return array
     *      bool $is_proctored Whther the exam is proctored
     *      obj $upload_exam The CM of the upload exam if the exam is proctored
     */
    public static function is_exam_protored(quiz_attempt $attemptobj) {

        // Set the exam unproctored by default.
        $is_proctored = false;

        // Contains the upload exam cm. false if the exam is unproctored.
        $upload_exam = false;

        $qrsub = new qrsub();

        // Return false if the quiz doesn't contain hybrid questions.
        if (!$qrsub->has_hybrid_question($attemptobj)) {
            return false;
        }

        // Get all slot (question) in the current attempt.
        $allslots = $attemptobj->get_slots('all');

        // Cycle through all the slots in the attempt to find all hybrid question(s).
        foreach ($allslots as $key => $slot) {
            if ('hybrid' == $attemptobj->get_question_type_name($slot)) {
                $question_attempt = $attemptobj->get_question_attempt($slot);
                $question = $question_attempt->get_question(false);
                if ($question->upload_exam != 0) {
                    $is_proctored = true;
                    $upload_exam = get_coursemodule_from_id('quiz', $question->upload_exam);
                    break;
                }
            }
        }

        return array($is_proctored, $upload_exam);
    }

    public function get_qrcode($cm) {
        global $CFG, $COURSE, $PAGE;

        $qrcodeoptions = new stdClass();

        // Course id.
        $qrcodeoptions->courseid = $COURSE->id;

        // Get the assignment cmid to link to.
        $qrcodeoptions->quizid = $cm->id;

        // Build the QR Code.
        $qrcodegenerator = new qrcodegenerator($qrcodeoptions);

        // Generate the QR Code URL.
        $url = new moodle_url('/local/qrsub/startqrsub.php', array(
            'cmid' => $cm->id
        ));

        // Output the QR Code image.
        $qrcode = $qrcodegenerator->output_image($url);

        // Prepare the data for the template.
        $tpldata = new stdClass();
        $tpldata->legend = new lang_string('instruction_qrcode', 'local_qrsub');

        // Set the QR Code format.
        if ($qrcodegenerator->get_format() == 1) {
            $tpldata->qrcodesvg = $qrcode;
        } else {
            $tpldata->qrcodepng = $qrcode;
        }

        ///////////////////////////////////////////////////////
        // QRMOOD-43 - As a dev, I want the QR Code URL to debug
        //
        // Only display the QR Code's URL if debug is turned on.
        if ($CFG->debug > 0) {
            $tpldata->url = $url->out();
        }
        // QRMOOD-43

        // Render the QR Code.
        $renderable = new \local_qrsub\output\qrcode_page($tpldata);
        $qrcodea_renderer = $PAGE->get_renderer('local_qrsub');
        $output = $qrcodea_renderer->render($renderable);

        return $output;
    }

    public function display_qrcode($attemptobj, $cm) {
        $output = '';

        $hashybrid = $this->has_hybrid_question($attemptobj);
        if ($hashybrid) {

            // Check if there is at least one hybrid question with a file uploaded in it.
            $exam_finished = $this->question_attempt_has_uploaded_file($attemptobj);

            // Get the attempt state.
            $attempt_state = $attemptobj->get_state();

            // Find if the exam is proctored.
            list($is_proctored, $upload_exam) = qrsub::is_exam_protored($attemptobj);

            if ($is_proctored) {

                // Create the quiz object of the upload exam.
                $upload_quizobj = \quiz::create($upload_exam->instance, $attemptobj->get_userid());

                // Get the last attempts of the upload exam.
                $upload_attempts = quiz_get_user_attempts(
                    $upload_quizobj->get_quizid(), $attemptobj->get_userid(), 'all', true
                );
                $upload_attempt = end($upload_attempts);
                if ($upload_attempt) {
                    $upload_attemptobj = quiz_attempt::create($upload_attempt->id);
                    $upload_attempt_state = $upload_attemptobj->get_state();
                }

                // Display the QR Code if (proctored exam)
                // 1 - The QE is finished
                // 2 - The UE is not started or in progress
                if (
                    $attempt_state == quiz_attempt::FINISHED &&
                    ($upload_attempt === false || $upload_attempt_state == quiz_attempt::IN_PROGRESS)
                ) {
                    $output .= $this->get_qrcode($upload_exam);
                }
                // Display the QR Code if (unproctored exam)
                // 1 - We are at the first attempt
                // 2 - The attempt state is finished or inprogress
                // 3 - The Exam is not finished
            } else if (
                $attemptobj->get_attempt_number() == 1 &&
                $attempt_state == quiz_attempt::FINISHED &&
                $exam_finished === false
            ) {
                $output .= $this->get_qrcode($cm);
            }
        }

        return $output;
    }

    public static function get_files_from_upload_exam(quiz_attempt $attemptobj, $slot, $output_renderer) {
        $output = '';

        list($is_proctored, $upload_exam) = self::is_exam_protored($attemptobj);
        if ($is_proctored) {

            // Create the quiz object of the upload exam.
            $upload_quizobj = \quiz::create($upload_exam->instance, $attemptobj->get_userid());

            // Get all the attempts of the upload exam.
            $upload_attempts = quiz_get_user_attempts($upload_quizobj->get_quizid(), $attemptobj->get_userid(), 'all', true);
            $upload_attempt = end($upload_attempts);
            if ($upload_attempt) {

                // Create the upload exam attempt object and get the question_by_usage (or answers).
                $upload_attemptobj = quiz_attempt::create($upload_attempt->id);
                $quba = \question_engine::load_questions_usage_by_activity($upload_attemptobj->get_uniqueid());

                // Get the database id of the question in the proctored exam.
                $question_exam_qa = $attemptobj->get_question_attempt($slot);
                $question_exam_id = $question_exam_qa->get_question_id();

                foreach ($quba->get_attempt_iterator() as $upload_qa) {

                    // Find the current question in the upload exam attempt.
                    if ($question_exam_id == $upload_qa->get_question_id()) {

                        // Get the uploaded files from the question in the upload exam.
                        $files = $upload_qa->get_last_qt_files('attachments', $quba->get_owning_context()->id);

                        if (count($files)) {

                            // Get the question's file list.
                            list($images, $indicators, $imagelinks) = self::get_uploaded_files_array(
                                $files, $upload_qa, $output_renderer
                            );

                            // Get the data for the template.
                            $tpldata = self::set_template_data(
                                $upload_qa->get_database_id(),
                                array('images' => $images, 'indicators' => $indicators, 'imagelinks' => $imagelinks)
                            );

                            // Render the modal and image link list.
                            $output .= self::render_image_modal($tpldata);
                        } else {
                            $output .= self::get_no_uploaded_file_msg();
                        }
                    }
                }
            }
        } else {

            // Get the question usage for this attempt.
            $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());

            // Get the questions attempt.
            $qa = $attemptobj->get_question_attempt($slot);

            // Get the uploaded files from the question in the upload exam.
            $files = $qa->get_last_qt_files('attachments', $quba->get_owning_context()->id);

            if (count($files) !== 0) {

                // Get the question's file list.
                list($images, $indicators, $imagelinks) = self::get_uploaded_files_array(
                    $files, $qa, $output_renderer
                );

                // Format the data for the template.
                $tpldata = self::set_template_data(
                    $qa->get_database_id(),
                    array('images' => $images, 'indicators' => $indicators, 'imagelinks' => $imagelinks)
                );

                // Render the modal and image link list.
                $output .= self::render_image_modal($tpldata);
            } else {

                // No files uploaded yet, display the appropriate message.
                $output .= self::get_no_uploaded_file_msg();
            }
        }

        return $output;
    }

    /**
     * Output the proper message if the question contains no files.
     *
     * @return str The HTML containing the message.
     */
    public static function get_no_uploaded_file_msg() {
        return \html_writer::tag('div',
            get_string('nofileuploadedbystudent', 'local_qrsub')
        );
    }

    /**
     * Returns an array of files contained in the question ready to be added to the tpl.
     *
     * @param array $files Array of files uploaded into the question
     * @param qeustion_attempt $qa The question attempt to get the file from.
     * @param core_renderer $output_renderer
     *
     * @return array Files data ready to be added to the template.
     */
    public static function get_uploaded_files_array($files, $qa, $output_renderer) {

        $images = $indicators = $imagelinks = [];

        $i = 0;
        foreach ($files as $file) {

            // Activate the first image by default.
            if ($i === 0) {
                $is_active = 'active';
            } else {
                $is_active = '';
            }

            ///////////////////////////////////////////////////////
            // QRMOOD-53 - As a prof, I want to download other file type than jpg.
            //
            // Get the file path.
            $image_path = self::get_response_file_url(
                $file, $qa->get_usage_id(), $qa->get_slot()
            );
            // QRMOOD-53

            // Images to display in the modal.
            $images[$i]['class'] = $is_active;
            $images[$i]['src'] = $image_path;
            $images[$i]['title'] = s($file->get_filename());

            // Little rectangles at the bottom of the modal to jump from one image to another.
            $indicators[$i]['class'] = $is_active;
            $indicators[$i]['slideto'] = $i;

            ///////////////////////////////////////////////////////
            // QRMOOD-53 - As a prof, I want to download other file type than jpg.
            //
            // Add the document path in the href so we can "save link as" pdf.
            $imagelinks[$i]['href'] = $image_path;
            // QRMOOD-53.

            // Image link to click on to open the modal.
            $imagelinks[$i]['filename'] = s($file->get_filename());
            $imagelinks[$i]['increment'] = $qa->get_database_id();
            $imagelinks[$i]['icon'] = $output_renderer->pix_icon(
                file_file_icon($file),
                get_mimetype_description($file),
                'moodle',
                array('class' => 'icon')
            );
            $imagelinks[$i]['index'] = $i;

            $i++;
        }

        return array($images, $indicators, $imagelinks);
    }

    /**
     * Set the uploaded image link and modal data.
     *
     * @param int   $increment The counter to create the modal navigation.
     * @param arrar $data      The images link data.
     *
     * @return stdClass Uploaded image data ready to be re
     */
    public static function set_template_data($increment, $data) {

        $tpldata = new stdClass();
        $tpldata->increment = $increment;
        $tpldata->images = new \ArrayIterator($data['images']);
        $tpldata->indicators = new \ArrayIterator($data['indicators']);
        $tpldata->imagelinks = new \ArrayIterator($data['imagelinks']);

        return $tpldata;

    }

    /**
     * Renders the uploaded images in modal.
     *
     * @param obj $tpldata The template data to render.
     *
     * @return str The modal HTML.
     */
    public static function render_image_modal($tpldata) {
        global $PAGE;
        $renderable = new \local_qrsub\output\image_modal($tpldata);
        $image_modal_renderer = $PAGE->get_renderer('local_qrsub');
        return $image_modal_renderer->render($renderable);
    }

    /**
     * Get the URL of a file that belongs to a response variable of this
     * question_attempt.
     *
     * QRMOOD-42 - As a prof, I want to see the question images in a modal.
     * Override the method to remove the force download.
     *
     * @param stored_file $file the file to link to.
     * @return string the URL of that file.
     */
    public static function get_response_file_url(\stored_file $file, $usageid, $slot) {
        return file_encode_url(new moodle_url('/pluginfile.php'), '/' . implode('/', array(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $usageid,
            $slot,
            $file->get_itemid()
        )) .
            $file->get_filepath() . $file->get_filename(), false);
    }

    /**
     * Add the uploaded files in the upload exam to the proctored attempt.
     *
     * @param str $formulation The node to attach the files to.
     * @param str $files The file list.
     *
     * @return str The question formulation with the files added.
     */
    public static function add_upload_exam_files_to_review($formulation, $files) {

        $dom = new \DOMDocument();

        // set error level.
        $internalErrors = libxml_use_internal_errors(true);
        // Fix Character issue Manual Grading Dec 15, 2021
        $dom->loadHTML(mb_convert_encoding($formulation, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $childNodeList = $dom->getElementsByTagName('div');
        for ($i = 0; $i < $childNodeList->length; $i++) {
            $temp = $childNodeList->item($i);

            // We insert the files in the attachment box.
            if ($temp->getAttribute('class') == 'attachments') {

                // Create the uploaded file box.
                $upload_file_box = \html_writer::tag(
                    'div',
                    get_string('upload_exam_files_title', 'local_qrsub') . $files,
                    array('id' => 'upload_exam_files')
                );

                // Remove current files, if any.
                self::remove_current_files($temp);

                // Add the upload file box to the question formulation.
                self::appendHTML($temp, $upload_file_box);

                // Stop cycling through the rest of the document.
                break;
            }
        }

        return $dom->saveHTML();
    }

    /**
     * Remove the current files in the attachments div.
     *
     * This is to avoid creating a duplicated file list in the upload exam.
     *
     * @param DOMNode The DOM node containing the files to remove.
     */
    private static function remove_current_files(\DOMNode $element) {
        $elements = $element->getElementsByTagName('p');
        for ($i = $elements->length; --$i >= 0;) {
            $p = $elements->item($i);
            $p->parentNode->removeChild($p);
        }
    }

    /**
     * Append the file list to the attachments div.
     *
     * @param DOMNode $parent The parent DOM Node.
     * @param str     $source The HTML to append the file list to.
     */
    private static function appendHTML(\DOMNode $parent, $source) {
        $tmpDoc = new \DOMDocument();
        // $tmpDoc->loadHTML($source);
        $tmpDoc->loadHTML($source, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        // foreach ($tmpDoc->getElementsByTagName('body')->item(0)->childNodes as $node) {
        foreach ($tmpDoc->getElementsByTagName('div')->item(0)->childNodes as $node) {
            $node = $parent->ownerDocument->importNode($node, true);
            $parent->appendChild($node);
        }
    }
}
