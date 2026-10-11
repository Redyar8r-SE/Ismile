<?php
declare(strict_types=1);
namespace Ismile\Admin;

use Ismile\App;
use Ismile\Security;

/**
 * The admin's PDF downloads (lists, attendance, lunch, payments, sponsors,
 * "All event reports" and student IDs), in one formal house style: A4
 * landscape, a letterhead on every page, a title block per report, ruled
 * tables whose heading repeats on each page, and a confidential footer with
 * page numbers.
 */
final class PdfReport
{
    private const NAVY = [0, 78, 137];
    private const INK = [15, 42, 61];
    private const MUTED = [96, 113, 125];
    private const RULE = [201, 211, 216];
    private const TEAL = [34, 188, 205];

    public static function studentIds(array $people): string
    {
        $pdf = self::document('Student ID records', 'P');
        foreach ($people as $person) {
            $pdf->AddPage();
            self::titleBlock($pdf, 'Student ID verification', 'Reference ' . $person['ref']);
            $facts = [
                'Full name'  => \Ismile\Registrations::fullName($person),
                'Reference'  => $person['ref'],
                'University' => (string) ($person['university'] ?? ''),
                'Phone'      => (string) $person['phone'],
            ];
            $html = '<table cellpadding="6" style="border-top:0.3mm solid #c9d3d8">';
            foreach ($facts as $label => $value) {
                $html .= '<tr><td width="32%" style="color:#60717d;border-bottom:0.2mm solid #c9d3d8">' . Security::e($label) . '</td>'
                    . '<td width="68%" style="color:#0f2a3d;border-bottom:0.2mm solid #c9d3d8"><b>' . Security::e($value !== '' ? $value : '–') . '</b></td></tr>';
            }
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->writeHTML($html . '</table>');
            $photo = \Ismile\IdPhotos::find((int) ($person['id_photo_id'] ?? 0));
            $top = $pdf->GetY() + 8;
            if ($photo) {
                $scale = min(170 / $photo['width'], 160 / $photo['height']);
                $width = $photo['width'] * $scale;
                $height = $photo['height'] * $scale;
                $left = (210 - $width) / 2;
                $pdf->SetDrawColor(...self::RULE);
                $pdf->SetLineWidth(0.3);
                $pdf->Rect($left - 2, $top - 2, $width + 4, $height + 4);
                $pdf->Image('@' . $photo['image'], $left, $top, $width, $height, 'JPEG');
                $pdf->SetY($top + $height + 5);
                $pdf->SetFont('dejavusans', '', 8);
                $pdf->SetTextColor(...self::MUTED);
                $pdf->Cell(0, 5, 'Student ID card as uploaded with the registration.', 0, 1, 'C');
            } else {
                $pdf->SetY($top);
                $pdf->SetFont('dejavusans', 'I', 10);
                $pdf->SetTextColor(...self::MUTED);
                $pdf->Cell(0, 8, 'No ID photo is stored for this registration.', 0, 1);
            }
        }
        if (!$people) {
            $pdf->AddPage();
            self::titleBlock($pdf, 'Student ID records', 'No student ID records.');
        }
        return $pdf->Output('', 'S');
    }

    /** One report: every column in one table, on A4 or (for many columns) A3 landscape. */
    public static function make(string $title, array $rows): string
    {
        $title = self::cleanTitle($title);
        $pdf = self::document($title);
        self::append($pdf, $title, $rows);
        return $pdf->Output('', 'S');
    }

    public static function bundle(array $sections): string
    {
        $pdf = self::document('Event reports');
        $pdf->AddPage();
        self::titleBlock($pdf, 'Event reports', 'iSmile 2026 · ' . count($sections) . ' reports in this document');
        $html = '<table cellpadding="6"><tr style="background-color:#004e89;color:#ffffff"><td width="10%"><b>No.</b></td><td width="65%"><b>Report</b></td><td width="25%" align="right"><b>Records</b></td></tr>';
        $number = 0;
        foreach ($sections as $name => $rows) {
            $count = max(0, count($rows) - 1 - (count($rows[0] ?? []) === 1 ? 1 : 0));
            $html .= '<tr style="background-color:' . ($number % 2 ? '#f4f7f8' : '#ffffff') . '"><td style="border-bottom:0.2mm solid #c9d3d8">' . ++$number . '</td>'
                . '<td style="border-bottom:0.2mm solid #c9d3d8">' . Security::e((string) $name) . '</td>'
                . '<td align="right" style="border-bottom:0.2mm solid #c9d3d8">' . number_format($count) . '</td></tr>';
        }
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->writeHTML($html . '</table>');
        foreach ($sections as $name => $rows) {
            self::append($pdf, (string) $name, $rows);
        }
        return $pdf->Output('', 'S');
    }

