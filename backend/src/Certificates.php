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
        $design = self::designFile();
        $font = $design !== null ? Badges::fonts($pdf)[0] : null;
        foreach ($rows as $row) {
            if ($design !== null) self::designPage($pdf, $row, $design, (string) $font);
            else self::page($pdf, $row);
        }
        return $pdf->Output('', 'S');
    }

    /** The Owner's uploaded certificate artwork: private storage only, never the public repository. */
    public static function designFile(): ?string
    {
        if ((string) App::config('storage', '') === '') return null;
        foreach (['jpg', 'png'] as $ext) {
            $file = App::storage('certificates/design.' . $ext);
            if (is_file($file)) return $file;
        }
        return null;
    }

    /** Saves an A4 landscape PNG or JPEG as the certificate design (Owner only). */
    public static function installDesign(array $user, mixed $file): string
    {
        if (($user['role'] ?? '') !== 'owner') throw new UserError('Only the Owner can change the certificate design.');
        if ((string) App::config('storage', '') === '') throw new UserError('Private storage is not configured on this server.');
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new UserError('Choose the certificate design image (PNG or JPEG).');
        }
        if ((int) $file['size'] < 10_000 || (int) $file['size'] > 25_000_000) throw new UserError('The design must be an image up to 25 MB.');
        $info = @getimagesize((string) $file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new UserError('The design must be a PNG or JPEG image.');
        [$w, $h] = $info;
        if ($w < 2000 || abs($w / $h - 297 / 210) > 0.03) throw new UserError('The design must be A4 landscape, at least 2000 pixels wide (for example 3508 × 2480).');
        $dir = App::storage('certificates');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new UserError('The private certificate folder could not be created.');
        $target = $dir . '/design.' . ($info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png');
        $temp = $target . '.uploading';
        if (!move_uploaded_file((string) $file['tmp_name'], $temp)) throw new UserError('The design could not be saved.');
        // A PNG becomes a high-quality JPEG when the server can, so each emailed PDF stays small.
        if ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng') && ($image = @imagecreatefrompng($temp))) {
            $jpeg = $dir . '/design.jpg.uploading';
            $ok = imagejpeg($image, $jpeg, 92);
            imagedestroy($image);
            if ($ok) { @unlink($temp); $temp = $jpeg; $target = $dir . '/design.jpg'; }
        }
        foreach (glob($dir . '/design.*') ?: [] as $old) if (!str_ends_with($old, '.uploading')) @unlink($old);
        if (!rename($temp, $target)) throw new UserError('The design could not be saved.');
        @chmod($target, 0640);
        try {
            self::pdf([['recipient_name' => 'Design Check', 'certificate_no' => 'ISM26-C-00000', 'attended_days' => 'Day 1']]);
        } catch (\Throwable) {
            self::removeDesign($user);
            throw new UserError('This image could not be used in a PDF. The previous certificate design is used.');
        }
        Audit::log((int) $user['id'], 'certificate.design', null, null, ['width' => $w, 'height' => $h]);
        return 'Certificate design saved privately on the server. Every certificate PDF now uses it.';
    }

    public static function removeDesign(array $user): string
    {
        if (($user['role'] ?? '') !== 'owner') throw new UserError('Only the Owner can change the certificate design.');
        if ((string) App::config('storage', '') !== '') foreach (glob(App::storage('certificates') . '/design.*') ?: [] as $file) @unlink($file);
        Audit::log((int) $user['id'], 'certificate.design_removed');
        return 'The certificate design was removed. Certificates use the built-in design.';
    }

    /**
     * The approved artwork (A4 landscape) with the guest's name in the blank
     * line under "THIS CERTIFICATE IS PROUDLY PRESENTED TO": badge name font,
     * capitals, in the certificate's blue (#004E88), left-aligned with the text.
     */
    private static function designPage(\TCPDF $pdf, array $row, string $design, string $font): void
    {
        $pdf->AddPage();
        $pdf->Image($design, 0, 0, 297, 210, '', '', '', false, 300);
        $name = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $row['recipient_name'])), 'UTF-8');
        // Kurdish or Arabic letters need DejaVu, as on the badge.
        $rtl = (bool) preg_match('/[^\x{0000}-\x{024F}]/u', $name);
        [$face, $style] = $rtl ? ['dejavusans', 'B'] : [$font, ''];
        $size = 32.0;
        $pdf->SetFont($face, $style, $size);
        while ($size > 14 && $pdf->GetStringWidth($name) > 249) $pdf->SetFont($face, $style, $size -= 0.5);
        $pdf->SetTextColor(0, 78, 136);
        // Baseline chosen so the capitals sit centred in the empty band (86.0–103.6 mm).
        $baseline = 94.8 + 0.727 * $size * 25.4 / 72 / 2 - ($rtl ? 1.5 : 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->SetXY(23.0, $baseline);
        $pdf->Cell(249, 0, $name, 0, 0, 'L', false, '', 0, false, 'L', 'M');
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
                // Same lock order as admission. Concurrent button presses queue once:
                // the email check below is a locking read, so it sees the email the
                // other press just queued (a plain read would see an older snapshot).
                $ticket=Db::one('SELECT * FROM tickets WHERE registration_id=? FOR UPDATE',[$id['id']]);
                $registration=Db::one('SELECT * FROM registrations WHERE id=? FOR UPDATE',[$id['id']]);
                if (!self::rows((int)$id['id']) || !Validate::email($registration['email'])) { $counts['skipped']++; continue; }
                $existing=Db::value("SELECT id FROM emails WHERE kind='certificate' AND registration_id=? AND to_email=? AND (status='pending' OR (status='sent' AND COALESCE(provider_message_id,'') NOT LIKE 'skipped:%' AND ?=0)) LIMIT 1 LOCK IN SHARE MODE",[$id['id'],$registration['email'],$resend?1:0]);
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
            $pdf->MultiCell(213, 7, "In recognition of participation in iSmile 2026\nat Grand Millennium Sulaimani · 19–20 November 2026", 0, 'C');
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
