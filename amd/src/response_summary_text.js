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
 * This script manage the response summary default text.
 *
 * @module     qtype_hybrid/response_summary_text
 * @package    qtype_hybrid
 * @copyright  2021 Knowledge One Inc.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      3.9
 */
/* eslint-disable no-console */

import $ from 'jquery';

export const init = (default_answer) => {

    // Cache the response summary textarea.
    let obj_response_summary_text = $('#page-local-qrsub-attempt .qtype_hybrid_plain');

    // Set the response summary textarea in default mode.
    response_summary_init(
        obj_response_summary_text,
        default_answer
    );

    // Define the blur action.
    obj_response_summary_text.on(
        "blur",
        {
            default_answer: default_answer,
            obj_response_summary_text: obj_response_summary_text
        },
        response_summary_blur
    );

    // Define the focus action.
    obj_response_summary_text.on(
        "focus",
        {
            default_answer: default_answer,
            obj_response_summary_text: obj_response_summary_text
        },
        response_summary_focus
    );
};

/**
 * Initialize the response summary text area.
 *
 * @param {*} obj_response_summary_text The jQuery textarea object.
 * @param {*} default_answer The default answer.
 */
const response_summary_init = (obj_response_summary_text, default_answer) => {
    // Get the current textarea value.
    let current_answer = obj_response_summary_text.val();

    // If the textarea is empty (student didn't answer yet or first time on the page) or
    // the textarea contains the default text (student hit previous button) then
    // set the textarea in default mode. Otherwise display the answer.
    if (current_answer == '' || current_answer == default_answer) {
        response_summary_set_default(obj_response_summary_text, default_answer);
    } else {
        response_summary_set_answer(obj_response_summary_text);
    }
};

/**
 * If no text is inputed by the student, set the response summary textarea in edit mode.
 *
 * @param {event} event The focus event. Contains the default text and the jQuery response summary obj.
 */
const response_summary_focus = (event) => {
    let current_answer = event.data.obj_response_summary_text.val();
    if (current_answer == event.data.default_answer) {
        response_summary_set_editmode(event.data.obj_response_summary_text);
    }
};

/**
 * If response summary is empty, set the default text.
 *
 * @param {event} event The focus event. Contains the default text and the jQuery response summary obj.
 */
const response_summary_blur = (event) => {
    let current_answer = event.data.obj_response_summary_text.val();
    if (current_answer == '') {
        response_summary_set_default(
            event.data.obj_response_summary_text,
            event.data.default_answer
        );
    }
};

/**
 * Restore the original color of the response summary so student can see their answer.
 *
 * @param {jquery} element The jQuery textarea object.
 */
const response_summary_set_answer = (element) => {
    element.css('color', '#495057');
};

/**
 * Restore the original color of the response summary and remove the default text
 * so student can input their answer.
 *
 * @param {jquery} element The jQuery textarea object.
 */
const response_summary_set_editmode = (element) => {
    element.css('color', '#495057').val('');
};

/**
 * Set the response summary color to white and add the default text.
 *
 * @param {jquery} element The jQuery textarea object.
 * @param {string} answer The default answer.
 */
const response_summary_set_default = (element, answer) => {
    element.css('color', element.css('background-color')).val(answer);
};