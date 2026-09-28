<?php
// Sends through Resend's email API (https://resend.com/docs/api-reference/emails/send-email).

declare(strict_types=1);

namespace Ismile\Mail;

use Ismile\App;

final class ResendMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, string $text, array $attachments = []): string
    {
        $key = (string) App::config('mail.resend_key', '');
        if ($key === '') {
            throw new \RuntimeException('Resend is not configured (mail.resend_key in config.php).');
        }
        // "Name <address>"; the name must not contain the characters that frame the address.
        $name = str_replace(['<', '>', '"', "\r", "\n"], '', (string) App::config('mail.from_name', 'iSmile 2026'));
        $payload = [
            'from'     => $name . ' <' . App::config('mail.from_email') . '>',
            'to'       => [$to],
            'reply_to' => App::config('mail.reply_to', 'ismile@italk.krd'),
            'subject'  => $subject,
            'html'     => $html,
            'text'     => $text,
        ];
        if ($attachments) {
            $payload['attachments'] = array_map(
                static fn (array $file): array => ['filename' => $file['name'], 'content' => base64_encode($file['content'])],
                $attachments
            );
        }
        $curl = curl_init('https://api.resend.com/emails');
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $key, 'user-agent: iSmile-2026'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false) {
            throw new \RuntimeException('Resend did not answer: ' . $error);
        }
        $json = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Resend refused the email (' . $status . '): ' . mb_substr((string) ($json['message'] ?? $body), 0, 300));
        }
        return (string) ($json['id'] ?? 'resend');
    }
}
