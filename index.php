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

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/courseoverview:view', $context);

$PAGE->set_url(new moodle_url('/report/courseoverview/index.php', ['id' => $id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('pluginname', 'report_courseoverview'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

$state = new \report_courseoverview\local\state($course, $context);
$page = new \report_courseoverview\output\page($state);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('report_courseoverview/page', $page->export_for_template($OUTPUT));
echo $OUTPUT->footer();
