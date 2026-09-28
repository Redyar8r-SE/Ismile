<?php
// Tickets: numbers, signed QR codes and the PDF.
//
// The QR carries the ticket number, its version and a signature made with the
// server's secret. A forged or edited QR fails the check; a copied one is
// caught at the door because check-in looks the ticket up in the database.

declare(strict_types=1);

namespace Ismile;

final class Tickets
{
    /**
     * Makes the ticket for a registration. Called from exactly two places:
     * a confirmed payment (Payments::apply) and a complimentary ticket from
     * the Owner. Must run inside the caller's transaction.
     */
    public static function issue(int $registrationId, string $source, ?int $paymentId, ?int $issuedBy): array
    {
        $existing = Db::one('SELECT * FROM tickets WHERE registration_id = ?', [$registrationId]);
        if ($existing !== null) {
            if ($existing['cancelled_at'] !== null) {
                Db::run('UPDATE tickets SET cancelled_at = NULL, version = version + 1, source = ?, payment_id = ?, issued_by = ? WHERE id = ?',
                    [$source, $paymentId, $issuedBy, $existing['id']]);
            }
            return Db::one('SELECT * FROM tickets WHERE id = ?', [$existing['id']]);
        }
        $id = Db::insert('tickets', [
            'registration_id' => $registrationId,
            'ticket_no'       => 'PENDING-' . bin2hex(random_bytes(4)),
            'source'          => $source,
            'payment_id'      => $paymentId,
            'issued_by'       => $issuedBy,
            'created_at'      => App::now(),
        ]);
        Db::run('UPDATE tickets SET ticket_no = ? WHERE id = ?', [sprintf('T26-%05d', $id), $id]);
        return Db::one('SELECT * FROM tickets WHERE id = ?', [$id]);
    }

    public static function forRegistration(int $registrationId): ?array
    {
        return Db::one('SELECT * FROM tickets WHERE registration_id = ?', [$registrationId]);
    }

    /** What the QR code contains, e.g. ISM26:T26-00012:1:Xy3... */
    public static function qrPayload(array $ticket): string
    {
        $core = 'ISM26:' . $ticket['ticket_no'] . ':' . $ticket['version'];
        return $core . ':' . Security::sign($core);
    }

    /**
     * Reads a scanned QR (or a typed ticket number) and returns the ticket
     * number, or null if the signature is wrong. An old version (before a
     * name change reissued it) is refused too.
     */
    public static function readScan(string $scanned): ?array
    {
        $scanned = trim($scanned);
        if (preg_match('/^ISM26:(T26-\d{5}):(\d+):([A-Za-z0-9_-]+)$/', $scanned, $m)) {
            if (!Security::verify("ISM26:{$m[1]}:{$m[2]}", $m[3])) {
                return ['error' => 'forged'];
            }
            $ticket = Db::one('SELECT * FROM tickets WHERE ticket_no = ?', [$m[1]]);
            if ($ticket === null) {
                return ['error' => 'unknown'];
            }
            if ((int) $ticket['version'] !== (int) $m[2]) {
                return ['error' => 'old_version', 'ticket' => $ticket];
            }
            return ['ticket' => $ticket];
        }
        if (preg_match('/^T26-\d{5}$/i', $scanned)) {
            $ticket = Db::one('SELECT * FROM tickets WHERE ticket_no = ?', [strtoupper($scanned)]);
            return $ticket ? ['ticket' => $ticket] : ['error' => 'unknown'];
        }
        return null;
    }

    public static function qrPng(array $ticket, int $pixelsPerModule = 8): string
    {
        $barcode = new \TCPDF2DBarcode(self::qrPayload($ticket), 'QRCODE,M');
        $png = $barcode->getBarcodePngData($pixelsPerModule, $pixelsPerModule, [0, 0, 0]);
        if ($png === false || $png === '') {
            throw new \RuntimeException('QR image could not be made.');
        }
        // Add a white border (the "quiet zone") so every phone scanner reads it.
        $inner = imagecreatefromstring($png);
        $pad = $pixelsPerModule * 3;
        $out = imagecreatetruecolor(imagesx($inner) + 2 * $pad, imagesy($inner) + 2 * $pad);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $inner, $pad, $pad, 0, 0, imagesx($inner), imagesy($inner));
        ob_start();
        imagepng($out);
        $data = (string) ob_get_clean();
        imagedestroy($inner);
        imagedestroy($out);
        return $data;
    }

    /** The PDF ticket, in the registrant's language. */
    public static function pdf(array $registration, array $ticket): string
    {
        $lang = Lang::pick($registration['lang']);
        $words = EmailText::for($lang);
        $pdf = new \TCPDF('P', 'mm', 'A5', true, 'UTF-8');
        $pdf->SetCreator('iSmile 2026');
        $pdf->SetAuthor('iSmile 2026');
        $pdf->SetTitle('iSmile 2026 ticket ' . $ticket['ticket_no']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->setRTL(Lang::isRtl($lang));

        // Header band
        $pdf->SetFillColor(18, 48, 47);
        $pdf->Rect(0, 0, 148, 34, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 20);
        $pdf->SetXY(12, 9);
        $pdf->Cell(124, 10, 'iSmile 2026', 0, 1);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetX(12);
        $pdf->Cell(124, 6, $words['dates_venue'], 0, 1);

        // Name – exactly as typed; it also goes on the certificate
        $pdf->SetTextColor(18, 48, 47);
        $pdf->SetXY(12, 42);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(124, 5, $words['pdf_name'], 0, 1);
        $pdf->SetX(12);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->MultiCell(124, 8, Registrations::fullName($registration), 0, Lang::isRtl($lang) ? 'R' : 'L', false, 1);

        $rows = [
            [$words['pdf_ticket_type'], $registration['ticket_type'] === 'student' ? $words['type_student'] : $words['type_professional']],
            [$words['pdf_lunch'], EmailText::lunchLine($registration, $lang)],
            [$words['pdf_reference'], $registration['ref']],
            [$words['pdf_ticket_no'], $ticket['ticket_no']],
        ];
        if ($registration['status'] === 'complimentary') {
            $rows[] = [$words['pdf_note'], $words['complimentary']];
        }
        $y = $pdf->GetY() + 4;
        foreach ($rows as [$label, $value]) {
            $pdf->SetXY(12, $y);
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor(91, 116, 119);
            $pdf->Cell(40, 7, $label, 0, 0);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor(18, 48, 47);
            $pdf->Cell(84, 7, $value, 0, 1);
            $y += 8;
        }

        // QR code, centred, with the ticket number under it for typing in.
        // Drawn left-to-right: in right-to-left mode TCPDF mirrors positions.
        $pdf->setRTL(false);
        $pdf->write2DBarcode(self::qrPayload($ticket), 'QRCODE,M', 44, 116, 60, 60, ['border' => 0, 'padding' => 2, 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]], 'N');
        $pdf->SetXY(12, 178);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->Cell(124, 7, $ticket['ticket_no'], 0, 1, 'C');
        $pdf->setRTL(Lang::isRtl($lang));
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(91, 116, 119);
        $pdf->SetXY(12, 187);
        $pdf->MultiCell(124, 4.5, $words['pdf_footer'], 0, 'C', false, 1);
        $pdf->setRTL(false);
        $pdf->SetX(12);
        $pdf->Cell(124, 5, 'ismile@italk.krd · ismile.krd', 0, 1, 'C');
        return $pdf->Output('', 'S');
    }
}
