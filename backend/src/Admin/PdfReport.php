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

    public static function studentIds(array $people, array $paymentRows = []): string
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
        if ($paymentRows !== []) self::append($pdf, 'Ticket and lunch payment breakdown', $paymentRows);
        return $pdf->Output('', 'S');
    }

    /** One report: every column in one table, on A4 or (for many columns) A3 landscape. */
    /**
     * One workshop's attendee list: the workshop with its trainer photo, three
     * summary boxes, then everyone booked: full name, ticket type, phone,
     * email and what they paid for the workshop, with the total.
     *
     * @param array $workshop the workshop as Workshops::find() returns it
     * @param list<array{name: string, ticket: string, guest: bool, phone: string, email: string, paid: int, how: string, state: string}> $people
     *        state is paid, free or unpaid; how says how the workshop was paid
     */
    public static function workshopAttendees(array $workshop, array $people): string
    {
        $text = static function (mixed $value): string {
            return is_array($value) ? (string) (($value['en'] ?? '') ?: (array_values(array_filter($value))[0] ?? '')) : (string) $value;
        };
        $title = (string) ($text($workshop['title'] ?? '') ?: 'Workshop');
        $pdf = self::document('Workshop attendee list', 'P');
        $pdf->tagline = 'International Dental Summit, official record';
        $pdf->footNote = 'CONFIDENTIAL: contains personal data. For the iSmile 2026 organising team only; do not share.';
        $pdf->AddPage('P', 'A4');
        $left = 14.0;
        $width = $pdf->getPageWidth() - 28;
        $top = $pdf->GetY();

        // The workshop card: trainer photo on the studio background, then the details.
        $cardH = 46.0;
        $pdf->SetFillColor(240, 248, 248);
        $pdf->SetDrawColor(184, 222, 220);
        $pdf->SetLineWidth(0.3);
        $pdf->RoundedRect($left, $top, $width, $cardH, 4, '1111', 'DF');
        $photo = self::workshopPhoto($workshop);
        $textX = $left + 6;
        if ($photo !== null) {
            $pdf->Image('@' . $photo, $left + 4, $top + 4, 32, 38, 'JPG');
            $textX = $left + 42;
        }
        $pdf->SetXY($textX, $top + 5);
        $pdf->SetTextColor(14, 127, 123);
        $pdf->SetFont('dejavusans', 'B', 7.5);
        $pdf->Cell(0, 4, 'ISMILE WORKSHOP', 0, 1, 'L');
        $pdf->SetX($textX);
        $pdf->SetTextColor(...self::INK);
        $pdf->SetFont('dejavuserif', 'B', 14);
        $pdf->MultiCell($left + $width - $textX - 4, 6.2, $title, 0, 'L');
        $pdf->SetX($textX);
        $pdf->SetFont('dejavusans', 'B', 9.5);
        $pdf->SetTextColor(54, 88, 90);
        $pdf->Cell(0, 5.5, $text($workshop['speaker'] ?? ''), 0, 1, 'L');
        $when = array_filter([
            ($workshop['scheduledDate'] ?? '') ? date('l j F Y', (int) strtotime((string) $workshop['scheduledDate'])) : '',
            implode(' to ', array_filter([(string) ($workshop['startTime'] ?? ''), (string) ($workshop['endTime'] ?? '')])),
        ]);
        $pdf->SetX($textX);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(14, 127, 123);
        $pdf->Cell(0, 5, implode(', ', $when) . ($when ? ' (Iraq time)' : ''), 0, 1, 'L');
        $price = (int) ($workshop['price'] ?? 0);
        $pdf->SetX($textX);
        $pdf->SetTextColor(...self::MUTED);
        $pdf->Cell(0, 5, $price > 0 ? 'Price per ticket: ' . number_format($price) . ' ' . ($workshop['currency'] ?? 'IQD') : 'Free workshop', 0, 1, 'L');

        // Three summary boxes.
        $total = array_sum(array_column($people, 'paid'));
        $states = array_count_values(array_column($people, 'state'));
        $paidCount = (int) ($states['paid'] ?? 0);
        $notPaid = (int) ($states['unpaid'] ?? 0);
        $boxes = [
            ['ATTENDEES', number_format(count($people)), 'Booked for this workshop'],
            ['TOTAL COLLECTED', number_format($total) . ' IQD', 'For this workshop'],
            ['PAYMENT', $paidCount . ' paid', ((int) ($states['free'] ?? 0)) . ' free' . ($notPaid ? ', ' . $notPaid . ' not paid yet' : '')],
        ];
        $y = $top + $cardH + 5;
        $gap = 4.0;
        $boxW = ($width - 2 * $gap) / 3;
        foreach ($boxes as $i => [$label, $value, $hint]) {
            $x = $left + $i * ($boxW + $gap);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(201, 226, 224);
            $pdf->RoundedRect($x, $y, $boxW, 21, 3, '1111', 'DF');
            $pdf->SetFillColor(...self::TEAL);
            $pdf->Rect($x, $y + 3, 1.2, 15, 'F');
            $pdf->SetXY($x + 5, $y + 3);
            $pdf->SetFont('dejavusans', 'B', 7);
            $pdf->SetTextColor(...self::MUTED);
            $pdf->Cell($boxW - 7, 4, $label, 0, 2, 'L');
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(...self::NAVY);
            $pdf->Cell($boxW - 7, 7, $value, 0, 2, 'L');
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(...self::MUTED);
            $pdf->Cell($boxW - 7, 4, $hint, 0, 0, 'L');
        }
        $pdf->SetY($y + 27);

        // The attendees, with the total at the end.
        $cell = static fn (string $html, string $extra = ''): string => '<td style="border-bottom:0.2mm solid #d3e6e6;color:#0f2a3d"' . $extra . '>' . $html . '</td>';
        $html = '<table cellpadding="4"><thead><tr style="background-color:#0e7f7b;color:#ffffff">'
            . '<th width="5%"><b>#</b></th><th width="24%"><b>Full name</b></th><th width="12%"><b>Ticket type</b></th>'
            . '<th width="15%"><b>Phone</b></th><th width="29%"><b>Email</b></th><th width="15%" align="right"><b>Total paid</b></th></tr></thead><tbody>';
        foreach (array_values($people) as $index => $p) {
            $html .= '<tr nobr="true" style="background-color:' . ($index % 2 ? '#f2f9f8' : '#ffffff') . '">'
                . $cell((string) ($index + 1), ' width="5%"')
                . $cell('<b>' . Security::e($p['name']) . '</b>', ' width="24%"')
                . $cell(Security::e($p['ticket']) . ($p['guest'] ? '<br><span style="color:#0e7f7b;font-size:7pt"><b>Guest</b></span>' : ''), ' width="12%"')
                . $cell(Security::e($p['phone']), ' width="15%"')
                . $cell('<span style="font-size:7.5pt">' . Security::e($p['email']) . '</span>', ' width="29%"')
                . $cell(($p['paid'] > 0 ? '<b>' . number_format($p['paid']) . ' IQD</b>' : '0 IQD') . '<br><span style="color:' . ($p['state'] === 'unpaid' ? '#b4232a' : '#60717d') . ';font-size:7pt">' . Security::e($p['how']) . '</span>', ' width="15%" align="right"')
                . '</tr>';
        }
        if (!$people) $html .= '<tr><td colspan="6" style="color:#60717d"><i>Nobody is booked for this workshop yet.</i></td></tr>';
        $html .= '<tr style="background-color:#dff1ef"><td colspan="5" width="85%" style="color:#004e89;border-top:0.4mm solid #0e7f7b"><b>Total collected for this workshop</b></td>'
            . '<td width="15%" align="right" style="color:#004e89;border-top:0.4mm solid #0e7f7b"><b>' . number_format($total) . ' IQD</b></td></tr>';
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->writeHTML($html . '</tbody></table>');
        return $pdf->Output('', 'S');
    }

    /**
     * The trainer photo as a JPEG for the PDF: a cut-out portrait is placed on the
     * studio background, like on the website. Null when there is no usable photo.
     */
    private static function workshopPhoto(array $workshop): ?string
    {
        $path = (string) ($workshop['photo'] ?? '');
        if (!preg_match('~^assets/(?:workshops|uploads/workshops)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g)$~i', $path) || str_contains($path, '..') || !function_exists('imagecreatetruecolor')) return null;
        $load = static function (string $file) {
            if (!is_file($file)) return null;
            $type = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $image = match ($type) {
                'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false,
                'png' => @imagecreatefrompng($file),
                'jpg', 'jpeg' => @imagecreatefromjpeg($file),
                default => false,
            };
            return $image ?: null;
        };
        $portrait = $load(App::siteFile($path)) ?? $load(dirname(__DIR__, 3) . '/' . $path);
        if (!$portrait) return null;
        [$w, $h] = [640, 760];
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 234, 245, 244));
        $studio = $load(App::siteFile('assets/workshops/studio-light.webp')) ?? $load(dirname(__DIR__, 3) . '/assets/workshops/studio-light.webp');
        if ($studio) {   // cover the box with the studio background
            $sw = imagesx($studio); $sh = imagesy($studio); $scale = max($w / $sw, $h / $sh);
            imagecopyresampled($canvas, $studio, (int) (($w - $sw * $scale) / 2), (int) (($h - $sh * $scale) / 2), 0, 0, (int) ($sw * $scale), (int) ($sh * $scale), $sw, $sh);
        }
        imagealphablending($canvas, true);
        $pw = imagesx($portrait); $ph = imagesy($portrait);
        if (($workshop['photoStyle'] ?? '') === 'cutout') {   // the whole person, standing at the bottom
            $scale = min($w / $pw, $h / $ph);
            imagecopyresampled($canvas, $portrait, (int) (($w - $pw * $scale) / 2), (int) ($h - $ph * $scale), 0, 0, (int) ($pw * $scale), (int) ($ph * $scale), $pw, $ph);
        } else {   // a normal photo fills the box
            $scale = max($w / $pw, $h / $ph);
            imagecopyresampled($canvas, $portrait, (int) (($w - $pw * $scale) / 2), (int) (($h - $ph * $scale) / 2), 0, 0, (int) ($pw * $scale), (int) ($ph * $scale), $pw, $ph);
        }
        ob_start();
        imagejpeg($canvas, null, 90);
        return (string) ob_get_clean();
    }

    public static function make(string $title, array $rows, bool $showCashTicketAmounts = false): string
    {
        $title = self::cleanTitle($title);
        $pdf = self::document($title);
        self::append($pdf, $title, $rows, $showCashTicketAmounts);
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

    private static function append(\TCPDF $pdf, string $title, array $rows, bool $showCashTicketAmounts = false): void
    {
        if (count($rows[0] ?? []) === 1) $subtitle = (string) array_shift($rows)[0];
        $headers = array_shift($rows) ?? [];
        // Apply these display choices to every PDF, including bundled reports.
        $headers = array_diff($headers, ['Specialty', 'Payment notes']);
        $ticketPaid = array_search('Ticket paid', $headers, true);
        $channel = array_search('Cash / website', $headers, true);
        if (!$showCashTicketAmounts && $ticketPaid !== false && $channel !== false) {
            foreach ($rows as &$row) {
                if (($row[$channel] ?? '') === 'Cash') $row[$ticketPaid] = '--';
            }
            unset($row);
        }
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
            public string $tagline = 'International Dental Summit  ·  Official record';
            public string $footNote = 'CONFIDENTIAL  ·  Contains personal data. For the iSmile 2026 organising team only; do not share.';

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
                $this->Cell(120, 5, $this->tagline, 0, 0, 'L');
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
                $this->Cell(200, 5, $this->footNote, 0, 0, 'L');
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
