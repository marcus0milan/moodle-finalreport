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

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/finalreport:view', $context);
require_sesskey();

$state = new \report_finalreport\local\state($course, $context);
$page = new \report_finalreport\output\page($state);
$data = $state->data;
$ind = $page->indicators();
$eng = $data->engagement($state->chart_start($state->eperiod), $state->to, $state->egran);
$S = function ($k) {
    return get_string($k, 'report_finalreport');
};
$filename = clean_filename('overview_' . $course->shortname . '_' . date('Ymd'));
$title = get_string('pluginname', 'report_finalreport') . ' - ' . format_string($course->fullname, true, ['context' => $context]);
$wb = new MoodleExcelWorkbook('-');
$wb->send($filename . '.xlsx');

$ws = $wb->add_worksheet($S('summary'));
$ws->write_string(0, 0, strip_tags($title));
$ws->write_string(1, 0, $S('indicator'));
$ws->write_string(1, 1, $S('value'));
$ws->write_string(1, 2, $S('details'));
$r = 2;
foreach ($ind as $i) {
    $ws->write_string($r, 0, $i['label']);
    $ws->write_string($r, 1, $i['value']);
    $ws->write_string($r, 2, $i['note']);
    $r++;
}

$ws2 = $wb->add_worksheet($S('engagement'));
foreach ([$S('period'), $S('clicks'), $S('logs'), $S('interactions')] as $c => $h) {
    $ws2->write_string(0, $c, $h);
}
foreach ($eng['labels'] as $k => $label) {
    $ws2->write_string($k + 1, 0, $label);
    $ws2->write_number($k + 1, 1, $eng['clicks'][$k]);
    $ws2->write_number($k + 1, 2, $eng['logs'][$k]);
    $ws2->write_number($k + 1, 3, $eng['interactions'][$k]);
}

$wb->close();
exit;
