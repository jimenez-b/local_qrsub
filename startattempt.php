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
 * This script deals with starting a new attempt at a quiz.
 *
 * Normally, it will end up redirecting to attempt.php - unless a password form is displayed.
 *
 * This code used to be at the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_quiz
 * @copyright 2009 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use local_qrsub\local\qrsub;

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id
$forcenew = optional_param('forcenew', false, PARAM_BOOL); // Used to force a new preview
$page = optional_param('page', -1, PARAM_INT); // Page to jump to in the attempt.

if (!$cm = get_coursemodule_from_id('quiz', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$quizobj = quiz::create($cm->instance, $USER->id);

///////////////////////////////////////////////////////
// QRMOD-11 - POC a file sub in a Hybrid question type.
// Set the page URL to the current URL so the student is
// redirected here if he is not logged in.
$pageurl = new \moodle_url('/local/qrsub/startattempt.php', array('cmid' => $id));
$PAGE->set_url($pageurl->out(false));

// Original.
// This script should only ever be posted to, so set page URL to the view page.
// $PAGE->set_url($quizobj->view_url());
// QRMOD-11.

// During quiz attempts, the browser back/forwards buttons should force a reload.
$PAGE->set_cacheable(false);

// Check login and sesskey.
require_login($quizobj->get_course(), false, $quizobj->get_cm());
require_sesskey();
$PAGE->set_heading($quizobj->get_course()->fullname);

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$quizobj->has_questions()) {
    if ($quizobj->has_capability('mod/quiz:manage')) {
        redirect($quizobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'quiz', $quizobj->view_url());
    }
}

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = $quizobj->get_access_manager($timenow);

///////////////////////////////////////////////////////
// QRMOOD-36 - As a dev, I want to make the first attempt inprogress before uploading files.
// QRMOOD-37 - As a prof, I want to be able to create proctored and upproctored exams.
// Set the quiz attempt status to inprogress if it is an unproctored exam.
$userattempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
$userattempt = end($userattempts);
if ($userattempt) {
    $attemptobj = quiz_attempt::create($userattempt->id);
    list($is_proctored, $upload_exam) = qrsub::is_exam_protored($attemptobj);
    if (!$is_proctored) {
        qrsub::set_quiz_attempt_inprogress($quizobj);
    }
}
// QRMOOD-36

// Validate permissions for creating a new attempt and start a new preview attempt if required.
list($currentattemptid, $attemptnumber, $lastattempt, $messages, $page) =
    quiz_validate_new_attempt($quizobj, $accessmanager, $forcenew, $page, true);

///////////////////////////////////////////////////////
// QRMOOD-36 - As a dev, I want to make the first attempt inprogress before uploading files.
// QRMOOD-37 - As a prof, I want to be able to create proctored and upproctored exams.
// Set the quiz attempt question status to inprogress.
if ($userattempt) {
    if (!$is_proctored) {
        qrsub::set_attempt_questions_inprogress($currentattemptid);
    }
}
// QRMOOD-36

///////////////////////////////////////////////////////
// QRMOOD-38 - As a student, I want no time limit when I upload my files.
if ($userattempt) {
    if (!$is_proctored) {
        $activerulenames = $accessmanager->get_active_rule_names();
        if (in_array('quizaccess_timelimit', $activerulenames)) {
            // qrsub::override_timelimit($quizobj);
        }
    }
}
// QRMOOD-38

// Check access.
if (!$quizobj->is_preview_user() && $messages) {
    $output = $PAGE->get_renderer('mod_quiz');
    print_error('attempterror', 'quiz', $quizobj->view_url(),
            $output->access_messages($messages));
}

if ($accessmanager->is_preflight_check_required($currentattemptid)) {
    // Need to do some checks before allowing the user to continue.
    $mform = $accessmanager->get_preflight_check_form(
            $quizobj->start_attempt_url($page), $currentattemptid);

    if ($mform->is_cancelled()) {
        $accessmanager->back_to_view_page($PAGE->get_renderer('mod_quiz'));

    } else if (!$mform->get_data()) {

        // Form not submitted successfully, re-display it and stop.
        $PAGE->set_url($quizobj->start_attempt_url($page));
        $PAGE->set_title($quizobj->get_quiz_name());
        $accessmanager->setup_attempt_page($PAGE);
        $output = $PAGE->get_renderer('mod_quiz');
        if (empty($quizobj->get_quiz()->showblocks)) {
            $PAGE->blocks->show_only_fake_blocks();
        }

        echo $output->start_attempt_page($quizobj, $mform);
        die();
    }

    // Pre-flight check passed.
    $accessmanager->notify_preflight_check_passed($currentattemptid);
}
if ($currentattemptid) {
    if ($lastattempt->state == quiz_attempt::OVERDUE) {
        redirect($quizobj->summary_url($lastattempt->id));
    } else {

        ///////////////////////////////////////////////////////
        // QRMOD-11 - POC a file sub in a Hybrid question type.
        // Redirect here if there is already one attempt in the quiz.

        // Find on which page the first hybrid question is and redirect to this page.
        $attemptobj = quiz_attempt::create($currentattemptid);
        list($slots, $page) = qrsub::get_first_hybrid_question($attemptobj, $page);

        // Replace the mod/quiz in URL by local/qrsub.
        $url = qrsub::replace_modquiz_path(
            $quizobj->attempt_url($currentattemptid, $page)
        );

        redirect($url);

        // Original
        // redirect($quizobj->attempt_url($currentattemptid, $page));
        // QRMOD-11
    }
}

$attempt = quiz_prepare_and_start_new_attempt($quizobj, $attemptnumber, $lastattempt);

///////////////////////////////////////////////////////
// QRMOD-11 - POC a file sub in a Hybrid question type.
// Redirect here if it is the student's first attempt at the quiz.

// Create a quiz_attempt object from the newly created attempt.
$attemptobj = quiz_attempt::create($attempt->id);

// Find the first occurence of the hybrid question and redirect to this page.
list($slots, $page) = qrsub::get_first_hybrid_question($attemptobj, $page);

// Replace the mod/quiz in URL by local/qrsub.
$url = qrsub::replace_modquiz_path(
    $quizobj->attempt_url($attempt->id, $page)
);
redirect($url);

// Original
// Redirect to the attempt page.
// redirect($quizobj->attempt_url($attempt->id, $page));
// QRMOD-11
