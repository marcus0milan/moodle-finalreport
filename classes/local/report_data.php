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
 * Definitions:
 *  - Students: users enrolled in the course holding a gradebook role ($CFG->gradebookroles).
 *  - Active: student with at least one currently valid enrolment. Suspended = total - active (dropout).
 *  - Activities: course modules with completion tracking AND a numeric grade item.
 *  - Course completion: course_completions.timecompleted up to the end of the period.
 *  - Average grade: course total grade normalised to 0-10.
 *  - NPS: first Feedback activity with a numeric 0-10 question. NPS = %promoters(9-10) - %detractors(0-6).
 *  - Clicks = read events (crud 'r'); Interactions = create/update/delete events; Logs = all events.
 */
class report_data {

    /** @var \stdClass */
    private $course;
    /** @var \context_course */
    private $context;
    private $groupid;
    private $userid;
    private $from;
    private $to;
    private $groupstudents = null;

    public function __construct(\stdClass $course, \context_course $context, int $groupid, int $userid, int $from, int $to) {
        $this->course = $course;
        $this->context = $context;
        $this->groupid = $groupid;
        $this->userid = $userid;
        $this->from = $from;
        $this->to = $to;
    }

    /** Students of the selected group (ignores the single-student filter). */
    public function get_group_students(): array {
        global $DB, $CFG;
        if ($this->groupstudents !== null) {
            return $this->groupstudents;
        }
        $this->groupstudents = [];
        $roleids = array_filter(array_map('intval', explode(',', (string)($CFG->gradebookroles ?? ''))));
        if (!$roleids || $this->groupid < 0) {
            return $this->groupstudents;
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
            if (!isset($this->groupstudents[$r->id])) {
                $student = clone $r;
                unset($student->ueid);
                $student->isactive = 0;
                $this->groupstudents[$r->id] = $student;
            }
            if ($r->isactive) {
                $this->groupstudents[$r->id]->isactive = 1;
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

    /** @return array|null ['done'=>int,'total'=>int,'pct'=>float] or null when course completion is disabled. */
    public function course_completion(): ?array {
        global $DB;
        if (!(new \completion_info($this->course))->is_enabled()) {
            return null;
        }
        $total = count($this->get_students());
        $done = 0;
        if ($total) {
            [$usql, $params] = $this->user_in('cc.userid', 'cu');
            $params += ['courseid' => $this->course->id, 'tto' => $this->to];
            $done = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT cc.userid) FROM {course_completions} cc
                  WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL
                        AND cc.timecompleted <= :tto AND $usql", $params);
        }
        return ['done' => $done, 'total' => $total, 'pct' => $total ? $done / $total * 100 : 0.0];
    }

    /** @return array|null ['avg'=>float(0-10),'count'=>int] */
    public function average_grade(): ?array {
        global $DB;
        [$usql, $params] = $this->user_in('gg.userid', 'gu');
        $params += ['courseid' => $this->course->id];
        $rows = $DB->get_records_sql(
            "SELECT gg.id, gg.finalgrade, gi.grademin, gi.grademax
               FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id
              WHERE gi.courseid = :courseid AND gi.itemtype = 'course' AND gg.finalgrade IS NOT NULL AND $usql", $params);
        $sum = 0.0;
        $n = 0;
        foreach ($rows as $r) {
            $v = self::norm10($r->finalgrade, $r->grademin, $r->grademax);
            if ($v !== null) {
                $sum += $v;
                $n++;
            }
        }
        return $n ? ['avg' => $sum / $n, 'count' => $n] : null;
    }

    /** @return array|null ['nps'=>float,'responses'=>int] or null when no 0-10 numeric Feedback question exists. */
    public function nps(): ?array {
        global $DB;
        $items = $DB->get_records_sql(
            "SELECT fi.id, fi.presentation
               FROM {feedback} f
               JOIN {course_modules} cm ON cm.instance = f.id AND cm.course = f.course
               JOIN {modules} m ON m.id = cm.module AND m.name = 'feedback'
               JOIN {feedback_item} fi ON fi.feedback = f.id AND fi.typ = 'numeric'
              WHERE f.course = :courseid AND cm.deletioninprogress = 0
           ORDER BY f.id, fi.position", ['courseid' => $this->course->id]);
        $itemid = 0;
        foreach ($items as $it) {
            $p = explode('|', (string)$it->presentation);
            if (count($p) >= 2 && trim($p[0]) === '0' && trim($p[1]) === '10') {
                $itemid = $it->id;
                break;
            }
        }
        if (!$itemid) {
            return null;
        }
        $rows = $DB->get_records_sql(
            "SELECT v.id, c.userid, v.value
               FROM {feedback_value} v
               JOIN {feedback_completed} c ON c.id = v.completed
              WHERE v.item = :item AND c.timemodified BETWEEN :tfrom AND :tto",
            ['item' => $itemid, 'tfrom' => $this->from, 'tto' => $this->to]);
        $students = $this->get_students();
        $allowanon = (!$this->userid && $this->groupid == 0);
        $n = $prom = $detr = 0;
        foreach ($rows as $r) {
            if (!isset($students[$r->userid]) && !($allowanon && (int)$r->userid === 0)) {
                continue;
            }
            $score = max(0, min(10, (int)round((float)$r->value)));
            $n++;
            if ($score >= 9) {
                $prom++;
            } else if ($score <= 6) {
                $detr++;
            }
        }
        return $n ? ['nps' => ($prom - $detr) / $n * 100, 'responses' => $n] : ['nps' => null, 'responses' => 0];
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

    // ---------------------------------------------------------------- Table

    private function completion_from_where(): array {
        [$usql, $params] = $this->user_in('cmc.userid', 'tu');
        $params += ['courseid' => $this->course->id, 'tfrom' => $this->from, 'tto' => $this->to];
        $sql = "FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                JOIN {modules} m ON m.id = cm.module
                JOIN {user} u ON u.id = cmc.userid
           LEFT JOIN {grade_items} gi ON gi.courseid = cm.course AND gi.itemtype = 'mod' AND gi.itemmodule = m.name
                                      AND gi.iteminstance = cm.instance AND gi.itemnumber = 0
           LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cmc.userid
               WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                     AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                     AND cmc.timemodified BETWEEN :tfrom AND :tto AND $usql";
        return [$sql, $params];
    }

    public function count_completions(): int {
        global $DB;
        [$sql, $params] = $this->completion_from_where();
        return (int)$DB->count_records_sql('SELECT COUNT(1) ' . $sql, $params);
    }

    /**
     * Latest activity completions. $limit = 0 returns everything.
     * @return array of objects: student, email, activity, type, time, grade (0-10 or null)
     */
    public function recent_completions(int $offset = 0, int $limit = 0): array {
        global $DB;
        require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');
        [$sql, $params] = $this->completion_from_where();
        $rows = $DB->get_records_sql(
            "SELECT cmc.id, cmc.timemodified, cm.id AS cmid, m.name AS modname, u.email,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename,
                    gg.finalgrade, gi.grademin, gi.grademax $sql
           ORDER BY cmc.timemodified DESC, cmc.id DESC", $params, $offset, $limit);
        $modinfo = get_fast_modinfo($this->course);
        $format = course_get_format($this->course);
        $out = [];
        foreach ($rows as $r) {
            $cm = $modinfo->cms[$r->cmid] ?? null;
            $name = $cm ? $cm->name : '-';
            if ($cm) {
                $name = $format->get_section_name($cm->sectionnum) . ' · ' . $cm->name;
            }
            $out[] = (object)[
                'student' => fullname($r),
                'email' => $r->email,
                'activity' => $name,
                'type' => get_string('modulename', 'mod_' . $r->modname),
                'time' => (int)$r->timemodified,
                'grade' => self::norm10($r->finalgrade, $r->grademin, $r->grademax),
            ];
        }
        return $out;
    }
}
