<?php
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
