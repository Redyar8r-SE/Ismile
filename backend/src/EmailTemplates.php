<?php
// Builds each email from the queue row. Every email is in English.
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
        $w = EmailText::words();
        $name = Registrations::fullName($registration);
        $vars = ['{ref}' => $registration['ref'], '{name}' => $name, '{ticket}' => $ticket['ticket_no']];
        $withQr = Settings::bool('ticket_qr_in_email');   // off until the entrance check is decided

        // ---- Your registration ----
        $paid = $registration['status'] === 'complimentary' ? null
            : Db::value("SELECT amount_confirmed FROM payments WHERE registration_id = ? AND status = 'paid' ORDER BY id LIMIT 1", [$registration['id']]);
        $mine = [
            [$w['row_name'], $name],
            [$w['reference'], $registration['ref']],
            [$w['ticket_no'], $ticket['ticket_no']],
            [$w['row_ticket'], $registration['ticket_type'] === 'student' ? $w['type_student'] : $w['type_professional']],
        ];
        if ($registration['ticket_type'] === 'student' && $registration['university']) {
            $mine[] = [$w['row_university'], $registration['university']];
        }
        // Lunch with the real day and date: "Day 1 · 20 November", both, or none.
        $event = self::eventInfo();
        $lunchDays = [];
        foreach ([1, 2] as $n) {
            if ((int) $registration['lunch_day' . $n] === 1) {
                $lunchDays[] = S::e($event['days'][$n - 1]['title'] ?? $w['lunch_day' . $n]);
            }
        }
        $mine[] = [$w['row_days'], $w['days_both']];
        $mine[] = [$w['pdf_lunch'], $lunchDays ? '✓ ' . implode('<br>✓ ', $lunchDays) : S::e($w['lunch_none']), true];
        if ($registration['status'] === 'complimentary') {
            $mine[] = [$w['row_paid'], $w['free_ticket']];
        } else {
            $methods = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'fib' => 'FIB', 'fastpay' => 'FastPay'];
            $mine[] = [$w['row_paid'], $paid !== null ? '<span dir="ltr">' . number_format((int) $paid) . ' ' . S::e(SiteData::prices()['currency']) . '</span>' : '–', true];
            if ($registration['pay_method']) {
                $mine[] = [$w['row_paid_by'], $methods[$registration['pay_method']] ?? $registration['pay_method']];
            }
            $mine[] = [$w['row_paid_on'], date('d/m/Y', (int) strtotime((string) $registration['paid_at']))];
        }

        // ---- The event (from the website's own program and map) ----
        $when = implode('<br>', array_map(static fn (array $day): string => S::e($day['title']) . ($day['start'] ? ' · ' . S::e(str_replace('{time}', $day['start'], $w['from_time'])) : ''), $event['days']));
        $where = S::e($event['place']) . '<br><a href="' . S::e($event['map']) . '" style="color:#0c6f6b">' . S::e($w['map_link']) . '</a>';
        $eventRows = [
            [$w['row_when'], $when, true],
            [$w['row_where'], $where, true],
            [$w['row_program'], '<a href="' . S::e(App::url('#program')) . '" style="color:#0c6f6b">' . S::e($w['program_link']) . '</a>', true],
        ];

        $entrance = S::e($withQr ? $w['entrance_qr'] : $w['entrance_text']);
        if ($registration['ticket_type'] === 'student') {
            $entrance .= '<br>' . S::e($w['entrance_student']);
        }
        $blocks = [
            '<p style="margin:0 0 18px;font-size:16px">' . S::e($w['ticket_intro']) . '</p>',
        ];
        if ($withQr) {
            $qrUrl = App::url('api/qr.php?r=' . rawurlencode($registration['ref']) . '&k=' . Links::viewToken($registration) . '&v=' . $ticket['version']);
            $blocks[] = '<div style="text-align:center;margin:18px 0"><img src="' . S::e($qrUrl) . '" width="220" height="220" alt="QR" style="display:inline-block;border:1px solid #cfe3e3;border-radius:8px">'
                . '<div style="font:bold 18px Arial,sans-serif;letter-spacing:1px;margin-top:8px" dir="ltr">' . S::e($ticket['ticket_no']) . '</div></div>';
        }
        $blocks[] = self::box('🎟 ' . $w['box_you'], $mine, '#e4f6f5', '#0c6f6b');
        $blocks[] = self::box('📍 ' . $w['box_event'], $eventRows, '#fcefd3', '#8a5a0f');
        $blocks[] = '<p style="margin:0 0 6px;font-weight:bold">' . S::e($w['entrance_title']) . '</p><p style="margin:0 0 16px">' . $entrance . '</p>';
        $blocks[] = $w['ticket_name_note'];
        $blocks[] = '<p style="margin:18px 0 0;font-weight:bold">' . S::e($w['see_you']) . '</p>';

        $greeting = '<span style="font-size:22px;color:#0c6f6b">' . S::e(str_replace('{name}', (string) $registration['first_name'], $w['congrats'])) . '</span>';
        $html = self::layout($greeting, $blocks, null, $vars);
        return [
            'to'          => $registration['email'],
            'subject'     => strtr($w['ticket_subject'], $vars),
            'html'        => $html,
            'text'        => self::toText($html),
            'attachments' => $withQr ? [['name' => 'iSmile-2026-' . $ticket['ticket_no'] . '.pdf', 'content' => Tickets::pdf($registration, $ticket)]] : [],
        ];
    }

    /**
     * A coloured box with a title and label/value rows. A row's third item
     * true means the value is already HTML (links, line breaks).
     */
    private static function box(string $title, array $rows, string $background, string $colour): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;background:' . $background . ';border-radius:10px;margin:0 0 18px">'
            . '<tr><td colspan="2" style="padding:12px 16px 6px;font-weight:bold;font-size:16px;color:' . $colour . ';text-align:left">' . S::e($title) . '</td></tr>';
        foreach ($rows as $row) {
            [$label, $value] = $row;
            $html .= '<tr><td style="padding:5px 16px;color:#5b7477;width:38%;vertical-align:top;font-size:14px;text-align:left">' . S::e($label) . '</td>'
                . '<td style="padding:5px 16px;font-weight:bold;font-size:14px;vertical-align:top;text-align:left">' . (($row[2] ?? false) ? $value : S::e((string) $value)) . '</td></tr>';
        }
        return $html . '<tr><td colspan="2" style="height:8px"></td></tr></table>';
    }

    /**
     * The summit's days, start times and venue, read from the website's own
     * content (data/program.json, data/map.json), so the email always matches
     * what the website shows.
     */
    private static function eventInfo(): array
    {
        $read = static function (string $file): array {
            $data = json_decode((string) @file_get_contents(App::siteFile('data/' . $file)), true);
            return is_array($data) ? $data : [];
        };
        $pick = static fn ($value): string => is_array($value) ? (string) ($value['en'] ?? '') : (string) $value;
        $days = [];
        $program = $read('program.json');
        foreach ((array) ($program['days'] ?? []) as $day) {
            if (!is_array($day)) {
                continue;
            }
            $days[] = ['title' => strip_tags($pick($day['label'] ?? '')), 'start' => (string) ($day['sessions'][0]['start'] ?? '')];
        }
        if ($days === []) {
            $days[] = ['title' => EmailText::words()['dates_venue'], 'start' => ''];
        }
        $map = $read('map.json');
        $place = (string) ($map['place'] ?? '') ?: 'Grand Millennium Sulaimani, Sulaymaniyah';
        $url = (string) ($map['url'] ?? '');
        if (!preg_match('#^https://#', $url)) {
            $url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($place);
        }
        return ['days' => $days, 'place' => $place, 'map' => $url];
    }

    /** "Complete your registration by paying": a registration taken by phone. */
    private static function payNow(array $checkout): ?array
    {
        if (!Checkouts::isOpen($checkout) || !SiteData::pricesReadyFor($checkout) || Registrations::isFull()) {
            return null;   // already paid, expired, or no longer possible
        }
        $w = EmailText::words();
        $name = Registrations::fullName($checkout);
        $vars = ['{ref}' => $checkout['ref'], '{name}' => $name];
        $blocks = [$w['pay_now_body'], self::detailsTable($checkout + ['status' => 'unpaid'], $w, [])];
        $html = self::layout(strtr($w['hello'], ['{name}' => S::e($name)]), $blocks, [$w['pay_button'], Links::payUrl($checkout), true], $vars);
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
            [$w['pdf_lunch'], EmailText::lunchLine($registration)],
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
                'Package: ' . (SponsorPackages::name(SponsorPackages::find($request['package_id'])) ?: 'not sure yet'),
                'Website: ' . ($request['website'] ?: '-'),
                'City: ' . ($request['city'] ?: '-'),
                'Message: ' . ($request['message'] ?: '-'),
                '',
                'Open it in the admin: ' . App::url('admin/sponsors.php?id=' . $request['id']),
            ];
            return self::plainForTeam($email['to_email'], "iSmile: new {$request['kind']} request {$request['ref']} from {$request['company']}", implode("\n", $lines));
        }
        $w = EmailText::words();
        $vars = ['{ref}' => $request['ref']];
        $table = '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">'
            . '<tr><td style="padding:6px 0;color:#5b7477;width:40%">' . S::e($w['reference']) . '</td><td style="font-weight:bold" dir="ltr">' . S::e($request['ref']) . '</td></tr>'
            . '<tr><td style="padding:6px 0;color:#5b7477">' . S::e($w['sponsor_kind']) . '</td><td style="font-weight:bold">' . S::e($request['kind'] === 'booth' ? $w['sponsor_booth'] : $w['sponsor_sponsor']) . '</td></tr>'
            . '</table>';
        $html = self::layout(strtr($w['hello'], ['{name}' => S::e($request['contact_name'])]), [$w['sponsor_body'], $table], null, $vars);
        return ['to' => $request['email'], 'subject' => strtr($w['sponsor_subject'], $vars), 'html' => $html, 'text' => self::toText($html), 'attachments' => []];
    }

    private static function plainForTeam(string $to, string $subject, string $message): array
    {
        $html = '<div style="font:14px/1.5 Arial,sans-serif;white-space:pre-wrap">' . S::e($message) . '</div>';
        return ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $message, 'attachments' => []];
    }

    /** The shared email frame: header band, text blocks, one button, footer. */
    private static function layout(string $greeting, array $blocks, ?array $button, array $vars): string
    {
        $w = EmailText::words();
        $font = 'Arial,Helvetica,sans-serif';
        $phone = (string) App::config('office_phone', '') ?: self::sitePhone();
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

        return '<!doctype html><html lang="en" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
            . '<body style="margin:0;padding:0;background:#eef6f6">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef6f6"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">'
            . '<tr><td style="background:#12302f;padding:22px 26px;color:#ffffff;font-family:Arial,sans-serif" dir="ltr"><div style="font-size:24px;font-weight:bold">iSmile 2026</div>'
            . '<div style="font-size:13px;color:#cfefee;font-family:' . $font . '">' . S::e($w['dates_venue']) . '</div></td></tr>'
            . '<tr><td style="padding:26px;font-family:' . $font . ';font-size:15px;line-height:1.6;color:#12302f;text-align:left">'
            . '<p style="margin:0 0 14px;font-weight:bold">' . $greeting . '</p>' . $body
            . '</td></tr>'
            . '<tr><td style="padding:16px 26px;background:#f4fafa;font-family:' . $font . ';font-size:12px;line-height:1.5;color:#5b7477;text-align:left">'
            . ($phone !== '' ? str_replace(S::e($phone), '<span dir="ltr">' . S::e($phone) . '</span>', S::e($questions)) : S::e($questions)) . '<br>' . S::e($w['refund_rule']) . '<br>' . S::e($w['no_reply'])
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** The phone number shown in the website's footer (data/footer.json). */
    private static function sitePhone(): string
    {
        $footer = json_decode((string) @file_get_contents(App::siteFile('data/footer.json')), true);
        foreach ((array) ($footer['contact'] ?? []) as $item) {
            if (is_array($item) && str_starts_with((string) ($item['href'] ?? ''), 'tel:')) {
                return (string) ($item['text'] ?? '');
            }
        }
        return '';
    }

    private static function toText(string $html): string
    {
        $text = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#s', '$2: $1', $html) ?? $html;
        $text = preg_replace('#</td>\s*<td[^>]*>#i', ': ', $text) ?? $text;   // "Label: value" in tables
        $text = preg_replace('#<(br|/p|/tr|/div)[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text) ?? $text) ?? $text);
    }
}
