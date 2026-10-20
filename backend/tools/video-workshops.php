<?php
// The Owner's wish (21 Oct 2026), for the company's video: the 4 "Coming
// soon" workshops (implant, dsd, endo, scan) are deleted, and two example
// workshops with trainer photos and details are added. Run once by the
// deploy (marker .video-workshops-1); safe to run again.
//
//   php backend/tools/video-workshops.php
//
// The two workshops are ordinary workshops afterwards: the Owner edits,
// hides or deletes them on the database's Workshops page.

declare(strict_types=1);

use Ismile\App;
use Ismile\Audit;
use Ismile\Db;
use Ismile\Workshops;

if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
require __DIR__ . '/../bootstrap.php';

$now = App::now();
$deleted = [];
foreach (['implant', 'dsd', 'endo', 'scan'] as $id) {
    // Only a workshop nobody was ever booked on is deleted (as on the Workshops page).
    if (Db::value('SELECT id FROM workshops WHERE id = ?', [$id]) && !Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ?', [$id])) {
        Db::run('DELETE FROM workshops WHERE id = ?', [$id]);
        $deleted[] = $id;
    }
}

$examples = [
    [
        'id' => 'soft-tissue-implants', 'icon' => 'implant', 'photo' => 'assets/workshops/sarah-hassan.jpg',
        'title_en' => 'Soft Tissue Management Around Dental Implants: Predictable Clinical Techniques',
        'speaker_en' => 'Dr. Sarah Hassan',
        'credentials_en' => 'BDS, MSc, Specialist in Periodontology and Implant Dentistry',
        'format_en' => 'Practical Clinical Workshop', 'duration_en' => '3 Hours', 'level_en' => 'Intermediate to Advanced',
        'overview_en' => 'Explore contemporary approaches to peri-implant soft tissue management, including tissue assessment, treatment planning, and techniques for achieving predictable aesthetic and functional outcomes.',
    ],
    [
        'id' => 'composite-restorations', 'icon' => 'tooth', 'photo' => 'assets/workshops/alan-karim.jpg',
        'title_en' => 'Advanced Direct Composite Restorations: From Fundamentals to Aesthetic Excellence',
        'speaker_en' => 'Dr. Alan Karim',
        'credentials_en' => 'BDS, MSc, PhD in Restorative Dentistry',
        'format_en' => 'Hands-on Clinical Workshop', 'duration_en' => '3 Hours', 'level_en' => 'Intermediate to Advanced',
        'overview_en' => 'Discover modern techniques for anterior and posterior composite restorations, with a focus on aesthetic outcomes, material selection, layering techniques, and clinical precision.',
    ],
];
$added = [];
foreach ($examples as $n => $workshop) {
    if (Db::value('SELECT id FROM workshops WHERE id = ?', [$workshop['id']])) {
        continue;
    }
    Db::insert('workshops', $workshop + [
        'price' => 100, 'currency' => 'USD', 'total_seats' => 30, 'status' => 'active', 'sort_order' => $n + 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $added[] = $workshop['id'];
}

Workshops::syncWebsite();
if ($deleted || $added) {
    Audit::log(null, 'workshop.video_examples', 'workshop', null, ['deleted' => $deleted, 'added' => $added]);
}
echo 'Workshops for the video: deleted ' . ($deleted ? implode(', ', $deleted) : 'none') . '; added ' . ($added ? implode(', ', $added) : 'none') . ".\n";
