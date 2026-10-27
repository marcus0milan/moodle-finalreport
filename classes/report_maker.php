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
                'levels' => [
                    'low' => 0,
                    'medium' => 0,
                    'high' => 0,
                ],
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

        $usersql = "SELECT eu.id AS userid,
                           COALESCE(logstats.interactions, 0) AS interactions,
                           COALESCE(logstats.activitycount, 0) AS activitycount
                      FROM ({$enrolledsql}) eu
                 LEFT JOIN (
                           SELECT l.userid,
                                  COUNT(1) AS interactions,
                                  COUNT(DISTINCT l.contextinstanceid) AS activitycount
                             FROM {logstore_standard_log} l
                            WHERE l.courseid = :scorecourseid
                              AND l.contextlevel = :scorecontextlevel
                              AND l.contextinstanceid {$insql}
                         GROUP BY l.userid
                 ) logstats ON logstats.userid = eu.id";
        $userscores = $DB->get_records_sql($usersql, array_merge($enrolledparams, $inparams, [
            'scorecourseid' => $course->id,
            'scorecontextlevel' => CONTEXT_MODULE,
        ]));
        $levels = self::classify_engagement($userscores);

        return [
            'summary' => [
                'available' => true,
                'interactions' => $interactions,
                'views' => $views,
                'activeparticipants' => count($activeparticipants),
                'levels' => $levels,
            ],
            'activities' => $activitystats,
        ];
    }

    /**
     * Classifies participants by their relative activity in the course logs.
     *
     * The score combines event volume with the number of different activities
     * used, then assigns positive scores to relative thirds of the cohort.
     *
     * @param array<int, \stdClass> $userscores Participant log scores.
     * @return array<string, int> Engagement levels keyed by level name.
     */
    private static function classify_engagement(array $userscores): array {
        $levels = ['low' => 0, 'medium' => 0, 'high' => 0];
        if (!$userscores) {
            return $levels;
        }

        $scores = [];
        foreach ($userscores as $userid => $userscore) {
            $score = (int)$userscore->interactions + ((int)$userscore->activitycount * 2);
            $scores[$userid] = $score;
        }
        asort($scores, SORT_NUMERIC);
        $participantcount = count($scores);
        $position = 0;

        foreach ($scores as $score) {
            $position++;
            if ($score === 0 || ($position / $participantcount) <= (1 / 3)) {
                $levels['low']++;
            } else if (($position / $participantcount) <= (2 / 3)) {
                $levels['medium']++;
            } else {
                $levels['high']++;
            }
        }

        return $levels;
    }

    /**
     * Gets component grade categories and the normalised final-course distribution.
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
            'series' => [],
        ];
        $finalitem = $DB->get_record(
            'grade_items',
            ['courseid' => $course->id, 'itemtype' => 'course'],
            'id,itemname,grademin,grademax,gradetype',
            IGNORE_MISSING
        );
        if (!$finalitem || (int)$finalitem->gradetype !== GRADE_TYPE_VALUE ||
            $finalitem->grademax <= $finalitem->grademin) {
            return $empty;
        }

        $finalstats = self::get_grade_series($finalitem, $enrolledsql, $enrolledparams);
        if (!$finalstats) {
            return $empty;
        }

        $categoryitems = $DB->get_records(
            'grade_items',
            ['courseid' => $course->id, 'itemtype' => 'category', 'gradetype' => GRADE_TYPE_VALUE],
            'sortorder ASC,id ASC',
            'id,iteminstance,itemname,grademin,grademax,gradetype'
        );
        $series = [];
        $frequencyitem = null;
        $nonfrequencyitems = [];
        foreach ($categoryitems as $categoryitem) {
            $category = $DB->get_record('grade_categories', ['id' => $categoryitem->iteminstance], 'id,fullname');
            $label = $category ? (string)$category->fullname : (string)$categoryitem->itemname;
            $stats = self::get_grade_series($categoryitem, $enrolledsql, $enrolledparams);
            if (!$stats) {
                continue;
            }
            $kind = self::is_frequency_grade_name($label) ? 'frequency' : 'grade';
            $series[] = [
                'key' => 'category-' . $categoryitem->id,
                'label' => $label,
                'kind' => $kind,
                'mandatorythreshold' => null,
            ] + $stats;
            if ($kind === 'frequency') {
                $frequencyitem = $categoryitem;
            } else {
                $nonfrequencyitems[] = $categoryitem;
            }
        }

        // Type Z has only the two mandatory component categories. With a
        // separate final category (type X) the Moodle aggregate remains the
        // authoritative final result and no 75% override is applied.
        $mandatoryfrequency = $frequencyitem && count($series) === 2 && $nonfrequencyitems;
        if ($mandatoryfrequency) {
            foreach ($series as &$categoryseries) {
                if ($categoryseries['kind'] === 'frequency') {
                    $categoryseries['mandatorythreshold'] = 75.0;
                }
            }
            unset($categoryseries);
            $finalstats = self::apply_mandatory_frequency(
                $finalitem,
                $frequencyitem,
                $finalstats,
                $enrolledsql,
                $enrolledparams
            );
        }

        // In type Y a single category is itself the final result. Avoid
        // duplicating that category as a separate "final average" chart.
        if (count($series) !== 1) {
            $series[] = [
                'key' => 'final',
                'label' => get_string('finalgrade', 'report_finalreport'),
                'kind' => 'final',
                'mandatorythreshold' => $mandatoryfrequency ? 75.0 : null,
            ] + $finalstats;
        }

        $count = (int)$finalstats['count'];
        return [
            'available' => true,
            'count' => $count,
            'average' => $finalstats['average'],
            'averagepercent' => $finalstats['averagepercent'],
            'minimum' => $finalstats['minimum'],
            'maximum' => $finalstats['maximum'],
            'distribution' => $finalstats['distribution'],
            'series' => $series,
        ];
    }

    /**
     * Calculates a normalised grade distribution for one Moodle grade item.
     */
    private static function get_grade_series(
        \stdClass $item,
        string $enrolledsql,
        array $enrolledparams
    ): ?array {
        global $DB;

        $sql = "SELECT gg.userid, gg.finalgrade
                  FROM {grade_grades} gg
                  JOIN ({$enrolledsql}) eu ON eu.id = gg.userid
                 WHERE gg.itemid = :itemid
                   AND gg.finalgrade IS NOT NULL";
        $records = $DB->get_records_sql($sql, array_merge($enrolledparams, ['itemid' => $item->id]));
        if (!$records) {
            return null;
        }

        $total = 0.0;
        $distribution = [0, 0, 0, 0, 0];
        foreach ($records as $record) {
            $grade = (float)$record->finalgrade;
            $total += $grade;
            $percent = self::normalise_grade($grade, (float)$item->grademin, (float)$item->grademax);
            $distribution[self::grade_distribution_bucket($percent)]++;
        }

        $count = count($records);
        $average = $total / $count;
        return [
            'count' => $count,
            'average' => $average,
            'averagepercent' => self::normalise_grade(
                $average,
                (float)$item->grademin,
                (float)$item->grademax
            ),
            'minimum' => (float)$item->grademin,
            'maximum' => (float)$item->grademax,
            'distribution' => $distribution,
        ];
    }

    /**
     * Applies the mandatory 75% frequency rule to the final course result.
     */
    private static function apply_mandatory_frequency(
        \stdClass $finalitem,
        \stdClass $frequencyitem,
        array $finalstats,
        string $enrolledsql,
        array $enrolledparams
    ): array {
        global $DB;

        $sql = "SELECT gg.userid, gg.finalgrade
                  FROM {grade_grades} gg
                  JOIN ({$enrolledsql}) eu ON eu.id = gg.userid
                 WHERE gg.itemid = :itemid
                   AND gg.finalgrade IS NOT NULL";
        $frequency = $DB->get_records_sql(
            $sql,
            array_merge($enrolledparams, ['itemid' => $frequencyitem->id])
        );
        $final = $DB->get_records_sql(
            $sql,
            array_merge($enrolledparams, ['itemid' => $finalitem->id])
        );
        $distribution = [0, 0, 0, 0, 0];
        $total = 0.0;
        foreach ($final as $userid => $record) {
            $grade = (float)$record->finalgrade;
            $frequencypct = isset($frequency[$userid])
                ? self::normalise_grade(
                    (float)$frequency[$userid]->finalgrade,
                    (float)$frequencyitem->grademin,
                    (float)$frequencyitem->grademax
                )
                : 0.0;
            $effectivegrade = $frequencypct < 75.0 ? 0.0 : $grade;
            $total += $effectivegrade;
            $effectivepct = self::normalise_grade(
                $effectivegrade,
                (float)$finalitem->grademin,
                (float)$finalitem->grademax
            );
            $distribution[self::grade_distribution_bucket($effectivepct)]++;
        }

        $count = count($final);
        $average = $count ? $total / $count : null;
        return [
            'count' => $count,
            'average' => $average,
            'averagepercent' => $count ? self::normalise_grade(
                (float)$average,
                (float)$finalitem->grademin,
                (float)$finalitem->grademax
            ) : null,
            'minimum' => (float)$finalitem->grademin,
            'maximum' => (float)$finalitem->grademax,
            'distribution' => $distribution,
        ];
    }

    /** Detects conventional frequency/attendance category names. */
    private static function is_frequency_grade_name(string $label): bool {
        $normalised = mb_strtolower(trim($label), 'UTF-8');
        $normalised = strtr($normalised, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ç' => 'c',
        ]);
        return preg_match('/frequ|presenc|attendance|presence|assiduid/', $normalised) === 1;
    }

    /** Normalises a raw grade to bounded percentages. */
    private static function normalise_grade(float $grade, float $minimum, float $maximum): float {
        if ($maximum <= $minimum) {
            return 0.0;
        }
        return max(0.0, min(100.0, (($grade - $minimum) / ($maximum - $minimum)) * 100));
    }

    /** Returns the fixed distribution bucket for a percentage. */
    private static function grade_distribution_bucket(float $percent): int {
        if ($percent < 60) {
            return 0;
        }
        if ($percent < 70) {
            return 1;
        }
        if ($percent < 80) {
            return 2;
        }
        if ($percent < 90) {
            return 3;
        }
        return 4;
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
