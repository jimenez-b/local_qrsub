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

namespace local_qrsub\local;

require_once($CFG->dirroot . '/question/type/hybrid/questiontype.php');

use quiz_attempt;
use local_qrsub\local\qrsub;
use question_attempt;
use question_state_complete;
use question_state_needsgrading;

defined('MOODLE_INTERNAL') || die();

/**
 * Specialisation of {@link quiz_nav_panel_base} for the attempt qrsub page.
 *
 * @copyright  2021 KnowledgeOne <nicolas.dalpe@knowledgeone.ca>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class qrsub_attempt_info_block {

    /** @var quiz_attempt */
    protected $attemptobj;

    public function __construct(quiz_attempt $attemptobj) {
        $this->attemptobj = $attemptobj;
    }

    /**
     * Get the hybrids question title and completion status.
     *
     * @return array Each hybrid question completion info.
     */
    public function get_questions() {
        global $OUTPUT;

        // Contains the qtype hybrid question info.
        $hybrids = array();

        // Get all slot (question) in the current attempt.
        $allslots = $this->attemptobj->get_slots('all');

        // Cycle through all the slots in the attempt to find all hybrid question(s).
        foreach($allslots as $key => $slot) {

            if ('hybrid' == $this->attemptobj->get_question_type_name($slot)) {

                // Checks whether a user can navigate to this particular slot.
                if ($this->attemptobj->can_navigate_to($slot)) {

                    // Load question options.
                    $question_attempt = $this->attemptobj->get_question_attempt($slot);

                    // Get the completion state for the current question.
                    list($complete, $complete_css_class) = $this->get_question_state($question_attempt);

                    ///////////////////////////////////////////////////////
                    // QRMOOD-45 - As a student, I want a smaller exam state panel.
                    //
                    // Choose the icon according to the question status.
                    if ($complete == 'Complete') {
                        $icon = 'i/checked';
                    } else {
                        $icon = 'req';
                    }
                    // QRMOOD-45

                    $hybrids[] = array(
                        // Get the question title.
                        'name' => $this->attemptobj->get_question_name($slot),

                        // QRMOOD-45 - As a student, I want a smaller exam state panel.
                        'icon' => $icon,

                        // The completion status along with it's css class.
                        'complete' => $complete,
                        'complete_css_class' => $complete_css_class,
                    );
                }
            }
        }

        return $hybrids;
    }

    /**
     * Generate the question state and css class of the current questiom.
     *
     * @param question_attempt The question attempt object.
     *
     * @return array The completion state and the css class to go w/ the comp. state.
     */
    public function get_question_state(question_attempt $question_attempt) {
        // Get question completion status.
        $question_state = $question_attempt->get_state();
        if (
            $question_state instanceof question_state_complete ||
            $question_state instanceof question_state_finished
            // $question_state instanceof question_state_needsgrading
        ) {
            $complete = get_string('completed', 'local_qrsub');
            $complete_css_class = 'text-success';
        } else {
            $complete = get_string('incomplete', 'local_qrsub');
            $complete_css_class = 'text-danger';
        }

        return array($complete, $complete_css_class);
    }

    /**
     * Build and render the nav panel.
     *
     * @return str The panel's HTML.
     */
    public function get_attempt_info_block() {
        global $OUTPUT;

        // New panel renderer.
        $renderer_attempt_nav_panel = new \local_qrsub\output\attempt_info_block($this);

        $block_attempt_nav_panel = new \block_contents();
        $block_attempt_nav_panel->attributes['id'] = 'local_qrsub_navblock';
        $block_attempt_nav_panel->attributes['role'] = 'navigation';
        $block_attempt_nav_panel->attributes['aria-labelledby'] = 'local_qrsub_navblock_title';
        $block_attempt_nav_panel->title = \html_writer::span(get_string('quiznavigation', 'local_qrsub'), '', array('id' => 'local_qrsub_navblock_title'));
        $block_attempt_nav_panel->content = $OUTPUT->render($renderer_attempt_nav_panel);

        return $block_attempt_nav_panel;
    }
}