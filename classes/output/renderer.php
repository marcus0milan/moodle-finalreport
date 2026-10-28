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
        $html .= \html_writer::empty_tag('input', [
            'checked' => 'checked',
            'class' => 'report-finalreport__view-input',
            'id' => 'report-finalreport-view-charts',
            'name' => 'report-finalreport-view',
            'type' => 'radio',
        ]);
        $html .= \html_writer::empty_tag('input', [
            'class' => 'report-finalreport__view-input',
            'id' => 'report-finalreport-view-table',
            'name' => 'report-finalreport-view',
            'type' => 'radio',
        ]);
        $html .= \html_writer::start_div('report-finalreport__header');
        $html .= \html_writer::div(
            \html_writer::tag('h2', $coursefullname, ['class' => 'report-finalreport__title']) .
            \html_writer::div(get_string('reportdescription', 'report_finalreport'), 'report-finalreport__description'),
            'report-finalreport__title-area'
        );
        $html .= \html_writer::start_div('report-finalreport__actions');
        $html .= \html_writer::start_div('report-finalreport__view-switch');
        $html .= \html_writer::tag('label', get_string('chartview', 'report_finalreport'), [
            'for' => 'report-finalreport-view-charts',
        ]);
        $html .= \html_writer::tag('label', get_string('tableview', 'report_finalreport'), [
            'for' => 'report-finalreport-view-table',
        ]);
        $html .= \html_writer::end_div();
        if (has_capability('report/finalreport:export', $coursecontext)) {
            $icon = $this->output->pix_icon('i/download', '', 'core', ['class' => 'iconsmall']);
            $html .= \html_writer::link(
                $downloadurl,
                $icon . get_string('downloadpdf', 'report_finalreport'),
                ['class' => 'btn btn-primary report-finalreport__download']
            );
        }
        $html .= \html_writer::end_div();
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
        $html .= \html_writer::start_div('report-finalreport__chart-view');
        $html .= $this->charts($data);
        $html .= \html_writer::end_div();
        $html .= \html_writer::start_div('report-finalreport__table-view');
        $html .= $this->activity_table($data['activities']);
        $html .= \html_writer::end_div();
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
            $charts[] = $this->completion_chart($data['completion']['rate']);
        }

        if (!empty($data['grade']['available']) && !empty($data['grade']['series'])) {
            foreach ($data['grade']['series'] as $gradeseries) {
                $chart = new \core\chart_pie();
                $chart->set_doughnut(true);
                $charttitle = $gradeseries['label'];
                if (!empty($gradeseries['mandatorythreshold'])) {
                    $charttitle .= ' — ' . get_string('mandatoryfrequency', 'report_finalreport');
                }
                $chart->set_title($charttitle);
                $chart->set_legend_options(['display' => true]);
                $chart->set_labels([
                    get_string('range0_59', 'report_finalreport'),
                    get_string('range60_69', 'report_finalreport'),
                    get_string('range70_79', 'report_finalreport'),
                    get_string('range80_89', 'report_finalreport'),
                    get_string('range90_100', 'report_finalreport'),
                ]);
                $series = new \core\chart_series(
                    get_string('gradedparticipants', 'report_finalreport'),
                    $gradeseries['distribution']
                );
                $this->apply_theme_palette($series, count($gradeseries['distribution']));
                $chart->add_series($series);
                $charts[] = $this->chart_container($this->output->render($chart));
            }
        }

        if ($data['engagement']['available'] && $data['activities']) {
            $charts[] = $this->chart_container($this->activity_type_chart($data['activities']));
        }

        if ($data['engagement']['available']) {
            $charts[] = $this->chart_container($this->engagement_chart($data['engagement']));
        }

        if (!$charts) {
            return '';
        }
        return \html_writer::div(implode('', $charts), 'report-finalreport__charts');
    }

    /**
     * Renders course completion as a percentage donut.
     *
     * @param float|null $rate Completion percentage.
     * @return string Chart HTML.
     */
    private function completion_chart(?float $rate): string {
        $progress = $rate === null ? 0 : max(0, min(100, $rate));
        $sweep = $progress / 2;
        $ratio = $progress / 100;
        $red = (int)round(220 + (25 - 220) * $ratio);
        $green = (int)round(53 + (135 - 53) * $ratio);
        $blue = (int)round(69 + (84 - 69) * $ratio);
        $colour = sprintf('#%02x%02x%02x', $red, $green, $blue);
        $value = $this->percentage($rate);
        $title = get_string('completionoverview', 'report_finalreport');
        $donut = \html_writer::tag('span', $value, [
            'class' => 'report-finalreport__completion-value',
        ]);
        $donut = \html_writer::div($donut, 'report-finalreport__completion-donut', [
            'aria-label' => $title . ': ' . $value,
            'role' => 'img',
            'style' => '--completion-sweep: ' . $sweep . '%; ' .
                '--completion-color: ' . $colour . ';',
        ]);
        $html = \html_writer::tag('h3', $title, ['class' => 'report-finalreport__chart-title']) . $donut;

        return $this->chart_container($html);
    }

    /**
        * Renders a pie chart for interactions grouped by activity type.
     *
     * @param array<int, array> $activities Activity rows, ordered by interactions.
     * @return string Chart HTML.
     */
    private function activity_type_chart(array $activities): string {
        $interactions = [];
        foreach ($activities as $activity) {
            $type = $activity['modulelabel'];
            if (!isset($interactions[$type])) {
                $interactions[$type] = 0;
            }
            $interactions[$type] += (int)$activity['interactions'];
        }
        arsort($interactions, SORT_NUMERIC);

        $chart = new \core\chart_pie();
        $chart->set_doughnut(true);
        $chart->set_title(get_string('activityinteractions', 'report_finalreport'));
        $chart->set_labels(array_keys($interactions));

        $series = new \core\chart_series(
            get_string('interactions', 'report_finalreport'),
            array_values($interactions)
        );
        $this->apply_theme_palette($series, count($interactions), true);
        $chart->add_series($series);

        return $this->output->render($chart);
    }

    /**
     * Renders the relative engagement levels calculated from course logs.
     *
     * @param array $engagement Engagement summary.
     * @return string Chart HTML.
     */
    private function engagement_chart(array $engagement): string {
        $chart = new \core\chart_pie();
        $chart->set_doughnut(true);
        $chart->set_title(get_string('engagementlevels', 'report_finalreport'));
        $chart->set_labels([
            get_string('engagementlow', 'report_finalreport'),
            get_string('engagementmedium', 'report_finalreport'),
            get_string('engagementhigh', 'report_finalreport'),
        ]);
        $series = new \core\chart_series(get_string('participants', 'report_finalreport'), [
            (int)$engagement['levels']['low'],
            (int)$engagement['levels']['medium'],
            (int)$engagement['levels']['high'],
        ]);
        $this->apply_theme_palette($series, 3);
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
     * Applies theme-derived shades to the values in a chart series.
     *
     * @param \core\chart_series $series Chart series to colour.
     * @param int $count Number of values in the series.
     * @param bool $reverse Whether the first value should receive the primary colour.
     * @return void
     */
    private function apply_theme_palette(\core\chart_series $series, int $count, bool $reverse = false): void {
        $colour = $this->theme_primary_colour();
        if ($colour === null || $count < 1) {
            return;
        }

        $rgb = $this->hex_to_rgb($colour);
        $luminance = ($rgb[0] * 0.299) + ($rgb[1] * 0.587) + ($rgb[2] * 0.114);
        $target = $luminance >= 140 ? [0, 0, 0] : [255, 255, 255];
        $palette = [];

        for ($index = 0; $index < $count; $index++) {
            $position = $count === 1 ? 0 : ($count - 1 - $index) / ($count - 1);
            $strength = $position * 0.45;
            $palette[] = $this->mix_colours($rgb, $target, $strength);
        }

        if ($reverse) {
            $palette = array_reverse($palette);
        }

        $series->set_colors($palette);
    }

    /**
     * Mixes two RGB colours and returns a CSS hexadecimal colour.
     *
     * @param int[] $first First RGB colour.
     * @param int[] $second Second RGB colour.
     * @param float $amount Proportion of the second colour.
     * @return string
     */
    private function mix_colours(array $first, array $second, float $amount): string {
        $channels = [];
        foreach ($first as $index => $channel) {
            $channels[] = (int)round($channel + (($second[$index] - $channel) * $amount));
        }

        return sprintf('#%02x%02x%02x', $channels[0], $channels[1], $channels[2]);
    }

    /**
     * Converts a hexadecimal CSS colour to RGB channels.
     *
     * @param string $colour Hexadecimal colour.
     * @return int[]
     */
    private function hex_to_rgb(string $colour): array {
        $hex = ltrim($colour, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
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
                $hex = ltrim($colour, '#');
                if (strlen($hex) === 3) {
                    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                }
                return '#' . strtolower($hex);
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