    private static function append(\TCPDF $pdf, string $title, array $rows): void
    {
        if (count($rows[0] ?? []) === 1) $subtitle = (string) array_shift($rows)[0];
        $headers = array_shift($rows) ?? [];
        // One table with every column: more than 13 columns use an A3 page.
        $keys = array_keys($headers);
        $columns = count($keys);
        // Each column must fit its longest word: pick the largest text that allows it.
        $format = $columns <= 13 ? 'A4' : 'A3';
        $usable = ($format === 'A4' ? 297 : 420) - 28;
        $stats = self::stats($keys, $headers, $rows);
        $longestWords = array_sum(array_map(static fn (array $stat): int => $stat[0], $stats));
        [$size, $padding] = $columns <= 9 ? [8, 3.5] : [7, 2];
        while ($size > 5.5 && $longestWords * 0.22 * $size + $columns * 2 * $padding > $usable) {
            $size -= 0.5;
        }
        $pdf->setDocTitle($title);
        $pdf->AddPage('L', $format);
        $meta = [($subtitle ?? 'iSmile 2026'), number_format(count($rows)) . (count($rows) === 1 ? ' record' : ' records')];
        self::titleBlock($pdf, $title, implode('  ·  ', $meta));
        $pdf->SetFont('dejavusans', '', $size);
        $widths = self::widths($stats, $size, $padding, $usable);
        $html = '<table cellpadding="' . $padding . '"><thead><tr style="background-color:#004e89;color:#ffffff">';
        foreach ($keys as $key) $html .= '<th width="' . $widths[$key] . '%" style="border-right:0.2mm solid #ffffff"><b>' . Security::e($headers[$key]) . '</b></th>';
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $index => $row) {
            $html .= '<tr nobr="true" style="background-color:' . ($index % 2 ? '#f4f7f8' : '#ffffff') . '">';
            foreach ($keys as $key) $html .= '<td width="' . $widths[$key] . '%" style="border-bottom:0.2mm solid #c9d3d8;color:#0f2a3d">' . nl2br(Security::e($row[$key] ?? '')) . '</td>';
            $html .= '</tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="' . count($keys) . '" style="color:#60717d"><i>No records.</i></td></tr>';
        $pdf->writeHTML($html . '</tbody></table>');
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Ln(3);
        $pdf->Cell(0, 5, 'End of report · ' . number_format(count($rows)) . (count($rows) === 1 ? ' record' : ' records'), 0, 1, 'R');
    }

    /** A TCPDF with the iSmile letterhead and the confidential footer on every page. */
    private static function document(string $title, string $orientation = 'L'): \TCPDF
    {
        $pdf = new class ($orientation, 'mm', 'A4', true, 'UTF-8') extends \TCPDF {
            public string $docTitle = '';
            public string $issued = '';
            public string $mark = '';

            public function setDocTitle(string $title): void
            {
                $this->docTitle = $title;
            }

            public function Header(): void
            {
                $left = $this->original_lMargin;
                $right = $this->getPageWidth() - $this->original_rMargin;
                if (is_file($this->mark)) $this->Image($this->mark, $left, 9, 0, 12, 'PNG');
                $this->SetTextColor(0, 78, 137);
                $this->SetFont('dejavuserif', 'B', 13);
                $this->SetXY($left + 13, 9.2);
                $this->Cell(90, 6, 'iSmile 2026', 0, 0, 'L');
                $this->SetFont('dejavusans', '', 7.5);
                $this->SetTextColor(96, 113, 125);
                $this->SetXY($left + 13, 15.2);
                $this->Cell(120, 5, 'International Dental Summit  ·  Official record', 0, 0, 'L');
                $this->SetFont('dejavusans', 'B', 9);
                $this->SetTextColor(15, 42, 61);
                $this->SetXY($right - 140, 9.2);
                $this->Cell(140, 6, $this->docTitle, 0, 0, 'R');
                $this->SetFont('dejavusans', '', 7.5);
                $this->SetTextColor(96, 113, 125);
                $this->SetXY($right - 140, 15.2);
                $this->Cell(140, 5, 'Issued ' . $this->issued, 0, 0, 'R');
                $this->SetDrawColor(0, 78, 137);
                $this->SetLineWidth(0.5);
                $this->Line($left, 23.5, $right, 23.5);
                $this->SetDrawColor(34, 188, 205);
                $this->SetLineWidth(0.25);
                $this->Line($left, 24.4, $right, 24.4);
            }

            public function Footer(): void
            {
                $left = $this->original_lMargin;
                $right = $this->getPageWidth() - $this->original_rMargin;
                $y = $this->getPageHeight() - 13;
                $this->SetDrawColor(201, 211, 216);
                $this->SetLineWidth(0.2);
                $this->Line($left, $y, $right, $y);
                $this->SetFont('dejavusans', '', 7);
                $this->SetTextColor(96, 113, 125);
                $this->SetXY($left, $y + 1.5);
                $this->Cell(200, 5, 'CONFIDENTIAL  ·  Contains personal data. For the iSmile 2026 organising team only; do not share.', 0, 0, 'L');
                // Page aliases are measured before they are filled in, so right-aligning drifts; start at a fixed spot.
                $this->SetXY($right - 21, $y + 1.5);
                $this->Cell(21, 5, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, 0, 'L');
            }
        };
        $pdf->docTitle = $title;
        $pdf->issued = date('j F Y, H:i');
        $pdf->mark = dirname(__DIR__, 2) . '/assets/pdf-mark.png';
        $pdf->SetCreator('iSmile 2026 Database');
        $pdf->SetAuthor('iSmile 2026 organising team');
        $pdf->SetTitle('iSmile 2026 · ' . $title);
        $pdf->SetMargins(14, 31, 14);
        $pdf->SetHeaderMargin(8);
        $pdf->SetFooterMargin(13);
        $pdf->SetAutoPageBreak(true, 18);
        return $pdf;
    }

