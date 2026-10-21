<?php
// The Owner's wish (21 Oct 2026), for the company's video: the 4 "Coming
// soon" workshops (implant, dsd, endo, scan) are deleted, and two example
// workshops with trainer photos and details are added:
//   - Dr. Sarah Hassan's is organized by iSmile: people register online with
//     the email they registered for the summit with;
//   - Dr. Alan Karim's is organized by a company: people call the company.
// Run once by the deploy (marker .video-workshops-2); safe to run again.
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

// The example company and its phone (the Owner changes them on the Workshops page).
const COMPANY = ['organizer' => 'company', 'company_en' => 'Partner Dental Company', 'company_phone' => '+964 750 123 4567'];

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
    ] + COMPANY,
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

// The first version of this script added Dr. Alan Karim's workshop as an
// iSmile one: it becomes the company's, unless the Owner already set a company.
$madeCompany = Db::run(
    "UPDATE workshops SET organizer = ?, company_en = ?, company_phone = ?, updated_at = ?
     WHERE id = 'composite-restorations' AND organizer = 'ismile' AND company_phone IS NULL",
    [COMPANY['organizer'], COMPANY['company_en'], COMPANY['company_phone'], $now]
) > 0;

Workshops::syncWebsite();
if ($deleted || $added || $madeCompany) {
    Audit::log(null, 'workshop.video_examples', 'workshop', null, ['deleted' => $deleted, 'added' => $added, 'company' => $madeCompany ? 'composite-restorations' : null]);
}
echo 'Workshops for the video: deleted ' . ($deleted ? implode(', ', $deleted) : 'none') . '; added ' . ($added ? implode(', ', $added) : 'none')
    . ($madeCompany ? '; composite-restorations is now organized by ' . COMPANY['company_en'] : '') . ".\n";
