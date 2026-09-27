<?php
// Picks the email service named in config.php (mail.driver).

declare(strict_types=1);

namespace Ismile\Mail;

use Ismile\App;

final class MailerFactory
{
    public static function make(): Mailer
    {
        return match ((string) App::config('mail.driver', 'log')) {
            'brevo' => new BrevoMailer(),
            default => new LogMailer(),
        };
    }
}