    private static function titleBlock(\TCPDF $pdf, string $title, string $meta): void
    {
        $pdf->SetTextColor(...self::INK);
        $pdf->SetFont('dejavuserif', 'B', 18);
        $pdf->Cell(0, 9, $title, 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->MultiCell(0, 5, $meta, 0, 'L');
        $pdf->SetDrawColor(...self::TEAL);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($pdf->GetX(), $pdf->GetY() + 1.5, $pdf->GetX() + 18, $pdf->GetY() + 1.5);
        $pdf->Ln(6);
        $pdf->SetTextColor(...self::INK);
    }

    /** Per column: [longest single word, longest line] in characters (headings count bold). */
    private static function stats(array $keys, array $headers, array $rows): array
    {
        $words = static fn (string $text): int => max(0, ...array_map('mb_strlen', preg_split('/\s+/u', $text) ?: ['']));
        $stats = [];
        foreach ($keys as $key) {
            $word = (int) ceil($words((string) $headers[$key]) * 1.15);
            $line = mb_strlen((string) $headers[$key]);
            foreach ($rows as $row) {
                $value = (string) ($row[$key] ?? '');
                $word = max($word, $words($value));
                foreach (preg_split('/\R/u', $value) ?: [] as $part) $line = max($line, mb_strlen($part));
            }
            $stats[$key] = [max(3, min($word, 26)), $line];
        }
        return $stats;
    }

    /**
     * Column widths (percent): first each column gets room for its longest word,
     * so words never break in the middle; the space left goes to longer text.
     */
    private static function widths(array $stats, float $size, float $padding, float $usable): array
    {
        $char = 0.22 * $size;   // mm per character, with a little margin, in DejaVu Sans
        $base = array_map(static fn (array $stat): float => $stat[0] * $char + 2 * $padding, $stats);
        $more = array_map(static fn (array $stat): float => max(0, min($stat[1], 40) - $stat[0]) * $char, $stats);
        $spare = $usable - array_sum($base);
        $share = array_sum($more) > 0 ? max(0, $spare) / array_sum($more) : 0;
        $widths = [];
        foreach ($base as $key => $width) {
            $widths[$key] = $width + $more[$key] * min(1, $share);
        }
        // Whatever is still left is spread evenly, so the table fills the page.
        $left = max(0, $usable - array_sum($widths));
        $total = array_sum($widths) + $left ?: 1;
        return array_map(static fn (float $width): string => number_format(($width + $left / count($widths)) / $total * 100, 2, '.', ''), $widths);
    }

    /** "Ismile Registrations" (from the file name) reads as "Registrations". */
    private static function cleanTitle(string $title): string
    {
        $title = trim(preg_replace('/^ismile\s+/i', '', $title) ?? $title);
        return $title !== '' ? $title : 'Report';
    }
}
