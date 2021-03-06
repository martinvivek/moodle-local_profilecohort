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
 * Local plugin "Profile field based cohort membership" - Main class for matching up users with themes
 *
 * @package   local_profilecohort
 * @copyright 2016 Davo Smith, Synergy Learning UK on behalf of Alexander Bias, Ulm University <alexander.bias@uni-ulm.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_profilecohort;

use core_component;
use Exception;
use html_writer;
use theme_config;

defined('MOODLE_INTERNAL') || die();

/**
 * Class profilecohort
 * @package local_profilecohort
 */
class profilecohort extends profilefields {
    protected static $tablename = 'local_profilecohort';

    /**
     * Process the submitted rule editing form.
     */
    public function process_form() {
        if (!$this->get_possible_values()) {
            // If there are no cohorts selected, go to the form for selecting cohorts.
            $cohorturl = new \moodle_url('/local/profilecohort/cohorts.php');
            redirect($cohorturl);
        }
        parent::process_form();
    }

    /**
     * Output the complete form for editing profile field mapping rules.
     * @return string
     */
    public function output_form() {
        $out = '';
        $out .= html_writer::tag('div', get_string('intro', 'local_profilecohort'), array('id' => 'intro', 'class' => 'box generalbox'));
        $out .= parent::output_form();
        return $out;
    }

    /**
     * Output a link to edit the list of cohorts associated with this pluign
     * @return string
     */
    public function output_cohort_link() {
        $out = '';
        $cohorturl = new \moodle_url('/local/profilecohort/cohorts.php');
        $out .= html_writer::link($cohorturl, get_string('reselectcohorts', 'local_profilecohort'));
        return $out;
    }

