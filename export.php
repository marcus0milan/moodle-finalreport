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
require_once($CFG->libdir . '/pdflib.php');

$id = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/courseoverview:view', $context);
require_sesskey();

$state = new \report_courseoverview\local\state($course, $context);
$page = new \report_courseoverview\output\page($state);
$data = $state->data;
$ind = $page->indicators();
$eng = $data->engagement($state->chart_start($state->eperiod), $state->to, $state->egran);
$items = $data->recent_completions(0, 0);
$datefmt = get_string('strftimedatetimeshort', 'langconfig');
$S = function ($k) {
    return get_string($k, 'report_courseoverview');
};
$filename = clean_filename('overview_' . $course->shortname . '_' . date('Ymd'));
$title = get_string('pluginname', 'report_courseoverview') . ' - ' . format_string($course->fullname, true, ['context' => $context]);
$periodtxt = userdate($state->from, get_string('strftimedateshort', 'langconfig')) . ' - ' .
    userdate($state->to, get_string('strftimedateshort', 'langconfig'));

if ($format === 'xlsx') {
    $wb = new MoodleExcelWorkbook('-');
    $wb->send($filename . '.xlsx');

    $ws = $wb->add_worksheet($S('summary'));
    $ws->write_string(0, 0, strip_tags($title));
    $ws->write_string(1, 0, $S('period'));
    $ws->write_string(1, 1, $periodtxt);
    $ws->write_string(3, 0, $S('indicator'));
    $ws->write_string(3, 1, $S('value'));
    $ws->write_string(3, 2, $S('details'));
    $r = 4;
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

    $ws3 = $wb->add_worksheet($S('completions'));
    foreach ([$S('student'), $S('email'), $S('activity'), $S('type'), $S('completedon'), $S('grade')] as $c => $h) {
        $ws3->write_string(0, $c, $h);
    }
    foreach ($items as $k => $it) {
        $ws3->write_string($k + 1, 0, $it->student);
        $ws3->write_string($k + 1, 1, $it->email);
        $ws3->write_string($k + 1, 2, $it->activity);
        $ws3->write_string($k + 1, 3, $it->type);
        $ws3->write_string($k + 1, 4, userdate($it->time, $datefmt));
        if ($it->grade !== null) {
            $ws3->write_number($k + 1, 5, round($it->grade, 2));
        }
    }
    $wb->close();
    exit;
}

// PDF.
$html = '<h2>' . s(strip_tags($title)) . '</h2><p>' . s($S('period')) . ': ' . s($periodtxt) . '</p>';
$html .= '<table border="1" cellpadding="4"><tr><th><b>' . s($S('indicator')) . '</b></th><th><b>' . s($S('value')) .
    '</b></th><th><b>' . s($S('details')) . '</b></th></tr>';
foreach ($ind as $i) {
    $html .= '<tr><td>' . s($i['label']) . '</td><td>' . s($i['value']) . '</td><td>' . s($i['note']) . '</td></tr>';
}
$html .= '</table><h3>' . s($S('engagement')) . '</h3><table border="1" cellpadding="3"><tr><th><b>' . s($S('period')) .
    '</b></th><th><b>' . s($S('clicks')) . '</b></th><th><b>' . s($S('logs')) . '</b></th><th><b>' .
    s($S('interactions')) . '</b></th></tr>';
foreach ($eng['labels'] as $k => $label) {
    $html .= '<tr><td>' . s($label) . '</td><td>' . $eng['clicks'][$k] . '</td><td>' . $eng['logs'][$k] .
        '</td><td>' . $eng['interactions'][$k] . '</td></tr>';
}
$html .= '</table><h3>' . s($S('recent')) . '</h3><table border="1" cellpadding="3"><tr><th><b>' . s($S('student')) .
    '</b></th><th><b>' . s($S('activity')) . '</b></th><th><b>' . s($S('completedon')) . '</b></th><th><b>' .
    s($S('grade')) . '</b></th></tr>';
foreach (array_slice($items, 0, 200) as $it) {
    $html .= '<tr><td>' . s($it->student) . '</td><td>' . s($it->activity) . '</td><td>' . s(userdate($it->time, $datefmt)) .
        '</td><td>' . ($it->grade === null ? '-' : format_float($it->grade, 1)) . '</td></tr>';
}
$html .= '</table>';

$pdf = new pdf();
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 9);
$pdf->writeHTML($html);
$pdf->Output($filename . '.pdf', 'D');
exit;
