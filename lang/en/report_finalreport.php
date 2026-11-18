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

$string['pluginname'] = 'Final Report';
$string['finalreport:view'] = 'View the Final Report';
$string['privacy:metadata'] = 'The Final Report does not store any personal data.';
$string['downloadpdf'] = 'Download PDF report';
$string['downloadexcel'] = 'Download Excel report';
$string['updatedat'] = 'Updated on {$a}';
$string['course'] = 'Course';
$string['filterby'] = 'Filter by';
$string['allstudents'] = 'All students';
$string['allgroups'] = 'All groups';
$string['group'] = 'Group';
$string['from'] = 'Start date';
$string['to'] = 'End date';
$string['apply'] = 'Apply';
$string['clearfilters'] = 'Clear filters';
$string['studentsingroup'] = '{$a} students in scope';
$string['enrolled'] = 'Enrolled students';
$string['enrolled_note'] = 'Students who confirmed their enrolment with YES';
$string['active'] = 'Active students';
$string['active_note'] = '{$a}% of enrolled students';
$string['activities'] = 'Number of activities';
$string['activities_note'] = 'With completion and grade enabled';
$string['dropout'] = 'Dropout rate';
$string['dropout_note'] = '{$a->suspended} suspended students out of {$a->total}';
$string['nps'] = 'NPS (Net Promoter Score)';
$string['nps_note'] = '{$a} responses received';
$string['nps_missing'] = 'No NPS survey found. Add a Feedback activity whose first question is single-choice with options from 0 to 10.';
$string['completionrate'] = 'Completion rate';
$string['completionrate_note'] = '{$a->done} of {$a->total} students completed the course';
$string['completion_disabled'] = 'Course completion is not enabled.';
$string['averagegrade'] = 'Average grade';
$string['averagegrade_note'] = '{$a} students with recorded grades';
$string['outof10'] = 'out of 10';
$string['engagement'] = 'Engagement';
$string['engagement_desc'] = 'Logs, clicks and interactions over time';
$string['clicks'] = 'Clicks';
$string['logs'] = 'Logs';
$string['interactions'] = 'Interactions';
$string['completions'] = 'Activity completions';
$string['completions_desc'] = 'Evolution of completions across all activity types';
$string['completionsseries'] = 'Completions';
$string['lastmonths'] = 'Last {$a} months';
$string['granularity'] = 'Granularity';
$string['monthly'] = 'Monthly';
$string['weekly'] = 'Weekly';
$string['daily'] = 'Daily';
$string['allactivities'] = 'All activities';
$string['clicksinperiod'] = '{$a} clicks in the period';
$string['completionsinperiod'] = '{$a} completions in the period';
$string['vsprev_month'] = 'vs. previous month';
$string['vsprev_week'] = 'vs. previous week';
$string['vsprev_day'] = 'vs. previous day';
$string['viewcompletions'] = 'View activity completions';
$string['nodata'] = 'No data in the period.';
$string['footer_title'] = 'Course reports';
$string['footer_desc'] = 'Enrolments, grades, logs and completions from Moodle · NPS from the course survey';
$string['summary'] = 'Summary';
$string['period'] = 'Period';
$string['indicator'] = 'Indicator';
$string['value'] = 'Value';
$string['details'] = 'Details';
$string['confirmationactivitymissing'] = 'No Quiz or Feedback activity with ID number "confirmacao_matricula" was found in this course. Configure the confirmation activity before opening the report.';
$string['confirmationactivityduplicate'] = 'More than one Quiz or Feedback activity uses ID number "confirmacao_matricula". Keep this ID number on exactly one activity per course.';
$string['confirmationmethodinvalid'] = 'The enrolment confirmation method is configured incorrectly. Use a Quiz with one question where SIM is passing and a valid passing grade, or a non-anonymous Feedback with exactly one single-choice SIM/NÃO question.';
$string['excelstudentsheet'] = 'Students';
$string['excelname'] = 'Name';
$string['excelemail'] = 'Email';
$string['excelstatus'] = 'Status';
$string['excelfunding'] = 'Paying or scholarship';
$string['excelgradecategory'] = '{$a}';
$string['excelmention'] = 'Course result';
$string['excelactive'] = 'Active';
$string['excelsuspendedcancelled'] = 'Suspended/Cancelled';
$string['excelbursary'] = 'Scholarship';
$string['excelpaying'] = 'Paying';
$string['excelapproved'] = 'Approved';
$string['excelreproved'] = 'Not approved';
$string['excelcompletionundefined'] = 'No completion criteria';
$string['pdf_overview'] = 'Course overview';
$string['pdf_subtitle'] = 'Performance indicators and course KPIs';
$string['pdf_logo_placeholder'] = 'INSTITUTION';
$string['pdf_logo_subtitle'] = 'LOGO';
$string['pdf_period'] = 'Period';
$string['pdf_studentbase'] = 'Student base';
