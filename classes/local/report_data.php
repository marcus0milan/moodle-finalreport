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

require_once($GLOBALS['CFG']->libdir . '/completionlib.php');

/**
 * All queries and calculations of the report. Everything is read live from the Moodle database.
 *
 * All indicators (KPIs and gauges) are cumulative: from the start of the course until now.
 * Only the time-series charts use their own "last N months" window.
 *
 * Definitions:
 *  - Students: enrolled users holding a gradebook role who confirmed their enrolment.
 *    The confirmation activity uses the course-module ID number "confirmacao_matricula".
 *  - Active: student with at least one currently valid enrolment. Suspended = total - active (dropout).
 *  - Activities: course modules with completion tracking AND a numeric grade item.
 *  - Course completion: course_completions (when the course has criteria) or all tracked activities completed.
 *  - Average grade: course total grade normalised to 0-10.
 *  - NPS: every answer to the first 0-10 question of each Feedback activity (multichoice or numeric).
 *    NPS = %promoters(9-10) - %detractors(0-6).
 *  - Clicks = read events (crud 'r'); Interactions = create/update/delete events; Logs = all events.
 */
class report_data {

    /** @var \stdClass */
    private $course;
    /** @var \context_course */
    private $context;
    private $groupid;
    private $userid;
    private $to;
    private $groupstudents = null;
    private $coursestudents = null;
    private $confirmedstudents = null;

    public function __construct(\stdClass $course, \context_course $context, int $groupid, int $userid, int $to) {
        $this->course = $course;
        $this->context = $context;
        $this->groupid = $groupid;
        $this->userid = $userid;
        $this->to = $to;
    }

