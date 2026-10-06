<?php
// A home for website content, using the same database frame and role checks.
declare(strict_types=1);
require __DIR__ . '/_boot.php';

use Ismile\Admin\Page;
use Ismile\SiteData;

$user = Page::guard('content');
$e = [Page::class, 'e'];
$editor = Page::contentEditorUrl();
$edit = static fn (string $group): string => $editor . '#' . rawurlencode($group);
$speakers = SiteData::read('speakers');
$speakerCount = count(array_filter($speakers, static function ($speaker): bool {
    if (!is_array($speaker)) return false;
    $name = $speaker['name'] ?? '';
    return is_array($name) ? count(array_filter($name)) > 0 : trim((string)$name) !== '';
}));
$sponsors = SiteData::read('sponsors');
$partners = SiteData::read('partners');
$brandCount = count($sponsors['sponsors'] ?? []) + count($partners['partners'] ?? $partners);
Page::top('Site content', 'content', '<a class="btn ghost" href="../index.html" target="_blank" rel="noopener">' . Page::navIcon('external') . '<span>Preview website</span></a><a class="btn" href="' . $e($editor) . '">' . Page::navIcon('content') . '<span>Open editor</span></a>');
?>
<section class="content-welcome">
  <div><span class="section-eyebrow">The iSmile experience</span><h2>Make every first impression count.</h2><p>A beautiful event starts before guests arrive. Keep your story, speakers and event details clear and welcoming.</p><a class="btn" href="<?= $e($edit('hero')) ?>"><span>Edit the homepage</span><?= Page::navIcon('arrow') ?></a></div>
  <div class="content-language-card"><span class="panel-icon"><?= Page::navIcon('mail') ?></span><b>One event. Three languages.</b><p>Keep your English, Arabic and Kurdish wording together.</p><div><span>EN</span><span lang="ar">عربي</span><span lang="ckb">کوردی</span></div></div>
</section>
<?= Page::stats([
    ['Website pages', '4', 'Home, workshops, registration & sponsors', 'content', 'blue'],
    ['Languages', '3', 'English, Arabic & Kurdish', 'mail', 'teal'],
    ['Named speakers', number_format($speakerCount), 'Speakers with a name in your content', 'users', 'violet'],
    ['Brand profiles', number_format($brandCount), 'Sponsor & partner profiles', 'sponsors', 'gold'],
]) ?>
<div class="panel-top"><?= Page::panelHeading('What would you like to update?', 'Choose a section and go straight to its editing tools.', 'content') ?></div>
<div class="content-section-grid">
<?php foreach ([
    ['hero','index','Homepage','Welcome your guests','Update the first screen, event introduction and ticket card.','Homepage'],
    ['speakers','users','Speakers','Put your experts in the spotlight','Manage speaker names, titles and portraits in all three languages.','People'],
    ['program','calendar','Event program','A clear plan for every day','Edit the days, sessions, timings and session types.','Schedule'],
    ['registration','ticket','Registration & prices','Make joining the event easy','Manage registration wording, ticket prices and lunch prices.','Registration'],
    ['sponsors','sponsors','Sponsors & partners','Celebrate your supporters','Keep company logos and partner profiles looking their best.','Partnerships'],
    ['venue','calendar','Venue & contact','Help guests find their way','Update the venue information, address and map location.','Event details'],
    ['workshops','workshops','Workshop page','Help guests choose their workshop','Edit workshop page wording and booking instructions.','Workshops'],
    ['becomeSponsor','payments','Sponsor enquiry page','Create a welcoming introduction','Update the sponsor and booth enquiry wording and contact details.','Enquiries'],
    ['footer','lists','Footer & contact','Keep useful information close','Edit footer wording, contact lines and links.','Shared content'],
] as [$group,$icon,$title,$subtitle,$description,$tag]): ?>
  <a class="content-section-card" href="<?= $e($edit($group)) ?>"><div class="content-card-top"><span class="content-card-icon"><?= Page::navIcon($icon) ?></span><span><?= $e($tag) ?></span></div><h3><?= $e($title) ?></h3><b><?= $e($subtitle) ?></b><p><?= $e($description) ?></p><span class="content-card-link">Edit section <?= Page::navIcon('arrow') ?></span></a>
<?php endforeach; ?>
</div>
<section class="card content-workflow"><?= Page::panelHeading('From an idea to a clear website', 'A simple rhythm for keeping your event content up to date.', 'check') ?><ol><li><b>01</b><div><strong>Choose a section</strong><p>Start with the content your guests need.</p></div></li><li><b>02</b><div><strong>Check every language</strong><p>Review wording, names and key details.</p></div></li><li><b>03</b><div><strong>Review and save</strong><p>Use the editor’s save or download controls, then preview the website.</p></div></li></ol></section>
<?php Page::bottom();
