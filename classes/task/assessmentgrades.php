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
* A scheduled task for scripted database integrations.
*
* @package    local_assessmentgrades - template
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

namespace local_assessmentgrades\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
* A scheduled task for scripted external database integrations.
*
* @copyright  2016 ROelmann
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/
class assessmentgrades extends \core\task\scheduled_task {
    
    /**
    * Get a descriptive name for this task (shown to admins).
    *
    * @return string
    */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentgrades');
    }
    
    /**
    * Run sync.
    */
    public function execute() {
        echo "start" . date("l jS \of F Y h:i:s A\n\n");
        global $CFG, $DB;
        require_once("$CFG->libdir/gradelib.php");
        
        // Get grade letters.
        $gradeletters = array();
        $gradeletters = $DB->get_records_menu('grade_letters', array('contextid' => 3), 'letter', 'letter, lowerboundary');
        
        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();
        
        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tableassm = get_string('assessmentstable', 'local_assessmentgrades');
        $tablegrades = get_string('stuassesstable', 'local_assessmentgrades');
        
        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tableassm) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $tableassm . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$tablegrades) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $tablegrades . '<br>';
        }
        echo 'Starting connection...<br>';
        
        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
                echo 'Error while communicating with external database <br>';
                return 1;
            }
            
            // Functions and code to pass grades goes here
            $stuassess = array(); // Maintain copy as per Integrations Db for writing back.
            $stuassessinternal = array(); // Processing copy to be able to add additional fields.
            // Read assessment data from external table.
            /********************************************************
            * ARRAY                                                *
            *     id                                               *
            *     student_code                                     *
            *     assessment_idcode                                *
            *     student_ext_duedate                              *
            *     student_ext_duetime                              *
            *     student_fbdue_date                               *
            *     student_fbdue_time                               *
            *     received_date                                    *
            *     received_time                                    *
            *     received_flag                                    *
            *     actual_mark                                      *
            *     actual_grade                                     *
            *     process_flag                                     *
            *     student_fbset_date                               *
            *     student_fbset_time                               *
            ********************************************************/
            //  $sql = $externaldb->db_get_sql_like($tablegrades, array('assessment_idcode' => '2018/19'), array(), true);
            // $sql = $externaldb->db_get_sql($tablegrades, array('student_code' => '1809106'), array(), true);
            // $sql = $externaldb->db_get_sql_like($tablegrades, array('assessment_idcode' => 'PS4003_A_A21_2018/19_001'), array(), true);
            $sql = $externaldb->db_get_sql($tablegrades, array(), array(), true);
            //  $sql = $externaldb->db_get_sql_like($tablegrades, array('assessment_idcode' => '2019/20'), array(), true);
            if ($rs = $extdb->Execute($sql)) {
                if (!$rs->EOF) {
                    while ($fields = $rs->FetchRow()) {
                        $fields = array_change_key_case($fields, CASE_LOWER);
                        $fields = $externaldb->db_decode($fields);
                        $stuassess[] = $fields;
                    }
                }
                $rs->Close();
            } else {
                // Report error if required.
                $extdb->Close();
                echo 'Error reading data from the external course table<br>';
                return 4;
            }
            
            /* Create keyed array of student data (grades etc) per student~assignment
            * ---------------------------------------------------------------------- */
            echo 'Creating keyed array<br>';
            foreach ($stuassess as $sa) {
                /*             echo "\n\n-------------- DEBUG ---------\n\n"; 
                echo date("l jS \of F Y h:i:s A\n\n");
                var_dump($sa);
                echo "\n\n------------ END DEBUG -----------\n\n"; 
                */
                
                // Create key.
                $idnumber = $sa['student_code'];
                while (strlen($idnumber) < 7){
                    $idnumber = '0' . $idnumber;
                }
                if (strlen($idnumber) != 7 ) {
                    echo 'Not 7 char: ' . $idnumber;
                }
                $idnumber = 's' . $idnumber;
                
                $key = $idnumber.'~'.$sa['assessment_idcode'];
                // echo '<br>'.$key;
                // Apply key to array.
                $stuassessinternal[$key]['key'] = $key;
                
                //            $stuassessinternal[$key]['username'] = 's'.$sa['student_code']; // Username.
                $stuassessinternal[$key]['username'] = $idnumber; // Username.
                // echo ' : $stuassessinternal[$key][username] = ' .$stuassessinternal[$key]['username'] .'<br>';
                
                // Assign_grades is showing -1 as grader and grade where a piece of work has been submitted but not marked.
                if ($stuassessinternal[$key]['gradenum'] == -1) {
                    $stuassessinternal[$key]['gradenum'] = null;
                }
                if ($stuassessinternal[$key]['finalgrade'] == -1) {
                    $stuassessinternal[$key]['finalgrade'] = null;
                }
                
                if ($DB->get_field('user', 'id',
                array('username' => $stuassessinternal[$key]['username']))) {
                    $stuassessinternal[$key]['uid'] = $DB->get_field('user', 'id',
                    array('username' => $stuassessinternal[$key]['username'])); // User id.
                } else {
                    $stuassessinternal[$key]['uid'] = '';
                }
                // echo  'Line 188 : $stuassessinternal[$key][uid] = ' .$stuassessinternal[$key]['uid'] ."\n";
                
                $stuassessinternal[$key]['lc'] = $sa['assessment_idcode']; // Assessment linkcode.
                // echo  'Line 191 : assessment link = '.$stuassessinternal[$key]['lc'] ."\n";
                
                if ($DB->get_field('course_modules', 'course',
                array('idnumber' => $stuassessinternal[$key]['lc']))) {
                    $stuassessinternal[$key]['crs'] = $DB->get_field('course_modules', 'course',
                    array('idnumber' => $stuassessinternal[$key]['lc'])); // Course id.
                } else {
                    $stuassessinternal[$key]['crs'] = '';
                }
                // echo  'Line 200: course id = '.$stuassessinternal[$key]['crs'] ."\n";
                
                if ($DB->get_field('course_modules', 'instance',
                array('idnumber' => $stuassessinternal[$key]['lc']))) {
                    $stuassessinternal[$key]['aid'] = $DB->get_field('course_modules', 'instance',
                    array('idnumber' => $stuassessinternal[$key]['lc'])); // Assignment id.
                } else {
                    $stuassessinternal[$key]['aid'] = '';
                }
                // echo  'Line 209: assignment id = '.$stuassessinternal[$key]['aid'] ."\n";
                
                $stuassessinternal[$key]['mod'] = $DB->get_field('course_modules', 'module',
                array('idnumber' => $stuassessinternal[$key]['lc'])); // Module id.
                $stuassessinternal[$key]['modname'] = $DB->get_field('modules', 'name',
                array('id' => $stuassessinternal[$key]['mod'])); // Module name.
                // echo  'Line 215: module id = '.$stuassessinternal[$key]['mod'].' : '.$stuassessinternal[$key]['modname'] ."\n";
                
                $stuassessinternal[$key]['giid'] = $DB->get_field('grade_items', 'id',
                array('iteminstance' => $stuassessinternal[$key]['aid'], 'itemmodule' => $stuassessinternal[$key]['modname'])); // Grade item instance.
                // echo  'Line 219: grade item = '.$stuassessinternal[$key]['giid'] ."\n";
                
                // Get submission received date & time.
                if ($DB->record_exists('assign_submission',
                array('assignment' => $stuassessinternal[$key]['aid'],
                'userid' => $stuassessinternal[$key]['uid'], 'status' => 'submitted'))) {
                    $stuassessinternal[$key]['received'] = $DB->get_field('assign_submission', 'timemodified',
                    array('assignment' => $stuassessinternal[$key]['aid'], 'userid' => $stuassessinternal[$key]['uid']));
                } else if ($DB->record_exists('quiz_attempts', array('quiz' => $stuassessinternal[$key]['aid'],
                'userid' => $stuassessinternal[$key]['uid'], 'state' => 'finished'))) {
                    $stuassessinternal[$key]['received'] = $DB->get_field('quiz_attempts', 'timefinish',
                    array('quiz' => $stuassessinternal[$key]['aid'], 'userid' => $stuassessinternal[$key]['uid']));
                } else {
                    $stuassessinternal[$key]['received'] = '';
                }
                if (!is_null($stuassessinternal[$key]['received']) && $stuassessinternal[$key]['received'] !== '') {
                    $stuassessinternal[$key]['received_date'] = date('Y-m-d', $stuassessinternal[$key]['received']);
                    $stuassessinternal[$key]['received_time'] = date('H:i:s', $stuassessinternal[$key]['received']);
                } else {
                    $stuassessinternal[$key]['received_date'] = '';
                    $stuassessinternal[$key]['received_time'] = '';
                }
/*                 echo '<br>' .'Submission received: '.$stuassessinternal[$key]['received'].': '.
                $stuassessinternal[$key]['received_date'].': '.$stuassessinternal[$key]['received_time'] .'<br>';
 */                
                // Assign_grades is showing -1 as grader and grade where a piece of work has been submitted but not marked.
                if ($numgrade == -1) {
                    $numgrade = null;
                }
                
                // Fetch alphanumeric grade.
                $fullscale = array(); // Clear any prior value.
                $graderaw = $grademax = null;
                // ECHO "\n BEGIN to scale or not \n";
                if ($DB->record_exists('grade_grades',
                array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']))
                && !is_null($DB->get_field('grade_grades', 'finalgrade',
                array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid'])))) {
                    // Get final grade and ensure %age.
                    $graderaw = $DB->get_field('grade_grades', 'finalgrade',
                    array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']));
                    // echo "\n" .'GRADERAW : ' .$graderaw .' gradeid = ' .$stuassessinternal[$key]['giid'] .' userid = ' .$stuassessinternal[$key]['uid'] ."\n";
                    $grademax = $DB->get_field('grade_grades', 'rawgrademax',
                    array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']));
                    // echo "\n" .'GRADEMAX : ' .$grademax .' gradeid = ' .$stuassessinternal[$key]['giid'] .' userid = ' .$stuassessinternal[$key]['uid'] ."\n";
                    $stuassessinternal[$key]['gradenum'] = $graderaw / $grademax * 100;
                    // echo "\n" .'grade_grades gradenum : ' .$stuassessinternal[$key]['gradenum'] .'% ' .'gradeid = ' .$stuassessinternal[$key]['giid'] .' userid = ' .$stuassessinternal[$key]['uid'] ."\n";

                    // Get which scale.
                    $stuassessinternal[$key]['gradescale'] = $DB->get_field('grade_grades', 'rawscaleid',
                    array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']));
                    // echo "\n" .'gradescale ' .$stuassessinternal[$key]['gradescale'] ."\n";
                    if (!is_null($stuassessinternal[$key]['gradescale']) && $stuassessinternal[$key]['gradescale'] !== 0) {
                        $stuassessinternal[$key]['gradenum'] = $graderaw;
                        $fullscale = $DB->get_record('scale', array('id' => $stuassessinternal[$key]['gradescale']), 'scale');
                        $scale = explode(',', $fullscale->scale);
                        // echo "\n\n fullscale array" .print_r($fullscale) ."\n\n";
                        // echo "\n\n scale array" .print_r($scale) ."\n\n";
                        // $scalenum = $scale[$stuassessinternal[$key]['gradenum']];
                        // echo "\n" .'scalenum ' .$scalenum .' gradenum ' .$stuassessinternal[$key]['gradenum'] ."\n";
                        $stuassessinternal[$key]['gradeletter'] = trim(substr($scale[$stuassessinternal[$key]['gradenum'] - 1], 0, 2)); // Trim scale to max 2 chrs, remove spaces.
                        // $stuassessinternal[$key]['gradeletter'] = $scale[$stuassessinternal[$key]['gradenum']];
                        $stuassessinternal[$key]['gradenum'] = null; // If a scale grade is set, remove numeric value.
/*                         echo "\n" .'Set_letter ' .'gradeid = ' .$stuassessinternal[$key]['giid'] .' userid = ' .$stuassessinternal[$key]['uid'] .' gradeletter '
                         .$stuassessinternal[$key]['gradeletter'] ."\n";
 */                    } else {
                        $stuassessinternal[$key]['gradeletter'] = '';
                        if ($stuassessinternal[$key]['gradenum'] > 0.01) {
                            foreach ($gradeletters as $l => $g) {
                                // echo $l.'gradescale empty and gradenum > 0.01 = '.$gradeletters[$l].'  ';
                                if ($stuassessinternal[$key]['gradeletter'] == ''
                                && $stuassessinternal[$key]['gradenum'] >= $gradeletters[$l]) {
                                    $stuassessinternal[$key]['gradeletter'] = $l;
                                }
                            }
                        }
                    }
                    
                } else {
                    // echo "\n" .'no grade_grades null ' .$stuassessinternal[$key]['giid'] .' ' .$stuassessinternal[$key]['uid'] ."\n";
                    $stuassessinternal[$key]['gradenum'] = null;
                    $stuassessinternal[$key]['gradeletter'] = null;
                }
                // ECHO "\n END to scale or not \n";
                // Get assessment flags. eg. SB.
                /*                 $asflag = '';
                if (strlen($stuassessinternal[$key]['aid']) > 0 && strlen($stuassessinternal[$key]['uid']) > 0) {
                    $afsql = "SELECT c.content FROM {comments} c
                    JOIN {assign_submission} sub ON sub.id = c.itemid
                    WHERE sub.assignment = ".$stuassessinternal[$key]['aid']." AND sub.userid = ".
                    $stuassessinternal[$key]['uid']."
                    AND c.commentarea = 'submission_assessmentflags'";
                    $asflagresult = $DB->get_records_sql($afsql);
                    foreach ($asflagresult as $af) {
                        $asflag = $af->content;
                    }
                    // echo 'asflag: '. $asflag.' ';
                    if ($asflag != '') {
                        $stuassessinternal[$key]['gradeletter'] = $asflag;
                    }
                    if ($asflag == 'SB' || $asflag == 'N') {
                        $stuassessinternal[$key]['gradenum'] = 0;
                    }
                }
                */               
                // Get assessment flags. eg. SB.
                // echo "\n" .'Pre-flags gradenum : ' .$stuassessinternal[$key]['gradenum'] .'Pre-flags gradeletter' .$stuassessinternal[$key]['gradeletter'] ."\n";
                // echo 'aid = ' .$stuassessinternal[$key]['aid'] .'uid = ' .$stuassessinternal[$key]['uid'] ."\n";
                $asflag = '';
                if (strlen($stuassessinternal[$key]['aid']) > 0 && strlen($stuassessinternal[$key]['uid']) > 0) {
                    $afsql = "SELECT c.content FROM {comments} c
                    JOIN {assign_submission} sub ON sub.id = c.itemid
                    WHERE sub.assignment = ".$stuassessinternal[$key]['aid']." AND sub.userid = ".
                    $stuassessinternal[$key]['uid']."
                    AND c.commentarea = 'submission_assessmentflags'";
                    $asflagresult = $DB->get_records_sql($afsql);
                    foreach ($asflagresult as $af) {
                        $asflag = $af->content;
                    }
                    if ($asflag == 'SB' || $asflag == 'N' || $asflag == 'F' || $asflag == 'X' || $asflag == 'L') {
                        $stuassessinternal[$key]['gradenum'] = 0;
                    }
                    if ($asflag == 'SB' || $asflag == 'N' || $asflag == 'F' || $asflag == 'X' || $asflag == 'L') {
                        $stuassessinternal[$key]['gradeletter'] = $asflag;
                    }
                    if ($asflag == '0N') {
                        $stuassessinternal[$key]['gradenum'] = 0;
                        $stuassessinternal[$key]['gradeletter'] = 'N';
                    }
                    if ($asflag == '0F') {
                        $stuassessinternal[$key]['gradenum'] = 0;
                        $stuassessinternal[$key]['gradeletter'] = 'F';
                    }
/*                     echo "\n" .'Flags gradenum : ' .$stuassessinternal[$key]['gradenum'] .'Flags gradeletter' 
                    .$stuassessinternal[$key]['gradeletter'] ."\n";
 */                }
                
                // Get feedback given date.
                if ($DB->record_exists('grade_grades',
                array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']))) {
                    $stuassessinternal[$key]['fbgiven'] = $DB->get_field('grade_grades', 'timemodified',
                    array('itemid' => $stuassessinternal[$key]['giid'], 'userid' => $stuassessinternal[$key]['uid']));
                } else {
                    $stuassessinternal[$key]['fbgiven'] = '';
                }
                if (!is_null($stuassessinternal[$key]['fbgiven']) && $stuassessinternal[$key]['fbgiven'] !== '') {
                    $stuassessinternal[$key]['fbgiven_date'] = date('Y-m-d', $stuassessinternal[$key]['fbgiven']);
                    $stuassessinternal[$key]['fbgiven_time'] = date('H:i:s', $stuassessinternal[$key]['fbgiven']);
                } else {
                    $stuassessinternal[$key]['fbgiven_date'] = '';
                    $stuassessinternal[$key]['fbgiven_time'] = '';
                }
/*                 echo '<br>' .'Grade: '.$stuassessinternal[$key]['gradeletter'].$stuassessinternal[$key]['gradenum'].': '.
                $stuassessinternal[$key]['fbgiven'].': '.$stuassessinternal[$key]['fbgiven_date'].
                ': '.$stuassessinternal[$key]['fbgiven_time'].'<br>';
 */
/*                
                // Write values to external database - but only if they exist.
                // Need to add code to this to only write them if they have changed from what's already there.
                $studentcode = mb_substr($stuassessinternal[$key]['username'], 1);
                // echo 'Write studentcode : ' .$studentcode . ' - ';
                $sql = "UPDATE " . $tablegrades . " SET student_code = " . $studentcode .", "; // Prevents error if nothing is set.
                $changeflag = 0;
                // echo 'recd date : ' .$stuassessinternal[$key]['received_date'] .$sa['received_date'] ."\n";
                if ($stuassessinternal[$key]['received_date'] != '' &&
                $stuassessinternal[$key]['received_date'] !== $sa['received_date']) {
                    $sql .= "received_date = '" . $stuassessinternal[$key]['received_date'] . "', ";
                    $sql .= "received_flag = 1, ";
                    $changeflag = 1;
                    // echo "Received Date written :" .$stuassessinternal[$key]['received_date'] ."\n";
                }
                if ($stuassessinternal[$key]['received_time'] != '' &&
                $stuassessinternal[$key]['received_time'] !== $sa['received_time']) {
                    $sql .= "received_time = '" . $stuassessinternal[$key]['received_time'] . "', ";
                    $changeflag = 1;
                    // echo "Received Timne written :" .$stuassessinternal[$key]['received_time'] ."\n";
                }
                if ($stuassessinternal[$key]['gradenum'] != '' &&
                $stuassessinternal[$key]['gradenum'] !== $sa['actual_mark']) {
                    if ($stuassessinternal[$key]['gradenum'] == -1) {
                        $stuassessinternal[$key]['gradenum'] = null;
                    }
                    $sql .= "actual_mark = '" . $stuassessinternal[$key]['gradenum'] . "', ";
                    $changeflag = 1;
                    // echo "Grade Number written :" .$stuassessinternal[$key]['gradenum'] ."\n";
                }
                if ($stuassessinternal[$key]['gradeletter'] != '' &&
                $stuassessinternal[$key]['gradeletter'] !== $sa['actual_grade']) {
                    // Remove any numerals from assessment flag / Grade letter string.
                    $stuassessinternal[$key]['gradeletter'] = preg_replace('/\d+/u', '', $stuassessinternal[$key]['gradeletter']);
                    $sql .= "actual_grade = '" . $stuassessinternal[$key]['gradeletter'] . "', ";
                    $changeflag = 1;
                    // echo "Gradeletter written :" .$stuassessinternal[$key]['gradeletter'] ."\n";
                }
                if ($stuassessinternal[$key]['fbgiven_date'] != '' &&
                $stuassessinternal[$key]['fbgiven_date'] !== $sa['student_fbset_date']) {
                    $sql .= "student_fbset_date = '" . $stuassessinternal[$key]['fbgiven_date'] . "', ";
                    $changeflag = 1;
                    // echo "FB Given Date written :" .$stuassessinternal[$key]['fbgiven_date'] ."\n";
                }
                if ($stuassessinternal[$key]['fbgiven_time'] != '' &&
                $stuassessinternal[$key]['fbgiven_time'] !== $sa['student_fbset_time']) {
                    $sql .= "student_fbset_time = '" . $stuassessinternal[$key]['fbgiven_time'] . "', ";
                    $changeflag = 1;
                    // echo "FB Given Time written :" .$stuassessinternal[$key]['fbgiven_time'] ."\n";
                }
*/
                // Write values to external database - but only if they exist.
                // Need to add code to this to only write them if they have changed from what's already there.
                $studentcode = mb_substr($stuassessinternal[$key]['username'], 1);
                // $sql = "UPDATE " . $tablegrades . " SET student_code = " . $studentcode .", "; // Prevents error if nothing is set.
                $sql = "UPDATE " . $tablegrades . " SET "; 
                $changeflag = 0;
                if (!empty($stuassessinternal[$key]['received_date']) &&
                $stuassessinternal[$key]['received_date'] !== $sa['received_date']) {
                    $sql .= "received_date = '" . $stuassessinternal[$key]['received_date'] . "', ";
                    $sql .= "received_flag = 1, ";
                    $changeflag = 1;
                }
                if (!empty($stuassessinternal[$key]['received_time']) &&
                $stuassessinternal[$key]['received_time'] !== $sa['received_time']) {
                    $sql .= "received_time = '" . $stuassessinternal[$key]['received_time'] . "', ";
                    $changeflag = 1;
                }
                if (!is_null($stuassessinternal[$key]['gradenum']) &&
                $stuassessinternal[$key]['gradenum'] !== $sa['actual_mark']) {
                    if ($stuassessinternal[$key]['gradenum'] == -1) {
                        $stuassessinternal[$key]['gradenum'] = null;
                    }
                    $sql .= "actual_mark = '" . $stuassessinternal[$key]['gradenum'] . "', ";
                    $changeflag = 1;
                }
                if (!empty($stuassessinternal[$key]['gradeletter']) &&
                $stuassessinternal[$key]['gradeletter'] !== $sa['actual_grade']) {
                    $sql .= "actual_grade = '" . $stuassessinternal[$key]['gradeletter'] . "', ";
                    $changeflag = 1;
                }
                if (!empty($stuassessinternal[$key]['fbgiven_date']) &&
                $stuassessinternal[$key]['fbgiven_date'] !== $sa['student_fbset_date']) {
                    $sql .= "student_fbset_date = '" . $stuassessinternal[$key]['fbgiven_date'] . "', ";
                    $changeflag = 1;
                }
                if (!empty($stuassessinternal[$key]['fbgiven_time']) &&
                $stuassessinternal[$key]['fbgiven_time'] !== $sa['student_fbset_time']) {
                    $sql .= "student_fbset_time = '" . $stuassessinternal[$key]['fbgiven_time'] . "', ";
                    $changeflag = 1;
                }
                $sql .= "assessment_changebymoodle = " . $changeflag ." WHERE ";
                $sql .= "assessment_idcode = '" . $stuassessinternal[$key]['lc'] . "' AND
                student_code = '" . $studentcode . "';";
                
/*                 if ($idnumber == 's1806369') {
                    echo ': grade scale = '.$stuassessinternal[$key]['gradescale'] ."\n";
                    echo 'raw ' .$graderaw ."\n";
                    echo 'gradenum ' .$stuassessinternal[$key]['gradenum'] ."\n";
                    echo 'gradeletter ' .$stuassessinternal[$key]['gradeletter'] ."\n\n";
                }
 */                
                if ($changeflag === 1) {
                    // echo date("l jS \of F Y h:i:s A\n\n");
                    echo "\n" .$sql ."\n";
                    $extdb->Execute($sql);
                }
                // echo "finished writing to Db at " .date("l jS \of F Y h:i:s A\n\n");
            }
            
            // Free memory.
            $extdb->Close();
            echo "end " . date("l jS \of F Y h:i:s A\n\n");
        }
        
    }
    
    