    /** Enrolled students of the selected group, before confirmation filtering. */
    private function get_course_students(): array {
        global $DB, $CFG;
        if ($this->coursestudents !== null) {
            return $this->coursestudents;
        }
        $this->coursestudents = [];
        $roleids = array_filter(array_map('intval', explode(',', (string)($CFG->gradebookroles ?? ''))));
        if (!$roleids || $this->groupid < 0) {
            return $this->coursestudents;
        }
        [$rsql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $now = time();
        $params += ['courseid' => $this->course->id, 'ctxid' => $this->context->id, 'now1' => $now, 'now2' => $now];
        $groupjoin = '';
        if ($this->groupid > 0) {
            $groupjoin = 'JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid';
            $params['groupid'] = $this->groupid;
        }
        $sql = "SELECT DISTINCT ue.id AS ueid, u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email,
                       CASE WHEN ue.status = 0 AND e.status = 0 AND ue.timestart <= :now1
                                 AND (ue.timeend = 0 OR ue.timeend > :now2) THEN 1 ELSE 0 END AS isactive
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                  JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
                  JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = :ctxid AND ra.roleid $rsql
                  $groupjoin
              ORDER BY u.lastname, u.firstname";
        foreach ($DB->get_records_sql($sql, $params) as $r) {
            if (!isset($this->coursestudents[$r->id])) {
                $student = clone $r;
                unset($student->ueid);
                $student->isactive = 0;
                $this->coursestudents[$r->id] = $student;
            }
            if ($r->isactive) {
                $this->coursestudents[$r->id]->isactive = 1;
            }
        }
        return $this->coursestudents;
    }

    /**
     * Gets distinct users who confirmed through the marked Quiz or Feedback activity.
     *
     * @return array user IDs keyed by ID
     */
    private function get_confirmed_students(): array {
        global $DB;
        if ($this->confirmedstudents !== null) {
            return $this->confirmedstudents;
        }

        $this->confirmedstudents = [];
        $activities = $DB->get_records_sql(
            "SELECT cm.id, cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.idnumber = :idnumber
                    AND cm.deletioninprogress = 0 AND m.name IN ('quiz', 'feedback')",
            ['courseid' => $this->course->id, 'idnumber' => 'confirmacao_matricula']);
        if (count($activities) !== 1) {
            $key = $activities ? 'confirmationactivityduplicate' : 'confirmationactivitymissing';
            throw new \moodle_exception($key, 'report_finalreport');
        }

        $activity = reset($activities);
        if ($activity->modulename === 'quiz') {
            $this->confirmedstudents = $this->get_quiz_confirmed_students((int)$activity->instance);
        } else {
            $this->confirmedstudents = $this->get_feedback_confirmed_students((int)$activity->instance);
        }
        return $this->confirmedstudents;
    }

    /** @return array user IDs keyed by ID */
    private function get_quiz_confirmed_students(int $quizid): array {
        global $DB;

        $quizsettings = $DB->get_record_sql(
            "SELECT q.grade, q.sumgrades, gi.gradepass
               FROM {quiz} q
               JOIN {grade_items} gi ON gi.courseid = q.course AND gi.itemtype = 'mod'
                                    AND gi.itemmodule = 'quiz' AND gi.iteminstance = q.id
                                    AND gi.itemnumber = 0
              WHERE q.id = :quizid",
            ['quizid' => $quizid]);
        $questioncount = $DB->count_records('quiz_slots', ['quizid' => $quizid]);
        if (!$quizsettings || $questioncount !== 1 || (float)$quizsettings->gradepass <= 0 ||
                (float)$quizsettings->grade <= 0 ||
                (float)$quizsettings->sumgrades <= 0 || (float)$quizsettings->gradepass > (float)$quizsettings->grade) {
            throw new \moodle_exception('confirmationquizinvalid', 'report_finalreport');
        }

        $rows = $DB->get_records_sql(
            "SELECT DISTINCT qa.userid
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
               JOIN {grade_items} gi ON gi.courseid = q.course AND gi.itemtype = 'mod'
                                    AND gi.itemmodule = 'quiz' AND gi.iteminstance = q.id
                                    AND gi.itemnumber = 0
              WHERE q.id = :quizid AND qa.state = 'finished' AND qa.preview = 0 AND qa.timefinish > 0
                    AND qa.timefinish <= :tto AND q.sumgrades > 0 AND gi.gradepass > 0
                    AND (qa.sumgrades / q.sumgrades) * q.grade >= gi.gradepass",
            ['quizid' => $quizid, 'tto' => $this->to]);
        $userids = [];
        foreach ($rows as $row) {
            $userids[(int)$row->userid] = (int)$row->userid;
        }
        return $userids;
    }

    /**
     * Returns the one-based option index for SIM, or null unless the item is exactly SIM/NÃO.
     */
    private function feedback_confirmation_option(string $presentation): ?int {
        $separator = strpos($presentation, '>>>>>');
        if ($separator === false) {
            return null;
        }
        $options = explode('<<<<<', substr($presentation, $separator + 5), 2)[0];
        $options = preg_split('/[|\r\n]+/', $options);
        $labels = [];
        foreach ($options as $option) {
            $option = trim($option);
            if ($option === '') {
                continue;
            }
            $parts = explode('####', $option, 2);
            $label = count($parts) === 2 ? $parts[1] : $parts[0];
            $label = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = \core_text::strtolower(trim($label));
            $labels[] = str_replace('ã', 'a', $label);
        }

        if (count($labels) !== 2 || !in_array('sim', $labels, true) || !in_array('nao', $labels, true)) {
            return null;
        }
        $yesindex = array_search('sim', $labels, true);
        return $yesindex === false ? null : $yesindex + 1;
    }

    /** @return array user IDs keyed by ID */
    private function get_feedback_confirmed_students(int $feedbackid): array {
        global $DB;

        $feedback = $DB->get_record('feedback', ['id' => $feedbackid], 'id, anonymous', MUST_EXIST);
        if ((int)$feedback->anonymous !== 0) {
            throw new \moodle_exception('confirmationfeedbackanonymous', 'report_finalreport');
        }

        $items = $DB->get_records('feedback_item', ['feedback' => $feedbackid],
            'position ASC', 'id, typ, presentation');
        $confirmationitem = null;
        $yesindex = null;
        foreach ($items as $item) {
            if (!in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
                continue;
            }
            $option = $this->feedback_confirmation_option((string)$item->presentation);
            if ($option === null) {
                continue;
            }
            if ($confirmationitem !== null) {
                throw new \moodle_exception('confirmationfeedbackinvalid', 'report_finalreport');
            }
            $confirmationitem = (int)$item->id;
            $yesindex = $option;
        }
        if ($confirmationitem === null) {
            throw new \moodle_exception('confirmationfeedbackinvalid', 'report_finalreport');
        }

        $rows = $DB->get_records_sql(
            "SELECT DISTINCT c.userid
               FROM {feedback_completed} c
               JOIN {feedback_value} v ON v.completed = c.id
              WHERE c.feedback = :feedbackid AND c.userid > 0 AND c.anonymous_response = 0
                    AND c.timemodified <= :tto AND v.item = :itemid AND v.value = :yesvalue",
            ['feedbackid' => $feedbackid, 'tto' => $this->to, 'itemid' => $confirmationitem,
                'yesvalue' => (string)$yesindex]);
        $userids = [];
        foreach ($rows as $row) {
            $userids[(int)$row->userid] = (int)$row->userid;
        }
        return $userids;
    }

    /** Students of the selected group who confirmed their enrolment. */
    public function get_group_students(): array {
        if ($this->groupstudents !== null) {
            return $this->groupstudents;
        }
        $this->groupstudents = [];
        $confirmed = $this->get_confirmed_students();
        foreach ($this->get_course_students() as $id => $student) {
            if (isset($confirmed[$id])) {
                $this->groupstudents[$id] = $student;
            }
        }
        return $this->groupstudents;
    }

    /** Students in scope (group + optional single-student filter). */
    public function get_students(): array {
        $students = $this->get_group_students();
        if ($this->userid) {
            return isset($students[$this->userid]) ? [$this->userid => $students[$this->userid]] : [];
        }
        return $students;
    }

    /** SQL fragment restricting $column to the students in scope. */
    private function user_in(string $column, string $prefix): array {
        global $DB;
        $ids = array_keys($this->get_students());
        if (!$ids) {
            return ['1 = 0', []];
        }
        [$sql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, $prefix);
        return [$column . ' ' . $sql, $params];
    }

    public static function norm10($final, $min, $max): ?float {
        if ($final === null || $max === null || (float)$max <= (float)$min) {
            return null;
        }
        return ((float)$final - (float)$min) / ((float)$max - (float)$min) * 10;
    }

    // ---------------------------------------------------------------- KPIs

    public function count_activities(): int {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod' AND gi.itemmodule = m.name
                                    AND gi.iteminstance = cm.instance AND gi.itemnumber = 0 AND gi.gradetype = 1
              WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0",
            ['courseid' => $this->course->id]);
    }

