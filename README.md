## Final report generator for Moodle

Centralises the native Moodle course data (completion, grades, logs and activity
completion) in one report page, with a PDF export.

## Requirements

- Moodle 4.4 or newer (branches 404 to 406);
- PHP version compatible with the installed Moodle version.

## How the figures are calculated

- Audience: active enrolled users who are tracked in completion reports. In courses with
  separate groups, users without access to all groups only see their own groups.
- Grades are shown exactly as they are in the gradebook; the plugin never changes them.
- The attendance reference (default 75%, site setting `frequencythreshold`) is only used to
  count participants below it in the attendance category chart.
- Engagement level: low (< 1/3 of the activities accessed), medium (1/3 to < 2/3), high (2/3 or more).
- Views and interactions come from the standard log store and depend on how long logs are kept.
