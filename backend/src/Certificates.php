<?php
declare(strict_types=1);

namespace Ismile;

final class Certificates
{
    public const ELIGIBLE_SQL = "r.status IN ('paid','complimentary') AND t.cancelled_at IS NULL AND EXISTS (SELECT 1 FROM ticket_attendance a WHERE a.ticket_id = t.id)";

    /** Called in the admission transaction; payment alone never calls this. */
    public static function issue(array $registration, array $ticket): void
    {
        if ((int)$ticket['registration_id'] !== (int)$registration['id'] || $ticket['cancelled_at'] !== null
            || !in_array($registration['status'], ['paid','complimentary'], true)
            || !Db::value('SELECT id FROM ticket_attendance WHERE ticket_id = ? LIMIT 1', [$ticket['id']])) {
            throw new UserError('A certificate requires recorded attendance.');
        }
        Db::run('INSERT INTO certificates (registration_id, certificate_no, recipient_name, issued_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE recipient_name = VALUES(recipient_name)',
            [$registration['id'], sprintf('ISM26-C-%05d', $registration['id']), Registrations::fullName($registration), App::now()]);
    }

    public static function rows(?int $registrationId = null): array
    {
        return Db::all('SELECT c.*, r.ref, (SELECT GROUP_CONCAT(CONCAT(\'Day \', a.event_day) ORDER BY a.event_day SEPARATOR \' & \') FROM ticket_attendance a WHERE a.ticket_id = t.id) AS attended_days
            FROM certificates c JOIN registrations r ON r.id = c.registration_id JOIN tickets t ON t.registration_id = r.id
            WHERE ' . self::ELIGIBLE_SQL . ($registrationId !== null ? ' AND r.id = ?' : '') . ' ORDER BY c.recipient_name, c.id', $registrationId !== null ? [$registrationId] : []);
    }

    public static function pdf(array $rows): string
    {
        if (!$rows) throw new UserError('No checked-in guests have certificates yet.');
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('iSmile 2026');
        $pdf->SetAuthor('iSmile 2026');
        $pdf->SetTitle('iSmile 2026 · Certificates of participation');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        foreach ($rows as $row) self::page($pdf, $row);
        return $pdf->Output('', 'S');
    }

    /** Bulk sends skip already sent/pending certificates; an individual can be resent. */
    public static function queueEmails(?int $registrationId, array $user, bool $resend = false): array
    {
        if (!Auth::can($user, 'certificates')) throw new UserError('Certificate access is required.');
        return Db::transaction(static function () use ($registrationId, $user, $resend): array {
            $ids = Db::all('SELECT r.id FROM certificates c JOIN registrations r ON r.id=c.registration_id JOIN tickets t ON t.registration_id=r.id WHERE '.self::ELIGIBLE_SQL.($registrationId!==null?' AND r.id=?':'').' ORDER BY r.id', $registrationId!==null?[$registrationId]:[]);
            if ($registrationId!==null && !$ids) throw new UserError('A certificate requires recorded attendance and an active ticket.');
            $counts=['queued'=>0,'skipped'=>0];
            foreach ($ids as $id) {
                // Same lock order as admission. Concurrent button presses queue once.
                $ticket=Db::one('SELECT * FROM tickets WHERE registration_id=? FOR UPDATE',[$id['id']]);
                $registration=Db::one('SELECT * FROM registrations WHERE id=? FOR UPDATE',[$id['id']]);
                if (!self::rows((int)$id['id']) || !Validate::email($registration['email'])) { $counts['skipped']++; continue; }
                $existing=Db::value("SELECT id FROM emails WHERE kind='certificate' AND registration_id=? AND to_email=? AND (status='pending' OR (status='sent' AND COALESCE(provider_message_id,'') NOT LIKE 'skipped:%' AND ?=0)) LIMIT 1",[$id['id'],$registration['email'],$resend?1:0]);
                if ($existing) { $counts['skipped']++; continue; }
                $emailId=Outbox::queue('certificate',$registration);
                Audit::log((int)$user['id'],'certificate.email','registration',(int)$id['id'],['email_id'=>$emailId,'to'=>$registration['email'],'resend'=>$resend]);
                $counts['queued']++;
            }
            if ($registrationId===null) Audit::log((int)$user['id'],'certificate.email_all',null,null,$counts);
            return $counts;
        });
    }

    private static function page(\TCPDF $pdf, array $row): void
    {
        $pdf->AddPage();
        $pdf->SetFillColor(250, 248, 241);
        $pdf->Rect(0, 0, 297, 210, 'F');
        $background = (string) App::config('certificates.background', '');
        if ($background !== '' && is_file($background)) {
            // Optional approved certificate artwork, installed in private storage.
            $pdf->Image($background, 0, 0, 297, 210);
        } else {
            $pdf->SetFillColor(18, 48, 47);
            $pdf->Rect(0, 0, 15, 210, 'F');
            $pdf->Rect(282, 0, 15, 210, 'F');
            $pdf->SetDrawColor(186, 148, 78);
            $pdf->SetLineWidth(0.5);
            $pdf->Rect(23, 10, 251, 190);
            $pdf->Rect(26, 13, 245, 184);
            $pdf->SetTextColor(18, 48, 47);
            $pdf->SetFont('dejavusans', 'B', 22);
            $pdf->SetXY(35, 24);
            $pdf->Cell(227, 12, 'iSmile 2026', 0, 1, 'C');
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY(35, 39);
            $pdf->Cell(227, 7, 'DENTAL SUMMIT  ·  SULAIMANI', 0, 1, 'C');
            $pdf->SetTextColor(153, 117, 52);
            $pdf->SetFont('dejavuserif', '', 30);
            $pdf->SetXY(35, 59);
            $pdf->Cell(227, 16, 'Certificate of Participation', 0, 1, 'C');
            $pdf->SetTextColor(85, 105, 105);
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->SetXY(35, 83);
            $pdf->Cell(227, 9, 'This certificate is proudly presented to', 0, 1, 'C');
            $pdf->SetXY(42, 126);
            $pdf->MultiCell(213, 7, "In recognition of participation in iSmile 2026\nat Grand Millennium Sulaimani · 20–21 November 2026", 0, 'C');
            $pdf->SetDrawColor(186, 148, 78);
            $pdf->Line(89, 121, 208, 121);
            $pdf->SetXY(35, 158);
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->Cell(227, 8, 'iSmile Organizing Committee', 0, 1, 'C');
        }
        // Unicode font supports Arabic and Kurdish, and shrinks long names to fit.
        $pdf->SetTextColor(18, 48, 47);
        $pdf->SetFont('dejavusans', 'B', 24);
        $pdf->SetXY(37, 98);
        $pdf->Cell(223, 18, $row['recipient_name'], 0, 1, 'C', false, '', 1);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(85, 105, 105);
        $pdf->SetXY(37, 181);
        $pdf->Cell(223, 6, $row['certificate_no'] . '  ·  Attendance: ' . $row['attended_days'], 0, 1, 'C');
    }
}
