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

defined('MOODLE_INTERNAL') || die();


class restore_plagiarism_turnitin_plugin extends restore_plagiarism_plugin {

    /**
     * Return the paths of the course data along with the function used for restoring that data.
     */
    protected function define_course_plugin_structure() {
        $paths = array();
        $paths[] = new restore_path_element('turnitin_course', $this->get_pathfor('turnitin_courses/turnitin_course'));

        // Only if not samesite.
        if (!$this->task->is_samesite()) {
            $paths[] = new restore_path_element('turnitin_user', $this->get_pathfor('turnitin_users/turnitin_user'));
        }

        return $paths;
    }

    /**
     * Restore the Turnitin course
     * This will only be done this if the course is from the same site it was backed up from
     * and if the Turnitin course id does not currently exist in the database.
     */
    public function process_turnitin_course($data) {
        global $DB;

        if ($this->task->is_samesite()) {
            $data = (object)$data;
            $recordexists = $DB->record_exists('plagiarism_turnitin_courses', array('turnitin_cid' => $data->turnitin_cid));

            if (!$recordexists) {
                $data = (object)$data;
                $data->courseid = $this->task->get_courseid();

                // Unset course type in case the restore is from a backup taken prior to change for where the courses are stored.
                unset($data->course_type);

                $DB->insert_record('plagiarism_turnitin_courses', $data);
            }
        }
    }

    /**
     * Restore the Turnitin user
     * This will only be done this if the course is from the same site it was backed up from
     * and if the Turnitin course id does not currently exist in the database.
     */
    public function process_turnitin_user($data) {
        global $DB;

        // Only if not samesite.
        if (!$this->task->is_samesite()) {
            $data = (object)$data;

            // Get new userid.
            $data->userid = $this->get_mappingid('user', $data->userid);

            $recordexists = $DB->record_exists('plagiarism_turnitin_users',
                array('turnitin_uid' => $data->turnitin_uid, 'userid' => $data->userid));

            if (!$recordexists) {
                $DB->insert_record('plagiarism_turnitin_users', $data);
            }
        }
    }

    /**
     * Return the paths of the module data along with the function used for restoring that data.
     */
    protected function define_module_plugin_structure() {
        $paths = array();
        $paths[] = new restore_path_element('turnitin_config', $this->get_pathfor('turnitin_configs/turnitin_config'));
        $paths[] = new restore_path_element('turnitin_files', $this->get_pathfor('/turnitin_files/turnitin_file'));

        return $paths;
    }

    /**
     * Restore the Turnitin assignment id for this module
     * This will only be done this if the module is from the same site it was backed up from
     * and if the Turnitin assignment id does not currently exist in the database.
     */
    public function process_turnitin_config($data) {
        global $DB;

        if ($this->task->is_samesite()) {
            $data = (object)$data;
            $recordexists = ($data->name == 'turnitin_assign') ? $DB->record_exists('plagiarism_turnitin_config',
                array('name' => 'turnitin_assign', 'value' => $data->value)) : false;
            $recordexists = ($data->name == 'turnitin_assignid') ? $DB->record_exists('plagiarism_turnitin_config',
                array('name' => 'turnitin_assignid', 'value' => $data->value)) : $recordexists;

            if (!$recordexists) {
                $data = (object)$data;
                $data->name = ($data->name == 'turnitin_assign') ? 'turnitin_assignid' : $data->name;
                $data->cm = $this->task->get_moduleid();
                $data->config_hash = $data->cm."_".$data->name;

                $DB->insert_record('plagiarism_turnitin_config', $data);
            }
        } else {
            $data = (object)$data;
            // We need to exclude this so it can be regenerated.
            if ($data->name !== 'turnitin_assign' && $data->name !== 'turnitin_assignid') {
                $data->name = ($data->name == 'turnitin_assign') ? 'turnitin_assignid' : $data->name;
                $data->cm = $this->task->get_moduleid();
                $data->config_hash = $data->cm."_".$data->name;

                $DB->insert_record('plagiarism_turnitin_config', $data);
            }
        }
    }

    /**
     * Restore the links to Turnitin files.
     * This will only be done this if the module is from the same site it was backed up from
     * and if the Turnitin submission does not currently exist in the database.
     */
    public function process_turnitin_files($data) {
        global $DB;

        if ($this->task->is_samesite()) {
            $data = (object)$data;
            $recordexists = (!empty($data->externalid)) ? $DB->record_exists('plagiarism_turnitin_files',
                array('externalid' => $data->externalid)) : false;

            if (!$recordexists) {
                $data->cm = $this->task->get_moduleid();
                $data->userid = $this->get_mappingid('user', $data->userid);

                $DB->insert_record('plagiarism_turnitin_files', $data);
            }
        }
    }


