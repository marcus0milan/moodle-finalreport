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
require_once($CFG->libdir . '/pdflib.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('report/finalreport:view', $context);
require_sesskey();

$state = new \report_finalreport\local\state($course, $context);
$data = $state->data;
$indicators = (new \report_finalreport\output\page($state))->indicators();
$engagement = $data->engagement($state->chart_start($state->eperiod), $state->to, $state->egran);
$completions = $data->completions_series($state->chart_start($state->cperiod), $state->to, $state->ctype);
$studentcount = count($data->get_students());
$groupname = $state->groupid > 0 ? $state->groups[$state->groupid] : get_string('allgroups', 'report_finalreport');
$filename = clean_filename('overview_' . $course->shortname . '_' . date('Ymd'));

$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 9, 10);
$pdf->SetAutoPageBreak(false, 8);
$pdf->AddPage();
$pdf->SetTextColor(32, 45, 54);

$text = function (float $x, float $y, string $value, float $size = 8, string $style = '',
        array $color = [32, 45, 54]) use ($pdf): void {
    $pdf->SetFont('helvetica', $style, $size);
    $pdf->SetTextColor($color[0], $color[1], $color[2]);
    $pdf->Text($x, $y, html_entity_decode(s($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
};
$drawcard = function (float $x, float $y, float $w, float $h, array $fill = [255, 255, 255]) use ($pdf): void {
    $pdf->SetDrawColor(225, 231, 234);
    $pdf->SetLineWidth(0.25);
    $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
    $pdf->RoundedRect($x, $y, $w, $h, 2, '1111', 'DF');
};
$trimnote = function (string $note): string {
    if (\core_text::strlen($note) > 46) {
        return \core_text::substr($note, 0, 43) . '...';
    }
    return $note;
};

$title = get_string('pdf_overview', 'report_finalreport') . ' - ' .
    format_string($course->fullname, true, ['context' => $context]);
$titlefontsize = 16;
$pdf->SetFont('helvetica', 'B', $titlefontsize);
while ($pdf->GetStringWidth($title) > 143 && $titlefontsize > 10) {
    $titlefontsize -= 0.5;
    $pdf->SetFont('helvetica', 'B', $titlefontsize);
}
$text(13, 13, $title, $titlefontsize, 'B');
$text(13, 21, get_string('pdf_subtitle', 'report_finalreport'), 8, '', [103, 116, 124]);

$pdf->SetDrawColor(216, 225, 229);
$pdf->SetFillColor(248, 250, 251);
$pdf->RoundedRect(160, 10, 39, 17, 2, '1111', 'DF');
$text(164, 15, get_string('pdf_logo_placeholder', 'report_finalreport'), 6, 'B', [112, 124, 132]);
$text(164, 19, get_string('pdf_logo_subtitle', 'report_finalreport'), 5.5, '', [112, 124, 132]);
$pdf->Line(10, 31, 200, 31);

$periodstart = userdate($state->chart_start($state->eperiod), get_string('strftimedateshort', 'langconfig'));
$periodend = userdate($state->to, get_string('strftimedateshort', 'langconfig'));
$metas = [
    [10, get_string('pdf_period', 'report_finalreport'), $periodstart . ' - ' . $periodend],
    [76, get_string('group', 'report_finalreport'), $groupname],
    [142, get_string('pdf_studentbase', 'report_finalreport'), get_string('studentsingroup', 'report_finalreport', $studentcount)],
];
foreach ($metas as [$x, $label, $value]) {
    $text($x, 34, core_text::strtoupper($label), 5.5, 'B', [105, 118, 126]);
    $text($x, 38, $value, 7.5, 'B');
}
$pdf->Line(10, 44, 200, 44);

$kpis = [
    $indicators['enrolled'],
    $indicators['active'],
    $indicators['activities'],
    $indicators['dropout'],
];
$gap = 2.5;
$cardw = (190 - 3 * $gap) / 4;
foreach ($kpis as $index => $kpi) {
    $x = 10 + $index * ($cardw + $gap);
    $drawcard($x, 47, $cardw, 27, $index === 0 ? [235, 246, 243] : [247, 249, 250]);
    $text($x + 3, 50, $kpi['label'], 6.2, '', [101, 114, 122]);
    $text($x + 3, 56, $kpi['value'], 14, 'B', $index === 0 ? [39, 139, 119] : [35, 49, 58]);
    $text($x + 3, 68, $trimnote($kpi['note']), 5.2, '', [105, 118, 126]);
}

$drawgauge = function (float $x, array $gauge) use ($pdf, $drawcard, $text, $trimnote): void {
    $w = (190 - 2 * 3) / 3;
    $y = 78;
    $h = 56;
    $drawcard($x, $y, $w, $h);
    $text($x + 3, $y + 4, $gauge['label'], 7.2, 'B');
    $cx = $x + $w / 2;
    $cy = $y + 39;
    $radius = 18;
    $colors = [[217, 107, 102], [224, 143, 98], [217, 185, 90], [155, 184, 106], [79, 168, 138]];
    $pdf->SetLineWidth(2.6);
    for ($segment = 0; $segment < 5; $segment++) {
        $pdf->SetDrawColor($colors[$segment][0], $colors[$segment][1], $colors[$segment][2]);
        $start = 180 + $segment * 36 + 1.5;
        $end = 180 + ($segment + 1) * 36 - 1.5;
        for ($step = 0; $step < 9; $step++) {
            $a1 = deg2rad($start + ($end - $start) * $step / 9);
            $a2 = deg2rad($start + ($end - $start) * ($step + 1) / 9);
            $pdf->Line($cx + $radius * cos($a1), $cy + $radius * sin($a1),
                $cx + $radius * cos($a2), $cy + $radius * sin($a2));
        }
    }
    $pdf->SetLineWidth(0.3);
    $text($x + 4, $cy + 1, $gauge['min'], 5.5, '', [110, 122, 129]);
    $text($x + $w - 10, $cy + 1, $gauge['max'], 5.5, '', [110, 122, 129]);
    if ($gauge['fraction'] !== null) {
        $angle = deg2rad(180 + max(0, min(1, (float)$gauge['fraction'])) * 180);
        $pdf->SetDrawColor(43, 58, 66);
        $pdf->SetLineWidth(0.7);
        $pdf->Line($cx, $cy, $cx + 14 * cos($angle), $cy + 14 * sin($angle));
        $pdf->SetFillColor(43, 58, 66);
        $pdf->Rect($cx - 0.8, $cy - 0.8, 1.6, 1.6, 'F');
    }
    $text($cx - 9, $y + 43, $gauge['value'] . ($gauge['suffix'] ? ' ' . $gauge['suffix'] : ''), 10, 'B');
    $text($x + 3, $y + 50, $trimnote($gauge['note']), 5, '', [105, 118, 126]);
};

$gaugewidth = (190 - 2 * 3) / 3;
$drawgauge(10, $indicators['nps']);
$drawgauge(10 + $gaugewidth + 3, $indicators['completion']);
$drawgauge(10 + 2 * ($gaugewidth + 3), $indicators['grade']);

$drawchart = function (float $x, float $y, float $w, float $h, string $title, string $subtitle,
        array $labels, array $series, string $footnote) use ($pdf, $drawcard, $text): void {
    $drawcard($x, $y, $w, $h);
    $text($x + 4, $y + 3, $title, 8, 'B');
    $text($x + 4, $y + 8, $subtitle, 5.8, '', [105, 118, 126]);

    $colors = [[91, 123, 213], [79, 168, 138], [155, 115, 184]];
    $legendx = $x + $w - 72;
    foreach ($series as $index => [$name, $values]) {
        $color = $colors[$index % count($colors)];
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.7);
        $pdf->Line($legendx, $y + 5, $legendx + 5, $y + 5);
        $text($legendx + 6, $y + 3.2, $name, 5.2, '', [85, 98, 106]);
        $legendx += 23;
    }

    $plotx = $x + 12;
    $ploty = $y + 15;
    $plotw = $w - 18;
    $ploth = $h - 25;
    $maxvalue = 1;
    foreach ($series as $item) {
        if ($item[1]) {
            $maxvalue = max($maxvalue, max($item[1]));
        }
    }
    $axismax = pow(10, floor(log10($maxvalue)));
    $axismax = ceil($maxvalue / $axismax) * $axismax;
    for ($tick = 0; $tick <= 3; $tick++) {
        $yy = $ploty + $ploth - $ploth * $tick / 3;
        $pdf->SetDrawColor(232, 237, 239);
        $pdf->SetLineWidth(0.15);
        $pdf->Line($plotx, $yy, $plotx + $plotw, $yy);
        $text($x + 2, $yy - 1.5, (string)round($axismax * $tick / 3), 4.5, '', [125, 136, 143]);
    }
    $labelcount = count($labels);
    $labelstep = max(1, (int)ceil($labelcount / 6));
    foreach ($labels as $index => $label) {
        if ($index % $labelstep !== 0 && $index !== $labelcount - 1) {
            continue;
        }
        $xx = $plotx + ($labelcount > 1 ? $plotw * $index / ($labelcount - 1) : $plotw / 2);
        $text($xx - 3, $ploty + $ploth + 1, (string)$label, 4.5, '', [105, 118, 126]);
    }
    foreach ($series as $seriesindex => [$name, $values]) {
        $color = $colors[$seriesindex % count($colors)];
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->SetLineWidth(0.8);
        $count = count($values);
        for ($index = 1; $index < $count; $index++) {
            $x1 = $plotx + ($count > 1 ? $plotw * ($index - 1) / ($count - 1) : $plotw / 2);
            $x2 = $plotx + ($count > 1 ? $plotw * $index / ($count - 1) : $plotw / 2);
            $y1 = $ploty + $ploth - $ploth * $values[$index - 1] / $axismax;
            $y2 = $ploty + $ploth - $ploth * $values[$index] / $axismax;
            $pdf->Line($x1, $y1, $x2, $y2);
        }
    }
    $text($x + 4, $y + $h - 5, $footnote, 5.5, 'B', [65, 78, 86]);
};

$drawchart(10, 138, 190, 62, get_string('engagement', 'report_finalreport'),
    get_string('engagement_desc', 'report_finalreport'), $engagement['labels'], [
        [get_string('clicks', 'report_finalreport'), $engagement['clicks']],
        [get_string('interactions', 'report_finalreport'), $engagement['interactions']],
        [get_string('logs', 'report_finalreport'), $engagement['logs']],
    ], get_string('clicksinperiod', 'report_finalreport', number_format(array_sum($engagement['clicks']), 0, ',', '.')));

$drawchart(10, 204, 190, 62, get_string('completions', 'report_finalreport'),
    get_string('completions_desc', 'report_finalreport'), $completions['labels'], [
        [get_string('completionsseries', 'report_finalreport'), $completions['values']],
    ], get_string('completionsinperiod', 'report_finalreport', number_format(array_sum($completions['values']), 0, ',', '.')));

$pdf->SetDrawColor(216, 225, 229);
$pdf->Line(10, 273, 200, 273);
$text(10, 276, get_string('footer_title', 'report_finalreport'), 6, 'B', [93, 106, 114]);
$text(10, 280, get_string('footer_desc', 'report_finalreport'), 5.2, '', [112, 124, 132]);
$text(151, 280, get_string('updatedat', 'report_finalreport',
    userdate(time(), get_string('strftimedatetimeshort', 'langconfig'))), 5.2, '', [112, 124, 132]);
$text(191, 280, '01 / 01', 5.2, 'B', [39, 139, 119]);

$pdf->Output($filename . '.pdf', 'D');
exit;
