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

namespace report_finalreport;

defined('MOODLE_INTERNAL') || die();

/**
 * Collects the native Moodle data shown by the course final report.
 *
 * @package    report_finalreport
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_maker {
    /**
     * Builds the report data for one course.
     *
     * The same enrolled, completion-tracked audience is used in every KPI. This
     * keeps completion, grades and engagement rates comparable and excludes staff.
     *
     * @param \stdClass $course Moodle course record.
     * @param string[]|null $types Module types selected in the filter, or null for all types.
     * @return array Report data ready for either HTML or PDF rendering.
     */
    public static function build(\stdClass $course, ?array $types = null): array {
        global $CFG;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $context = \context_course::instance($course->id);
        $completioninfo = new \completion_info($course);
        list($enrolledsql, $enrolledparams) = get_enrolled_sql(
            $context,
            'moodle/course:isincompletionreports',
            0,
            true
        );

        $allactivities = self::get_activities($course);
        $activitytypes = self::get_activity_types($allactivities);
        $selectedtypes = self::normalise_activity_types($types, $activitytypes);
        $activities = $types === null
            ? $allactivities
            : array_filter($allactivities, static function(array $activity) use ($selectedtypes): bool {
                return in_array($activity['module'], $selectedtypes, true);
            });
        $trackedparticipants = $completioninfo->get_num_tracked_users();
        $completion = self::get_course_completion(
            $course,
            $completioninfo,
            $enrolledsql,
            $enrolledparams,
            $trackedparticipants
        );
        $engagement = self::get_engagement(
            $course,
            $activities,
            $enrolledsql,
            $enrolledparams
        );
        self::add_activity_completion(
            $activities,
            $completioninfo,
            $enrolledsql,
            $enrolledparams,
            $trackedparticipants
        );

        return [
            'course' => $course,
            'generatedat' => time(),
            'participants' => $trackedparticipants,
            'completion' => $completion,
            'grade' => self::get_grades($course, $enrolledsql, $enrolledparams),
            'engagement' => $engagement['summary'],
            'activities' => self::merge_activity_statistics($activities, $engagement['activities']),
            'activitytypes' => $activitytypes,
            'filteractive' => $types !== null,
            'selectedtypes' => $selectedtypes,
        ];
    }

    /**
     * Returns the module types available in the course, with their translated labels.
     *
     * @param array<int, array> $activities Activities indexed by course-module id.
     * @return array<string, string> Module type names keyed by their internal names.
     */
    private static function get_activity_types(array $activities): array {
        $types = [];
        foreach ($activities as $activity) {
            $types[$activity['module']] = $activity['modulelabel'];
        }
        natcasesort($types);

        return $types;
    }

    /**
     * Removes values that are not module types available in this course.
     *
     * @param string[]|null $types Submitted module types.
     * @param array<string, string> $availabletypes Module types available in the course.
     * @return string[] Valid module types.
     */
    private static function normalise_activity_types(?array $types, array $availabletypes): array {
        if ($types === null) {
            return array_keys($availabletypes);
        }

        $selectedtypes = [];
        foreach ($types as $type) {
            $type = clean_param((string)$type, PARAM_ALPHANUMEXT);
            if ($type !== '' && array_key_exists($type, $availabletypes)) {
                $selectedtypes[$type] = $type;
            }
        }

        return array_values($selectedtypes);
    }

    /**
     * Gets course modules in the same order as the course page.
     *
     * @param \stdClass $course Course record.
     * @return array<int, array> Activities indexed by course-module id.
     */
    private static function get_activities(\stdClass $course): array {
        $activities = [];
        $modinfo = get_fast_modinfo($course);

        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }

            $activities[$cm->id] = [
                'cmid' => (int)$cm->id,
                'name' => $cm->name,
                'module' => $cm->modname,
                'modulelabel' => get_string('pluginname', 'mod_' . $cm->modname),
                'completiontracked' => (int)$cm->completion !== COMPLETION_TRACKING_NONE,
                'interactions' => 0,
                'views' => 0,
                'uniqueusers' => 0,
                'lastinteraction' => 0,
                'completed' => null,
                'completionrate' => null,
            ];
        }

        return $activities;
    }

    /**
     * Gets the course-level completion KPI from the native completion tables.
     *
     * @param \stdClass $course Course record.
     * @param \completion_info $completioninfo Completion API object.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @param int $trackedparticipants Number of tracked participants.
     * @return array
     */
    private static function get_course_completion(
        \stdClass $course,
        \completion_info $completioninfo,
        string $enrolledsql,
        array $enrolledparams,
        int $trackedparticipants
    ): array {
        global $DB;

        $configured = (bool)$completioninfo->is_enabled() && $completioninfo->has_criteria();
        $completed = 0;

        if ($configured && $trackedparticipants) {
            $sql = "SELECT COUNT(DISTINCT cc.userid)
                      FROM {course_completions} cc
                      JOIN ({$enrolledsql}) eu ON eu.id = cc.userid
                     WHERE cc.course = :courseid
                       AND cc.timecompleted > 0";
            $params = array_merge($enrolledparams, ['courseid' => $course->id]);
            $completed = (int)$DB->count_records_sql($sql, $params);
        }

        return [
            'configured' => $configured,
            'completed' => $completed,
            'rate' => $configured ? self::rate($completed, $trackedparticipants) : null,
        ];
    }

    /**
     * Adds native activity-completion counts to each activity.
     *
     * @param array<int, array> $activities Activities keyed by course-module id.
     * @param \completion_info $completioninfo Completion API object.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @param int $trackedparticipants Number of tracked participants.
     * @return void
     */
    private static function add_activity_completion(
        array &$activities,
        \completion_info $completioninfo,
        string $enrolledsql,
        array $enrolledparams,
        int $trackedparticipants
    ): void {
        global $DB;

        if (!$activities || !$completioninfo->is_enabled()) {
            return;
        }

        $trackedids = array_keys(array_filter($activities, static function(array $activity): bool {
            return $activity['completiontracked'];
        }));
        if (!$trackedids) {
            return;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($trackedids, SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT cmc.coursemoduleid AS cmid, COUNT(DISTINCT cmc.userid) AS completed
                  FROM {course_modules_completion} cmc
                  JOIN ({$enrolledsql}) eu ON eu.id = cmc.userid
                 WHERE cmc.coursemoduleid {$insql}
                   AND cmc.completionstate > :incompletestate
              GROUP BY cmc.coursemoduleid";
        $params = array_merge(
            $enrolledparams,
            $inparams,
            ['incompletestate' => COMPLETION_INCOMPLETE]
        );
        $records = $DB->get_records_sql($sql, $params);

        foreach ($trackedids as $cmid) {
            $completed = isset($records[$cmid]) ? (int)$records[$cmid]->completed : 0;
            $activities[$cmid]['completed'] = $completed;
            $activities[$cmid]['completionrate'] = self::rate($completed, $trackedparticipants);
        }
    }

    /**
     * Gets event counts from Moodle's standard log store when it is available.
     *
     * @param \stdClass $course Course record.
     * @param array<int, array> $activities Activities keyed by course-module id.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @return array Summary and per-activity engagement values.
     */
    private static function get_engagement(
        \stdClass $course,
        array $activities,
        string $enrolledsql,
        array $enrolledparams
    ): array {
        global $DB;

        $empty = [
            'summary' => [
                'available' => false,
                'interactions' => 0,
                'views' => 0,
                'activeparticipants' => 0,
            ],
            'activities' => [],
        ];
        if (!$DB->get_manager()->table_exists(new \xmldb_table('logstore_standard_log'))) {
            return $empty;
        }
        if (!$activities) {
            $empty['summary']['available'] = true;
            return $empty;
        }

        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED, 'logcm');
        $sql = "SELECT l.contextinstanceid AS cmid,
                       COUNT(1) AS interactions,
                       SUM(CASE WHEN l.action = :viewaction AND l.target = :viewtarget THEN 1 ELSE 0 END) AS views,
                       COUNT(DISTINCT l.userid) AS uniqueusers,
                       MAX(l.timecreated) AS lastinteraction
                  FROM {logstore_standard_log} l
                  JOIN ({$enrolledsql}) eu ON eu.id = l.userid
                 WHERE l.courseid = :courseid
                   AND l.contextlevel = :modulecontext
                   AND l.contextinstanceid {$insql}
              GROUP BY l.contextinstanceid";
        $params = array_merge($enrolledparams, $inparams, [
            'viewaction' => 'viewed',
            'viewtarget' => 'course_module',
            'courseid' => $course->id,
            'modulecontext' => CONTEXT_MODULE,
        ]);
        $activitystats = $DB->get_records_sql($sql, $params);

        $interactions = 0;
        $views = 0;
        $activeparticipants = [];
        foreach ($activitystats as $stat) {
            $interactions += (int)$stat->interactions;
            $views += (int)$stat->views;
        }

        $usersql = "SELECT DISTINCT l.userid
                      FROM {logstore_standard_log} l
                      JOIN ({$enrolledsql}) eu ON eu.id = l.userid
                     WHERE l.courseid = :courseid
                       AND l.contextlevel = :modulecontext
                       AND l.contextinstanceid {$insql}";
        $userparams = array_merge($enrolledparams, $inparams, [
            'courseid' => $course->id,
            'modulecontext' => CONTEXT_MODULE,
        ]);
        $activeparticipants = $DB->get_fieldset_sql($usersql, $userparams);

        return [
            'summary' => [
                'available' => true,
                'interactions' => $interactions,
                'views' => $views,
                'activeparticipants' => count($activeparticipants),
            ],
            'activities' => $activitystats,
        ];
    }

    /**
     * Gets average final course grade and a normalised distribution.
     *
     * @param \stdClass $course Course record.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @return array
     */
    private static function get_grades(\stdClass $course, string $enrolledsql, array $enrolledparams): array {
        global $DB;

        $empty = [
            'available' => false,
            'count' => 0,
            'average' => null,
            'averagepercent' => null,
            'minimum' => null,
            'maximum' => null,
            'distribution' => [0, 0, 0, 0, 0],
        ];
        $item = $DB->get_record(
            'grade_items',
            ['courseid' => $course->id, 'itemtype' => 'course'],
            'id,grademin,grademax,gradetype',
            IGNORE_MISSING
        );
        if (!$item || (int)$item->gradetype !== GRADE_TYPE_VALUE || $item->grademax <= $item->grademin) {
            return $empty;
        }

        $sql = "SELECT gg.id, gg.finalgrade
                  FROM {grade_grades} gg
                  JOIN ({$enrolledsql}) eu ON eu.id = gg.userid
                 WHERE gg.itemid = :itemid
                   AND gg.finalgrade IS NOT NULL";
        $records = $DB->get_records_sql($sql, array_merge($enrolledparams, ['itemid' => $item->id]));
        if (!$records) {
            return $empty;
        }

        $total = 0.0;
        $distribution = [0, 0, 0, 0, 0];
        foreach ($records as $record) {
            $grade = (float)$record->finalgrade;
            $total += $grade;
            $percent = max(0, min(100, (($grade - $item->grademin) / ($item->grademax - $item->grademin)) * 100));
            $bucket = $percent < 60 ? 0 : ($percent < 70 ? 1 : ($percent < 80 ? 2 : ($percent < 90 ? 3 : 4)));
            $distribution[$bucket]++;
        }

        $count = count($records);
        $average = $total / $count;
        return [
            'available' => true,
            'count' => $count,
            'average' => $average,
            'averagepercent' => (($average - $item->grademin) / ($item->grademax - $item->grademin)) * 100,
            'minimum' => (float)$item->grademin,
            'maximum' => (float)$item->grademax,
            'distribution' => $distribution,
        ];
    }

    /**
     * Merges the log rows into the activity list.
     *
     * @param array<int, array> $activities Activities keyed by course-module id.
     * @param array<int, \stdClass> $statistics Log statistics keyed by course-module id.
     * @return array<int, array>
     */
    private static function merge_activity_statistics(array $activities, array $statistics): array {
        foreach ($statistics as $cmid => $statistic) {
            if (!isset($activities[$cmid])) {
                continue;
            }
            $activities[$cmid]['interactions'] = (int)$statistic->interactions;
            $activities[$cmid]['views'] = (int)$statistic->views;
            $activities[$cmid]['uniqueusers'] = (int)$statistic->uniqueusers;
            $activities[$cmid]['lastinteraction'] = (int)$statistic->lastinteraction;
        }

        return array_values($activities);
    }

    /**
     * Returns a percentage or null when there is no valid denominator.
     *
     * @param int $value Numerator.
     * @param int $total Denominator.
     * @return float|null
     */
    private static function rate(int $value, int $total): ?float {
        return $total ? ($value / $total) * 100 : null;
    }
}
