<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

namespace report_finalreport\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads and validates the request filters and exposes the data provider for them.
 */
class state {
    public $course;
    public $context;
    public $groupid = 0;
    public $userid = 0;
    public $to;
    public $eperiod = 6;
    public $egran = 'month';
    public $cperiod = 6;
    public $ctype = '';
    /** @var array id => name of groups the user may select */
    public $groups = [];
    public $restricted = false;
    /** @var report_data */
    public $data;

    public function __construct(\stdClass $course, \context_course $context) {
        global $USER;
        $this->course = $course;
        $this->context = $context;

        // Groups (respect separate groups + accessallgroups).
        $groupmode = groups_get_course_groupmode($course);
        $canall = has_capability('moodle/site:accessallgroups', $context);
        $this->restricted = ($groupmode == SEPARATEGROUPS && !$canall);
        foreach (groups_get_all_groups($course->id, $this->restricted ? $USER->id : 0, 0, 'g.id, g.name') as $g) {
            $this->groups[$g->id] = format_string($g->name, true, ['escape' => false, 'context' => $context]);
        }
        $this->groupid = optional_param('groupid', 0, PARAM_INT);
        if ($this->groupid && !isset($this->groups[$this->groupid])) {
            $this->groupid = 0;
        }
        if ($this->restricted && !$this->groupid) {
            $this->groupid = $this->groups ? (int)array_key_first($this->groups) : -1;
        }

        $this->to = usergetmidnight(time()) + DAYSECS - 1;

        // Student filter validated against the group's students.
        $uid = optional_param('userid', 0, PARAM_INT);
        $probe = new report_data($course, $context, $this->groupid, 0, $this->to);
        $this->userid = ($uid && isset($probe->get_group_students()[$uid])) ? $uid : 0;
        $this->data = $this->userid ? new report_data($course, $context, $this->groupid, $this->userid, $this->to) : $probe;

        $e = optional_param('eperiod', 6, PARAM_INT);
        $this->eperiod = in_array($e, [3, 6, 12]) ? $e : 6;
        $c = optional_param('cperiod', 6, PARAM_INT);
        $this->cperiod = in_array($c, [3, 6, 12]) ? $c : 6;
        $g = optional_param('egran', 'month', PARAM_ALPHA);
        $this->egran = in_array($g, ['day', 'week', 'month']) ? $g : 'month';
        $t = optional_param('ctype', '', PARAM_ALPHANUMEXT);
        $this->ctype = ($t !== '' && isset($this->data->completion_modtypes()[$t])) ? $t : '';
    }

    /** Start of the chart window: first day of the month ($months - 1) months before the period end. */
    public function chart_start(int $months): int {
        $tz = \core_date::get_user_timezone_object();
        return (new \DateTime('@' . $this->to))->setTimezone($tz)
            ->modify('first day of this month')->modify('-' . ($months - 1) . ' months')->setTime(0, 0)->getTimestamp();
    }

    public function url(array $extra = [], string $path = '/report/finalreport/index.php'): \moodle_url {
        return new \moodle_url($path, $extra + [
            'id' => $this->course->id,
            'groupid' => $this->groupid,
            'userid' => $this->userid,
            'eperiod' => $this->eperiod,
            'egran' => $this->egran,
            'cperiod' => $this->cperiod,
            'ctype' => $this->ctype,
        ]);
    }
}
