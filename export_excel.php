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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/excellib.class.php');
require_once($CFG->libdir . '/completionlib.php');

global $DB;

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/finalreport:view', $context);
require_sesskey();

$state = new \report_finalreport\local\state($course, $context);
$data = $state->data;
$students = $data->get_students();
$studentids = array_keys($students);
$S = function ($key, $a = null) {
    return get_string($key, 'report_finalreport', $a);
};

$categories = $DB->get_records('grade_categories', ['courseid' => $course->id],
    'sortorder ASC', 'id, fullname, parent');
$gradeitems = $DB->get_records_sql(
    "SELECT gi.id, gi.iteminstance, gi.grademin, gi.grademax
       FROM {grade_items} gi
      WHERE gi.courseid = :courseid AND gi.itemtype = 'category'
   ORDER BY gi.sortorder",
    ['courseid' => $course->id]);
$gradecolumns = [];
foreach ($gradeitems as $item) {
    $parts = [];
    $categoryid = (int)$item->iteminstance;
    $depth = 0;
    while (isset($categories[$categoryid]) && $depth < 20) {
        $category = $categories[$categoryid];
        $parts[] = format_string($category->fullname, true, ['context' => $context]);
        $categoryid = (int)$category->parent;
        $depth++;
    }
    $gradecolumns[$item->id] = [
        'name' => implode(' / ', array_reverse($parts)),
        'min' => (float)$item->grademin,
        'max' => (float)$item->grademax,
    ];
}

$bursaryids = [];
$coursecompletion = [];
$hascompletionrule = false;
if ($studentids) {
    [$usersql, $userparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'exportuser');
    $groups = $DB->get_records_sql(
        "SELECT gm.id, gm.userid, g.name
           FROM {groups_members} gm
           JOIN {groups} g ON g.id = gm.groupid
          WHERE g.courseid = :courseid AND gm.userid $usersql",
        ['courseid' => $course->id] + $userparams);
    foreach ($groups as $group) {
        if (\core_text::strtoupper(trim($group->name)) === 'BOLSISTA') {
            $bursaryids[(int)$group->userid] = true;
        }
    }

    if ((new \completion_info($course))->is_enabled()) {
        if ($DB->record_exists('course_completion_criteria', ['course' => $course->id])) {
            $hascompletionrule = true;
            [$usersql, $userparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'completeuser');
            $completedusers = $DB->get_fieldset_sql(
                "SELECT DISTINCT cc.userid
                   FROM {course_completions} cc
                  WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL AND cc.timecompleted <= :tto
                        AND cc.userid $usersql",
                ['courseid' => $course->id, 'tto' => $state->to] + $userparams);
            foreach ($completedusers as $userid) {
                $coursecompletion[(int)$userid] = true;
            }
        } else {
            $tracked = (int)$DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {course_modules} cm
                  WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0",
                ['courseid' => $course->id]);
            if ($tracked > 0) {
                $hascompletionrule = true;
                [$usersql, $userparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'activityuser');
                $completedactivities = $DB->get_records_sql(
                    "SELECT cmc.userid, COUNT(DISTINCT cmc.coursemoduleid) AS completedcount
                       FROM {course_modules_completion} cmc
                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                      WHERE cm.course = :courseid AND cm.completion > 0 AND cm.deletioninprogress = 0
                            AND cmc.completionstate IN (" . COMPLETION_COMPLETE . ", " . COMPLETION_COMPLETE_PASS . ")
                            AND cmc.timemodified <= :tto AND cmc.userid $usersql
                   GROUP BY cmc.userid",
                    ['courseid' => $course->id, 'tto' => $state->to] + $userparams);
                foreach ($completedactivities as $userid => $row) {
                    if ((int)$row->completedcount >= $tracked) {
                        $coursecompletion[(int)$userid] = true;
                    }
                }
            }
        }
    }
}

$gradevalues = [];
if ($studentids && $gradecolumns) {
    [$usersql, $userparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'gradeuser');
    $rows = $DB->get_records_sql(
        "SELECT gg.userid, gg.itemid, gg.finalgrade
           FROM {grade_grades} gg
           JOIN {grade_items} gi ON gi.id = gg.itemid
          WHERE gi.courseid = :courseid AND gi.itemtype = 'category'
                AND gg.finalgrade IS NOT NULL AND gg.userid $usersql",
        ['courseid' => $course->id] + $userparams);
    foreach ($rows as $row) {
        $gradevalues[(int)$row->userid][(int)$row->itemid] = (float)$row->finalgrade;
    }
}

$filename = clean_filename('students_' . $course->shortname . '_' . date('Ymd'));
$wb = new MoodleExcelWorkbook('-');
$wb->send($filename . '.xlsx');
$ws = $wb->add_worksheet($S('excelstudentsheet'));

$headers = [
    $S('excelname'),
    $S('excelemail'),
    $S('excelstatus'),
    $S('excelfunding'),
];
foreach ($gradecolumns as $column) {
    $headers[] = $S('excelgradecategory', $column['name'] . ' (' . $column['min'] . '-' . $column['max'] . ')');
}
$headers[] = $S('excelmention');
foreach ($headers as $column => $header) {
    $ws->write_string(0, $column, $header);
}

$row = 1;
foreach ($students as $userid => $student) {
    $column = 0;
    $ws->write_string($row, $column++, fullname($student));
    $ws->write_string($row, $column++, $student->email);
    $ws->write_string($row, $column++, $student->isactive ? $S('excelactive') : $S('excelsuspendedcancelled'));
    $ws->write_string($row, $column++, isset($bursaryids[$userid]) ? $S('excelbursary') : $S('excelpaying'));
    foreach ($gradecolumns as $itemid => $gradecolumn) {
        if (isset($gradevalues[$userid][$itemid])) {
            $ws->write_number($row, $column, $gradevalues[$userid][$itemid]);
        }
        $column++;
    }
    if (!$hascompletionrule) {
        $ws->write_string($row, $column, $S('excelcompletionundefined'));
    } else {
        $ws->write_string($row, $column, isset($coursecompletion[$userid]) ?
            $S('excelapproved') : $S('excelreproved'));
    }
    $row++;
}

$wb->close();
exit;
