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

namespace report_finalreport\output;

defined('MOODLE_INTERNAL') || die();

/**
 * HTML renderer for the course final report.
 *
 * @package    report_finalreport
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Renders the complete dashboard.
     *
     * @param array $data Report data.
     * @return string HTML.
     */
    public function dashboard(array $data): string {
        $coursecontext = \context_course::instance($data['course']->id);
        $downloadparams = [
            'id' => $data['course']->id,
            'download' => 1,
        ];
        if ($data['filteractive']) {
            $downloadparams['filter'] = 1;
            $downloadparams['types'] = implode(',', $data['selectedtypes']);
        }
        $downloadurl = new \moodle_url('/report/finalreport/index.php', $downloadparams);
        $coursefullname = format_string($data['course']->fullname, true, ['context' => $coursecontext]);

        $html = \html_writer::start_div('report-finalreport');
        $html .= \html_writer::start_div('report-finalreport__header');
        $html .= \html_writer::div(
            \html_writer::tag('h2', $coursefullname, ['class' => 'report-finalreport__title']) .
            \html_writer::div(get_string('reportdescription', 'report_finalreport'), 'report-finalreport__description'),
            'report-finalreport__title-area'
        );
        if (has_capability('report/finalreport:export', $coursecontext)) {
            $icon = $this->output->pix_icon('i/download', '', 'core', ['class' => 'iconsmall']);
            $html .= \html_writer::link(
                $downloadurl,
                $icon . get_string('downloadpdf', 'report_finalreport'),
                ['class' => 'btn btn-primary report-finalreport__download']
            );
        }
        $html .= \html_writer::end_div();
        $html .= \html_writer::div(
            get_string('generatedat', 'report_finalreport', userdate($data['generatedat'])),
            'report-finalreport__generated'
        );
        $html .= $this->activity_type_filter($data);

        if (!$data['completion']['configured']) {
            $html .= \html_writer::div(
                get_string('completionnotconfigured', 'report_finalreport'),
                'alert alert-warning report-finalreport__notice',
                ['role' => 'status']
            );
        }
        if (!$data['engagement']['available']) {
            $html .= \html_writer::div(
                get_string('lognotavailable', 'report_finalreport'),
                'alert alert-info report-finalreport__notice',
                ['role' => 'status']
            );
        }

        $html .= $this->kpis($data);
        $html .= $this->charts($data);
        $html .= $this->activity_table($data['activities']);
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * Renders the filter for the activity and resource types found in the course.
     *
     * @param array $data Report data.
     * @return string Filter form HTML.
     */
    private function activity_type_filter(array $data): string {
        if (!$data['activitytypes']) {
            return '';
        }

        $formurl = new \moodle_url('/report/finalreport/index.php', ['id' => $data['course']->id]);
        $formaction = new \moodle_url('/report/finalreport/index.php');
        $html = \html_writer::start_tag('form', [
            'action' => $formaction->out(false),
            'class' => 'report-finalreport__filter',
            'method' => 'get',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'name' => 'id',
            'type' => 'hidden',
            'value' => $data['course']->id,
        ]);
        $html .= \html_writer::start_tag('fieldset', ['class' => 'report-finalreport__filter-fieldset']);
        $html .= \html_writer::tag(
            'legend',
            get_string('filteractivitytypes', 'report_finalreport'),
            ['class' => 'report-finalreport__filter-title']
        );
        $html .= \html_writer::div(
            get_string('filteractivitytypeshelp', 'report_finalreport'),
            'report-finalreport__filter-help'
        );
        $html .= \html_writer::start_div('report-finalreport__filter-options');
        foreach ($data['activitytypes'] as $type => $label) {
            $id = 'report-finalreport-type-' . $type;
            $attributes = [
                'class' => 'report-finalreport__filter-checkbox',
                'id' => $id,
                'name' => 'type[]',
                'type' => 'checkbox',
                'value' => $type,
            ];
            if (!$data['filteractive'] || in_array($type, $data['selectedtypes'], true)) {
                $attributes['checked'] = 'checked';
            }
            $html .= \html_writer::start_div('report-finalreport__filter-option');
            $html .= \html_writer::empty_tag('input', $attributes);
            $html .= \html_writer::tag('label', s($label), ['for' => $id]);
            $html .= \html_writer::end_div();
        }
        $html .= \html_writer::end_div();
        $html .= \html_writer::start_div('report-finalreport__filter-actions');
        $html .= \html_writer::tag('button', get_string('applyfilters', 'report_finalreport'), [
            'class' => 'btn btn-primary',
            'name' => 'filter',
            'type' => 'submit',
            'value' => 1,
        ]);
        if ($data['filteractive']) {
            $html .= \html_writer::link(
                $formurl,
                get_string('clearfilters', 'report_finalreport'),
                ['class' => 'btn btn-secondary']
            );
        }
        $html .= \html_writer::end_div();
        $html .= \html_writer::end_tag('fieldset');
        $html .= \html_writer::end_tag('form');

        return $html;
    }

    /**
     * Renders the KPI cards.
     *
     * @param array $data Report data.
     * @return string HTML.
     */
    private function kpis(array $data): string {
        $completionrate = $this->percentage($data['completion']['rate']);
        $completiondetail = $data['completion']['configured']
            ? get_string('completedparticipants', 'report_finalreport') . ': ' .
                $data['completion']['completed'] . '/' . $data['participants']
            : get_string('completiondisabled', 'report_finalreport');

        $gradevalue = get_string('notavailable', 'report_finalreport');
        $gradedetail = get_string('nogrades', 'report_finalreport');
        if ($data['grade']['available']) {
            $gradevalue = format_float($data['grade']['average'], 2) . ' / ' .
                format_float($data['grade']['maximum'], 2);
            $gradedetail = get_string('gradepercent', 'report_finalreport') . ': ' .
                $this->percentage($data['grade']['averagepercent']) . ' · ' .
                get_string('gradedparticipants', 'report_finalreport') . ': ' . $data['grade']['count'];
        }

        $cards = [
            [$data['participants'], get_string('participants', 'report_finalreport'), ''],
            [$completionrate, get_string('coursecompletion', 'report_finalreport'), $completiondetail],
            [$gradevalue, get_string('averagegrade', 'report_finalreport'), $gradedetail],
            [
                format_float($data['engagement']['interactions'], 0),
                get_string('interactions', 'report_finalreport'),
                get_string('views', 'report_finalreport') . ': ' . format_float($data['engagement']['views'], 0) .
                    ' · ' . get_string('activeparticipants', 'report_finalreport') . ': ' .
                    format_float($data['engagement']['activeparticipants'], 0),
            ],
        ];

        $html = \html_writer::start_div('report-finalreport__kpis');
        foreach ($cards as [$value, $label, $detail]) {
            $html .= \html_writer::start_div('report-finalreport__kpi');
            $html .= \html_writer::div($value, 'report-finalreport__kpi-value');
            $html .= \html_writer::div($label, 'report-finalreport__kpi-label');
            if ($detail !== '') {
                $html .= \html_writer::div($detail, 'report-finalreport__kpi-detail');
            }
            $html .= \html_writer::end_div();
        }
        $html .= \html_writer::end_div();

        return $html;
    }

    /**
     * Renders Moodle native charts for the dashboard.
     *
     * @param array $data Report data.
     * @return string HTML.
     */
    private function charts(array $data): string {
        $charts = [];
        if ($data['completion']['configured']) {
            $chart = new \core\chart_bar();
            $chart->set_title(get_string('completionoverview', 'report_finalreport'));
            $chart->set_legend_options(['display' => false]);
            $chart->set_labels([
                get_string('completed', 'report_finalreport'),
                get_string('notcompleted', 'report_finalreport'),
            ]);
            $series = new \core\chart_series('', [
                $data['completion']['completed'],
                max(0, $data['participants'] - $data['completion']['completed']),
            ]);
            $this->apply_theme_colour($series);
            $chart->add_series($series);
            $charts[] = $this->chart_container($this->output->render($chart));
        }

        if ($data['grade']['available']) {
            $chart = new \core\chart_bar();
            $chart->set_title(get_string('gradedistribution', 'report_finalreport'));
            $chart->set_legend_options(['display' => false]);
            $chart->set_labels([
                get_string('range0_59', 'report_finalreport'),
                get_string('range60_69', 'report_finalreport'),
                get_string('range70_79', 'report_finalreport'),
                get_string('range80_89', 'report_finalreport'),
                get_string('range90_100', 'report_finalreport'),
            ]);
            $series = new \core\chart_series(
                get_string('gradedparticipants', 'report_finalreport'),
                $data['grade']['distribution']
            );
            $this->apply_theme_colour($series);
            $chart->add_series($series);
            $charts[] = $this->chart_container($this->output->render($chart));
        }

        if ($data['engagement']['available'] && $data['activities']) {
            $activities = $data['activities'];
            usort($activities, static function(array $left, array $right): int {
                return $right['interactions'] <=> $left['interactions'];
            });
            $activities = array_slice($activities, 0, 10);
            $charts[] = $this->chart_container($this->interaction_chart($activities), true);
        }

        if (!$charts) {
            return '';
        }
        return \html_writer::div(implode('', $charts), 'report-finalreport__charts');
    }

    /**
     * Renders a vertical bar chart for activity interactions.
     *
     * @param array<int, array> $activities Activity rows, ordered by interactions.
     * @return string Chart HTML.
     */
    private function interaction_chart(array $activities): string {
        $chart = new \core\chart_bar();
        $chart->set_title(get_string('activityinteractions', 'report_finalreport'));
        $chart->set_horizontal(false);
        $chart->set_legend_options(['display' => false]);
        $chart->set_labels(array_map(static function(array $activity): string {
            return s(strip_tags(format_string($activity['name'])));
        }, $activities));

        $series = new \core\chart_series(
            get_string('interactions', 'report_finalreport'),
            array_map(static function(array $activity): int {
                return (int)$activity['interactions'];
            }, $activities)
        );
        $this->apply_theme_colour($series);
        $chart->add_series($series);

        return $this->output->render($chart);
    }

    /**
     * Wraps one chart in a layout cell.
     *
     * @param string $chart Rendered chart.
     * @param bool $wide Whether the chart needs the full dashboard width.
     * @return string HTML.
     */
    private function chart_container(string $chart, bool $wide = false): string {
        $classes = 'report-finalreport__chart';
        if ($wide) {
            $classes .= ' report-finalreport__chart--wide';
        }
        return \html_writer::div($chart, $classes);
    }

    /**
     * Applies the primary colour configured by the active Moodle theme.
     *
     * If the theme does not expose a hexadecimal primary colour, Moodle's own
     * chart colour set is left in control rather than introducing a plugin colour.
     *
     * @param \core\chart_series $series Chart series to colour.
     * @return void
     */
    private function apply_theme_colour(\core\chart_series $series): void {
        $colour = $this->theme_primary_colour();
        if ($colour !== null) {
            $series->set_color($colour);
        }
    }

    /**
     * Reads a hexadecimal primary colour from the active theme, when available.
     *
     * @return string|null A CSS hexadecimal colour or null when Moodle should use its chart defaults.
     */
    private function theme_primary_colour(): ?string {
        $theme = $this->page->theme;
        $candidates = [];

        if (!empty($theme->settings) && is_object($theme->settings)) {
            foreach (['brandcolor', 'primarycolor', 'primarycolour', 'maincolor', 'themecolor'] as $setting) {
                if (!empty($theme->settings->{$setting})) {
                    $candidates[] = $theme->settings->{$setting};
                }
            }
        }

        if (!empty($theme->name)) {
            $themeconfig = get_config('theme_' . $theme->name);
            if (is_object($themeconfig)) {
                foreach (['brandcolor', 'primarycolor', 'primarycolour', 'maincolor', 'themecolor'] as $setting) {
                    if (!empty($themeconfig->{$setting})) {
                        $candidates[] = $themeconfig->{$setting};
                    }
                }
            }
        }

        foreach ($candidates as $candidate) {
            $colour = trim((string)$candidate);
            if (preg_match('/^#?([a-f0-9]{3}|[a-f0-9]{6})$/i', $colour)) {
                return '#' . ltrim($colour, '#');
            }
        }

        return null;
    }

    /**
     * Renders the complete activity-level table.
     *
     * @param array $activities Activity rows.
     * @return string HTML.
     */
    private function activity_table(array $activities): string {
        $html = \html_writer::tag('h3', get_string('activityanalysis', 'report_finalreport'), [
            'class' => 'report-finalreport__section-title',
        ]);
        if (!$activities) {
            return $html . \html_writer::div(get_string('noactivities', 'report_finalreport'), 'alert alert-info');
        }

        $table = new \html_table();
        $table->attributes = ['class' => 'generaltable report-finalreport__table'];
        $table->head = [
            get_string('activity', 'report_finalreport'),
            get_string('type', 'report_finalreport'),
            get_string('activitycompletion', 'report_finalreport'),
            get_string('views', 'report_finalreport'),
            get_string('interactions', 'report_finalreport'),
            get_string('uniqueviews', 'report_finalreport'),
            get_string('lastinteraction', 'report_finalreport'),
        ];
        $table->data = [];

        foreach ($activities as $activity) {
            $completion = $activity['completiontracked']
                ? $this->percentage($activity['completionrate'])
                : get_string('notavailable', 'report_finalreport');
            $lastinteraction = $activity['lastinteraction']
                ? userdate($activity['lastinteraction'])
                : get_string('never', 'report_finalreport');
            $table->data[] = [
                format_string($activity['name']),
                s($activity['modulelabel']),
                $completion,
                format_float($activity['views'], 0),
                format_float($activity['interactions'], 0),
                format_float($activity['uniqueusers'], 0),
                $lastinteraction,
            ];
        }

        return $html . \html_writer::div(\html_writer::table($table), 'table-responsive');
    }

    /**
     * Formats a percentage for presentation.
     *
     * @param float|null $value Percentage.
     * @return string Formatted percentage or unavailable label.
     */
    private function percentage(?float $value): string {
        if ($value === null) {
            return get_string('notavailable', 'report_finalreport');
        }
        return format_float($value, 1) . '%';
    }
}