    /**
     * Course completion rate.
     * - When the course has course-completion criteria, uses Moodle's own result (course_completions, filled by cron).
     * - When it has none (only activity completion), uses "completed ALL activities that track completion".
     * @return array|null ['done','total','pct','mode'] (mode: 'course'|'activities'); null when completion is disabled.
     */
    public function course_completion(): ?array {
        global $DB;
        if (!(new \completion_info($this->course))->is_enabled()) {
            return null;
        }
        $total = count($this->get_students());
        $done = 0;
        $mode = 'course';
        if ($total) {
            if ($DB->record_exists('course_completion_criteria', ['course' => $this->course->id])) {
                [$usql, $params] = $this->user_in('cc.userid', 'cu');
                $params += ['courseid' => $this->course->id, 'tto' => $this->to];
                $done = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cc.userid) FROM {course_completions} cc
                      WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL
                            AND cc.timecompleted <= :tto AND $usql", $params);
            } else {
                $mode = 'activities';
                $tracked = (int)$DB->count_records_sql(
                    "SELECT COUNT(1) FROM {course_modules} cm
                      WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0",
                    ['courseid' => $this->course->id]);
                if ($tracked) {
                    [$usql, $params] = $this->user_in('cmc.userid', 'au');
                    $params += ['courseid' => $this->course->id, 'tto' => $this->to];
                    $rows = $DB->get_records_sql(
                        "SELECT cmc.userid, COUNT(DISTINCT cmc.coursemoduleid) AS cnt
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0
                                AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                                AND cmc.timemodified <= :tto AND $usql
                       GROUP BY cmc.userid", $params);
                    foreach ($rows as $r) {
                        if ((int)$r->cnt >= $tracked) {
                            $done++;
                        }
                    }
                }
            }
        }
        return ['done' => $done, 'total' => $total, 'pct' => $total ? $done / $total * 100 : 0.0, 'mode' => $mode];
    }

