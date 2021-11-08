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
 * Signed Quiz Export
 *
 * @package    block_signed_quiz_export
 * @copyright  Daniel Neis <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/signed_quiz_export/classes/forms/block_form_sign.php');
require_once($CFG->dirroot . '/blocks/signed_quiz_export/export.php');
require_once($CFG->dirroot . '/blocks/signed_quiz_export/TrustedTimestamps.php');

class block_signed_quiz_export extends block_base {

    function init() {
        $this->title = get_string('pluginname', 'block_signed_quiz_export');
        $this->content_type = BLOCK_TYPE_TEXT;
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    function get_content() {
        global $CFG, $OUTPUT, $DB, $PAGE;
        if ($this->content !== null) {
            return $this->content;
        }
        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';
        $cm = $this->get_owning_activity();
        $mformSign = new block_form_sign($PAGE->url, array('id'=>$cm->id));
        try {
            $quiz_attempts = $DB->get_records( 'quiz_attempts', array('quiz'=> $cm->instance));
        } catch(Exception $e){
            return $this->content;
        }

        try{
            $quiz_exports = $DB->get_records('signed_quiz_export', array('quizid' => $cm->instance));
            $this->content->text = 'Download Quiz results:';
            $this->content->text .= '<br>';
            foreach($quiz_exports as $quiz_export){
                $exportid = $quiz_export->id;
                $this->content->text .= html_writer::tag('a', 'Export from '. date("Y-m-d H:i:s",$quiz_export->sdate), array('href' => '/blocks/signed_quiz_export/download_export.php?exportid='. $exportid));
                if($quiz_export->valid) {
                    $this->content->text .= '<i class="fa fa-check"></i>';
                } else {
                    $this->content->text .= '<i class="fa fa-times"></i>';
                }
                $this->content->text .= '<br>';
            }
        }catch(Exception $e){

        }

        if($mformSign->get_data()){
            $this->sign($quiz_attempts);
        }
        $this->content->footer .= $mformSign->render();
        return $this->content;
    }

    // my moodle can only have SITEID and it's redundant here, so take it away
    public function applicable_formats() {
        return array('mod-quiz' => true);
    }

    public function instance_allow_multiple() {
          return true;
    }

    function has_config() {return true;}

    /**
     * Return the quiz activity's id.
     * @return stdclass the activity record.
     * @throws coding_exception
     */
    public function get_owning_activity() {

        // Set some defaults.
        $result = new stdClass();
        $result->id = 0;

        if (empty($this->instance->parentcontextid)) {
            return $result;
        }
        $parentcontext = context::instance_by_id($this->instance->parentcontextid);
        if ($parentcontext->contextlevel != CONTEXT_MODULE) {
            return $result;
        }
        $cm = get_coursemodule_from_id($this->page->cm->modname, $parentcontext->instanceid);
        if (!$cm) {
            return $result;
        }

        return $cm;
    }

    function prepareFiles($attemptids){
        global $DB, $CFG;
        $pdf_files = array();
        $exporter = new quiz_export_engine();

        $tmp_dir = '/tmp/';
        $tmp_file = tempnam($tmp_dir, "mdl-qexp_");
        $tmp_zip_file = $tmp_file . ".zip";
        rename($tmp_file, $tmp_zip_file);
        chmod($tmp_zip_file, 0644);

        $zip = new ZipArchive;
        $zip->open($tmp_zip_file);

        foreach ($attemptids as $attemptid) {
            $attemptobj = quiz_attempt::create($attemptid);
            $pdf_file = $exporter->a2pdf($attemptobj);
            $pdf_files[] = $pdf_file;
            $student = $DB->get_record('user', array('id' => $attemptobj->get_userid()));
            $zip->addFile($pdf_file, fullname($student, true) . "_" . $attemptid . '.pdf');
        }
        $zip->close();
        foreach ($pdf_files as $pdf_file) {
            unlink($pdf_file);
        }
        return $tmp_zip_file;
    }

/**
     * Export the quiz attempts
     * @param object $quiz the quiz settings
     * @param object $cm the course_module object.
     * @param array $attemptids the list of attempt ids to export.
     * @param array $allowed This list of userids that are visible in the report.
     *      Users can only export attempts that they are allowed to see in the report.
     *      Empty means all users.
     * @throws Exception
     */
    function export_attempts($attemptids, $tmp_zip_file)
    {
        global $CFG,$DB,$USER;
        $time = time(); // this will get you the current time in unix time format (seconds since 1/1/1970 GMT)
        $currentYear = userdate($time,'%Y');
        $backupTime = userdate($time, '%Y%m%d-%H%M'); // this will print the time in the timezone of the current user (formats)
        $quizattempt = quiz_attempt::create(current($attemptids));
        $backupPath = $CFG->dataroot.'/backups/'. $currentYear . '/' . $quizattempt->get_course()->fullname . '/' . $quizattempt->get_quiz_name();
        if(!is_dir($backupPath)){
            mkdir($backupPath, 0777, true);
        }
        $backupFilePath =  $backupPath . '/' . $backupTime;
        copy($tmp_zip_file, $backupFilePath. '.zip');

        $requestFilePath = TrustedTimestamps::createRequestfile($backupFilePath . '.zip');
        copy($requestFilePath,$backupFilePath.'.tsq');
        $response = TrustedTimestamps::signRequestfile($requestFilePath, get_config('block_signed_quiz_export', 'tsdomain'));
        $responseFile = fopen($backupFilePath. '.tsr', 'w+') or die("Unable to open file!");
        fwrite($responseFile, $response);
        fclose($responseFile);
        $isValid = TrustedTimestamps::validate($backupFilePath, $CFG->dirroot . '/blocks/signed_quiz_export/certs/dfn-cert.pem');
        $DB->insert_record("signed_quiz_export",
            ['teacherid' => $USER->id,
                'quizid' => $quizattempt->get_quizid(),
                'sdate' => $time,
                'path' => '/backups/' . $currentYear . '/' . $quizattempt->get_course()->fullname . '/' . $quizattempt->get_quiz_name() . '/' . $backupTime . '.zip',
        'valid' => $isValid]);
    }

    function sign($quiz_attempts){
        $tmp_zip_file = $this->prepareFiles(array_keys($quiz_attempts));
        $this->export_attempts(array_keys($quiz_attempts),$tmp_zip_file);
        unset($zip);
        unlink($tmp_zip_file);
        $quiz_info = $this->get_owning_activity();
        $urltogo = new moodle_url('/mod/quiz/view.php', array('id' => $quiz_info->id));
        redirect($urltogo);
        
    }
}
