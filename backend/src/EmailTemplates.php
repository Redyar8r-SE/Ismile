<?php
// Builds each email from the queue row, in the registrant's language.
// No email ever contains a password, a card number, an ID photo or another
// person's details; links are personal signed links.

declare(strict_types=1);

namespace Ismile;

use Ismile\Security as S;

final class EmailTemplates
{
    /**
     * Returns ['to', 'subject', 'html', 'text', 'attachments'], or null when the
     * email is no longer needed (for example "Pay now" to someone who has paid).
     *
     * The emails: the ticket (the moment a payment is confirmed), the "pay now"
     * link for a registration taken by phone, sponsor emails, and alerts to
     * the team. Nobody who has not paid gets reminders: an unpaid form is not
     * a registration.
     */
    public static function build(array $email): ?array
    {
        $data = $email['data'] ? (json_decode((string) $email['data'], true) ?: []) : [];
        $kind = $email['kind'];

        if ($kind === 'alert') {
            $message = (string) ($data['message'] ?? '');
            return self::plainForTeam($email['to_email'], 'iSmile: needs attention', $message);
        }
        if ($kind === 'sponsor_received' || $kind === 'sponsor_notify') {
            $request = Db::one('SELECT * FROM sponsor_requests WHERE id = ?', [$email['sponsor_request_id']]);
            return $request ? self::sponsor($kind, $email, $request) : null;
        }
        if ($kind === 'pay_now') {
            $checkout = $email['checkout_id'] ? Checkouts::find((int) $email['checkout_id']) : null;
            return $checkout ? self::payNow($checkout) : null;
        }
        if ($kind !== 'ticket') {
            throw new \RuntimeException("Unknown email kind '$kind'.");
        }

        $registration = Registrations::find((int) $email['registration_id']);
        if ($registration === null) {
            return null;
        }
        $ticket = Tickets::forRegistration((int) $registration['id']);
        if ($ticket === null || $ticket['cancelled_at'] !== null || !in_array($registration['status'], ['paid', 'complimentary'], true)) {
            return null;
        }
        $lang = Lang::pick($registration['lang']);
        $w = EmailText::for($lang);
        $name = Registrations::fullName($registration);
        $vars = ['{ref}' => $registration['ref'], '{name}' => $name, '{ticket}' => $ticket['ticket_no']];
        $qrUrl = App::url('api/qr.php?r=' . rawurlencode($registration['ref']) . '&k=' . Links::viewToken($registration) . '&v=' . $ticket['version']);
        $blocks = [
            $w['ticket_body'],
            '<div style="text-align:center;margin:18px 0">'
                . '<img src="' . S::e($qrUrl) . '" width="220" height="220" alt="QR" style="display:inline-block;border:1px solid #cfe3e3;border-radius:8px">'
                . '<div style="font:bold 18px Arial,sans-serif;letter-spacing:1px;margin-top:8px" dir="ltr">' . S::e($ticket['ticket_no']) . '</div></div>',
            self::detailsTable($registration, $w, [[$w['ticket_no'], $ticket['ticket_no']]]),
            $w['ticket_name_note'],
            str_replace('{url}', App::url('workshops.html'), $w['ticket_workshops']),
        ];
        $html = self::layout($lang, strtr($w['hello'], ['{name}' => S::e($name)]), $blocks, null, $vars);
        return [
            'to'          => $registration['email'],
            'subject'     => strtr($w['ticket_subject'], $vars),
            'html'        => $html,
            'text'        => self::toText($html),
            'attachments' => [['name' => 'iSmile-2026-' . $ticket['ticket_no'] . '.pdf', 'content' => Tickets::pdf($registration, $ticket)]],
        ];
    }

    /** "Complete your registration by paying": a registration taken by phone. */
    private static function payNow(array $checkout): ?array
    {
        if (!Checkouts::isOpen($checkout) || !SiteData::pricesReadyFor($checkout) || Registrations::isFull()) {
            return null;   // already paid, expired, or no longer possible
        }
        $lang = Lang::pick($checkout['lang']);
        $w = EmailText::for($lang);
        $name = Registrations::fullName($checkout);
        $vars = ['{ref}' => $checkout['ref'], '{name}' => $name];
        $blocks = [$w['pay_now_body'], self::detailsTable($checkout + ['status' => 'unpaid'], $w, [])];
        $html = self::layout($lang, strtr($w['hello'], ['{name}' => S::e($name)]), $blocks, [$w['pay_button'], Links::payUrl($checkout), true], $vars);
        return [
            'to'          => $checkout['email'],
            'subject'     => strtr($w['pay_now_subject'], $vars),
            'html'        => $html,
            'text'        => self::toText($html),
            'attachments' => [],
        ];
    }

