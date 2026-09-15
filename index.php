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

$courseid = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$filterrequested = optional_param('filter', 0, PARAM_BOOL);

// The filter is intentionally an array: more than one module type may be analysed together.
$type = optional_param_array('type', [], PARAM_ALPHANUMEXT);

// PDF links use one scalar parameter because moodle_url cannot add repeated
// type[] parameters. Values are normalised against the course modules below.
if (!$type && $filterrequested) {
    $exporttypes = optional_param('types', '', PARAM_TEXT);
    if ($exporttypes !== '') {
        $type = explode(',', $exporttypes);
    }
}


$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('report/finalreport:view', $context);

$pageparams = ['id' => $course->id];
if ($filterrequested) {
    $pageparams['filter'] = 1;
}
$PAGE->set_url(new moodle_url('/report/finalreport/index.php', $pageparams));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('reporttitle', 'report_finalreport'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$report = \report_finalreport\report_maker::build($course, $filterrequested ? $type : null);

if ($download) {
    require_capability('report/finalreport:export', $context);
    \report_finalreport\pdf_builder::download($report);
}

$PAGE->requires->css(new moodle_url('/report/finalreport/styles.css'));
$renderer = $PAGE->get_renderer('report_finalreport');

echo $OUTPUT->header();
echo $renderer->dashboard($report);
echo $OUTPUT->footer();
