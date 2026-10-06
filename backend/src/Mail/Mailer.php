<?php
// One swappable piece for sending email: changing service later is one class.

declare(strict_types=1);

namespace Ismile\Mail;

interface Mailer
{
    /**
     * @param array<int, array{name: string, content: string}> $attachments raw file contents
     * @return string the service's message id
     */
    public function send(string $to, string $subject, string $html, string $text, array $attachments = []): string;
}
