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

use report_finalreport\local\report_data;
use report_finalreport\local\state;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the mustache context of the report page and the indicator list used by the exports.
 */
class page {
    /** @var state */
    private $s;
    private $metrics = null;

    public function __construct(state $s) {
        $this->s = $s;
    }

    private function str(string $key, $a = null): string {
        return get_string($key, 'report_finalreport', $a);
    }

    private function metrics(): array {
        return $this->metrics ?? ($this->metrics = $this->s->data->metrics());
    }

    private function pct(?float $v): string {
        return $v === null ? '—' : format_float($v, 0) . '%';
    }

    /**
     * Indicators as label/value/note (+ gauge fraction). Also used by the exports.
     * @return array keyed enrolled, active, activities, dropout, nps, completion, grade
     */
    public function indicators(): array {
        $m = $this->metrics();
        $ind = [];
        $ind['enrolled'] = ['label' => $this->str('enrolled'), 'value' => (string)$m['enrolled'], 'note' => $this->str('enrolled_note')];
        $ind['active'] = ['label' => $this->str('active'), 'value' => (string)$m['active'],
            'note' => $this->str('active_note', format_float($m['activepct'], 0))];
        $ind['activities'] = ['label' => $this->str('activities'), 'value' => (string)$m['activities'], 'note' => $this->str('activities_note')];
        $ind['dropout'] = ['label' => $this->str('dropout'), 'value' => $this->pct($m['dropoutpct']),
            'note' => $this->str('dropout_note', ['suspended' => $m['suspended'], 'total' => $m['enrolled']])];

        $nps = $m['nps'];
        $ind['nps'] = ['label' => $this->str('nps'), 'fraction' => null, 'value' => '—', 'min' => '-100', 'max' => '100',
            'note' => $this->str('nps_missing')];
        if ($nps !== null) {
            $ind['nps']['note'] = $this->str('nps_note', $nps['responses']);
            if ($nps['nps'] !== null) {
                $ind['nps']['value'] = format_float($nps['nps'], 0) . '%';
                $ind['nps']['fraction'] = max(0, min(1, ($nps['nps'] + 100) / 200));
            }
        }
        $c = $m['completion'];
        $ind['completion'] = ['label' => $this->str('completionrate'), 'fraction' => null, 'value' => '—',
            'min' => '0', 'max' => '100%', 'note' => $this->str('completion_disabled')];
        if ($c !== null) {
            $ind['completion']['value'] = $this->pct($c['pct']);
            $ind['completion']['fraction'] = max(0, min(1, $c['pct'] / 100));
            $ind['completion']['note'] = $this->str('completionrate_note', ['done' => $c['done'], 'total' => $c['total']]);
        }
        $g = $m['grade'];
        $ind['grade'] = ['label' => $this->str('averagegrade'), 'fraction' => null, 'value' => '—', 'suffix' => $this->str('outof10'),
            'min' => '0', 'max' => '10', 'note' => $this->str('nodata')];
        if ($g !== null) {
            $ind['grade']['value'] = format_float($g['avg'], 1);
            $ind['grade']['fraction'] = max(0, min(1, $g['avg'] / 10));
            $ind['grade']['note'] = $this->str('averagegrade_note', $g['count']);
        }
        return $ind;
    }

    private function gauge(array $i): array {
        $cx = 120;
        $cy = 120;
        $r = 90;
        $colors = ['#d96b66', '#e08f62', '#d9b95a', '#9bb86a', '#4fa88a'];
        $f = function (float $v): string {
            return number_format($v, 2, '.', '');
        };
        $pt = function (float $deg) use ($cx, $cy, $r) {
            $rad = deg2rad($deg);
            return [$cx - $r * cos($rad), $cy - $r * sin($rad)];
        };
        $segments = [];
        for ($k = 0; $k < 5; $k++) {
            $a1 = $k * 36 + ($k ? 1 : 0);
            $a2 = ($k + 1) * 36 - ($k < 4 ? 1 : 0);
            [$x1, $y1] = $pt($a1);
            [$x2, $y2] = $pt($a2);
            $segments[] = ['d' => "M {$f($x1)} {$f($y1)} A $r $r 0 0 1 {$f($x2)} {$f($y2)}", 'color' => $colors[$k]];
        }
        $out = $i + ['segments' => $segments, 'hasvalue' => $i['fraction'] !== null, 'suffix' => $i['suffix'] ?? ''];
        if ($i['fraction'] !== null) {
            $angle = deg2rad($i['fraction'] * 180);
            $needleradius = $r - 20;
            $out['needle_x'] = $f($cx - $needleradius * cos($angle));
            $out['needle_y'] = $f($cy - $needleradius * sin($angle));
        }
        return $out;
    }

    private function opts(array $pairs, $current): array {
        $o = [];
        foreach ($pairs as $v => $l) {
            $o[] = ['value' => $v, 'label' => $l, 'selected' => ((string)$v === (string)$current)];
        }
        return $o;
    }

    private function select(string $name, string $label, array $pairs, $current): array {
        return ['name' => $name, 'label' => $label, 'options' => $this->opts($pairs, $current)];
    }

