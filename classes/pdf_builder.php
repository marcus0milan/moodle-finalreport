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

namespace report_finalreport;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates the PDF export with Moodle's bundled TCPDF wrapper.
 *
 * @package    report_finalreport
 * @copyright  2026
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_builder {
    /**
     * Sends the report PDF to the browser and stops the request.
     *
     * @param array $data Report data from report_maker.
     * @return never
     */
    public static function download(array $data): never {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $pdf = new \pdf('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('Moodle');
        $pdf->SetAuthor('Moodle');
        $pdf->SetTitle(self::course_name($data));
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();
        self::title($pdf, $data);
        self::kpis($pdf, $data);
        self::visual_indicators($pdf, $data);

        $pdf->AddPage();
        self::activities($pdf, $data);

        $filename = clean_filename('relatorio-final-' . $data['course']->shortname . '-' . date('Ymd-His') . '.pdf');
        $pdf->Output($filename, 'D');
        exit;
    }

    /**
     * Writes the document heading.
     *
     * @param \pdf $pdf PDF document.
     * @param array $data Report data.
     * @return void
     */
    private static function title(\pdf $pdf, array $data): void {
        $pdf->SetFont('freesans', 'B', 18);
        $pdf->Cell(0, 8, get_string('reporttitle', 'report_finalreport'), 0, 1);
        $pdf->SetFont('freesans', '', 11);
        $pdf->Cell(0, 6, self::course_name($data), 0, 1);
        $pdf->SetTextColor(95, 107, 122);
        $pdf->SetFont('freesans', '', 8);
        $pdf->Cell(0, 5, get_string('pdfsubtitle', 'report_finalreport') . ' · ' .
            get_string('generatedat', 'report_finalreport', userdate($data['generatedat'])), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(3);
    }

    /**
     * Writes the report KPI table.
     *
     * @param \pdf $pdf PDF document.
     * @param array $data Report data.
     * @return void
     */
    private static function kpis(\pdf $pdf, array $data): void {
        $completion = $data['completion']['configured']
            ? self::percentage($data['completion']['rate'])
            : get_string('notavailable', 'report_finalreport');
        $grade = $data['grade']['available']
            ? format_float($data['grade']['average'], 2) . ' / ' . format_float($data['grade']['maximum'], 2)
            : get_string('notavailable', 'report_finalreport');
        $html = '<table cellpadding="5" cellspacing="0" border="1">';
        $html .= '<tr style="background-color:#eaf3fb;font-weight:bold;">';
        $html .= '<td width="25%">' . s(get_string('participants', 'report_finalreport')) . '</td>';
        $html .= '<td width="25%">' . s(get_string('coursecompletion', 'report_finalreport')) . '</td>';
        $html .= '<td width="25%">' . s(get_string('averagegrade', 'report_finalreport')) . '</td>';
        $html .= '<td width="25%">' . s(get_string('interactions', 'report_finalreport')) . '</td></tr>';
        $html .= '<tr>';
        $html .= '<td>' . s((string)$data['participants']) . '</td>';
        $html .= '<td>' . s($completion) . '<br/><small>' . s(get_string('completedparticipants', 'report_finalreport')) . ': ' .
            s($data['completion']['completed'] . '/' . $data['participants']) . '</small></td>';
        $html .= '<td>' . s($grade) . '<br/><small>' . s(get_string('gradedparticipants', 'report_finalreport')) . ': ' .
            s((string)$data['grade']['count']) . '</small></td>';
        $html .= '<td>' . s(format_float($data['engagement']['interactions'], 0)) . '<br/><small>' .
            s(get_string('views', 'report_finalreport')) . ': ' .
            s(format_float($data['engagement']['views'], 0)) . '</small></td>';
        $html .= '</tr></table>';

        $pdf->SetFont('freesans', '', 9);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Ln(3);
    }

    /**
     * Draws compact visual indicators that work without browser-side JavaScript.
     *
     * @param \pdf $pdf PDF document.
     * @param array $data Report data.
     * @return void
     */
    private static function visual_indicators(\pdf $pdf, array $data): void {
        $pdf->SetFont('freesans', 'B', 12);
        $pdf->Cell(0, 7, get_string('completionoverview', 'report_finalreport'), 0, 1);
        $completion = $data['completion']['configured'] ? $data['completion']['rate'] : 0.0;
        self::bar($pdf, get_string('coursecompletion', 'report_finalreport'), $completion, [15, 108, 191]);
        self::bar(
            $pdf,
            get_string('activeparticipants', 'report_finalreport'),
            self::ratio($data['engagement']['activeparticipants'], $data['participants']),
            [31, 137, 101]
        );
        if ($data['grade']['available']) {
            self::bar($pdf, get_string('gradepercent', 'report_finalreport'), $data['grade']['averagepercent'], [15, 108, 191]);
        }

        if ($data['grade']['available']) {
            $pdf->Ln(3);
            $pdf->SetFont('freesans', 'B', 12);
            $pdf->Cell(0, 7, get_string('gradedistribution', 'report_finalreport'), 0, 1);
            $labels = [
                get_string('range0_59', 'report_finalreport'),
                get_string('range60_69', 'report_finalreport'),
                get_string('range70_79', 'report_finalreport'),
                get_string('range80_89', 'report_finalreport'),
                get_string('range90_100', 'report_finalreport'),
            ];
            foreach ($data['grade']['distribution'] as $position => $count) {
                self::bar(
                    $pdf,
                    $labels[$position],
                    self::ratio($count, $data['grade']['count']),
                    [15, 108, 191],
                    $count . ' (' . self::percentage(self::ratio($count, $data['grade']['count'])) . ')'
                );
            }
        }

        $pdf->Ln(3);
        $pdf->SetFont('freesans', '', 8);
        $pdf->SetTextColor(95, 107, 122);
        $pdf->MultiCell(0, 5, get_string('pdfnote', 'report_finalreport'));
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Draws one percentage bar.
     *
     * @param \pdf $pdf PDF document.
     * @param string $label Bar label.
     * @param float|null $percent Percentage.
     * @param array $colour RGB fill colour.
     * @param string|null $suffix Optional text after the bar.
     * @return void
     */
    private static function bar(\pdf $pdf, string $label, ?float $percent, array $colour, ?string $suffix = null): void {
        $percent = max(0, min(100, $percent ?? 0));
        $pdf->SetFont('freesans', '', 9);
        $pdf->Cell(62, 6, $label, 0, 0);
        $x = $pdf->GetX();
        $y = $pdf->GetY() + 1;
        $width = 120;
        $pdf->SetFillColor(229, 233, 238);
        $pdf->Rect($x, $y, $width, 4, 'F');
        $pdf->SetFillColor($colour[0], $colour[1], $colour[2]);
        $pdf->Rect($x, $y, $width * ($percent / 100), 4, 'F');
        $pdf->SetX($x + $width + 4);
        $pdf->Cell(35, 6, $suffix ?? self::percentage($percent), 0, 1);
    }

    /**
     * Writes all activity data to a landscape table.
     *
     * @param \pdf $pdf PDF document.
     * @param array $data Report data.
     * @return void
     */
    private static function activities(\pdf $pdf, array $data): void {
        $pdf->SetFont('freesans', 'B', 14);
        $pdf->Cell(0, 8, get_string('activityanalysis', 'report_finalreport'), 0, 1);
        if (!$data['activities']) {
            $pdf->SetFont('freesans', '', 10);
            $pdf->Cell(0, 6, get_string('noactivities', 'report_finalreport'), 0, 1);
            return;
        }

        $html = '<table cellpadding="3" cellspacing="0" border="1">';
        $html .= '<tr style="background-color:#eaf3fb;font-weight:bold;">';
        $headers = [
            ['activity', 29],
            ['type', 10],
            ['activitycompletion', 11],
            ['views', 9],
            ['interactions', 11],
            ['uniqueviews', 11],
            ['lastinteraction', 19],
        ];
        foreach ($headers as [$string, $width]) {
            $html .= '<td width="' . $width . '%">' . s(get_string($string, 'report_finalreport')) . '</td>';
        }
        $html .= '</tr>';

        foreach ($data['activities'] as $activity) {
            $completion = $activity['completiontracked']
                ? self::percentage($activity['completionrate'])
                : get_string('notavailable', 'report_finalreport');
            $lastinteraction = $activity['lastinteraction']
                ? userdate($activity['lastinteraction'])
                : get_string('never', 'report_finalreport');
            $html .= '<tr>';
            $html .= '<td>' . s(strip_tags(format_string($activity['name']))) . '</td>';
            $html .= '<td>' . s($activity['modulelabel']) . '</td>';
            $html .= '<td align="right">' . s($completion) . '</td>';
            $html .= '<td align="right">' . s(format_float($activity['views'], 0)) . '</td>';
            $html .= '<td align="right">' . s(format_float($activity['interactions'], 0)) . '</td>';
            $html .= '<td align="right">' . s(format_float($activity['uniqueusers'], 0)) . '</td>';
            $html .= '<td>' . s($lastinteraction) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        $pdf->SetFont('freesans', '', 8);
        $pdf->writeHTML($html, true, false, true, false, '');
    }

    /**
     * Gets the printable course name.
     *
     * @param array $data Report data.
     * @return string Course name.
     */
    private static function course_name(array $data): string {
        return strip_tags(format_string($data['course']->fullname));
    }

    /**
     * Calculates a percentage safely.
     *
     * @param int $value Numerator.
     * @param int $total Denominator.
     * @return float|null Percentage.
     */
    private static function ratio(int $value, int $total): ?float {
        return $total ? ($value / $total) * 100 : null;
    }

    /**
     * Formats a percentage for PDF output.
     *
     * @param float|null $value Percentage.
     * @return string Formatted percentage.
     */
    private static function percentage(?float $value): string {
        return $value === null ? '-' : format_float($value, 1) . '%';
    }
}
