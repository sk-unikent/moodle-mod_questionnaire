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

require_once("../../config.php");
require_once($CFG->dirroot.'/mod/questionnaire/questions_form.php');
require_once($CFG->dirroot.'/mod/questionnaire/questionnaire.class.php');

$id     = required_param('id', PARAM_INT);                 // Course module ID
$action = optional_param('action', 'main', PARAM_ALPHA);   // Screen.
$qid    = optional_param('qid', 0, PARAM_INT);             // Question id.
$moveq  = optional_param('moveq', 0, PARAM_INT);           // Question id to move.
$delq   = optional_param('delq', 0, PARAM_INT);             // Question id to delete
$qtype  = optional_param('type_id', 0, PARAM_INT);         // Question type.

if (! $cm = get_coursemodule_from_id('questionnaire', $id)) {
    print_error('invalidcoursemodule');
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

if (! $questionnaire = $DB->get_record("questionnaire", array("id" => $cm->instance))) {
    print_error('invalidcoursemodule');
}

require_course_login($course, true, $cm);
$context = get_context_instance(CONTEXT_MODULE, $cm->id);

$url = new moodle_url($CFG->wwwroot.'/mod/questionnaire/questions.php');
$url->param('id', $id);
if ($qid) {
    $url->param('qid', $qid);
}

$PAGE->set_url($url);
$PAGE->set_context($context);

$questionnaire = new questionnaire(0, $questionnaire, $course, $cm);

if (!$questionnaire->capabilities->editquestions) {
    print_error('nopermissions', 'error', 'mod:questionnaire:edit');
}

$questionnairehasdependencies = questionnaire_has_dependencies($questionnaire->questions);
$haschildren = array();
$SESSION->questionnaire->current_tab = 'questions';
$reload = false;
$sid = $questionnaire->survey->id;
// Process form data.

// Delete question button has been pressed in questions_form AND deletion has been confirmed on the confirmation page.
if ($delq) {
    $qid = $delq;
    $sid = $questionnaire->survey->id;
    $questionnaireid = $questionnaire->id;

    // Does the question to be deleted have any child questions?
    if ($questionnairehasdependencies) {
        $haschildren = questionnaire_check_dependencies_qu ($sid, $qid);
    }

    // Need to reload questions before setting deleted question to 'y'.
    $questions = $DB->get_records('questionnaire_question', array('survey_id' => $sid, 'deleted' => 'n'), 'id');
    $DB->set_field('questionnaire_question', 'deleted', 'y', array('id' => $qid, 'survey_id' => $sid));

    // Just in case the page is refreshed (F5) after a question has been deleted.
    if (isset($questions[$qid])) {
        $select = 'survey_id = '.$sid.' AND deleted = \'n\' AND position > '.
                        $questions[$qid]->position;
    } else {
        redirect($CFG->wwwroot.'/mod/questionnaire/questions.php?id='.$questionnaire->cm->id);
    }

    if ($records = $DB->get_records_select('questionnaire_question', $select, null, 'position ASC')) {
        foreach ($records as $record) {
            $DB->set_field('questionnaire_question', 'position', $record->position-1, array('id' => $record->id));
        }
    }
    // Delete section breaks without asking for confirmation.
    $qtype = $questionnaire->questions[$qid]->type_id;
    if ($qtype == QUESPAGEBREAK && $questionnairehasdependencies) {
            $SESSION->questionnaire->validateresults = questionnaire_check_page_breaks($questionnaire);
    }
    // No need to delete responses to those "question types" which are not real questions.
    if ($qtype == QUESPAGEBREAK || $qtype == QUESSECTIONTEXT) {
        $reload = true;
    } else {
        // Delete responses to that deleted question.
        questionnaire_delete_responses($qid);

        // The deleted question was a parent, so now we must delete its child question(s).
        if (count($haschildren) !== 0) {
            foreach ($haschildren as $qid => $child) {
                // Need to reload questions first.
                $questions = $DB->get_records('questionnaire_question', array('survey_id' => $sid, 'deleted' => 'n'), 'id');
                $DB->set_field('questionnaire_question', 'deleted', 'y', array('id' => $qid, 'survey_id' => $sid));
                $select = 'survey_id = '.$sid.' AND deleted = \'n\' AND position > '.
                                $questions[$qid]->position;
                if ($records = $DB->get_records_select('questionnaire_question', $select, null, 'position ASC')) {
                    foreach ($records as $record) {
                        $DB->set_field('questionnaire_question', 'position', $record->position-1, array('id' => $record->id));
                    }
                }
                // Delete responses to that deleted question.
                questionnaire_delete_responses($qid);
            }
        }

        // If no questions left in this questionnaire, remove all attempts and responses.
        if (!$questions = $DB->get_records('questionnaire_question', array('survey_id' => $sid, 'deleted' => 'n'), 'id') ) {
            $DB->delete_records('questionnaire_response', array('survey_id' => $sid));
            $DB->delete_records('questionnaire_attempts', array('qid' => $questionnaireid));
        }
    }
    $reload = true;
}

if ($action == 'main') {
    $questions_form = new questionnaire_questions_form('questions.php', $moveq);
    $sdata = clone($questionnaire->survey);
    $sdata->sid = $questionnaire->survey->id;
    $sdata->id = $cm->id;
    if (!empty($questionnaire->questions)) {
        $pos = 1;
        foreach ($questionnaire->questions as $qidx => $question) {
            $sdata->{'pos_'.$qidx} = $pos;
            $pos++;
        }
    }
    $questions_form->set_data($sdata);

    if ($qformdata = $questions_form->get_data()) {
        // Quickforms doesn't return values for 'image' input types using 'exportValue', so we need to grab
        // it from the raw submitted data.
        $exformdata = data_submitted();

        if (isset($exformdata->movebutton)) {
            $qformdata->movebutton = $exformdata->movebutton;
        } else if (isset($exformdata->moveherebutton)) {
            $qformdata->moveherebutton = $exformdata->moveherebutton;
        } else if (isset($exformdata->editbutton)) {
            $qformdata->editbutton = $exformdata->editbutton;
        } else if (isset($exformdata->removebutton)) {
            $qformdata->removebutton = $exformdata->removebutton;
        } else if (isset($exformdata->requiredbutton)) {
            $qformdata->requiredbutton = $exformdata->requiredbutton;
        }

        // Insert a section break.
        if (isset($qformdata->removebutton)) {
            // Need to use the key, since IE returns the image position as the value rather than the specified
            // value in the <input> tag.
            $qid = key($qformdata->removebutton);
            $qtype = $questionnaire->questions[$qid]->type_id;

            // Delete section breaks without asking for confirmation.
            if ($qtype == QUESPAGEBREAK) {
                redirect($CFG->wwwroot.'/mod/questionnaire/questions.php?id='.$questionnaire->cm->id.'&amp;delq='.$qid);
            }
            if ($questionnairehasdependencies) {
                $haschildren = questionnaire_check_dependencies_qu ($questionnaire->survey->id, $qid);
            }
            if (count($haschildren) != 0) {
                $action = "confirmdelquestionparent";
            } else {
                $action = "confirmdelquestion";
            }

        } else if (isset($qformdata->editbutton)) {
            // Switch to edit question screen.
            $action = 'question';
            // Need to use the key, since IE returns the image position as the value rather than the specified
            // value in the <input> tag.
            $qid = key($qformdata->editbutton);
            $reload = true;

        } else if (isset($qformdata->requiredbutton)) {
            // Need to use the key, since IE returns the image position as the value rather than the specified
            // value in the <input> tag.

            $qid = key($qformdata->requiredbutton);
            if ($questionnaire->questions[$qid]->required == 'y') {
                $DB->set_field('questionnaire_question', 'required', 'n', array('id' => $qid, 'survey_id' => $sid));

            } else {
                $DB->set_field('questionnaire_question', 'required', 'y', array('id' => $qid, 'survey_id' => $sid));
            }

            $reload = true;

        } else if (isset($qformdata->addqbutton)) {
            if ($qformdata->type_id == 99) { // Adding section break is handled right away....
                $sql = 'SELECT MAX(position) as maxpos FROM {questionnaire_question} '.
                       'WHERE survey_id = '.$qformdata->sid.' AND deleted = \'n\'';
                if ($record = $DB->get_record_sql($sql)) {
                    $pos = $record->maxpos + 1;
                } else {
                    $pos = 1;
                }
                $question = new Object();
                $question->survey_id = $qformdata->sid;
                $question->type_id = 99;
                $question->position = $pos;
                $question->content = 'break';
                $DB->insert_record('questionnaire_question', $question);
                $reload = true;
            } else {
                // Switch to edit question screen.
                $action = 'question';
                $qtype = $qformdata->type_id;
                $qid = 0;
                $reload = true;
            }

        } else if (isset($qformdata->movebutton)) {
            // Nothing I do will seem to reload the form with new data, except for moving away from the page, so...
            redirect($CFG->wwwroot.'/mod/questionnaire/questions.php?id='.$questionnaire->cm->id.
                     '&moveq='.key($qformdata->movebutton));
            $reload = true;

        } else if (isset($qformdata->moveherebutton)) {
            // Need to use the key, since IE returns the image position as the value rather than the specified
            // value in the <input> tag.

            // No need to move question if new position = old position!
            $qpos = key($qformdata->moveherebutton);
            if ($qformdata->moveq != $qpos) {
                $questionnaire->move_question($qformdata->moveq, $qpos);
            }
            if ($questionnairehasdependencies) {
                $SESSION->questionnaire->validateresults = questionnaire_check_page_breaks($questionnaire);
            }
            // Nothing I do will seem to reload the form with new data, except for moving away from the page, so...
            redirect($CFG->wwwroot.'/mod/questionnaire/questions.php?id='.$questionnaire->cm->id);
            $reload = true;

        } else if (isset($qformdata->validate)) {
            // Validates page breaks for depend questions.
            $SESSION->questionnaire->validateresults = questionnaire_check_page_breaks($questionnaire);
            $reload = true;
        }

    }

} else if ($action == 'question') {
    if ($qid != 0) {
        $question = clone($questionnaire->questions[$qid]);
        $question->qid = $question->id;
        $question->sid = $questionnaire->survey->id;
        $question->id = $cm->id;
        $draftid_editor = file_get_submitted_draft_itemid('question');
        $content = file_prepare_draft_area($draftid_editor, $context->id, 'mod_questionnaire', 'question',
                                           $qid, array('subdirs'=>true), $question->content);
        $question->content = array('text' => $content, 'format' => FORMAT_HTML, 'itemid'=>$draftid_editor);
    } else {
        $question = new Object();
        $question->sid = $questionnaire->survey->id;
        $question->id = $cm->id;
        $question->type_id = $qtype;
        $question->type = '';
        $draftid_editor = file_get_submitted_draft_itemid('question');
        $content = file_prepare_draft_area($draftid_editor, $context->id, 'mod_questionnaire', 'question',
                                           null, array('subdirs'=>true), '');
        $question->content = array('text' => $content, 'format' => FORMAT_HTML, 'itemid'=>$draftid_editor);
    }
    $questions_form = new questionnaire_edit_question_form('questions.php');
    $questions_form->set_data($question);
    if ($questions_form->is_cancelled()) {
        // Switch to main screen.
        $action = 'main';
        $reload = true;

    } else if ($qformdata = $questions_form->get_data()) {
        // Saving question data.
        if (isset($qformdata->makecopy)) {
            $qformdata->qid = 0;
        }

        $has_choices = $questionnaire->type_has_choices();
        // THIS SECTION NEEDS TO BE MOVED OUT OF HERE - SHOULD CREATE QUESTION-SPECIFIC UPDATE FUNCTIONS.
        if ($has_choices[$qformdata->type_id]) {
            // Eliminate trailing blank lines.
            $qformdata->allchoices =  preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $qformdata->allchoices);
            // Trim to eliminate potential trailing carriage return.
            $qformdata->allchoices = trim($qformdata->allchoices);
            if (empty($qformdata->allchoices)) {
                if ($qformdata->type_id != 8) {
                    error (get_string('enterpossibleanswers', 'questionnaire'));
                } else {
                    // Add dummy blank space character for empty value.
                    $qformdata->allchoices = " ";
                }
            } else if ($qformdata->type_id == 8) {    // Rate.
                $allchoices = $qformdata->allchoices;
                $allchoices = explode("\n", $allchoices);
                $ispossibleanswer = false;
                $nbnameddegrees = 0;
                $nbvalues = 0;
                foreach ($allchoices as $choice) {
                    if ($choice) {
                        // Check for number from 1 to 3 digits, followed by the equal sign =.
                        if (preg_match("/^[0-9]{1, 3}=/", $choice)) {
                            $nbnameddegrees++;
                        } else {
                            $nbvalues++;
                            $ispossibleanswer = true;
                        }
                    }
                }
                // Add carriage return and dummy blank space character for empty value.
                if (!$ispossibleanswer) {
                    $qformdata->allchoices.= "\n ";
                }

                // Sanity checks for correct number of values in $qformdata->length.

                // Sanity check for named degrees.
                if ($nbnameddegrees && $nbnameddegrees != $qformdata->length) {
                    $qformdata->length = $nbnameddegrees;
                }
                // Sanity check for "no duplicate choices"".
                if ($qformdata->precise == 2 && ($qformdata->length > $nbvalues || !$qformdata->length)) {
                    $qformdata->length = $nbvalues;
                }
            } else if ($qformdata->type_id == QUESCHECK) {
                // Sanity checks for min and max checked boxes.
                $allchoices = $qformdata->allchoices;
                $allchoices = explode("\n", $allchoices);
                $nbvalues = count($allchoices);
                if ($qformdata->length > $nbvalues) {
                    $qformdata->length = $nbvalues;
                }
                if ($qformdata->precise > $nbvalues) {
                    $qformdata->precise = $nbvalues;
                }
                $qformdata->precise = max($qformdata->length, $qformdata->precise);
            }
        }

        $dependency = array();
        if (isset($qformdata->dependquestion) && $qformdata->dependquestion != 0) {
            $dependency = explode(",", $qformdata->dependquestion);
            $qformdata->dependquestion = $dependency[0];
            $qformdata->dependchoice = $dependency[1];
        }

        if (!empty($qformdata->qid)) {

            // Update existing question.
            // Handle any attachments in the content.
            $qformdata->itemid  = $qformdata->content['itemid'];
            $qformdata->format  = $qformdata->content['format'];
            $qformdata->content = $qformdata->content['text'];
            $qformdata->content = file_save_draft_area_files($qformdata->itemid, $context->id, 'mod_questionnaire', 'question',
                                                             $qformdata->qid, array('subdirs'=>true), $qformdata->content);

            $fields = array('name', 'type_id', 'length', 'precise', 'required', 'content', 'dependquestion', 'dependchoice');
            $question_record = new Object();
            $question_record->id = $qformdata->qid;
            foreach ($fields as $f) {
                if (isset($qformdata->$f)) {
                    $question_record->$f = trim($qformdata->$f);
                }
            }
            $result = $DB->update_record('questionnaire_question', $question_record);
            if ($dependency) {
                questionnaire_check_page_breaks($questionnaire);
            }
        } else {
            // Create new question:
            // set the position to the end.
            $sql = 'SELECT MAX(position) as maxpos FROM {questionnaire_question} '.
                   'WHERE survey_id = '.$qformdata->sid.' AND deleted = \'n\'';
            if ($record = $DB->get_record_sql($sql)) {
                $qformdata->position = $record->maxpos + 1;
            } else {
                $qformdata->position = 1;
            }

            // Need to update any image content after the question is created, so create then update the content.
            $qformdata->survey_id = $qformdata->sid;
            $fields = array('survey_id', 'name', 'type_id', 'length', 'precise', 'required', 'position',
                            'dependquestion', 'dependchoice');
            $question_record = new Object();
            foreach ($fields as $f) {
                if (isset($qformdata->$f)) {
                    $question_record->$f = trim($qformdata->$f);
                }
            }
            $question_record->content = '';
            $qformdata->qid = $DB->insert_record('questionnaire_question', $question_record);

            // Handle any attachments in the content.
            $qformdata->itemid  = $qformdata->content['itemid'];
            $qformdata->format  = $qformdata->content['format'];
            $qformdata->content = $qformdata->content['text'];
            $content            = file_save_draft_area_files($qformdata->itemid, $context->id, 'mod_questionnaire', 'question',
                                                             $qformdata->qid, array('subdirs'=>true), $qformdata->content);
            $result = $DB->set_field('questionnaire_question', 'content', $content, array('id' => $qformdata->qid));
            if ($dependency) {
                questionnaire_check_page_breaks($questionnaire);
            }
        }

        // UPDATE or INSERT rows for each of the question choices for this question.
        if ($has_choices[$qformdata->type_id]) {
            $cidx = 0;
            if (isset($question->choices) && !isset($qformdata->makecopy)) {
                $oldcount = count($question->choices);
                $echoice = reset($question->choices);
                $ekey = key($question->choices);
            } else {
                $oldcount = 0;
            }

            $newchoices = explode("\n", $qformdata->allchoices);
            $nidx = 0;
            $newcount = count($newchoices);

            while (($nidx < $newcount) && ($cidx < $oldcount)) {
                if ($newchoices[$nidx] != $echoice->content) {
                    $newchoices[$nidx] = trim ($newchoices[$nidx]);
                    $result = $DB->set_field('questionnaire_quest_choice', 'content', $newchoices[$nidx], array('id' => $ekey));
                    $r = preg_match_all("/^(\d{1, 2})(=.*)$/", $newchoices[$nidx], $matches);
                    // This choice has been attributed a "score value" OR this is a rate question type.
                    if ($r) {
                        $new_score = $matches[1][0];
                        $result = $DB->set_field('questionnaire_quest_choice', 'value', $new_score, array('id' => $ekey));
                    } else {     // No score value for this choice.
                        $result = $DB->set_field('questionnaire_quest_choice', 'value', null, array('id' => $ekey));
                    }
                }
                $nidx++;
                $echoice = next($question->choices);
                $ekey = key($question->choices);
                $cidx++;
            }

            while ($nidx < $newcount) {
                // New choices...
                $choice_record = new Object();
                $choice_record->question_id = $qformdata->qid;
                $choice_record->content = trim($newchoices[$nidx]);
                $r = preg_match_all("/^(\d{1, 2})(=.*)$/", $choice_record->content, $matches);
                // This choice has been attributed a "score value" OR this is a rate question type.
                if ($r) {
                    $choice_record->value = $matches[1][0];
                }
                $result = $DB->insert_record('questionnaire_quest_choice', $choice_record);
                $nidx++;
            }

            while ($cidx < $oldcount) {
                $result = $DB->delete_records('questionnaire_quest_choice', array('id' => $ekey));
                $echoice = next($question->choices);
                $ekey = key($question->choices);
                $cidx++;
            }
        }
        // Make these field values 'sticky' for further new questions.
        if (!isset($qformdata->required)) {
            $qformdata->required = 'n';
        }
        $SESSION->questionnaire->required =  $qformdata->required;
        $SESSION->questionnaire->type_id =  $qformdata->type_id;
        // Switch to main screen.
        $action = 'main';
        $reload = true;
    }
    $questions_form->set_data($question);
}