     /**
     * Executed after course restore is complete
     *
     * This method is only executed if course configuration was overridden
     */
    public function after_restore_course() {
        global $DB;

        $backupinfo = $this->step->get_task()->get_info();

        foreach ($backupinfo->activities as $activityid => $activity) {
            if ('assign' ==  $activity->modulename) {
                // Get new activity ID.
                $cmid = $this->get_mappingid('course_module', $activity->moduleid);

                // Now check assign has use_turninit set to 1.
                $useturnitinparams = array(
                    'cm' => $cmid,
                    'name' => 'use_turnitin',
                    'value' => 1,
                    'config_hash' => $cmid.'_use_turnitin'
                );

                if ($useturnitin = $DB->get_record('plagiarism_turnitin_config', $useturnitinparams)) {
                    $submissionfilesparams = array (
                        'cmid' => $cmid
                    );

                    $cm = get_coursemodule_from_id('', $cmid);
                    $context = context_course::instance($cm->course);

                    $order = " ORDER BY f.timemodified ASC ";
                    $sql = "SELECT f.id,f.pathnamehash ,f.itemid,f.userid,f.timemodified
                              FROM {assign_submission} sub
                              JOIN {course_modules} cm ON cm.instance = sub.assignment AND cm.module = 1
                              JOIN {files} f ON f.itemid = sub.id AND f.component = 'assignsubmission_file' AND f.filearea = 'submission_files'
                             WHERE f.filesize > 0 and cm.id = :cmid".$order;

                    $submissionfiles = $DB->get_records_sql($sql, $submissionfilesparams);

                    foreach ($submissionfiles as $subfile) {
                        $data = new stdClass();

                        // Set same as new assign id.
                        $data->cm = $cmid;

                        // Set userid as file userid.
                        $data->userid = $subfile->userid;

                        // Set submitter to userid.
                        $data->submitter = $data->userid;

                        // Set same as file pathnamehash.
                        $data->identifier = $subfile->pathnamehash;

                        // Set externalid to null.
                        $data->externalid = null;

                        // Set itemid as file itemid.
                        $data->itemid = $subfile->itemid;

                        // Set statuscode to 'queued' so it can process in next task run.
                        $data->statuscode = 'queued';

                        // Set similarityscore to null.
                        $data->similarityscore = null;

                        // Set attempt to 0.
                        $data->attempt = 0;

                        // Set transmatch to 0.
                        $data->transmatch = 0;

                        // Set lastmodified as file timemodified.
                        $data->lastmodified = $subfile->timemodified;

                        // Set grade to null.
                        $data->grade = null;

                        // Set submissiontype to 'file'.
                        $data->submissiontype = 'file';

                        // Set orcapable to null.
                        $data->orcapable = null;

                        // Set errorcode to null.
                        $data->errorcode = null;

                        // Set errormsg to null.
                        $data->errormsg = null;

                        // Set student_read to 0.
                        $data->student_read = 0;

                        // Set gm_feedback to 0.
                        $data->gm_feedback = 0;

                        // Set duedate_report_refresh to 0.
                        $data->duedate_report_refresh = 0;

                        // Now insert into plagiarism_turnitin_files;
                        $DB->insert_record('plagiarism_turnitin_files', $data);
                    }

                    // Now process online text.
                    $submissiononlinetextparams = array (
                        'cmid' => $cmid
                    );

                    $order = " ORDER BY sub.timemodified ASC ";
                    $sql = "SELECT onl.onlinetext, sub.id AS itemid, sub.userid, sub.timemodified
                              FROM {assignsubmission_onlinetext} onl
                              JOIN {assign_submission} sub ON sub.id = onl.submission
                              JOIN {course_modules} cm ON cm.instance = onl.assignment AND cm.module = 1
                             WHERE onl.onlinetext <> '' AND cm.id = :cmid".$order;

                    $submissiononlinetext = $DB->get_records_sql($sql, $submissiononlinetextparams);

                    foreach ($submissiononlinetext as $subtext) {
                        $data = new stdClass();

                        // Set same as new assign id.
                        $data->cm = $cmid;

                        // Set userid as subtext userid.
                        $data->userid = $subtext->userid;

                        // Set submitter to userid.
                        $data->submitter = $data->userid;

                        // Set same as sha1 of text.
                        $data->identifier = sha1($subtext->onlinetext);

                        // Set externalid to null.
                        $data->externalid = null;

                        // Set itemid as subtext itemid.
                        $data->itemid = $subtext->itemid;

                        // Set statuscode to 'queued' so it can process in next task run.
                        $data->statuscode = 'queued';

                        // Set similarityscore to null.
                        $data->similarityscore = null;

                        // Set attempt to 0.
                        $data->attempt = 0;

                        // Set transmatch to 0.
                        $data->transmatch = 0;

                        // Set lastmodified as subtext timemodified.
                        $data->lastmodified = $subtext->timemodified;

                        // Set grade to null.
                        $data->grade = null;

                        // Set submissiontype to 'text_content'.
                        $data->submissiontype = 'text_content';

                        // Set orcapable to null.
                        $data->orcapable = null;

                        // Set errorcode to null.
                        $data->errorcode = null;

                        // Set errormsg to null.
                        $data->errormsg = null;

                        // Set student_read to 0.
                        $data->student_read = 0;

                        // Set gm_feedback to 0.
                        $data->gm_feedback = 0;

                        // Set duedate_report_refresh to 0.
                        $data->duedate_report_refresh = 0;

                        // Now insert into plagiarism_turnitin_files;
                        $DB->insert_record('plagiarism_turnitin_files', $data);
                    }
                }
            }
        }
    }
}
