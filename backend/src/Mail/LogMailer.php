<?php
// For testing: writes each email as an HTML file (and its attachments) into
// storage/outbox instead of sending it.

declare(strict_types=1);

namespace Ismile\Mail;

use Ismile\App;

final class LogMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, string $text, array $attachments = []): string
    {
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $safeTo = preg_replace('/[^a-z0-9@._-]/i', '_', $to);
        $head = '<!-- To: ' . htmlspecialchars($to) . "\n     Subject: " . htmlspecialchars($subject) . " -->\n";
        file_put_contents(App::storage("outbox/$id-$safeTo.html"), $head . $html);
        foreach ($attachments as $file) {
            file_put_contents(App::storage("outbox/$id-" . preg_replace('/[^a-z0-9._-]/i', '_', $file['name'])), $file['content']);
        }
        return 'log:' . $id;
    }
}