// Reload the form data if called for...
if ($reload) {
    unset($questions_form);
    $questionnaire = new questionnaire($questionnaire->id, null, $course, $cm);
    if ($action == 'main') {
        $questions_form = new questionnaire_questions_form('questions.php', $moveq);
        $sdata = clone($questionnaire->survey);
        $sdata->sid = $questionnaire->survey->id;
        $sdata->id = $cm->id;
        if (!empty($questionnaire->questions)) {
            $pos = 1;
            foreach ($questionnaire->questions as $qidx => $question) {
                $sdata->{'pos_'.$qidx} = $pos;
                $pos++;
            }
        }
        $questions_form->set_data($sdata);
    } else if ($action == 'question') {
        if ($qid != 0) {
            $question = clone($questionnaire->questions[$qid]);
            $question->qid = $question->id;
            $question->sid = $questionnaire->survey->id;
            $question->id = $cm->id;
            $draftid_editor = file_get_submitted_draft_itemid('question');
            $content = file_prepare_draft_area($draftid_editor, $context->id, 'mod_questionnaire', 'question',
                                               $qid, array('subdirs'=>true), $question->content);
            $question->content = array('text' => $content, 'format' => FORMAT_HTML, 'itemid'=>$draftid_editor);
        } else {
            $question = new Object();
            $question->sid = $questionnaire->survey->id;
            $question->id = $cm->id;
            $question->type_id = $qtype;
            $question->type = $DB->get_field('questionnaire_question_type', 'type', array('id' => $qtype));
            $draftid_editor = file_get_submitted_draft_itemid('question');
            $content = file_prepare_draft_area($draftid_editor, $context->id, 'mod_questionnaire', 'question',
                                               null, array('subdirs'=>true), '');
            $question->content = array('text' => $content, 'format' => FORMAT_HTML, 'itemid'=>$draftid_editor);
        }
        $questions_form = new questionnaire_edit_question_form('questions.php');
        $questions_form->set_data($question);
    }
}

