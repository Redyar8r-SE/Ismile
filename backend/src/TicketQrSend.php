<?php
// "Send QR code" on a person's page (owner, 19 Nov 2026). PSOOLA has no
// resend request, so iSmile sends the person's PSOOLA entry QR itself:
// by email (our email service, the QR picture attached), and by WhatsApp
// (opens WhatsApp with a ready message; the team member presses Send).
// The QR is the entry ticket: it is never logged or written to the audit log.

declare(strict_types=1);

namespace Ismile;

final class TicketQrSend
{
    /** The person's PSOOLA tickets that have a QR (valid or already used). */
    public static function qrs(int $registrationId): array
    {
        return Db::all("SELECT code, ticket_type_name, holder_name, status, qr_image_url FROM psoola_ticket_qrs
            WHERE registration_id = ? AND status IN ('valid','used') AND qr_image_url IS NOT NULL ORDER BY id", [$registrationId]);
    }

    /** Queues the QR email to $to (the address on file, or a corrected one). */
    public static function email(array $registration, array $user, string $to): string
    {
        if (!in_array($registration['status'], ['paid', 'complimentary'], true)) throw new UserError('This person has no active ticket.');
        $to = strtolower(trim($to));
        if (!Validate::email($to)) throw new UserError('Enter a valid email address.');
        if (!self::qrs((int) $registration['id'])) {
            throw new UserError('No PSOOLA QR code is saved for this person yet. Open "View PSOOLA ticket" once, then try again.');
        }
        if (!RateLimit::hit('ticket-qr-email:' . (int) $registration['id'], 5, 3600)) {
            throw new UserError('The QR email was already sent 5 times in the last hour. Wait a little before sending again.');
        }
        Outbox::queue('ticket_qr', $registration, ['by' => (int) $user['id']], null, $to);
        Audit::log((int) $user['id'], 'ticket.qr_email', 'registration', (int) $registration['id'], ['to_changed' => $to !== strtolower((string) $registration['email'])]);
        return 'The QR code email is on its way to ' . $to . ' (it leaves within a minute; see Communications for its status).';
    }

    /** A WhatsApp link with the ready message for the person's number, or null. */
    public static function whatsappUrl(array $registration): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $registration['phone']);
        $qrs = self::qrs((int) $registration['id']);
        if (strlen($digits) < 8 || !$qrs) return null;
        $lines = ['Hello ' . $registration['first_name'] . ',', '', 'This is your iSmile 2026 entry ticket QR code. Please show it at the entrance:'];
        foreach ($qrs as $qr) {
            $lines[] = '';
            $lines[] = ($qr['ticket_type_name'] !== '' ? $qr['ticket_type_name'] . ' ticket ' : 'Ticket ') . $qr['code'] . ':';
            $lines[] = $qr['qr_image_url'];
        }
        $lines[] = '';
        $lines[] = 'Reference: ' . $registration['ref'];
        $lines[] = 'iSmile Organizing Committee';
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode(implode("\n", $lines));
    }

    /**
     * The QR picture from PSOOLA, attached to the email so it shows even when
     * pictures are blocked. Only PSOOLA's own https address; null when it fails.
     */
    public static function picture(string $url): ?string
    {
        if (!PsoolaTickets::safeUrl($url)) return null;
        $context = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 0, 'header' => "User-Agent: ismile-backend/1.0\r\n"]]);
        $png = @file_get_contents($url, false, $context, 0, 2_000_000);
        return is_string($png) && str_starts_with($png, "\x89PNG") ? $png : null;
    }
}
