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

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Course final report';
$string['finalreport:view'] = 'View the course final report';
$string['finalreport:export'] = 'Download the course final report as PDF';
$string['privacy:metadata'] = 'The Course final report plugin only displays data that already exists in Moodle and does not store any personal data.';
$string['eventreportviewed'] = 'Course final report viewed';
$string['reporttitle'] = 'Course final report';
$string['reportdescription'] = 'Completion, grade and engagement indicators for enrolled participants.';
$string['downloadpdf'] = 'Download PDF report';
$string['chartview'] = 'Chart view';
$string['tableview'] = 'Activities table';
$string['generatedat'] = 'Generated on {$a}';
$string['participants'] = 'Tracked participants';
$string['coursecompletion'] = 'Course completion';
$string['completedparticipants'] = 'Completed participants';
$string['averagegrade'] = 'Average grade';
$string['gradedparticipants'] = 'Participants with a grade';
$string['interactions'] = 'Recorded interactions';
$string['views'] = 'Views';
$string['activeparticipants'] = 'Active participants in logs';
$string['completionnotconfigured'] = 'Course completion is disabled or has no criteria. The completion rate will be unavailable until it is configured.';
$string['lognotavailable'] = 'Standard log storage is not enabled. Views, interactions and engagement levels are not available.';
$string['lognotavailableshort'] = 'No log data';
$string['lognote'] = 'Access data includes events currently retained in Moodle standard log storage. Events recorded while an administrator was logged in as a participant are ignored.';
$string['gradesneedupdate'] = 'Some gradebook items are waiting to be recalculated. The grades shown may be out of date until Moodle recalculates the gradebook.';
$string['emptyfilter'] = 'No activity type was selected, so all activity types were included.';
$string['restrictedtogroups'] = 'This course uses separate groups: the report only includes the participants of your groups.';
$string['nogrades'] = 'There are no final course grades for tracked participants yet.';
$string['noactivities'] = 'There are no activities available for analysis in this course.';
$string['activityanalysis'] = 'Performance and engagement by activity';
$string['activity'] = 'Activity';
$string['type'] = 'Type';
$string['uniqueviews'] = 'Active participants';
$string['lastinteraction'] = 'Last interaction';
$string['never'] = 'Never';
$string['notavailable'] = 'Not available';
$string['gradepercent'] = 'Normalised average';
$string['gradedistribution'] = 'Grade distribution';
$string['gradesbycategory'] = 'Average by grade category';
$string['finalgrade'] = 'Final average';
$string['frequencyreference'] = 'reference {$a}%';
$string['belowfrequency'] = '{$a->count} of {$a->total} participants below {$a->limit}%';
$string['activityinteractions'] = 'Interactions by activity';
$string['engagementlevels'] = 'Engagement levels';
$string['engagementlow'] = 'Low engagement';
$string['engagementmedium'] = 'Medium engagement';
$string['engagementhigh'] = 'High engagement';
$string['engagementcriteria'] = 'Low: fewer than 1/3 of the activities accessed. Medium: from 1/3 to less than 2/3. High: 2/3 or more.';
$string['completionoverview'] = 'Completion overview';
$string['range0_59'] = '0–59%';
$string['range60_69'] = '60–69%';
$string['range70_79'] = '70–79%';
$string['range80_89'] = '80–89%';
$string['range90_100'] = '90–100%';
$string['completiondisabled'] = 'Completion not configured';
$string['pdfsubtitle'] = 'Academic and engagement indicators';
$string['pdffilename'] = 'final-report';
$string['pdffilterapplied'] = 'Activity types included: {$a}';
$string['activitycompletion'] = 'Activity completion';
$string['filteractivitytypes'] = 'Activity and resource types';
$string['filteractivitytypeshelp'] = 'Select one or more types to include in the activity analysis and interaction indicators.';
$string['applyfilters'] = 'Apply filters';
$string['clearfilters'] = 'Clear filters';
$string['frequencythreshold'] = 'Attendance reference (%)';
$string['frequencythreshold_desc'] = 'Percentage used as a reference in the attendance category chart (participants below this value are counted). It does not change any grade. Default: 75.';
