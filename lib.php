<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Adds the report to the course "Reports" navigation.
 */
function report_courseoverview_extend_navigation_course($navigation, $course, $context) {
    if (has_capability('report/courseoverview:view', $context)) {
        $url = new moodle_url('/report/courseoverview/index.php', ['id' => $course->id]);
        $navigation->add(get_string('pluginname', 'report_courseoverview'), $url,
            navigation_node::TYPE_SETTING, null, null, new pix_icon('i/report', ''));
    }
}