    /**
     * Average of the course total grade, normalised to 0-10.
     * With "Natural" aggregation Moodle stores each student's own maximum in grade_grades.rawgrademax
     * (empty grades are excluded), so that is the correct denominator in that case.
     * @return array|null ['avg'=>float(0-10),'count'=>int]
     */
    public function average_grade(): ?array {
        global $DB;
        [$usql, $params] = $this->user_in('gg.userid', 'gu');
        $params += ['courseid' => $this->course->id];
        $rows = $DB->get_records_sql(
            "SELECT gg.id, gg.finalgrade, gg.rawgrademin, gg.rawgrademax, gi.grademin, gi.grademax, gc.aggregation
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
          LEFT JOIN {grade_categories} gc ON gc.id = gi.iteminstance
              WHERE gi.courseid = :courseid AND gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND $usql", $params);
        $sum = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            $min = $r->grademin;
            $max = $r->grademax;
            // 13 = GRADE_AGGREGATE_SUM (Natural).
            if ((int)$r->aggregation === 13 && $r->rawgrademax !== null && (float)$r->rawgrademax > 0) {
                $min = $r->rawgrademin;
                $max = $r->rawgrademax;
            }
            $v = self::norm10($r->finalgrade, $min, $max);
            if ($v !== null) {
                $sum += $v;
                $n++;
            }
        }
        return $n ? ['avg' => $sum / $n, 'count' => $n] : null;
    }

