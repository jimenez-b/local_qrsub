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
 * Defines the renderer for the quiz module.
 *
 * @package   mod_quiz
 * @copyright 2011 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_qrsub\local\qrsub;

defined('MOODLE_INTERNAL') || die();

/**
 * The renderer for the quiz module.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_qrsub_renderer extends \mod_quiz_renderer {


    /**
     * Render the QR Code template.
     *
     * @param qrcode $page
     *
     * @return string html of the QR Code.
     */
    public function render_qrcode_page($page) {
            $data = $page->export_for_template($this);
            return parent::render_from_template('local_qrsub/qrcode', $data);
    }

    /**
     * Display the student submitted files in a modal.
     *
     * @param image_modal $page
     *
     * @return string html of the modal.
     */
    public function render_image_modal($page) {
            $data = $page->export_for_template($this);
            return parent::render_from_template('local_qrsub/image_modal', $data);
    }

    /**
     * Attempt Page
     *
     * @param quiz_attempt $attemptobj Instance of quiz_attempt
     * @param int $page Current page number
     * @param quiz_access_manager $accessmanager Instance of quiz_access_manager
     * @param array $messages An array of messages
     * @param array $slots Contains an array of integers that relate to questions
     * @param int $id The ID of an attempt
     * @param int $nextpage The number of the next page
     */
    public function attempt_page($attemptobj, $page, $accessmanager, $messages, $slots, $id,
            $nextpage) {

        ///////////////////////////////////////////////////////
        // QRMOD-11 - POC a file sub in a Hybrid question type.
        $this->page->requires->js_call_amd('local_qrsub/qrsub', 'init');
        // QRMOD-11

        $output = '';
        $output .= $this->header();
        $output .= $this->quiz_notices($messages);
        $output .= $this->attempt_form($attemptobj, $page, $slots, $id, $nextpage);
        $output .= html_writer::tag('div','',array('class'=>'lastsaved d-none'));
        $output .= $this->footer();
        return $output;
    }

    /**
     * Ouputs the form for making an attempt
     *
     * @param quiz_attempt $attemptobj
     * @param int $page Current page number
     * @param array $slots Array of integers relating to questions
     * @param int $id ID of the attempt
     * @param int $nextpage Next page number
     */
    public function attempt_form($attemptobj, $page, $slots, $id, $nextpage) {
        $output = '';

        // Start the form.
        $output .= html_writer::start_tag('form',
                array('action' => new moodle_url($attemptobj->processattempt_url(),
                array('cmid' => $attemptobj->get_cmid())), 'method' => 'post',
                'enctype' => 'multipart/form-data', 'accept-charset' => 'utf-8',
                'id' => 'responseform'));
        $output .= html_writer::start_tag('div');

        // Print all the questions.
        foreach ($slots as $slot) {
            $output .= $attemptobj->render_question($slot, false, $this,
                    $attemptobj->attempt_url($slot, $page), $this);
        }

        $navmethod = $attemptobj->get_quiz()->navmethod;

        ///////////////////////////////////////////////////////
        // QRMOD-11 - POC a file sub in a Hybrid question type.
        list($this->firstslot, $this->firstpage) = qrsub::get_first_hybrid_question($attemptobj, 0);
        $output .= $this->attempt_navigation_buttons(
            $page,
            qrsub::is_last_hybrid_question($attemptobj, $slots),
            $navmethod
        );

        // Original
        // $output .= $this->attempt_navigation_buttons($page, $attemptobj->is_last_page($page), $navmethod);
        // QRMOD-11

        // Some hidden fields to trach what is going on.
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'attempt',
                'value' => $attemptobj->get_attemptid()));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'thispage',
                'value' => $page, 'id' => 'followingpage'));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'nextpage',
                'value' => $nextpage));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'timeup',
                'value' => '0', 'id' => 'timeup'));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'sesskey',
                'value' => sesskey()));
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'scrollpos',
                'value' => '', 'id' => 'scrollpos'));

        // Add a hidden field with questionids. Do this at the end of the form, so
        // if you navigate before the form has finished loading, it does not wipe all
        // the student's answers.
        $output .= html_writer::empty_tag('input', array('type' => 'hidden', 'name' => 'slots',
                'value' => implode(',', $attemptobj->get_active_slots($page))));

        // Finish the form.
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('form');

        $output .= $this->connection_warning();

        return $output;
    }

    /**
     * Display the prev/next buttons that go at the bottom of each page of the attempt.
     *
     * @param int $page the page number. Starts at 0 for the first page.
     * @param bool $lastpage is this the last page in the quiz?
     * @param string $navmethod Optional quiz attribute, 'free' (default) or 'sequential'
     * @return string HTML fragment.
     */
    protected function attempt_navigation_buttons($page, $lastpage, $navmethod = 'free') {
        $output = '';


        $output .= html_writer::start_tag('div', array('class' => 'submitbtns'));
        ///////////////////////////////////////////////////////
        // QRMOD-11 - POC a file sub in a Hybrid question type.
        if ($page > $this->firstpage && $navmethod == 'free') {

        // Original
        // if ($page > 0 && $navmethod == 'free') {
        // QRMOOD-11

                $output .= html_writer::empty_tag('input', array(
                        'type' => 'submit', 'name' => 'previous',
                        //'value' => get_string('navigateprevious', 'quiz'), 'class' => 'mod_quiz-prev-nav btn btn-secondary'
                        'value' => get_string('qrnavigateprevious', 'local_qrsub'), 'class' => 'mod_quiz-prev-nav btn btn-secondary'
                ));
        }
        if ($lastpage) {
                $nextlabel = get_string('endtest', 'quiz');
        } else {
                //$nextlabel = get_string('navigatenext', 'quiz');
                $nextlabel = get_string('qrnavigatenext', 'local_qrsub');
        }
        $output .= html_writer::empty_tag('input', array(
                'type' => 'submit', 'name' => 'next',
                'value' => $nextlabel, 'class' => 'mod_quiz-next-nav btn btn-primary'
        ));
        $output .= html_writer::end_tag('div');

        return $output;
    }

    /**
     * Generates the table of summarydata
     *
     * QRMOOD-39 - As a student, I want a better summary page.
     * We only modify the output of the parent methos this way we don't
     * have to merge any code here if the parent method gets an update.
     *
     * @param quiz_attempt $attemptobj
     * @param mod_quiz_display_options $displayoptions
     */
    public function summary_table($attemptobj, $displayoptions) {

        // Get the HTML from the parent method.
        $summary_page = parent::summary_table($attemptobj, $displayoptions);

        $qrsub = new qrsub();
        $has_hybrid = $qrsub->has_hybrid_question($attemptobj);
        if ($has_hybrid) {

            $dom = new DOMDocument;
            $dom->loadHTML($summary_page, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            foreach ($dom->getElementsByTagName('td') as $td) {
                foreach ($td->getElementsByTagName('a') as $a) {

                    // Creates a span with the question # as content.
                    $span = $dom->createElement('span', $a->nodeValue);

                    // Replace the a with the span.
                    $a->parentNode->replaceChild($span, $a);
                }
            }

            $summary_page = $dom->saveHTML();
        }

        return $summary_page;
    }

}
