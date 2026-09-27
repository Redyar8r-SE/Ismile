<?php
// The timed jobs. Set up in cPanel -> Cron Jobs (replace the paths):
//
//   * * * * *     php /home/USER/ismile-backend/cron/run.php minute
//   */5 * * * *   php /home/USER/ismile-backend/cron/run.php five
//   30 2 * * *    php /home/USER/ismile-backend/cron/run.php nightly
//
// minute  : sends the emails that are due (tickets go out within a minute)
// five    : asks the payment company about payments still waiting (lost
//           webhooks), deletes forms that were not paid in time, raises alerts
// nightly : backs up the database, deletes ID photos after the summit,
//           tidies old counters
//
// Each job takes a lock, so a slow run is never started twice at once.

declare(strict_types=1);

use Ismile\App;
use Ismile\Audit;
use Ismile\Backup;
use Ismile\Checkouts;
use Ismile\Db;
use Ismile\IdPhotos;
use Ismile\Mail\MailerFactory;
use Ismile\Outbox;
use Ismile\Payments\Payments;
use Ismile\RateLimit;
use Ismile\Registrations;
use Ismile\Settings;
use Ismile\SiteData;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$job = $argv[1] ?? '';
if (!in_array($job, ['minute', 'five', 'nightly'], true)) {
    fwrite(STDERR, "Usage: php cron/run.php minute|five|nightly\n");
    exit(1);
}

$lock = fopen(App::storage("tmp/cron-$job.lock"), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // the previous run is still busy
}

$report = [];
try {
    if ($job === 'minute') {
        $report = Outbox::process(MailerFactory::make());
    }

    if ($job === 'five') {
        $report['payments'] = Payments::checkWaiting();

        // Forms not paid in time are closed, and deleted two days later with
        // their student ID photo: nobody is kept who did not pay.
        $report['unpaid_forms'] = Checkouts::cleanUp();

        // Alerts: many failed emails, a missed backup, the disk nearly full.
        $failed = (int) Db::value("SELECT COUNT(*) FROM emails WHERE status = 'failed' AND next_attempt_at > ?", [date('Y-m-d H:i:s', time() - 3600)]);
        if ($failed > 5 && RateLimit::hit('alert:emails', 1, 3600)) {
            Outbox::alert("$failed ticket or registration emails failed in the last hour. Check the email service (Brevo) and the Registrations page.");
        }
        $lastBackup = Backup::latestTime();
        if (($lastBackup === null || $lastBackup < time() - 36 * 3600) && Db::value('SELECT COUNT(*) FROM registrations') > 0 && RateLimit::hit('alert:backup', 1, 6 * 3600)) {
            Outbox::alert('No database backup in the last 36 hours. Check the nightly cron job.');
        }
        $free = @disk_free_space(App::storage());
        if ($free !== false && $free < 500 * 1024 * 1024 && RateLimit::hit('alert:disk', 1, 6 * 3600)) {
            Outbox::alert('Less than 500 MB of disk space is left on the server.');
        }

        // Prices live in data/tickets.json, which the content editor can change.
        // Every change is written to the audit log and announced to the Owner,
        // so a wrong or unauthorised price is noticed within minutes.
        $prices = json_encode(SiteData::prices());
        $seen = Settings::get('prices_seen');
        if ($prices !== $seen) {
            if ($seen !== '') {
                Audit::log(null, 'prices.changed', null, null, ['from' => json_decode($seen, true), 'to' => json_decode($prices, true)]);
                Outbox::alert("Ticket prices were changed. Before: $seen. Now: $prices. If this was not planned, check Site content -> Ticket prices at once.");
            }
            Settings::set('prices_seen', $prices);
        }
    }

    if ($job === 'nightly') {
        $report['backup'] = Backup::run();

        // Student ID photos are deleted a fixed time after the summit.
        $deleteAfter = strtotime(Settings::get('summit_end') . ' 23:59:59') + Settings::int('photo_keep_days') * 86400;
        if ($deleteAfter > 0 && time() > $deleteAfter) {
            $deleted = 0;
            foreach (Db::all('SELECT id, id_photo_id FROM registrations WHERE id_photo_id IS NOT NULL') as $row) {
                Db::run('UPDATE registrations SET id_photo_id = NULL, id_photo_deleted_at = ? WHERE id = ?', [App::now(), $row['id']]);
                IdPhotos::delete((int) $row['id_photo_id']);
                $deleted++;
            }
            foreach (Db::all('SELECT id, id_photo_id FROM checkouts WHERE id_photo_id IS NOT NULL') as $row) {
                Db::run('UPDATE checkouts SET id_photo_id = NULL WHERE id = ?', [$row['id']]);
                IdPhotos::delete((int) $row['id_photo_id']);
                $deleted++;
            }
            if ($deleted > 0) {
                Outbox::alert("$deleted student ID photos were deleted, as planned after the summit.");
            }
            $report['photos_deleted'] = $deleted;
        }
        Db::run('DELETE FROM rate_limits WHERE window_start < ?', [time() - 86400]);
    }
} catch (\Throwable $error) {
    App::log('error', "Cron job '$job' failed", ['error' => $error->getMessage(), 'at' => $error->getFile() . ':' . $error->getLine()]);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

if (array_filter($report, static fn ($value) => $value !== 0 && $value !== [])) {
    App::log('info', "cron $job", $report);
}
echo json_encode($report) . "\n";
