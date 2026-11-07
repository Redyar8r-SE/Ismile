<?php
declare(strict_types=1);
if(!isset($user)||!\Ismile\Auth::canIssueComplimentary($user)){http_response_code(403);exit;}
?>
<p><a class="btn ghost" href="registration.php?new=free">Single iSmile guest ticket</a></p>
<form method="post" action="<?= $e($rootUrl) ?>" class="guest-issuance-layout" data-guest-ticket-form data-guest-options>
 <?= \Ismile\Admin\Page::csrfField() ?><input type="hidden" name="do" value="issue"><input type="hidden" name="attempt" value="<?= $e($attempt) ?>">
 <input type="hidden" name="add_on_ids_json" value="<?= $value('add_on_ids_json')?:'[]' ?>">
 <section class="guest-details-panel">
  <h2><?= $e($t('title')) ?></h2>
  <p><?= $e($t('intro')) ?></p>
  <div class="guest-field-pair">
   <?php foreach(['event_id'=>'event','show_id'=>'show','ticket_type_id'=>'type'] as $key=>$label): ?>
   <label><?= $e($t($label)) ?><select name="<?= $e($key) ?>" data-selected="<?= $value($key) ?>" required disabled><option value=""><?= $e($t('choose')) ?></option></select></label>
   <?php endforeach; ?>
   <label><?= $e($t('quantity')) ?><input type="number" name="quantity" min="1" max="100" step="1" value="<?= $value('quantity')?:'1' ?>" required></label>
   <label><?= $e($t('locale')) ?><select name="locale"><?php foreach(['en'=>'English','ar'=>'العربية','ckb'=>'کوردی','kmr'=>'Kurdî'] as $key=>$label): ?><option value="<?= $key ?>"<?= ($values['locale']??$ui)===$key?' selected':'' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
  </div>
  <fieldset><legend><?= $e($t('addons')) ?></legend><div data-guest-addons data-selected="<?= $e(json_encode($values['add_on_ids']??json_decode($values['add_on_ids_json']??'[]',true)??[])) ?>"></div></fieldset>
  <button type="button" class="btn ghost" data-guest-refresh><?= $e($t('refresh')) ?></button>
  <p data-guest-catalogue-error role="status" aria-live="polite"></p>
  <h2><?= $e($t('guest')) ?></h2>
  <label><?= $e($t('name')) ?><input name="buyer_name" maxlength="120" autocomplete="name" value="<?= $value('buyer_name') ?>"></label>
  <div class="guest-field-pair">
   <label><?= $e($t('email')) ?><input type="email" name="email" maxlength="190" autocomplete="email" value="<?= $value('email') ?>" dir="ltr" required></label>
   <label><?= $e($t('phone')) ?><input type="tel" name="phone" maxlength="30" autocomplete="tel" placeholder="+9647501234567" value="<?= $value('phone') ?>" dir="ltr" required></label>
  </div>
  <label><?= $e($t('reason')) ?><textarea name="complimentary_reason" maxlength="500" rows="3" required><?= $value('complimentary_reason') ?></textarea></label>
  <label><input type="checkbox" name="whatsapp_consent" value="1"> <?= $e($t('consent')) ?></label><p class="muted small"><?= $e($t('consent_note')) ?></p>
 </section>
 <aside class="guest-ticket-sidebar">
  <section class="guest-pass"><header class="guest-pass-header"><h2><?= $e($t('review')) ?></h2><span class="guest-pass-badge"><?= $e($t('complimentary')) ?></span></header>
   <div class="guest-pass-person"><strong data-guest-preview-name></strong><p data-guest-review aria-live="polite"></p></div>
   <div class="guest-pass-total"><strong><?= $e($t('total')) ?></strong></div>
  </section>
  <button type="submit" class="btn guest-issue-button" data-guest-issue disabled><?= $e($t('issue')) ?></button>
  <p data-guest-form-error role="status" aria-live="polite"></p>
 </aside>
</form>
