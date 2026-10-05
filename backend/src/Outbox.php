<?php
// The email queue. Emails are queued at once and sent by the timed job every
// minute (backend/cron/every-minute.php). Content is written at send time,
// from the registration as it is then, so a resend after fixing an address or
// a name is always right.

declare(strict_types=1);

namespace Ismile;

use Ismile\Mail\Mailer;

final class Outbox
{
    /** Minutes to wait before retry 1, 2 and 3. After that the email is marked failed. */
    private const RETRY_MINUTES = [5, 15, 40];

    public static function queue(string $kind, ?array $registration, array $data = [], ?int $sponsorRequestId = null, ?string $to = null): int
    {
        return Db::insert('emails', [
            'kind'               => $kind,
            'registration_id'    => $registration['id'] ?? null,
            'sponsor_request_id' => $sponsorRequestId,
            'to_email'           => $to ?? (string) ($registration['email'] ?? ''),
            'data'               => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'status'             => 'pending',
            'next_attempt_at'    => App::now(),
            'created_at'         => App::now(),
        ]);
    }

    /** An email about a form waiting for payment (the phone "Pay now" link). */
    public static function queueForCheckout(string $kind, array $checkout): int
    {
        return Db::insert('emails', [
            'kind'            => $kind,
            'checkout_id'     => $checkout['id'],
            'to_email'        => (string) $checkout['email'],
            'status'          => 'pending',
            'next_attempt_at' => App::now(),
            'created_at'      => App::now(),
        ]);
    }

    /** "Something needs attention": to the people in alerts_to, in English. */
    public static function alert(string $message): void
    {
        foreach ((array) App::config('alerts_to', []) as $address) {
            if (Validate::email((string) $address)) {
                self::queue('alert', null, ['message' => $message], null, (string) $address, 'en');
            }
        }
        App::log('alert', $message);
    }

    /** Sends what is due. Returns counts for the job's log line. */
    public static function process(Mailer $mailer, int $limit = 40): array
    {
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0];
        Db::run("UPDATE emails SET status = 'failed', last_error = COALESCE(last_error, 'Gave up after 10 tries') WHERE status = 'pending' AND attempts >= 10");
        $due = Db::all("SELECT * FROM emails WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id LIMIT $limit", [App::now()]);
        foreach ($due as $email) {
            // Claim it, so two overlapping jobs never send the same email.
            $claimed = Db::run("UPDATE emails SET attempts = attempts + 1, next_attempt_at = ? WHERE id = ? AND status = 'pending' AND attempts = ?", [
                date('Y-m-d H:i:s', time() + 600), $email['id'], $email['attempts'],
            ]);
            if ($claimed !== 1) {
                continue;
            }
            $attempt = (int) $email['attempts'] + 1;
            try {
                $message = EmailTemplates::build($email);
                if ($message === null) {
                    Db::update('emails', ['status' => 'sent', 'sent_at' => App::now(), 'provider_message_id' => 'skipped: no longer needed'], 'id = ?', [$email['id']]);
                    $counts['skipped']++;
                    continue;
                }
                $to = $message['to'];
                if (Settings::bool('email_test_mode') && $email['kind'] !== 'alert') {
                    $testAddress = Settings::get('email_test_address');
                    if (!Validate::email($testAddress)) {
                        throw new \RuntimeException('Test mode is on but no test address is set (Settings).');
                    }
                    $message['subject'] = '[TEST → ' . $to . '] ' . $message['subject'];
                    $to = $testAddress;
                }
                $id = $mailer->send($to, $message['subject'], $message['html'], $message['text'], $message['attachments'] ?? []);
                Db::update('emails', ['status' => 'sent', 'sent_at' => App::now(), 'provider_message_id' => mb_substr($id, 0, 190), 'last_error' => null], 'id = ?', [$email['id']]);
                $counts['sent']++;
            } catch (\Throwable $error) {
                $retryIn = self::RETRY_MINUTES[$attempt - 1] ?? null;
                if ($retryIn === null) {
                    Db::update('emails', ['status' => 'failed', 'last_error' => mb_substr($error->getMessage(), 0, 500)], 'id = ?', [$email['id']]);
                    $counts['failed']++;
                } else {
                    Db::update('emails', ['next_attempt_at' => date('Y-m-d H:i:s', time() + $retryIn * 60), 'last_error' => mb_substr($error->getMessage(), 0, 500)], 'id = ?', [$email['id']]);
                    $counts['retry']++;
                }
                App::log('warning', 'Email not sent', ['email' => $email['id'], 'kind' => $email['kind'], 'attempt' => $attempt, 'error' => $error->getMessage()]);
            }
        }
        return $counts;
    }

    /** Puts a failed or sent email back in the queue (the admin's Resend button). */
    public static function resend(int $emailId): void
    {
        Db::update('emails', ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => App::now(), 'last_error' => null], 'id = ?', [$emailId]);
    }
}