    /**
     * Gets the scores represented by a 0-10 Feedback question.
     * Multichoice answers are stored as option indexes, while rated choices store their scores in presentation.
     *
     * @return array|null Scores in option order, or null when the question is not a 0-10 scale.
     */
    private function nps_choice_scores(string $type, string $presentation): ?array {
        if ($type === 'numeric') {
            $range = explode('|', $presentation);
            return count($range) >= 2 && is_numeric($range[0]) && is_numeric($range[1]) &&
                (float)$range[0] === 0.0 && (float)$range[1] === 10.0 ? range(0, 10) : null;
        }

        if (!in_array($type, ['multichoice', 'multichoicerated'], true)) {
            return null;
        }
        $sep = strpos($presentation, '>>>>>');
        if ($sep === false || !in_array(substr($presentation, 0, 1), ['r', 'd'], true)) {
            return null;
        }
        // Drop the optional horizontal-layout suffix ("<<<<<1") and normalise line breaks.
        $optionstr = explode('<<<<<', substr($presentation, $sep + 5), 2)[0];
        $options = explode('|', str_replace(["\r\n", "\r", "\n"], '|', $optionstr));
        $options = array_values(array_filter($options, function ($o) {
            return trim(strip_tags((string)$o)) !== '';
        }));
        if (count($options) !== 11) {
            return null;
        }

        $scores = [];
        foreach ($options as $index => $option) {
            if ($type === 'multichoicerated') {
                $score = explode('####', $option, 2)[0];
            } else {
                $score = trim(html_entity_decode(strip_tags($option), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (!is_numeric($score) || (float)$score !== (float)$index) {
                return null;
            }
            $scores[] = (float)$score;
        }
        return $scores;
    }

    /**
     * @return array|null ['nps'=>float|null,'responses'=>int,'total'=>int]
     *         'responses' = answers counted within the group/student filters; 'total' = all answers ever given to the NPS questions.
     *         null when no 0-10 Feedback question exists.
     */
    public function nps(): ?array {
        global $DB;
        $items = $DB->get_records_sql(
            "SELECT fi.id, fi.feedback, fi.typ, fi.presentation
               FROM {feedback} f
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = f.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'feedback'
               JOIN {feedback_item} fi ON fi.feedback = f.id AND fi.typ IN ('numeric', 'multichoice', 'multichoicerated')
              WHERE f.course = :courseid AND cm.deletioninprogress = 0
           ORDER BY f.id, fi.position", ['courseid' => $this->course->id]);
        $itemsbyid = [];
        $seenfeedback = [];
        foreach ($items as $it) {
            if (isset($seenfeedback[$it->feedback])) {
                continue;
            }
            $scores = $this->nps_choice_scores($it->typ, (string)$it->presentation);
            if ($scores !== null) {
                $itemsbyid[$it->id] = ['type' => $it->typ, 'scores' => $it->typ === 'numeric' ? null : $scores];
                $seenfeedback[$it->feedback] = true;
            }
        }
        if (!$itemsbyid) {
            return null;
        }
        [$itemsql, $itemparams] = $DB->get_in_or_equal(array_keys($itemsbyid), SQL_PARAMS_NAMED, 'npsitem');
        $total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {feedback_value} v WHERE v.item $itemsql", $itemparams);

        $rows = $DB->get_records_sql(
            "SELECT v.id, v.item, c.userid, c.anonymous_response, v.value
               FROM {feedback_value} v
               JOIN {feedback_completed} c ON c.id = v.completed
              WHERE v.item $itemsql AND c.timemodified <= :tto",
            $itemparams + ['tto' => $this->to]);
        $students = $this->get_students();
        // Anonymous answers cannot be tied to the confirmed-student population.
        $allowanon = false;
        $n = $prom = $detr = 0;
        foreach ($rows as $r) {
            $anonymous = ((int)$r->anonymous_response === 1) || (int)$r->userid === 0;
            if ($anonymous ? !$allowanon : !isset($students[$r->userid])) {
                continue;
            }
            if (!is_numeric($r->value)) {
                continue;
            }
            $item = $itemsbyid[$r->item];
            $score = (float)$r->value;
            if ($item['type'] !== 'numeric') {
                $answerindex = (int)$r->value - 1;
                if (!isset($item['scores'][$answerindex])) {
                    continue;
                }
                $score = $item['scores'][$answerindex];
            }
            $score = max(0, min(10, (int)round($score)));
            $n++;
            if ($score >= 9) {
                $prom++;
            } else if ($score <= 6) {
                $detr++;
            }
        }
        return ['nps' => $n ? ($prom - $detr) / $n * 100 : null, 'responses' => $n, 'total' => $total];
    }

    public function metrics(): array {
        $students = $this->get_students();
        $total = count($students);
        $active = count(array_filter($students, function ($s) {
            return $s->isactive;
        }));
        return [
            'enrolled' => $total,
            'active' => $active,
            'suspended' => $total - $active,
            'activepct' => $total ? $active / $total * 100 : 0.0,
            'dropoutpct' => $total ? ($total - $active) / $total * 100 : 0.0,
            'activities' => $this->count_activities(),
            'completion' => $this->course_completion(),
            'grade' => $this->average_grade(),
            'nps' => $this->nps(),
        ];
    }

    // ---------------------------------------------------------------- Time series

    /**
     * Builds the bucket list between two timestamps. Day index i (days since local midnight of $start)
     * maps to a bucket key through 'daykeys'.
     */
    private function buckets(int $start, int $end, string $gran): array {
        $tz = \core_date::get_user_timezone_object();
        $d = (new \DateTime('@' . $start))->setTimezone($tz);
        $d->setTime(0, 0, 0);
        $s0 = $d->getTimestamp();
        $last = (new \DateTime('@' . $end))->setTimezone($tz);
        $daykeys = [];
        $labels = [];
        while ($d <= $last) {
            if ($gran === 'day') {
                $key = $d->format('Y-m-d');
            } else if ($gran === 'week') {
                $key = $d->format('o-W');
            } else {
                $key = $d->format('Y-m');
            }
            if (!isset($labels[$key])) {
                $fmt = $gran === 'month' ? 'strftimemonthyear' : 'strftimedateshort';
                $labels[$key] = userdate($d->getTimestamp(), get_string($fmt, 'langconfig'));
            }
            $daykeys[] = $key;
            $d->modify('+1 day');
        }
        return ['s0' => $s0, 'daykeys' => $daykeys, 'labels' => $labels];
    }

    /** @return array ['labels'=>[], 'clicks'=>[], 'logs'=>[], 'interactions'=>[]] */
    public function engagement(int $start, int $end, string $gran): array {
        global $DB;
        $b = $this->buckets($start, $end, $gran);
        $keys = array_keys($b['labels']);
        $clicks = array_fill_keys($keys, 0);
        $logs = $clicks;
        [$usql, $params] = $this->user_in('l.userid', 'lu');
        $params += ['courseid' => $this->course->id, 'tfrom' => $b['s0'], 'tto' => $end];
        $expr = 'FLOOR((l.timecreated - ' . (int)$b['s0'] . ') / 86400)';
        $rows = $DB->get_records_sql(
            "SELECT $expr AS dayidx, COUNT(1) AS cnt_all,
                    SUM(CASE WHEN l.crud = 'r' THEN 1 ELSE 0 END) AS cnt_r
               FROM {logstore_standard_log} l
              WHERE l.courseid = :courseid AND l.timecreated BETWEEN :tfrom AND :tto AND $usql
           GROUP BY $expr", $params);
        foreach ($rows as $r) {
            $i = (int)$r->dayidx;
            if (!isset($b['daykeys'][$i])) {
                continue;
            }
            $k = $b['daykeys'][$i];
            $logs[$k] += (int)$r->cnt_all;
            $clicks[$k] += (int)$r->cnt_r;
        }
        $interactions = [];
        foreach ($keys as $k) {
            $interactions[] = $logs[$k] - $clicks[$k];
        }
        return ['labels' => array_values($b['labels']), 'clicks' => array_values($clicks),
            'logs' => array_values($logs), 'interactions' => $interactions];
    }

    /** @return array ['labels'=>[], 'values'=>[]] monthly completions. */
    public function completions_series(int $start, int $end, string $modname = ''): array {
        global $DB;
        $b = $this->buckets($start, $end, 'month');
        $counts = array_fill_keys(array_keys($b['labels']), 0);
        [$usql, $params] = $this->user_in('cmc.userid', 'cu');
        $params += ['courseid' => $this->course->id, 'tfrom' => $b['s0'], 'tto' => $end];
        $modsql = '';
        if ($modname !== '') {
            $modsql = 'AND m.name = :modname';
            $params['modname'] = $modname;
        }
        $expr = 'FLOOR((cmc.timemodified - ' . (int)$b['s0'] . ') / 86400)';
        $rows = $DB->get_records_sql(
            "SELECT $expr AS dayidx, COUNT(1) AS cnt
               FROM {course_modules_completion} cmc
               JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                    AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                    AND cmc.timemodified BETWEEN :tfrom AND :tto AND $usql $modsql
           GROUP BY $expr", $params);
        foreach ($rows as $r) {
            $i = (int)$r->dayidx;
            if (isset($b['daykeys'][$i])) {
                $counts[$b['daykeys'][$i]] += (int)$r->cnt;
            }
        }
        return ['labels' => array_values($b['labels']), 'values' => array_values($counts)];
    }

    /** Percentage change between the last two buckets, or null when not computable. */
    public static function delta(array $values): ?float {
        $n = count($values);
        if ($n < 2 || $values[$n - 2] == 0) {
            return null;
        }
        return ($values[$n - 1] - $values[$n - 2]) / $values[$n - 2] * 100;
    }

    /** Module types (with completion tracking) available in the course: name => label. */
    public function completion_modtypes(): array {
        global $DB;
        $names = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0",
            ['courseid' => $this->course->id]);
        $out = [];
        foreach ($names as $n) {
            $out[$n] = get_string('modulename', 'mod_' . $n);
        }
        \core_collator::asort($out);
        return $out;
    }

}