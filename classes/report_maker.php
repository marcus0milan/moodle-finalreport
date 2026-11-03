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
    /** Activity modules that only structure the course page and are not analysed. */
    private const IGNORED_MODULES = ['label', 'subsection'];

    /** Default minimum attendance (percent) used as a reference line in the frequency chart. */
    private const DEFAULT_FREQUENCY_THRESHOLD = 75;

    /**
     * Builds the report data for one course.
     *
     * The same enrolled, completion-tracked audience is used in every KPI. This
     * keeps completion, grades and engagement rates comparable and excludes staff.
     * When the course uses separate groups, teachers without access to all groups
     * only see the participants of their own groups.
     *
     * @param \stdClass $course Moodle course record.
     * @param string[]|null $types Module types selected in the filter, or null for all types.
     * @return array Report data ready for either HTML or PDF rendering.
     */
    public static function build(\stdClass $course, ?array $types = null): array {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grouplib.php');

        $context = \context_course::instance($course->id);
        $completioninfo = new \completion_info($course);

        // Respect "separate groups" for people who cannot access all groups.
        $groupids = 0;
        $nousers = false;
        $restrictedtogroups = false;
        if (groups_get_course_groupmode($course) == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups', $context)) {
            $restrictedtogroups = true;
            $mygroups = groups_get_all_groups($course->id, $USER->id, 0, 'g.id');
            if ($mygroups) {
                $groupids = array_keys($mygroups);
            } else {
                $nousers = true;
            }
        }

        [$enrolledsql, $enrolledparams] = get_enrolled_sql(
            $context,
            'moodle/course:isincompletionreports',
            $groupids,
            true
        );
        if ($nousers) {
            $enrolledsql = "SELECT eu0.id FROM ({$enrolledsql}) eu0 WHERE 1 = 0";
        }
        $trackedparticipants = (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM ({$enrolledsql}) eux",
            $enrolledparams
        );

        $allactivities = self::get_activities($course);
        $activitytypes = self::get_activity_types($allactivities);
        $selectedtypes = self::normalise_activity_types($types, $activitytypes);

        // A filter with every box unticked would produce an empty report: fall back to all types.
        $emptyfilter = false;
        if ($types !== null && !$selectedtypes) {
            $emptyfilter = true;
            $types = null;
            $selectedtypes = array_keys($activitytypes);
        }

        $activities = $types === null
            ? $allactivities
            : array_filter($allactivities, static function(array $activity) use ($selectedtypes): bool {
                return in_array($activity['module'], $selectedtypes, true);
            });

        $threshold = self::get_frequency_threshold();
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
            'grade' => self::get_grades($course, $enrolledsql, $enrolledparams, $threshold),
            'engagement' => $engagement['summary'],
            'activities' => self::merge_activity_statistics($activities, $engagement['activities']),
            'activitytypes' => $activitytypes,
            'filteractive' => $types !== null,
            'selectedtypes' => $selectedtypes,
            'emptyfilter' => $emptyfilter,
            'restrictedtogroups' => $restrictedtogroups,
            'gradesneedupdate' => $DB->record_exists('grade_items', ['courseid' => $course->id, 'needsupdate' => 1]),
            'frequencythreshold' => $threshold,
        ];
    }

    /**
     * Returns the configured attendance reference (percent), bounded to 1-100.
     *
     * @return float
     */
    private static function get_frequency_threshold(): float {
        $value = get_config('report_finalreport', 'frequencythreshold');
        if ($value === false || $value === null || $value === '') {
            $value = self::DEFAULT_FREQUENCY_THRESHOLD;
        }

        return max(1.0, min(100.0, (float)$value));
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
            if (!empty($cm->deletioninprogress) || in_array($cm->modname, self::IGNORED_MODULES, true)) {
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
     * Only "complete" and "complete (pass)" count as completed; "complete (fail)" does not.
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

        [$insql, $inparams] = $DB->get_in_or_equal($trackedids, SQL_PARAMS_NAMED, 'cm');
        [$statesql, $stateparams] = $DB->get_in_or_equal(
            [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS],
            SQL_PARAMS_NAMED,
            'state'
        );
        $sql = "SELECT cmc.coursemoduleid AS cmid, COUNT(DISTINCT cmc.userid) AS completed
                  FROM {course_modules_completion} cmc
                  JOIN ({$enrolledsql}) eu ON eu.id = cmc.userid
                 WHERE cmc.coursemoduleid {$insql}
                   AND cmc.completionstate {$statesql}
              GROUP BY cmc.coursemoduleid";
        $records = $DB->get_records_sql($sql, array_merge($enrolledparams, $inparams, $stateparams));

        foreach ($trackedids as $cmid) {
            $completed = isset($records[$cmid]) ? (int)$records[$cmid]->completed : 0;
            $activities[$cmid]['completed'] = $completed;
            $activities[$cmid]['completionrate'] = self::rate($completed, $trackedparticipants);
        }
    }

    /**
     * Tells whether Moodle's standard log store exists and is enabled.
     *
     * @return bool
     */
    private static function logstore_available(): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            return false;
        }

        return array_key_exists('logstore_standard', get_log_manager()->get_readers('\core\log\sql_reader'));
    }

    /**
     * Gets event counts from Moodle's standard log store when it is available.
     *
     * Events written by an administrator logged in as the participant, and
     * anonymous events, are ignored.
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
                'interactions' => null,
                'views' => null,
                'activeparticipants' => null,
                'levels' => ['low' => 0, 'medium' => 0, 'high' => 0],
            ],
            'activities' => [],
        ];
        if (!self::logstore_available()) {
            return $empty;
        }
        $empty['summary']['available'] = true;
        $empty['summary']['interactions'] = 0;
        $empty['summary']['views'] = 0;
        $empty['summary']['activeparticipants'] = 0;
        if (!$activities) {
            return $empty;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED, 'logcm');
        $sql = "SELECT l.contextinstanceid AS cmid,
                       COUNT(1) AS interactions,
                       SUM(CASE WHEN l.action = :viewaction AND l.target = :viewtarget THEN 1 ELSE 0 END) AS views,
                       COUNT(DISTINCT l.userid) AS uniqueusers,
                       MAX(l.timecreated) AS lastinteraction
                  FROM {logstore_standard_log} l
                  JOIN ({$enrolledsql}) eu ON eu.id = l.userid
                 WHERE l.courseid = :courseid
                   AND l.contextlevel = :modulecontext
                   AND l.anonymous = 0
                   AND l.realuserid IS NULL
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
        foreach ($activitystats as $stat) {
            $interactions += (int)$stat->interactions;
            $views += (int)$stat->views;
        }

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
                              AND l.anonymous = 0
                              AND l.realuserid IS NULL
                              AND l.contextinstanceid {$insql}
                         GROUP BY l.userid
                 ) logstats ON logstats.userid = eu.id";
        $userscores = $DB->get_records_sql($usersql, array_merge($enrolledparams, $inparams, [
            'scorecourseid' => $course->id,
            'scorecontextlevel' => CONTEXT_MODULE,
        ]));

        $activeparticipants = 0;
        foreach ($userscores as $userscore) {
            if ((int)$userscore->interactions > 0) {
                $activeparticipants++;
            }
        }

        return [
            'summary' => [
                'available' => true,
                'interactions' => $interactions,
                'views' => $views,
                'activeparticipants' => $activeparticipants,
                'levels' => self::classify_engagement($userscores, count($activities)),
            ],
            'activities' => $activitystats,
        ];
    }

    /**
     * Classifies participants by how many of the course activities they interacted with.
     *
     * The rule is absolute and deterministic: less than one third of the activities
     * is low engagement, from one third to less than two thirds is medium, and two
     * thirds or more is high. Participants with the same usage always get the same level.
     *
     * @param array<int, \stdClass> $userscores Participant log scores.
     * @param int $activitycount Number of activities in the analysis.
     * @return array<string, int> Engagement levels keyed by level name.
     */
    private static function classify_engagement(array $userscores, int $activitycount): array {
        $levels = ['low' => 0, 'medium' => 0, 'high' => 0];

        foreach ($userscores as $userscore) {
            $used = (int)$userscore->activitycount;
            if ($activitycount < 1 || ($used * 3) < $activitycount) {
                $levels['low']++;
            } else if (($used * 3) < ($activitycount * 2)) {
                $levels['medium']++;
            } else {
                $levels['high']++;
            }
        }

        return $levels;
    }

    /**
     * Gets component grade categories and the final-course distribution, exactly as in the gradebook.
     *
     * Grades are never altered: the attendance threshold is only a reference value
     * used to count how many participants are below it in the attendance category.
     *
     * @param \stdClass $course Course record.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @param float $threshold Attendance reference (percent).
     * @return array
     */
    private static function get_grades(
        \stdClass $course,
        string $enrolledsql,
        array $enrolledparams,
        float $threshold
    ): array {
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
            'id,iteminstance,itemname,grademin,grademax,gradetype',
            IGNORE_MISSING
        );
        if (!$finalitem || (int)$finalitem->gradetype !== GRADE_TYPE_VALUE ||
            $finalitem->grademax <= $finalitem->grademin) {
            return $empty;
        }

        $finalnatural = (int)$DB->get_field('grade_categories', 'aggregation', ['id' => $finalitem->iteminstance])
            === GRADE_AGGREGATE_SUM;
        $finalstats = self::get_grade_series($finalitem, $finalnatural, $enrolledsql, $enrolledparams);
        if (!$finalstats) {
            return $empty;
        }

        $categoryitems = $DB->get_records(
            'grade_items',
            ['courseid' => $course->id, 'itemtype' => 'category', 'gradetype' => GRADE_TYPE_VALUE],
            'sortorder ASC,id ASC',
            'id,iteminstance,itemname,grademin,grademax,gradetype'
        );
        $categories = [];
        if ($categoryitems) {
            $categoryids = array_map(static function(\stdClass $item): int {
                return (int)$item->iteminstance;
            }, $categoryitems);
            $categories = $DB->get_records_list('grade_categories', 'id', $categoryids, '', 'id,fullname,aggregation');
        }

        $series = [];
        foreach ($categoryitems as $categoryitem) {
            $category = $categories[$categoryitem->iteminstance] ?? null;
            $label = $category ? (string)$category->fullname : (string)$categoryitem->itemname;
            $natural = $category && (int)$category->aggregation === GRADE_AGGREGATE_SUM;
            $isfrequency = self::is_frequency_grade_name($label);
            $stats = self::get_grade_series(
                $categoryitem,
                $natural,
                $enrolledsql,
                $enrolledparams,
                $isfrequency ? $threshold : null
            );
            if (!$stats) {
                continue;
            }
            $series[] = [
                'key' => 'category-' . $categoryitem->id,
                'label' => $label,
                'kind' => $isfrequency ? 'frequency' : 'grade',
                'threshold' => $isfrequency ? $threshold : null,
            ] + $stats;
        }

        // When a single category is itself the final result, do not repeat it as a separate final chart.
        if (count($series) !== 1) {
            $series[] = [
                'key' => 'final',
                'label' => get_string('finalgrade', 'report_finalreport'),
                'kind' => 'final',
                'threshold' => null,
            ] + $finalstats;
        }

        return [
            'available' => true,
            'count' => (int)$finalstats['count'],
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
     *
     * With natural aggregation the maximum can differ for each participant, so the
     * per-participant range stored by the gradebook is used for those items.
     *
     * @param \stdClass $item Grade item record.
     * @param bool $natural Whether the item's category uses natural aggregation.
     * @param string $enrolledsql SQL for completion-tracked, enrolled users.
     * @param array $enrolledparams Parameters for $enrolledsql.
     * @param float|null $threshold Optional reference (percent) to count participants below it.
     * @return array|null Null when no participant has a grade.
     */
    private static function get_grade_series(
        \stdClass $item,
        bool $natural,
        string $enrolledsql,
        array $enrolledparams,
        ?float $threshold = null
    ): ?array {
        global $DB;

        $sql = "SELECT gg.userid, gg.finalgrade, gg.rawgrademin, gg.rawgrademax
                  FROM {grade_grades} gg
                  JOIN ({$enrolledsql}) eu ON eu.id = gg.userid
                 WHERE gg.itemid = :itemid
                   AND gg.finalgrade IS NOT NULL";
        $records = $DB->get_records_sql($sql, array_merge($enrolledparams, ['itemid' => $item->id]));
        if (!$records) {
            return null;
        }

        $itemmin = (float)$item->grademin;
        $itemmax = (float)$item->grademax;
        $totalpercent = 0.0;
        $below = 0;
        $distribution = [0, 0, 0, 0, 0];
        foreach ($records as $record) {
            $min = $itemmin;
            $max = $itemmax;
            if ($natural && $record->rawgrademax !== null && $record->rawgrademin !== null &&
                    (float)$record->rawgrademax > (float)$record->rawgrademin) {
                $min = (float)$record->rawgrademin;
                $max = (float)$record->rawgrademax;
            }
            $percent = self::normalise_grade((float)$record->finalgrade, $min, $max);
            $totalpercent += $percent;
            $distribution[self::grade_distribution_bucket($percent)]++;
            if ($threshold !== null && $percent < $threshold) {
                $below++;
            }
        }

        $count = count($records);
        $averagepercent = $totalpercent / $count;
        return [
            'count' => $count,
            'average' => $itemmin + (($averagepercent / 100) * ($itemmax - $itemmin)),
            'averagepercent' => $averagepercent,
            'minimum' => $itemmin,
            'maximum' => $itemmax,
            'distribution' => $distribution,
            'belowthreshold' => $threshold === null ? null : $below,
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
