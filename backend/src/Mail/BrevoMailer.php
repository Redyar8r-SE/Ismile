<?php
// Sends through Brevo's transactional email API (https://developers.brevo.com).

declare(strict_types=1);

namespace Ismile\Mail;

use Ismile\App;

final class BrevoMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, string $text, array $attachments = []): string
    {
        $key = (string) App::config('mail.brevo_key', '');
        if ($key === '') {
            throw new \RuntimeException('Brevo is not configured (mail.brevo_key in config.php).');
        }
        $payload = [
            'sender'      => ['email' => App::config('mail.from_email'), 'name' => App::config('mail.from_name', 'iSmile 2026')],
            'to'          => [['email' => $to]],
            'replyTo'     => ['email' => App::config('mail.reply_to', 'ismile@italk.krd')],
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text,
        ];
        if ($attachments) {
            $payload['attachment'] = array_map(
                static fn (array $file): array => ['name' => $file['name'], 'content' => base64_encode($file['content'])],
                $attachments
            );
        }
        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['accept: application/json', 'content-type: application/json', 'api-key: ' . $key],
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
            throw new \RuntimeException('Brevo did not answer: ' . $error);
        }
        $json = json_decode((string) $body, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Brevo refused the email (' . $status . '): ' . mb_substr((string) ($json['message'] ?? $body), 0, 300));
        }
        return (string) ($json['messageId'] ?? 'brevo');
    }
}