    private static function detailsTable(array $registration, array $w, array $extra): string
    {
        $rows = [
            [$w['reference'], $registration['ref']],
            [$w['pdf_ticket_type'], $registration['ticket_type'] === 'student' ? $w['type_student'] : $w['type_professional']],
            [$w['pdf_lunch'], EmailText::lunchLine($registration, Lang::pick($registration['lang']))],
        ];
        if ($registration['status'] === 'unpaid' && SiteData::pricesReadyFor($registration)) {   // a form waiting for payment
            $rows[] = [$w['amount'], number_format(SiteData::amountFor($registration)) . ' ' . SiteData::prices()['currency']];
        }
        if ($registration['status'] === 'complimentary') {
            $rows[] = [$w['pdf_note'], $w['complimentary']];
        }
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:8px 0;font-size:14px">';
        foreach (array_merge($rows, $extra) as [$label, $value]) {
            $html .= '<tr><td style="padding:6px 0;color:#5b7477;width:40%">' . S::e($label) . '</td><td style="padding:6px 0;font-weight:bold">' . S::e($value) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private static function sponsor(string $kind, array $email, array $request): array
    {
        if ($kind === 'sponsor_notify') {
            $lines = [
                "New {$request['kind']} request {$request['ref']}",
                "Company: {$request['company']}",
                "Contact: {$request['contact_name']}" . ($request['contact_role'] ? ", {$request['contact_role']}" : ''),
                "Phone: {$request['phone']}",
                "Email: {$request['email']}",
                'Package: ' . ($request['package'] ?: '-'),
                'Website: ' . ($request['website'] ?: '-'),
                'City: ' . ($request['city'] ?: '-'),
                'Message: ' . ($request['message'] ?: '-'),
                '',
                'Open it in the admin: ' . App::url('admin/sponsors.php?id=' . $request['id']),
            ];
            return self::plainForTeam($email['to_email'], "iSmile: new {$request['kind']} request {$request['ref']} from {$request['company']}", implode("\n", $lines));
        }
        $lang = Lang::pick($request['lang']);
        $w = EmailText::for($lang);
        $vars = ['{ref}' => $request['ref']];
        $table = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">'
            . '<tr><td style="padding:6px 0;color:#5b7477;width:40%">' . S::e($w['reference']) . '</td><td style="font-weight:bold" dir="ltr">' . S::e($request['ref']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#5b7477">' . S::e($w['sponsor_kind']) . '</td><td style="font-weight:bold">' . S::e($request['kind'] === 'booth' ? $w['sponsor_booth'] : $w['sponsor_sponsor']) . '</td></tr>'
            . '</table>';
        $html = self::layout($lang, strtr($w['hello'], ['{name}' => S::e($request['contact_name'])]), [$w['sponsor_body'], $table], null, $vars);
        return ['to' => $request['email'], 'subject' => strtr($w['sponsor_subject'], $vars), 'html' => $html, 'text' => self::toText($html), 'attachments' => []];
    }

    private static function plainForTeam(string $to, string $subject, string $message): array
    {
        $html = '<div style="font:14px/1.5 Arial,sans-serif;white-space:pre-wrap">' . S::e($message) . '</div>';
        return ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $message, 'attachments' => []];
    }

    /** The shared email frame: header band, text blocks, one button, footer. */
    private static function layout(string $lang, string $greeting, array $blocks, ?array $button, array $vars): string
    {
        $w = EmailText::for($lang);
        $rtl = Lang::isRtl($lang);
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $font = $rtl ? "Tahoma,'Noto Kufi Arabic',Arial,sans-serif" : 'Arial,Helvetica,sans-serif';
        $phone = (string) App::config('office_phone', '');
        $questions = str_replace('{phone}', $phone !== '' ? str_replace('{phone}', $phone, $w['call']) : '', $w['questions']);

        $body = '';
        foreach ($blocks as $block) {
            $isHtml = str_starts_with(ltrim($block), '<');
            $body .= $isHtml ? $block : '<p style="margin:0 0 14px">' . ($block === strip_tags($block) ? S::e($block) : $block) . '</p>';
        }
        if ($button !== null) {
            [$label, $url, $showExpiry] = $button;
            $body .= '<p style="margin:22px 0;text-align:center"><a href="' . S::e($url) . '" style="background:#16a6a1;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 28px;border-radius:8px;display:inline-block;font-size:16px">' . S::e($label) . '</a></p>';
            if ($showExpiry) {
                $body .= '<p style="margin:0 0 14px;color:#5b7477;font-size:12px;text-align:center">' . S::e(str_replace('{days}', (string) max(1, Settings::int('pay_link_days')), $w['pay_expires'])) . '</p>';
            }
        }

        return '<!doctype html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#eef6f6">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef6f6"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="background:#12302f;padding:22px 26px;color:#ffffff;font-family:Arial,sans-serif" dir="ltr"><div style="font-size:24px;font-weight:bold">iSmile 2026</div>'
            . '<div style="font-size:13px;color:#cfefee;font-family:' . $font . '" dir="' . $dir . '">' . S::e($w['dates_venue']) . '</div></td></tr>'
            . '<tr><td dir="' . $dir . '" style="padding:26px;font-family:' . $font . ';font-size:15px;line-height:1.6;color:#12302f;text-align:' . $align . '">'
            . '<p style="margin:0 0 14px;font-weight:bold">' . $greeting . '</p>' . $body
            . '</td></tr>'
            . '<tr><td dir="' . $dir . '" style="padding:16px 26px;background:#f4fafa;font-family:' . $font . ';font-size:12px;line-height:1.5;color:#5b7477;text-align:' . $align . '">'
            . S::e($questions) . '<br>' . S::e($w['refund_rule']) . '<br>' . S::e($w['no_reply'])
            . '</td></tr></table></td></tr></table></body></html>';
    }

    private static function toText(string $html): string
    {
        $text = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#s', '$2: $1', $html) ?? $html;
        $text = preg_replace('#<(br|/p|/tr|/div)[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