    private function delta_text(array $values, string $gran): array {
        $d = report_data::delta($values);
        if ($d === null) {
            return ['text' => '', 'class' => ''];
        }
        return ['text' => ($d > 0 ? '+' : '') . format_float($d, 0) . '% ' . $this->str('vsprev_' . $gran),
            'class' => $d >= 0 ? 'rco-pos' : 'rco-neg'];
    }

    private function line_chart(array $labels, array $series, bool $smooth = true): string {
        global $OUTPUT;
        $chart = new \core\chart_line();
        $chart->set_smooth($smooth);
        foreach ($series as [$name, $values, $color]) {
            $se = new \core\chart_series($name, $values);
            $se->set_color($color);
            $chart->add_series($se);
        }
        $chart->set_labels($labels);
        return $OUTPUT->render($chart);
    }

    public function export_for_template(): array {
        global $USER;
        $s = $this->s;
        $data = $s->data;
        $ind = $this->indicators();

        // Filters.
        $courses = [];
        $list = get_user_capability_course('report/finalreport:view', $USER->id, true, 'shortname,fullname', 'fullname ASC', 300);
        foreach ($list ?: [] as $c) {
            $courses[$c->id] = format_string($c->fullname, true, ['escape' => false, 'context' => \context_system::instance()]);
        }
        $courses[$s->course->id] = $courses[$s->course->id]
            ?? format_string($s->course->fullname, true, ['escape' => false, 'context' => $s->context]);
        $students = [0 => $this->str('allstudents')];
        foreach ($data->get_group_students() as $st) {
            $students[$st->id] = fullname($st);
        }
        $groups = $s->restricted ? $s->groups : ([0 => $this->str('allgroups')] + $s->groups);

        // Charts.
        $eend = $s->to;
        $eng = $data->engagement($s->chart_start($s->eperiod), $eend, $s->egran);
        $engchart = $this->line_chart($eng['labels'], [
            [$this->str('clicks'), $eng['clicks'], '#5b7bd5'],
            [$this->str('interactions'), $eng['interactions'], '#4fa88a'],
        ]);
        $edelta = $this->delta_text($eng['clicks'], $s->egran);

        $comp = $data->completions_series($s->chart_start($s->cperiod), $s->to, $s->ctype);
        $compchart = $this->line_chart($comp['labels'], [[$this->str('completionsseries'), $comp['values'], '#4fa88a']]);
        $cdelta = $this->delta_text($comp['values'], 'month');

        $periods = function (string $name, int $cur) {
            return $this->select($name, '', [3 => $this->str('lastmonths', 3), 6 => $this->str('lastmonths', 6),
                12 => $this->str('lastmonths', 12)], $cur);
        };

        $groupname = $s->groupid > 0 ? $s->groups[$s->groupid] : $this->str('allgroups');

        return [
            'formaction' => (new \moodle_url('/report/finalreport/index.php'))->out(false),
            'title' => $this->str('pluginname'),
            'pdfurl' => $s->url(['sesskey' => sesskey()], '/report/finalreport/export_pdf.php')->out(false),
            'excelurl' => $s->url(['sesskey' => sesskey()], '/report/finalreport/export_excel.php')->out(false),
            'updated' => $this->str('updatedat', userdate(time(), get_string('strftimedatetimeshort', 'langconfig'))),
            'courseselect' => $this->select('id', $this->str('course'), $courses, $s->course->id),
            'studentselect' => $this->select('userid', $this->str('allstudents'), $students, $s->userid),
            'groupselect' => $this->select('groupid', $this->str('group'), $groups, $s->groupid),
            'clearurl' => (new \moodle_url('/report/finalreport/index.php', ['id' => $s->course->id]))->out(false),
            'groupcount' => $this->str('studentsingroup', count($data->get_students())),
            'kpis' => array_values(array_map(function ($k) use ($ind) {
                return $ind[$k];
            }, ['enrolled', 'active', 'activities', 'dropout'])),
            'gauges' => [$this->gauge($ind['nps']), $this->gauge($ind['completion']), $this->gauge($ind['grade'])],
            'engagementchart' => $engchart,
            'eperiodselect' => $periods('eperiod', $s->eperiod),
            'egranselect' => $this->select('egran', $this->str('granularity'),
                ['month' => $this->str('monthly'), 'week' => $this->str('weekly'), 'day' => $this->str('daily')], $s->egran),
            'etotal' => $this->str('clicksinperiod', number_format(array_sum($eng['clicks']), 0, ',', '.')),
            'edelta' => $edelta['text'], 'edeltaclass' => $edelta['class'],
            'completionchart' => $compchart,
            'cperiodselect' => $periods('cperiod', $s->cperiod),
            'ctypeselect' => $this->select('ctype', $this->str('allactivities'),
                ['' => $this->str('allactivities')] + $data->completion_modtypes(), $s->ctype),
            'ctotal' => $this->str('completionsinperiod', number_format(array_sum($comp['values']), 0, ',', '.')),
            'cdelta' => $cdelta['text'], 'cdeltaclass' => $cdelta['class'],
            'viewcompletionsurl' => (new \moodle_url('/report/progress/index.php', ['course' => $s->course->id]))->out(false),
            'footerright' => $this->str('group') . ': ' . $groupname,
        ];
    }
}
