<?php
declare(strict_types=1);

namespace Ismile;

/**
 * The printed attendee badge: 10 × 11.5 cm, two sides. The front is the
 * approved ATTENDEE artwork; the back is the approved artwork with the
 * guest's name, ticket type and the PSOOLA ticket code as a QR, placed and
 * coloured exactly like the approved sample. Read-only: nothing is saved.
 */
final class Badges
{
    private const WIDTH = 100.0;
    private const HEIGHT = 115.0;
    /** The approved brand blue (the ATTENDEE lettering). */
    private const BLUE = [4, 79, 138];

    /** One badge per PSOOLA ticket code of this guest (normally one). */
    public static function pdf(array $registration, array $codes): string
    {
        $pdf = new \TCPDF('P', 'mm', [self::WIDTH, self::HEIGHT], true, 'UTF-8');
        $pdf->SetCreator('iSmile 2026');
        $pdf->SetAuthor('iSmile 2026');
        $pdf->SetTitle('iSmile 2026 badge · ' . Registrations::fullName($registration));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $fonts = self::fonts($pdf);
        // Print at 100% on both sides of the card (front, then back).
        $pdf->setViewerPreferences(['PrintScaling' => 'None', 'Duplex' => 'DuplexFlipLongEdge', 'PickTrayByPDFSize' => true]);
        foreach ($codes as $code) {
            self::front($pdf);
            self::back($pdf, $registration, $code, $fonts);
        }
        return $pdf->Output('', 'S');
    }

    /** True when the Owner has uploaded the licensed Helvetica Now Display. */
    public static function hasLicensedFont(): bool
    {
        $dir = self::privateFontDir();
        return $dir !== null && is_file("$dir/helveticanowdisplayblack.php") && is_file("$dir/helveticanowdisplaymedium.php");
    }