// Print the page header.
if ($action == 'question') {
    if (isset($question->qid)) {
        $streditquestion = get_string('editquestion', 'questionnaire', questionnaire_get_type($question->type_id));
    } else {
        $streditquestion = get_string('addnewquestion', 'questionnaire', questionnaire_get_type($question->type_id));
    }
} else {
    $streditquestion = get_string('managequestions', 'questionnaire');
}

$PAGE->set_title($streditquestion);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add($streditquestion);
echo $OUTPUT->header();
require('tabs.php');

if ($action == "confirmdelquestion" || $action == "confirmdelquestionparent") {

    $qid = key($qformdata->removebutton);
    $question = $questionnaire->questions[$qid];
    $qtype = $question->type_id;

    // Count responses already saved for that question.
    $countresps = 0;
    if ($qtype != QUESSECTIONTEXT) {
        $sql = 'SELECT response_table FROM {questionnaire_question_type} WHERE typeid = '.$qtype;
        if ($resptable = $DB->get_record_sql($sql)) {
            $sql = 'SELECT COUNT(id) FROM {questionnaire_'.$resptable->response_table.'} WHERE question_id ='.$qid;
            $countresps = $DB->count_records_sql($sql);
        }
    }

    // Needed to print potential media in question text.
    $qcontent = format_text(file_rewrite_pluginfile_urls($question->content, 'pluginfile.php',
                    $question->context->id, 'mod_questionnaire', 'question', $question->id), FORMAT_HTML);

    $num = get_string('num', 'questionnaire');
    $pos = $question->position;
    $msg = '<div class="warning centerpara"><p>'.get_string('confirmdelquestion', 'questionnaire', $num.$pos).'</p>';
    if ($countresps !== 0) {
        $msg .= '<p>'.get_string('confirmdelquestionresps', 'questionnaire', $countresps).'</p>';
    }
    $msg .= '</div>';
    $msg .= $num.$pos.'<div class="qn-question">'.$qcontent.'</div>';
    $args = "id={$questionnaire->cm->id}";
    $urlno = new moodle_url("/mod/questionnaire/questions.php?{$args}");
    $args .= "&delq={$qid}";
    $urlyes = new moodle_url("/mod/questionnaire/questions.php?{$args}");
    $buttonyes = new single_button($urlyes, get_string('yes'));
    $buttonno = new single_button($urlno, get_string('no'));
    if ($action == "confirmdelquestionparent") {
        $str_num = get_string('num', 'questionnaire');
        $qid = key($qformdata->removebutton);
        $msg .= '<div class="warning">'.get_string('confirmdelchildren', 'questionnaire').'</div><br />';
        foreach ($haschildren as $child) {
            $msg .= $str_num.$child['position'].'<span class="qdepend"><strong>'.
                            get_string('dependquestion', 'questionnaire').'</strong>'.
                            ' ('.$str_num.$child['parentposition'].') '.
                            '&nbsp;:&nbsp;'.$child['parent'].'</span>'.
                            '<div class="qn-question">'.
                            $child['content'].
                            '</div>';
        }
    }
    echo $OUTPUT->confirm($msg, $buttonyes, $buttonno);

} else {
    $questions_form->display();
}
echo $OUTPUT->footer();
