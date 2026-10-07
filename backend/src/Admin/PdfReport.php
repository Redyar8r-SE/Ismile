<?php
declare(strict_types=1);
namespace Ismile\Admin;

use Ismile\Security;

final class PdfReport
{
    public static function studentIds(array $people): string
    {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetTitle('iSmile student ID records');
        $pdf->SetMargins(15, 15, 15);
        foreach ($people as $person) {
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', 'B', 16);
            $pdf->MultiCell(180, 9, \Ismile\Registrations::fullName($person));
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->MultiCell(180, 7, $person['ref'] . "\n" . ($person['university'] ?? '') . "\n" . $person['phone']);
            $photo = \Ismile\IdPhotos::find((int) ($person['id_photo_id'] ?? 0));
            if ($photo) {
                $scale = min(180 / $photo['width'], 185 / $photo['height']);
                $pdf->Image('@' . $photo['image'], 15, $pdf->GetY() + 8, $photo['width'] * $scale, $photo['height'] * $scale, 'JPEG');
            } else $pdf->MultiCell(180, 7, 'No ID photo stored.');
        }
        if (!$people) { $pdf->AddPage(); $pdf->SetFont('dejavusans', '', 12); $pdf->Cell(180, 10, 'No student ID records.'); }
        return $pdf->Output('', 'S');
    }

    /** Wide exports are split into readable column groups with the reference repeated. */
    public static function make(string $title, array $rows): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetCreator('iSmile Database');
        $pdf->SetTitle($title);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 16);
        self::append($pdf, $title, $rows);
        return $pdf->Output('', 'S');
    }

    public static function bundle(array $sections): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->setPrintHeader(false);
        $pdf->SetCreator('iSmile Database');
        $pdf->SetTitle('iSmile 2026 · Event reports');
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 16);
        foreach ($sections as $title => $rows) self::append($pdf, $title, $rows);
        return $pdf->Output('', 'S');
    }

    private static function append(\TCPDF $pdf, string $title, array $rows): void
    {
        $pdf->SetFont('dejavusans', '', 8);
        if (count($rows[0] ?? []) === 1) $subtitle = (string) array_shift($rows)[0];
        $headers = array_shift($rows) ?? [];
        $groups = count($headers) <= 8 ? [array_keys($headers)] : array_map(static fn ($keys) => [0, ...$keys], array_chunk(array_slice(array_keys($headers), 1), 7));
        foreach ($groups as $groupIndex => $keys) {
            $pdf->AddPage();
            $pdf->SetTextColor(18, 48, 47);
            $pdf->SetFont('dejavusans', 'B', 18);
            $pdf->Cell(0, 12, $title, 0, 1);
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->MultiCell(0, 7, ($subtitle ?? 'iSmile 2026') . ' · ' . count($rows) . ' records · ' . date('d M Y H:i') . (count($groups) > 1 ? ' · Column group ' . ($groupIndex + 1) . '/' . count($groups) : ''), 0, 'L');
            $pdf->Ln(4);
            $pdf->SetFont('dejavusans', '', 8);
            $html = '<table border="1" cellpadding="5"><thead><tr style="background-color:#12302f;color:#ffffff">';
            foreach ($keys as $key) $html .= '<th><b>' . Security::e($headers[$key]) . '</b></th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $index => $row) {
                $html .= '<tr style="background-color:' . ($index % 2 ? '#eef5f3' : '#ffffff') . '">';
                foreach ($keys as $key) $html .= '<td>' . nl2br(Security::e($row[$key] ?? '')) . '</td>';
                $html .= '</tr>';
            }
            if (!$rows) $html .= '<tr><td colspan="' . count($keys) . '">No records.</td></tr>';
            $pdf->writeHTML($html . '</tbody></table>');
        }
    }
}