    /**
     * Called after the user has logged in, to apply any mappings and update their cohorts
     * @param \core\event\base|null $event (optional)
     * @param $userid (optional) mostly used by testing
     */
    public static function set_cohorts_from_profile(\core\event\base $event = null, $userid = null) {
        global $USER, $DB, $CFG;
        require_once($CFG->dirroot.'/cohort/lib.php');

        if ($event) {
            $userid = $event->userid;
        }
        if (!$userid) {
            $userid = $USER->id;
        }

        $allowedcohortids = array_keys(self::load_possible_values());
        if (!$allowedcohortids) {
            return; // No cohorts handled by this plugin => nothing to do.
        }

        list($csql, $params) = $DB->get_in_or_equal($allowedcohortids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $select = "userid = :userid AND cohortid $csql";
        $oldcohortids = $DB->get_fieldset_select('cohort_members', 'cohortid', $select, $params);
        $newcohortids = self::get_mapped_value($userid, true);

        $addcohortids = array_diff($newcohortids, $oldcohortids);
        $removecohortids = array_diff($oldcohortids, $newcohortids);
        foreach ($addcohortids as $addcohortid) {
            cohort_add_member($addcohortid, $userid);
        }
        foreach ($removecohortids as $removecohortid) {
            cohort_remove_member($removecohortid, $userid);
        }
    }

    /**
     * Load a list of possible values that fields can be mapped onto.
     * @return string[] $value => $displayname
     */
    protected static function load_possible_values() {
        global $DB;
        $cohorts = $DB->get_records_menu('cohort', ['visible' => 1, 'component' => 'local_profilecohort'], 'name', 'id, name');
        return $cohorts;
    }

    /**
     * Schedule an update of all user cohorts.
     */
    protected function apply_all_rules() {
        set_config('updatecohorts', true, 'local_profilecohort');
    }

    /**
     * Apply all the rules to all users on the site, updating their cohorts to match.
     */
    public function update_all_cohorts_from_rules() {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/cohort/lib.php');

        // Create a recordset to load the relevant user profile fields for all users.
        $fieldids = [];
        $rules = $this->get_rules();
        foreach ($rules as $rule) {
            $fieldids[] = (int)$rule->fieldid;
        }
        $fieldids = array_unique($fieldids);

        $fieldsql = [];
        foreach ($fieldids as $fieldid) {
            $fieldsql[] = "(SELECT data FROM {user_info_data} WHERE fieldid = {$fieldid} AND userid = u.id) AS field_{$fieldid}";
        }
        if ($fieldsql) {
            $fieldsql = implode(', ', $fieldsql).', ';
        } else {
            $fieldsql = '';
        }
        $sql = "SELECT $fieldsql u.id
                  FROM {user} u
                 ORDER BY u.id";
        $urs = $DB->get_recordset_sql($sql);

        // Create a recordset to load the current cohorts for all users.
        $allowedcohortids = array_keys($this->get_possible_values());
        $crs = $DB->get_recordset_list('cohort_members', 'cohortid', $allowedcohortids, 'userid', 'userid, cohortid');

        $cohortrec = null;
        if ($crs->valid()) {
            $cohortrec = $crs->current();
        }
        foreach ($urs as $userrec) {
            // Loop through the cohort recordset to get all the old cohorts for the current user.
            $oldcohortids = [];
            while ($cohortrec && $cohortrec->userid == $userrec->id) {
                $oldcohortids[] = $cohortrec->cohortid;
                $crs->next();
                if ($crs->valid()) {
                    $cohortrec = $crs->current();
                } else {
                    $cohortrec = null;
                }
            }

            // Prepare the fields list for the user, then apply the rules to each in turn.
            $fields = [];
            foreach ($fieldids as $fieldid) {
                $fieldname = "field_{$fieldid}";
                $fields[$fieldid] = $userrec->$fieldname;
            }
            $newcohortids = [];
            foreach ($rules as $rule) {
                if ($value = $rule->get_value($fields)) {
                    $newcohortids[] = $value;
                }
            }

            // See if the cohorts list has changed for this user and apply the additions / removals, as needed.
            $newcohortids = array_unique($newcohortids);
            $addcohortids = array_diff($newcohortids, $oldcohortids);
            $removecohortids = array_diff($oldcohortids, $newcohortids);
            foreach ($addcohortids as $addcohortid) {
                cohort_add_member($addcohortid, $userrec->id);
            }
            foreach ($removecohortids as $removecohortid) {
                cohort_remove_member($removecohortid, $userrec->id);
            }
        }
    }

    // ------------------------------------------
    // Admin form for editing mappings
    // ------------------------------------------

    /**
     * Process the form for editing which cohorts should be managed by this plugin.
     */
    public function process_cohort_form() {
        global $DB;

        $select = "(component = '' OR component = 'local_profilecohort') AND visible = 1";
        $allcohorts = $DB->get_records_select('cohort', $select, [], 'name', 'id, name, component');

        $custom = ['cohorts' => $allcohorts];
        $this->form = new cohort_form(null, $custom);

        $redir = new \moodle_url('/local/profilecohort/index.php');
        if ($this->form->is_cancelled()) {
            redirect($redir);
        }
        if ($formdata = $this->form->get_data()) {
            $changed = false;
            foreach ($allcohorts as $cohort) {
                if ($formdata->cohort[$cohort->id]) {
                    if ($cohort->component != 'local_profilecohort') {
                        // Cohort selected - start managing this cohort.
                        $DB->set_field('cohort', 'component', 'local_profilecohort', ['id' => $cohort->id]);
                        $changed = true;
                    }
                } else {
                    if ($cohort->component == 'local_profilecohort') {
                        // Cohort deselected - stop managing this cohort.
                        $DB->set_field('cohort', 'component', '', ['id' => $cohort->id]);
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $this->apply_all_rules();
            }
            redirect($redir);
        }
    }

    /**
     * Output the form for editing which cohorts should be managed by this plugin.
     * @return string
     */
    public function output_cohort_form() {
        $out = '';
        $out .= $this->form->render();
        return $out;
    }
}
