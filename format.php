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
 * Code for exporting questions as Moodle XML.
 *
 * @package    qformat_xmlrgx
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/xmlize.php');
if (!class_exists('qformat_default')) {
    // This is ugly, but this class is also (ab)used by mod/lesson, which defines
    // a different base class in mod/lesson/format.php. Thefore, we can only
    // include the proper base class conditionally like this. (We have to include
    // the base class like this, otherwise it breaks third-party question types.)
    // This may be reviewd, and a better fix found one day.
    require_once($CFG->dirroot . '/question/format.php');
}


/**
 * Importer for Moodle XML question format.
 *
 * See http://docs.moodle.org/en/Moodle_XML_format for a description of the format.
 *
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qformat_xmlrgx extends qformat_default {

    /** @var array Array of files for question answers. */
    protected $answerfiles = [];

    /** @var array Array of files for feedback to question answers. */
    protected $feedbackfiles = [];

    public function provide_import() {
        return true;
    }

    public function provide_export() {
        return false;
    }

    public function mime_type() {
        return 'application/xml';
    }

    /**
     * Validate the given file.
     *
     * For more expensive or detailed integrity checks.
     *
     * @param stored_file $file the file to check
     * @return string the error message that occurred while validating the given file
     */
    public function validate_file(stored_file $file): string {
        return $this->validate_is_utf8_file($file);
    }

    // IMPORT FUNCTIONS START HERE.

    /**
     * Translate human readable format name
     * into internal Moodle code number
     * Note the reverse function is called get_format.
     * @param string name format name from xml file
     * @return int Moodle format code
     */
    public function trans_format($name) {
        $name = trim($name);

        if ($name == 'moodle_auto_format') {
            return FORMAT_MOODLE;
        } else if ($name == 'html') {
            return FORMAT_HTML;
        } else if ($name == 'plain_text') {
            return FORMAT_PLAIN;
        } else if ($name == 'wiki_like') {
            return FORMAT_WIKI;
        } else if ($name == 'markdown') {
            return FORMAT_MARKDOWN;
        } else {
            debugging("Unrecognised text format '{$name}' in the import file. Assuming 'html'.");
            return FORMAT_HTML;
        }
    }

    /**
     * Translate human readable single answer option
     * to internal code number
     * @param string name true/false
     * @return int internal code number
     */
    public function trans_single($name) {
        $name = trim($name);
        if ($name == "false" || !$name) {
            return 0;
        } else {
            return 1;
        }
    }

    /**
     * process text string from xml file
     * @param array $text bit of xml tree after ['text']
     * @return string processed text.
     */
    public function import_text($text) {
        // Quick sanity check.
        if (empty($text)) {
            return '';
        }
        $data = $text[0]['#'];
        return trim($data);
    }

    /**
     * return the value of a node, given a path to the node
     * if it doesn't exist return the default value
     * @param array xml data to read
     * @param array path path to node expressed as array
     * @param mixed default
     * @param bool istext process as text
     * @param string error if set value must exist, return false and issue message if not
     * @return mixed value
     */
    public function getpath($xml, $path, $default, $istext=false, $error='') {
        foreach ($path as $index) {
            if (!isset($xml[$index])) {
                if (!empty($error)) {
                    $this->error($error);
                    return false;
                } else {
                    return $default;
                }
            }

            $xml = $xml[$index];
        }

        if ($istext) {
            if (!is_string($xml)) {
                $this->error(get_string('invalidxml', 'qformat_xmlrgx'));
                return false;
            }
            $xml = trim($xml);
        }

        return $xml;
    }

    public function import_text_with_files($data, $path, $defaultvalue = '', $defaultformat = 'html') {
        $field  = [];
        $field['text'] = $this->getpath($data,
                array_merge($path, ['#', 'text', 0, '#']), $defaultvalue, true);
        $field['format'] = $this->trans_format($this->getpath($data,
                array_merge($path, ['@', 'format']), $defaultformat));
        $itemid = $this->import_files_as_draft($this->getpath($data,
                array_merge($path, ['#', 'file']), [], false));
        if (!empty($itemid)) {
            $field['itemid'] = $itemid;
        }
        return $field;
    }

    public function import_files_as_draft($xml) {
        global $USER;
        if (empty($xml)) {
            return null;
        }
        $fs = get_file_storage();
        $itemid = file_get_unused_draft_itemid();
        $filepaths = [];
        foreach ($xml as $file) {
            $filename = $this->getpath($file, ['@', 'name'], '', true);
            $filepath = $this->getpath($file, ['@', 'path'], '/', true);
            $fullpath = $filepath . $filename;
            if (in_array($fullpath, $filepaths)) {
                debugging('Duplicate file in XML: ' . $fullpath, DEBUG_DEVELOPER);
                continue;
            }
            $filerecord = [
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $itemid,
                'filepath'  => $filepath,
                'filename'  => $filename,
            ];
            $fs->create_file_from_string($filerecord, base64_decode($file['#']));
            $filepaths[] = $fullpath;
        }
        return $itemid;
    }

    /**
     * import parts of question common to all types
     * @param $question array question question array from xml tree
     * @return object question object
     */
    public function import_headers($question) {
        global $USER;

        // This routine initialises the question object.
        $qo = $this->defaultquestion();

        // Question name.
        $qo->name = $this->clean_question_name($this->getpath($question,
                ['#', 'name', 0, '#', 'text', 0, '#'], '', true,
                get_string('xmlimportnoname', 'qformat_xmlrgx')));
                echo '$qo->name '.$qo->name;die;
        $questiontext = $this->import_text_with_files($question,
                ['#', 'questiontext', 0]);
        $qo->questiontext = $questiontext['text'];
        $qo->questiontextformat = $questiontext['format'];
        if (!empty($questiontext['itemid'])) {
            $qo->questiontextitemid = $questiontext['itemid'];
        }
        // Backwards compatibility, deal with the old image tag.
        $filedata = $this->getpath($question, ['#', 'image_base64', '0', '#'], null, false);
        $filename = $this->getpath($question, ['#', 'image', '0', '#'], null, false);
        if ($filedata && $filename) {
            $fs = get_file_storage();
            if (empty($qo->questiontextitemid)) {
                $qo->questiontextitemid = file_get_unused_draft_itemid();
            }
            $filename = clean_param(str_replace('/', '_', $filename), PARAM_FILE);
            $filerecord = [
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $qo->questiontextitemid,
                'filepath'  => '/',
                'filename'  => $filename,
            ];
            $fs->create_file_from_string($filerecord, base64_decode($filedata));
            $qo->questiontext .= ' <img src="@@PLUGINFILE@@/' . $filename . '" />';
        }

        $qo->idnumber = $this->getpath($question, ['#', 'idnumber', 0, '#'], null);

        // Restore files in generalfeedback.
        $generalfeedback = $this->import_text_with_files($question,
                ['#', 'generalfeedback', 0], '', $this->get_format($qo->questiontextformat));
        $qo->generalfeedback = $generalfeedback['text'];
        $qo->generalfeedbackformat = $generalfeedback['format'];
        if (!empty($generalfeedback['itemid'])) {
            $qo->generalfeedbackitemid = $generalfeedback['itemid'];
        }

        $qo->defaultmark = $this->getpath($question,
                ['#', 'defaultgrade', 0, '#'], $qo->defaultmark);
        $qo->penalty = $this->getpath($question,
                ['#', 'penalty', 0, '#'], $qo->penalty);

        // Fix problematic rounding from old files.
        if (abs($qo->penalty - 0.3333333) < 0.005) {
            $qo->penalty = 0.3333333;
        }

        // Read the question tags.
        $this->import_question_tags($qo, $question);

        return $qo;
    }

    /**
     * Import the common parts of a single answer
     * @param array answer xml tree for single answer
     * @param bool $withanswerfiles if true, the answers are HTML (or $defaultformat)
     *      and so may contain files, otherwise the answers are plain text.
     * @param array Default text format for the feedback, and the answers if $withanswerfiles
     *      is true.
     * @return object answer object
     */
    public function import_answer($answer, $withanswerfiles = false, $defaultformat = 'html') {
        $ans = new stdClass();

        if ($withanswerfiles) {
            $ans->answer = $this->import_text_with_files($answer, [], '', $defaultformat);
        } else {
            $ans->answer = [];
            $ans->answer['text']   = $this->getpath($answer, ['#', 'text', 0, '#'], '', true);
            $ans->answer['format'] = FORMAT_PLAIN;
        }

        $ans->feedback = $this->import_text_with_files($answer, ['#', 'feedback', 0], '', $defaultformat);

        $ans->fraction = $this->getpath($answer, ['@', 'fraction'], 0) / 100;

        return $ans;
    }

    /**
     * Import the common overall feedback fields.
     * @param object $question the part of the XML relating to this question.
     * @param object $qo the question data to add the fields to.
     * @param bool $withshownumpartscorrect include the shownumcorrect field.
     */
    public function import_combined_feedback($qo, $questionxml, $withshownumpartscorrect = false) {
        $fields = ['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'];
        foreach ($fields as $field) {
            $qo->$field = $this->import_text_with_files($questionxml,
                    ['#', $field, 0], '', $this->get_format($qo->questiontextformat));
        }

        if ($withshownumpartscorrect) {
            $qo->shownumcorrect = array_key_exists('shownumcorrect', $questionxml['#']);

            // Backwards compatibility.
            if (array_key_exists('correctresponsesfeedback', $questionxml['#'])) {
                $qo->shownumcorrect = $this->trans_single($this->getpath($questionxml,
                        ['#', 'correctresponsesfeedback', 0, '#'], 1));
            }
        }
    }

    /**
     * Import a question hint
     * @param array $hintxml hint xml fragment.
     * @param string $defaultformat the text format to assume for hints that do not specify.
     * @return object hint for storing in the database.
     */
    public function import_hint($hintxml, $defaultformat) {
        $hint = new stdClass();
        if (array_key_exists('hintcontent', $hintxml['#'])) {
            // Backwards compatibility.

            $hint->hint = $this->import_text_with_files($hintxml,
                    ['#', 'hintcontent', 0], '', $defaultformat);

            $hint->shownumcorrect = $this->getpath($hintxml,
                    ['#', 'statenumberofcorrectresponses', 0, '#'], 0);
            $hint->clearwrong = $this->getpath($hintxml,
                    ['#', 'clearincorrectresponses', 0, '#'], 0);
            $hint->options = $this->getpath($hintxml,
                    ['#', 'showfeedbacktoresponses', 0, '#'], 0);

            return $hint;
        }
        $hint->hint = $this->import_text_with_files($hintxml, [], '', $defaultformat);
        $hint->shownumcorrect = array_key_exists('shownumcorrect', $hintxml['#']);
        $hint->clearwrong = array_key_exists('clearwrong', $hintxml['#']);
        $hint->options = $this->getpath($hintxml, ['#', 'options', 0, '#'], '', true);

        return $hint;
    }

    /**
     * Import all the question hints
     *
     * @param object $qo the question data that is being constructed.
     * @param array $questionxml The xml representing the question.
     * @param bool $withparts whether the extra fields relating to parts should be imported.
     * @param bool $withoptions whether the extra options field should be imported.
     * @param string $defaultformat the text format to assume for hints that do not specify.
     * @return array of objects representing the hints in the file.
     */
    public function import_hints($qo, $questionxml, $withparts = false,
            $withoptions = false, $defaultformat = 'html') {
        if (!isset($questionxml['#']['hint'])) {
            return;
        }

        foreach ($questionxml['#']['hint'] as $hintxml) {
            $hint = $this->import_hint($hintxml, $defaultformat);
            $qo->hint[] = $hint->hint;

            if ($withparts) {
                $qo->hintshownumcorrect[] = $hint->shownumcorrect;
                $qo->hintclearwrong[] = $hint->clearwrong;
            }

            if ($withoptions) {
                $qo->hintoptions[] = $hint->options;
            }
        }
    }

    /**
     * Import all the question tags
     *
     * @param object $qo the question data that is being constructed.
     * @param array $questionxml The xml representing the question.
     * @return array of objects representing the tags in the file.
     */
    public function import_question_tags($qo, $questionxml) {
        global $CFG;

        if (core_tag_tag::is_enabled('core_question', 'question')) {

            $qo->tags = [];
            if (!empty($questionxml['#']['tags'][0]['#']['tag'])) {
                foreach ($questionxml['#']['tags'][0]['#']['tag'] as $tagdata) {
                    $qo->tags[] = $this->getpath($tagdata, ['#', 'text', 0, '#'], '', true);
                }
            }

            $qo->coursetags = [];
            if (!empty($questionxml['#']['coursetags'][0]['#']['tag'])) {
                foreach ($questionxml['#']['coursetags'][0]['#']['tag'] as $tagdata) {
                    $qo->coursetags[] = $this->getpath($tagdata, ['#', 'text', 0, '#'], '', true);
                }
            }
        }
    }

    /**
     * Import files from a node in the XML.
     * @param array $xml an array of <file> nodes from the the parsed XML.
     * @return array of things representing files - in the form that save_question expects.
     */
    public function import_files($xml) {
        $files = [];
        foreach ($xml as $file) {
            $data = new stdClass();
            $data->content = $file['#'];
            $data->encoding = $file['@']['encoding'];
            $data->name = $file['@']['name'];
            $files[] = $data;
        }
        return $files;
    }

    /**
     * import multiple choice question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_multichoice($question) {
        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to multichoice.
        $qo->qtype = 'multichoice';
        $single = $this->getpath($question, ['#', 'single', 0, '#'], 'true');
        $qo->single = $this->trans_single($single);
        $shuffleanswers = $this->getpath($question,
                ['#', 'shuffleanswers', 0, '#'], 'false');
        $qo->answernumbering = $this->getpath($question,
                ['#', 'answernumbering', 0, '#'], 'abc');
        $qo->shuffleanswers = $this->trans_single($shuffleanswers);
        $qo->showstandardinstruction = $this->getpath($question,
            ['#', 'showstandardinstruction', 0, '#'], '1');

        // There was a time on the 1.8 branch when it could output an empty
        // answernumbering tag, so fix up any found.
        if (empty($qo->answernumbering)) {
            $qo->answernumbering = 'abc';
        }

        // Run through the answers.
        $answers = $question['#']['answer'];
        $acount = 0;
        foreach ($answers as $answer) {
            $ans = $this->import_answer($answer, true, $this->get_format($qo->questiontextformat));
            $qo->answer[$acount] = $ans->answer;
            $qo->fraction[$acount] = $ans->fraction;
            $qo->feedback[$acount] = $ans->feedback;
            ++$acount;
        }

        $this->import_combined_feedback($qo, $question, true);
        $this->import_hints($qo, $question, true, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * Import cloze type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_multianswer($question) {
        global $USER;
        question_bank::get_qtype('multianswer');

        $questiontext = $this->import_text_with_files($question,
                ['#', 'questiontext', 0]);
        $qo = qtype_multianswer_extract_question($questiontext);
        $errors = qtype_multianswer_validate_question($qo);
        if ($errors) {
            $this->error(get_string('invalidmultianswerquestion', 'qtype_multianswer', implode(' ', $errors)));
            return null;
        }

        // Header parts particular to multianswer.
        $qo->qtype = 'multianswer';

        // Only set the course if the data is available.
        if (isset($this->course)) {
            $qo->course = $this->course;
        }
        if (isset($question['#']['name'])) {
            $qo->name = $this->clean_question_name($this->import_text($question['#']['name'][0]['#']['text']));
        } else {
            $qo->name = $this->create_default_question_name($qo->questiontext['text'],
                    get_string('questionname', 'question'));
        }
        $qo->questiontextformat = $questiontext['format'];
        $qo->questiontext = $qo->questiontext['text'];
        if (!empty($questiontext['itemid'])) {
            $qo->questiontextitemid = $questiontext['itemid'];
        }

        // Backwards compatibility, deal with the old image tag.
        $filedata = $this->getpath($question, ['#', 'image_base64', '0', '#'], null, false);
        $filename = $this->getpath($question, ['#', 'image', '0', '#'], null, false);
        if ($filedata && $filename) {
            $fs = get_file_storage();
            if (empty($qo->questiontextitemid)) {
                $qo->questiontextitemid = file_get_unused_draft_itemid();
            }
            $filename = clean_param(str_replace('/', '_', $filename), PARAM_FILE);
            $filerecord = [
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $qo->questiontextitemid,
                'filepath'  => '/',
                'filename'  => $filename,
            ];
            $fs->create_file_from_string($filerecord, base64_decode($filedata));
            $qo->questiontext .= ' <img src="@@PLUGINFILE@@/' . $filename . '" />';
        }

        $qo->idnumber = $this->getpath($question, ['#', 'idnumber', 0, '#'], null);

        // Restore files in generalfeedback.
        $generalfeedback = $this->import_text_with_files($question,
                ['#', 'generalfeedback', 0], '', $this->get_format($qo->questiontextformat));
        $qo->generalfeedback = $generalfeedback['text'];
        $qo->generalfeedbackformat = $generalfeedback['format'];
        if (!empty($generalfeedback['itemid'])) {
            $qo->generalfeedbackitemid = $generalfeedback['itemid'];
        }

        $qo->penalty = $this->getpath($question,
                ['#', 'penalty', 0, '#'], $this->defaultquestion()->penalty);
        // Fix problematic rounding from old files.
        if (abs($qo->penalty - 0.3333333) < 0.005) {
            $qo->penalty = 0.3333333;
        }

        $this->import_hints($qo, $question, true, false, $this->get_format($qo->questiontextformat));
        $this->import_question_tags($qo, $question);

        return $qo;
    }


    /**
     * Import cloze type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_multianswerrgx($question) {

        global $USER;
        question_bank::get_qtype('multianswerrgx');
        // Provide 2 possibilities for import according to the export method used!
        if ($this->key_exists_recursive('questiontextrgx', $question)) {
            $questiontext = $this->import_text_with_files($question,
                ['#', 'questiontextrgx', 0]);
        } else {
            $questiontext = $this->import_text_with_files($question,
                ['#', 'questiontext', 0]);
        }
        $qo = qtype_multianswerrgx_extract_question($questiontext);
        $errors = qtype_multianswerrgx_validate_question($qo);
        if ($errors) {
            $this->error(get_string('invalidmultianswerquestion', 'qtype_multianswerrgx', implode(' ', $errors)));
            return null;
        }

        // Header parts particular to multianswerrgx.
        $qo->qtype = 'multianswerrgx';

        // Only set the course if the data is available.
        if (isset($this->course)) {
            $qo->course = $this->course;
        }
        if (isset($question['#']['name'])) {
            $qo->name = $this->clean_question_name($this->import_text($question['#']['name'][0]['#']['text']));
        } else {
            $qo->name = $this->create_default_question_name($qo->questiontext['text'],
                    get_string('questionname', 'question'));
        }
        $qo->questiontextformat = $questiontext['format'];
        $qo->questiontext = $qo->questiontext['text'];
        if (!empty($questiontext['itemid'])) {
            $qo->questiontextitemid = $questiontext['itemid'];
        }

        // Backwards compatibility, deal with the old image tag.
        $filedata = $this->getpath($question, ['#', 'image_base64', '0', '#'], null, false);
        $filename = $this->getpath($question, ['#', 'image', '0', '#'], null, false);
        if ($filedata && $filename) {
            $fs = get_file_storage();
            if (empty($qo->questiontextitemid)) {
                $qo->questiontextitemid = file_get_unused_draft_itemid();
            }
            $filename = clean_param(str_replace('/', '_', $filename), PARAM_FILE);
            $filerecord = [
                'contextid' => context_user::instance($USER->id)->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $qo->questiontextitemid,
                'filepath'  => '/',
                'filename'  => $filename,
            ];
            $fs->create_file_from_string($filerecord, base64_decode($filedata));
            $qo->questiontext .= ' <img src="@@PLUGINFILE@@/' . $filename . '" />';
        }

        $qo->idnumber = $this->getpath($question, ['#', 'idnumber', 0, '#'], null);

        // Restore files in generalfeedback.
        $generalfeedback = $this->import_text_with_files($question,
                ['#', 'generalfeedback', 0], '', $this->get_format($qo->questiontextformat));
        $qo->generalfeedback = $generalfeedback['text'];
        $qo->generalfeedbackformat = $generalfeedback['format'];
        if (!empty($generalfeedback['itemid'])) {
            $qo->generalfeedbackitemid = $generalfeedback['itemid'];
        }

        $qo->penalty = $this->getpath($question,
                ['#', 'penalty', 0, '#'], $this->defaultquestion()->penalty);
        // Fix problematic rounding from old files.
        if (abs($qo->penalty - 0.3333333) < 0.005) {
            $qo->penalty = 0.3333333;
        }

        $this->import_hints($qo, $question, true, false, $this->get_format($qo->questiontextformat));
        $this->import_question_tags($qo, $question);

        return $qo;
    }

    /**
     * Import true/false type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_truefalse($question) {
        // Get common parts.
        global $OUTPUT;
        $qo = $this->import_headers($question);

        // Header parts particular to true/false.
        $qo->qtype = 'truefalse';

        // In the past, it used to be assumed that the two answers were in the file
        // true first, then false. Howevever that was not always true. Now, we
        // try to match on the answer text, but in old exports, this will be a localised
        // string, so if we don't find true or false, we fall back to the old system.
        $first = true;
        $warning = false;
        foreach ($question['#']['answer'] as $answer) {
            $answertext = $this->getpath($answer,
                    ['#', 'text', 0, '#'], '', true);
            $feedback = $this->import_text_with_files($answer,
                    ['#', 'feedback', 0], '', $this->get_format($qo->questiontextformat));

            if ($answertext != 'true' && $answertext != 'false') {
                // Old style file, assume order is true/false.
                $warning = true;
                if ($first) {
                    $answertext = 'true';
                } else {
                    $answertext = 'false';
                }
            }

            if ($answertext == 'true') {
                $qo->answer = ($answer['@']['fraction'] == 100);
                $qo->correctanswer = $qo->answer;
                $qo->feedbacktrue = $feedback;
            } else {
                $qo->answer = ($answer['@']['fraction'] != 100);
                $qo->correctanswer = $qo->answer;
                $qo->feedbackfalse = $feedback;
            }
            $first = false;
        }

        if ($warning) {
            $a = new stdClass();
            $a->questiontext = $qo->questiontext;
            $a->answer = get_string($qo->correctanswer ? 'true' : 'false', 'qtype_truefalse');
            echo $OUTPUT->notification(get_string('truefalseimporterror', 'qformat_xmlrgx', $a));
        }

        $this->import_hints($qo, $question, false, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * Import short answer type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_shortanswer($question) {
        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to shortanswer.
        $qo->qtype = 'shortanswer';

        // Get usecase.
        $qo->usecase = $this->getpath($question, ['#', 'usecase', 0, '#'], $qo->usecase);

        // Run through the answers.
        $answers = $question['#']['answer'];
        $acount = 0;
        foreach ($answers as $answer) {
            $ans = $this->import_answer($answer, false, $this->get_format($qo->questiontextformat));
            $qo->answer[$acount] = $ans->answer['text'];
            $qo->fraction[$acount] = $ans->fraction;
            $qo->feedback[$acount] = $ans->feedback;
            ++$acount;
        }

        $this->import_hints($qo, $question, false, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * Import description type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_description($question) {
        // Get common parts.
        $qo = $this->import_headers($question);
        // Header parts particular to shortanswer.
        $qo->qtype = 'description';
        $qo->defaultmark = 0;
        $qo->length = 0;
        return $qo;
    }

    /**
     * Import numerical type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_numerical($question) {
        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to numerical.
        $qo->qtype = 'numerical';

        // Get answers array.
        $answers = $question['#']['answer'];
        $qo->answer = [];
        $qo->feedback = [];
        $qo->fraction = [];
        $qo->tolerance = [];
        foreach ($answers as $answer) {
            // Answer outside of <text> is deprecated.
            $obj = $this->import_answer($answer, false, $this->get_format($qo->questiontextformat));
            $qo->answer[] = $obj->answer['text'];
            if (empty($qo->answer)) {
                $qo->answer = '*';
            }
            $qo->feedback[]  = $obj->feedback;
            $qo->tolerance[] = $this->getpath($answer, ['#', 'tolerance', 0, '#'], 0);

            // Fraction as a tag is deprecated.
            $fraction = $this->getpath($answer, ['@', 'fraction'], 0) / 100;
            $qo->fraction[] = $this->getpath($answer,
                    ['#', 'fraction', 0, '#'], $fraction); // Deprecated.
        }

        // Get the units array.
        $qo->unit = [];
        $units = $this->getpath($question, ['#', 'units', 0, '#', 'unit'], []);
        if (!empty($units)) {
            $qo->multiplier = [];
            foreach ($units as $unit) {
                $qo->multiplier[] = $this->getpath($unit, ['#', 'multiplier', 0, '#'], 1);
                $qo->unit[] = $this->getpath($unit, ['#', 'unit_name', 0, '#'], '', true);
            }
        }
        $qo->unitgradingtype = $this->getpath($question, ['#', 'unitgradingtype', 0, '#'], 0);
        $qo->unitpenalty = $this->getpath($question, ['#', 'unitpenalty', 0, '#'], 0.1);
        $qo->showunits = $this->getpath($question, ['#', 'showunits', 0, '#'], null);
        $qo->unitsleft = $this->getpath($question, ['#', 'unitsleft', 0, '#'], 0);
        $qo->instructions['text'] = '';
        $qo->instructions['format'] = FORMAT_HTML;
        $instructions = $this->getpath($question, ['#', 'instructions'], []);
        if (!empty($instructions)) {
            $qo->instructions = $this->import_text_with_files($instructions,
                    ['0'], '', $this->get_format($qo->questiontextformat));
        }

        if (is_null($qo->showunits)) {
            // Set a good default, depending on whether there are any units defined.
            if (empty($qo->unit)) {
                $qo->showunits = 3; // This is qtype_numerical::UNITNONE, but we cannot refer to that constant here.
            } else {
                $qo->showunits = 0; // This is qtype_numerical::UNITOPTIONAL, but we cannot refer to that constant here.
            }
        }

        $this->import_hints($qo, $question, false, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * Import matching type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_match($question) {
        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to matching.
        $qo->qtype = 'match';
        $qo->shuffleanswers = $this->trans_single($this->getpath($question,
                ['#', 'shuffleanswers', 0, '#'], 1));

        // Run through subquestions.
        $qo->subquestions = [];
        $qo->subanswers = [];
        foreach ($question['#']['subquestion'] as $subqxml) {
            $qo->subquestions[] = $this->import_text_with_files($subqxml,
                    [], '', $this->get_format($qo->questiontextformat));

            $answers = $this->getpath($subqxml, ['#', 'answer'], []);
            $qo->subanswers[] = $this->getpath($subqxml,
                    ['#', 'answer', 0, '#', 'text', 0, '#'], '', true);
        }

        $this->import_combined_feedback($qo, $question, true);
        $this->import_hints($qo, $question, true, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * Import essay type question
     * @param array question question array from xml tree
     * @return object question object
     */
    public function import_essay($question) {
        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to essay.
        $qo->qtype = 'essay';

        $qo->responseformat = $this->getpath($question,
                ['#', 'responseformat', 0, '#'], 'editor');
        $qo->responsefieldlines = $this->getpath($question,
                ['#', 'responsefieldlines', 0, '#'], 15);
        $qo->responserequired = $this->getpath($question,
                ['#', 'responserequired', 0, '#'], 1);
        $qo->minwordlimit = $this->getpath($question,
                ['#', 'minwordlimit', 0, '#'], null);
        $qo->minwordenabled = !empty($qo->minwordlimit);
        $qo->maxwordlimit = $this->getpath($question,
                ['#', 'maxwordlimit', 0, '#'], null);
        $qo->maxwordenabled = !empty($qo->maxwordlimit);
        $qo->attachments = $this->getpath($question,
                ['#', 'attachments', 0, '#'], 0);
        $qo->attachmentsrequired = $this->getpath($question,
                ['#', 'attachmentsrequired', 0, '#'], 0);
        $qo->filetypeslist = $this->getpath($question,
                ['#', 'filetypeslist', 0, '#'], null);
        $qo->maxbytes = $this->getpath($question,
                ['#', 'maxbytes', 0, '#'], null);
        $qo->graderinfo = $this->import_text_with_files($question,
                ['#', 'graderinfo', 0], '', $this->get_format($qo->questiontextformat));
        $qo->responsetemplate['text'] = $this->getpath($question,
                ['#', 'responsetemplate', 0, '#', 'text', 0, '#'], '', true);
        $qo->responsetemplate['format'] = $this->trans_format($this->getpath($question,
                ['#', 'responsetemplate', 0, '@', 'format'], $this->get_format($qo->questiontextformat)));

        return $qo;
    }

    /**
     * Import a calculated question
     * @param object $question the imported XML data.
     */
    public function import_calculated($question) {

        // Get common parts.
        $qo = $this->import_headers($question);

        // Header parts particular to calculated.
        $qo->qtype = 'calculated';
        $qo->synchronize = $this->getpath($question, ['#', 'synchronize', 0, '#'], 0);
        $single = $this->getpath($question, ['#', 'single', 0, '#'], 'true');
        $qo->single = $this->trans_single($single);
        $shuffleanswers = $this->getpath($question, ['#', 'shuffleanswers', 0, '#'], 'false');
        $qo->answernumbering = $this->getpath($question,
                ['#', 'answernumbering', 0, '#'], 'abc');
        $qo->shuffleanswers = $this->trans_single($shuffleanswers);

        $this->import_combined_feedback($qo, $question);

        $qo->unitgradingtype = $this->getpath($question,
                ['#', 'unitgradingtype', 0, '#'], 0);
        $qo->unitpenalty = $this->getpath($question, ['#', 'unitpenalty', 0, '#'], null);
        $qo->showunits = $this->getpath($question, ['#', 'showunits', 0, '#'], 0);
        $qo->unitsleft = $this->getpath($question, ['#', 'unitsleft', 0, '#'], 0);
        $qo->instructions = $this->getpath($question,
                ['#', 'instructions', 0, '#', 'text', 0, '#'], '', true);
        if (!empty($instructions)) {
            $qo->instructions = $this->import_text_with_files($instructions,
                    ['0'], '', $this->get_format($qo->questiontextformat));
        }

        // Get answers array.
        $answers = $question['#']['answer'];
        $qo->answer = [];
        $qo->feedback = [];
        $qo->fraction = [];
        $qo->tolerance = [];
        $qo->tolerancetype = [];
        $qo->correctanswerformat = [];
        $qo->correctanswerlength = [];
        $qo->feedback = [];
        foreach ($answers as $answer) {
            $ans = $this->import_answer($answer, true, $this->get_format($qo->questiontextformat));
            // Answer outside of <text> is deprecated.
            if (empty($ans->answer['text'])) {
                $ans->answer['text'] = '*';
            }
            // The qtype_calculatedmulti allows HTML in answer options.
            if ($question['@']['type'] == 'calculatedmulti') {
                // If the import file contains a "format" attribute for the answer text,
                // then use it. Otherwise, we must set the answerformat to FORMAT_PLAIN,
                // because the question has been exported from a Moodle version that
                // did not yet allow HTML answer options.
                if (array_key_exists('format', $answer['@'])) {
                    $ans->answer['format'] = $this->trans_format($answer['@']['format']);
                } else {
                    $ans->answer['format'] = FORMAT_PLAIN;
                }
                $qo->answer[] = $ans->answer;
            } else {
                $qo->answer[] = $ans->answer['text'];
            }
            $qo->feedback[] = $ans->feedback;
            $qo->tolerance[] = $answer['#']['tolerance'][0]['#'];
            // Fraction as a tag is deprecated.
            if (!empty($answer['#']['fraction'][0]['#'])) {
                $qo->fraction[] = $answer['#']['fraction'][0]['#'];
            } else {
                $qo->fraction[] = $answer['@']['fraction'] / 100;
            }
            $qo->tolerancetype[] = $answer['#']['tolerancetype'][0]['#'];
            $qo->correctanswerformat[] = $answer['#']['correctanswerformat'][0]['#'];
            $qo->correctanswerlength[] = $answer['#']['correctanswerlength'][0]['#'];
        }
        // Get units array.
        $qo->unit = [];
        if (isset($question['#']['units'][0]['#']['unit'])) {
            $units = $question['#']['units'][0]['#']['unit'];
            $qo->multiplier = [];
            foreach ($units as $unit) {
                $qo->multiplier[] = $unit['#']['multiplier'][0]['#'];
                $qo->unit[] = $unit['#']['unit_name'][0]['#'];
            }
        }
        $instructions = $this->getpath($question, ['#', 'instructions'], []);
        if (!empty($instructions)) {
            $qo->instructions = $this->import_text_with_files($instructions,
                    ['0'], '', $this->get_format($qo->questiontextformat));
        }

        if (is_null($qo->unitpenalty)) {
            // Set a good default, depending on whether there are any units defined.
            if (empty($qo->unit)) {
                $qo->showunits = 3; // This is qtype_numerical::UNITNONE, but we cannot refer to that constant here.
            } else {
                $qo->showunits = 0; // This is qtype_numerical::UNITOPTIONAL, but we cannot refer to that constant here.
            }
        }

        $datasets = $question['#']['dataset_definitions'][0]['#']['dataset_definition'] ?? [];
        $qo->dataset = [];
        $qo->datasetindex = 0;
        foreach ($datasets as $dataset) {
            $qo->datasetindex++;
            $qo->dataset[$qo->datasetindex] = new stdClass();
            $qo->dataset[$qo->datasetindex]->status =
                    $this->import_text($dataset['#']['status'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->name =
                    $this->import_text($dataset['#']['name'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->type =
                    $dataset['#']['type'][0]['#'];
            $qo->dataset[$qo->datasetindex]->distribution =
                    $this->import_text($dataset['#']['distribution'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->max =
                    $this->import_text($dataset['#']['maximum'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->min =
                    $this->import_text($dataset['#']['minimum'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->length =
                    $this->import_text($dataset['#']['decimals'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->distribution =
                    $this->import_text($dataset['#']['distribution'][0]['#']['text']);
            $qo->dataset[$qo->datasetindex]->itemcount = $dataset['#']['itemcount'][0]['#'];
            $qo->dataset[$qo->datasetindex]->datasetitem = [];
            $qo->dataset[$qo->datasetindex]->itemindex = 0;
            $qo->dataset[$qo->datasetindex]->number_of_items = $this->getpath($dataset,
                    ['#', 'number_of_items', 0, '#'], 0);
            $datasetitems = $this->getpath($dataset,
                    ['#', 'dataset_items', 0, '#', 'dataset_item'], []);
            foreach ($datasetitems as $datasetitem) {
                $qo->dataset[$qo->datasetindex]->itemindex++;
                $qo->dataset[$qo->datasetindex]->datasetitem[$qo->dataset[$qo->datasetindex]->itemindex] = new stdClass();
                $qo->dataset[$qo->datasetindex]->datasetitem[$qo->dataset[$qo->datasetindex]->itemindex]->itemnumber =
                                $datasetitem['#']['number'][0]['#'];
                $qo->dataset[$qo->datasetindex]->datasetitem[$qo->dataset[$qo->datasetindex]->itemindex]->value =
                                $datasetitem['#']['value'][0]['#'];
            }
        }

        $this->import_hints($qo, $question, false, false, $this->get_format($qo->questiontextformat));

        return $qo;
    }

    /**
     * This is not a real question type. It's a dummy type used to specify the
     * import category. The format is:
     * <question type="category">
     *     <category>tom/dick/harry</category>
     *     <info format="moodle_auto_format"><text>Category description</text></info>
     * </question>
     */
    protected function import_category($question) {
        $qo = new stdClass();
        $qo->qtype = 'category';
        $qo->category = $this->import_text($question['#']['category'][0]['#']['text']);
        $qo->info = '';
        $qo->infoformat = FORMAT_MOODLE;
        if (array_key_exists('info', $question['#'])) {
            $qo->info = $this->import_text($question['#']['info'][0]['#']['text']);
            // The import should have the format in human readable form, so translate to machine readable format.
            $qo->infoformat = $this->trans_format($question['#']['info'][0]['@']['format']);
        }
        $qo->idnumber = $this->getpath($question, ['#', 'idnumber', 0, '#'], null);
        return $qo;
    }

    /**
     * Parse the array of lines into an array of questions
     * this *could* burn memory - but it won't happen that much
     * so fingers crossed!
     * @param array of lines from the input file.
     * @param stdClass $context
     * @return array (of objects) question objects.
     */
    public function readquestions($lines) {
        // We just need it as one big string.
        $lines = implode('', $lines);

        // This converts xml to big nasty data structure
        // the 0 means keep white space as it is (important for markdown format).
        try {
            $xml = xmlize($lines, 0, 'UTF-8', true);
        } catch (xml_format_exception $e) {
            $this->error($e->getMessage(), '');
            return false;
        }
        unset($lines); // No need to keep this in memory.
        return $this->import_questions($xml['quiz']['#']['question']);
    }

    /**
     * @param array $xml the xmlized xml
     * @return stdClass[] question objects to pass to question type save_question_options
     */
    public function import_questions($xml) {
        $questions = [];

        // Iterate through questions.
        foreach ($xml as $questionxml) {
            $qo = $this->import_question($questionxml);

            // Stick the result in the $questions array.
            if ($qo) {
                $questions[] = $qo;
            }
        }
        return $questions;
    }

    /**
     * @param array $questionxml xml describing the question
     * @return null|stdClass an object with data to be fed to question type save_question_options
     */
    protected function import_question($questionxml) {
        $questiontype = $questionxml['@']['type'];

        if ($questiontype == 'multichoice') {
            return $this->import_multichoice($questionxml);
        } else if ($questiontype == 'truefalse') {
            return $this->import_truefalse($questionxml);
        } else if ($questiontype == 'shortanswer') {
            return $this->import_shortanswer($questionxml);
        } else if ($questiontype == 'numerical') {
            return $this->import_numerical($questionxml);
        } else if ($questiontype == 'description') {
            return $this->import_description($questionxml);
        } else if ($questiontype == 'matching' || $questiontype == 'match') {
            return $this->import_match($questionxml);
        } else if ($questiontype == 'cloze') {
            return $this->import_multianswer($questionxml);
        } else if ($questiontype == 'multianswerrgx') {
            return $this->import_multianswerrgx($questionxml);
        } else if ($questiontype == 'essay') {
            return $this->import_essay($questionxml);
        } else if ($questiontype == 'calculated') {
            return $this->import_calculated($questionxml);
        } else if ($questiontype == 'calculatedsimple') {
            $qo = $this->import_calculated($questionxml);
            $qo->qtype = 'calculatedsimple';
            return $qo;
        } else if ($questiontype == 'calculatedmulti') {
            $qo = $this->import_calculated($questionxml);
            $qo->qtype = 'calculatedmulti';
            return $qo;
        } else if ($questiontype == 'category') {
            return $this->import_category($questionxml);

        } else {
            // Not a type we handle ourselves. See if the question type wants
            // to handle it.
            if (!$qo = $this->try_importing_using_qtypes($questionxml, null, null, $questiontype)) {
                $this->error(get_string('xmltypeunsupported', 'qformat_xmlrgx', $questiontype));
                return null;
            }
            return $qo;
        }
    }

    // EXPORT FUNCTIONS START HERE.

    public function export_file_extension() {
        return '.xml';
    }

    /**
     * Turn the internal question type name into a human readable form.
     * (In the past, the code used to use integers internally. Now, it uses
     * strings, so there is less need for this, but to maintain
     * backwards-compatibility we change two of the type names.)
     * @param string $qtype question type plugin name.
     * @return string $qtype string to use in the file.
     */
    protected function get_qtype($qtype) {
        switch($qtype) {
            case 'match':
                return 'matching';
            case 'multianswer':
                return 'cloze';
            default:
                return $qtype;
        }
    }

    /**
     * Convert internal Moodle text format code into
     * human readable form
     * @param int id internal code
     * @return string format text
     */
    public function get_format($id) {
        switch($id) {
            case FORMAT_MOODLE:
                return 'moodle_auto_format';
            case FORMAT_HTML:
                return 'html';
            case FORMAT_PLAIN:
                return 'plain_text';
            case FORMAT_WIKI:
                return 'wiki_like';
            case FORMAT_MARKDOWN:
                return 'markdown';
            default:
                return 'unknown';
        }
    }

    protected function key_exists_recursive($key, $array) {

        $stack = new SplStack();
        $stack->push($array);

        while (!$stack->isEmpty()) {
            $current = $stack->pop();

            if (array_key_exists($key, $current)) {
                return true;
            }

            foreach ($current as $v) {
                if (is_array($v)) {
                    $stack->push($v);
                }
            }
        }

        return false;
    }

}