    /**
     * Installs the Owner's licensed Helvetica Now Display (Black and Medium
     * TrueType files) in the server's private storage. It is never put in
     * the repository; badge PDFs embed only the letters they use.
     */
    public static function installFont(array $user, array $files): string
    {
        if (($user['role'] ?? '') !== 'owner') throw new UserError('Only the Owner can change the badge font.');
        $dir = self::privateFontDir();
        if ($dir === null) throw new UserError('Private storage is not configured on this server.');
        $sources = [];
        foreach (['black' => 'Black', 'medium' => 'Medium'] as $key => $weight) {
            $file = $files['font_' . $key] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                throw new UserError("Choose the HelveticaNowDisplay-$weight.ttf file.");
            }
            if ((int) $file['size'] < 1000 || (int) $file['size'] > 3_000_000) throw new UserError("The $weight font file is not a usable font.");
            $magic = (string) file_get_contents((string) $file['tmp_name'], false, null, 0, 4);
            if ($magic === 'OTTO') throw new UserError("Upload the .ttf version of $weight, not the .otf.");
            if ($magic !== "\x00\x01\x00\x00" && $magic !== 'true') throw new UserError("The $weight file is not a TrueType (.ttf) font.");
            $sources[$weight] = (string) $file['tmp_name'];
        }
        if (!is_dir("$dir/src") && !@mkdir("$dir/src", 0750, true)) throw new UserError('The private font folder could not be created.');
        foreach ($sources as $weight => $tmp) {
            $target = "$dir/src/HelveticaNowDisplay-$weight.ttf";
            if (!move_uploaded_file($tmp, $target)) throw new UserError("The $weight font could not be saved.");
            $name = \TCPDF_FONTS::addTTFfont($target, 'TrueTypeUnicode', '', 32, $dir . '/');
            if ($name !== 'helveticanowdisplay' . strtolower($weight)) {
                self::removeFont($user);
                throw new UserError("The $weight font could not be prepared for PDFs.");
            }
        }
        try {
            self::pdf(['first_name' => 'Font', 'father_name' => 'Check', 'grandfather_name' => '', 'ticket_type' => 'professional', 'specialty' => 'gp'], ['FONTTEST']);
        } catch (\Throwable) {
            self::removeFont($user);
            throw new UserError('The font files could not be used in a badge. The free Inter Display font is still used.');
        }
        Audit::log((int) $user['id'], 'badge.font', null, null, ['font' => 'Helvetica Now Display']);
        return 'Helvetica Now Display is installed privately on the server. New badges use it.';
    }

    /** Back to the free Inter Display font. */
    public static function removeFont(array $user): string
    {
        if (($user['role'] ?? '') !== 'owner') throw new UserError('Only the Owner can change the badge font.');
        $dir = self::privateFontDir();
        foreach ($dir === null ? [] : [...(glob("$dir/helveticanowdisplay*") ?: []), ...(glob("$dir/src/HelveticaNowDisplay-*") ?: [])] as $file) {
            @unlink($file);
        }
        Audit::log((int) $user['id'], 'badge.font_removed');
        return 'The uploaded font was removed. Badges use the free Inter Display font.';
    }

    /** [name font, label font]: the licensed font when uploaded, otherwise Inter Display. */
    private static function fonts(\TCPDF $pdf): array
    {
        if (self::hasLicensedFont()) {
            $dir = self::privateFontDir();
            $pdf->AddFont('helveticanowdisplayblack', '', "$dir/helveticanowdisplayblack.php");
            $pdf->AddFont('helveticanowdisplaymedium', '', "$dir/helveticanowdisplaymedium.php");
            return ['helveticanowdisplayblack', 'helveticanowdisplaymedium'];
        }
        // Inter Display (SIL Open Font License): the closest free match to the artwork's Helvetica Now Display.
        $pdf->AddFont('interdisplayblack', '', self::asset('fonts/interdisplayblack.php'));
        $pdf->AddFont('interdisplaymedium', '', self::asset('fonts/interdisplaymedium.php'));
        return ['interdisplayblack', 'interdisplaymedium'];
    }

    /** storage/fonts: private, never in the repository or the web folder. */
    private static function privateFontDir(): ?string
    {
        return (string) App::config('storage', '') === '' ? null : App::storage('fonts');
    }

    /** "DR. FIRST FATHER" for dentists (and VIP dentists), "FIRST FATHER" for students and lab technicians. */
    public static function displayName(array $registration): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $registration['first_name'] . ' ' . $registration['father_name']));
        $doctor = $registration['ticket_type'] !== 'student' && !in_array($registration['specialty'] ?? '', ['student', 'lab'], true);
        return mb_strtoupper(($doctor ? 'Dr. ' : '') . $name, 'UTF-8');
    }

    private static function front(\TCPDF $pdf): void
    {
        $pdf->AddPage();
        $pdf->Image(self::asset('badge-front.jpg'), 0, 0, self::WIDTH, self::HEIGHT, 'JPG', '', '', false, 600);
    }

    private static function back(\TCPDF $pdf, array $registration, string $code, array $fonts): void
    {
        $pdf->AddPage();
        $pdf->Image(self::asset('badge-back.jpg'), 0, 0, self::WIDTH, self::HEIGHT, 'JPG', '', '', false, 600);
        [$r, $g, $b] = self::BLUE;
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetDrawColor($r, $g, $b);
        $pdf->SetFillColor($r, $g, $b);

        // The name: one line across the card, smaller when it is long.
        $name = self::displayName($registration);
        // Kurdish or Arabic letters need DejaVu; Latin names use the artwork's heavy display face.
        [$font, $style] = preg_match('/[^\x{0000}-\x{024F}]/u', $name) ? ['dejavusans', 'B'] : [$fonts[0], ''];
        $size = 25.8;
        $pdf->SetFont($font, $style, $size);
        while ($size > 9 && $pdf->GetStringWidth($name) > 84) {
            $pdf->SetFont($font, $style, $size -= 0.5);
        }
        $pdf->SetXY(0, 28.0);
        $pdf->Cell(self::WIDTH, 0, $name, 0, 0, 'C', false, '', 0, false, 'L', 'M');

        // The ticket type in the blue pill, with a line on each side.
        $pdf->SetLineWidth(0.27);
        $pdf->Line(7.55, 32.6, 31.5, 32.6);
        $pdf->Line(68.75, 32.6, 92.45, 32.6);
        $pdf->RoundedRect(32.76, 29.8, 34.74, 5.8, 2.9, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont($fonts[1], '', 14.3);
        $pdf->SetXY(32.76, 34.4);
        $pdf->Cell(34.74, 0, Registrations::ticketName($registration['ticket_type']), 0, 0, 'C', false, '', 0, false, 'L', 'M');

        // The box: white inside, filled by the QR (as tall as the box allows). No printed code.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(23.35, 38.3, 53.2, 41.4, 'F');
        self::qr($pdf, $code, 50.0, 38.7, 40.6);
        $pdf->SetLineWidth(0.27);
        $pdf->Rect(23.35, 38.3, 53.2, 41.4, 'D');
    }

    /**
     * The QR in iSmile style: rounded blue modules and softly rounded corner
     * eyes (more rounding stops scanners finding them) on a white card, the iSmile tooth in the middle. High error correction keeps it
     * readable with the logo; the content is exactly the PSOOLA code.
     */
    private static function qr(\TCPDF $pdf, string $code, float $centerX, float $top, float $card): void
    {
        require_once dirname(__DIR__) . '/vendor/tecnickcom/tcpdf/include/barcodes/qrcode.php';
        // At least version 4 (33 × 33): a short code would otherwise make the
        // smallest QR, with too little room around the logo. The content is unchanged.
        $matrix = (new class ($code) extends \QRcode {
            public function __construct(string $code)
            {
                $this->version = 4;
                parent::__construct($code, 'H');
            }
        })->getBarcodeArray();
        $n = (int) ($matrix['num_rows'] ?? 0);
        if ($n < 21 || $n !== (int) ($matrix['num_cols'] ?? 0)) throw new UserError('The badge QR could not be made.');
        $left = $centerX - $card / 2;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($left, $top, $card, $card, 2.2, '1111', 'F');
        $quiet = 2.0;                                    // modules of white around the code
        $m = $card / ($n + 2 * $quiet);
        $x0 = $left + $quiet * $m;
        $y0 = $top + $quiet * $m;
        [$r, $g, $b] = self::BLUE;
        $pdf->SetFillColor($r, $g, $b);
        // The logo area in the middle: an odd number of modules, about a fifth of the width.
        $hole = max(5, (int) round($n * 0.22) | 1);
        $from = intdiv($n - $hole, 2);
        $eye = static fn (int $x, int $y): bool => ($x < 7 && $y < 7) || ($x >= $n - 7 && $y < 7) || ($x < 7 && $y >= $n - 7);
        foreach ($matrix['bcode'] as $y => $row) {
            foreach ($row as $x => $dark) {
                if (!$dark || $eye($x, $y) || ($x >= $from && $x < $from + $hole && $y >= $from && $y < $from + $hole)) continue;
                $pdf->RoundedRect($x0 + $x * $m + 0.04 * $m, $y0 + $y * $m + 0.04 * $m, 0.92 * $m, 0.92 * $m, 0.3 * $m, '1111', 'F');
            }
        }
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ex, $ey]) {
            $ox = $x0 + $ex * $m;
            $oy = $y0 + $ey * $m;
            $pdf->SetFillColor($r, $g, $b);
            $pdf->RoundedRect($ox, $oy, 7 * $m, 7 * $m, 0.7 * $m, '1111', 'F');
            $pdf->SetFillColor(255, 255, 255);
            $pdf->RoundedRect($ox + $m, $oy + $m, 5 * $m, 5 * $m, 0.5 * $m, '1111', 'F');
            $pdf->SetFillColor($r, $g, $b);
            $pdf->RoundedRect($ox + 2 * $m, $oy + 2 * $m, 3 * $m, 3 * $m, 0.3 * $m, '1111', 'F');
        }
        $logoW = ($hole - 0.6) * $m;
        $logoH = $logoW * 559 / 482;
        if ($logoH > ($hole - 0.6) * $m) {
            $logoH = ($hole - 0.6) * $m;
            $logoW = $logoH * 482 / 559;
        }
        $pdf->Image(self::asset('badge-qr-logo.png'), $x0 + ($from + $hole / 2) * $m - $logoW / 2, $y0 + ($from + $hole / 2) * $m - $logoH / 2, $logoW, $logoH, 'PNG', '', '', false, 600);
    }

    private static function asset(string $file): string
    {
        return dirname(__DIR__) . '/assets/' . $file;
    }
}
