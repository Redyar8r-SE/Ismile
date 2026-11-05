<?php
declare(strict_types=1);
// Included only by the authenticated, permission-checked registration page.
if (!isset($user) || !\Ismile\Auth::canIssueComplimentary($user)) { http_response_code(403); exit; }
$e = [\Ismile\Admin\Page::class, 'e'];
$value = static fn(string $key): string => \Ismile\Admin\Page::e(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
$attemptKey = is_string($_POST['attempt'] ?? null) && preg_match('/^[a-f0-9]{32}$/D',$_POST['attempt']) ? $_POST['attempt'] : bin2hex(random_bytes(16));
$readOnly = $retrying ? ' readonly' : '';
$companies = \Ismile\Db::all("SELECT DISTINCT company FROM sponsor_requests WHERE status NOT IN ('declined','cancelled','waiting_list') ORDER BY company");
\Ismile\Admin\Page::top('Free ticket', 'registrations', '<a class="btn ghost" href="registrations.php">&larr; All registrations</a>');
?>
<form method="post" class="card stack caller-form" id="free-ticket-form">
  <?= \Ismile\Admin\Page::csrfField() ?><input type="hidden" name="attempt" value="<?= $e($attemptKey) ?>">
  <p class="muted">Register a sponsored guest with a complimentary ticket. Fields marked * are required.</p>
  <?php if ($retrying): ?><p class="flash warn">Retrying your saved ticket request. The original guest details and permission record are preserved to prevent duplicate tickets.</p><?php endif; ?>
  <fieldset class="form-section">
    <legend>Guest details</legend>
    <div class="row3">
      <label>First name *<input name="first_name" autocomplete="given-name" minlength="2" maxlength="60" value="<?= $value('first_name') ?>"<?= $readOnly ?> required></label>
      <label>Second name *<input name="father_name" autocomplete="additional-name" minlength="2" maxlength="60" value="<?= $value('father_name') ?>"<?= $readOnly ?> required></label>
      <label>Third name *<input name="grandfather_name" autocomplete="family-name" minlength="2" maxlength="60" value="<?= $value('grandfather_name') ?>"<?= $readOnly ?> required></label>
    </div>
    <div class="row3">
      <label>Specialty *<?php if ($retrying): ?><input type="hidden" name="specialty" value="<?= $value('specialty') ?>"><?php endif; ?><select<?= $retrying ? ' disabled' : ' name="specialty"' ?> required>
        <option value="">Choose specialty</option>
        <?php foreach (\Ismile\Registrations::SPECIALTY_NAMES as $key=>$label): ?><option value="<?= $e($key) ?>"<?= ($_POST['specialty'] ?? '') === $key ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
      </select></label>
      <label>Email address *<input type="email" name="email" autocomplete="email" dir="ltr" maxlength="190" value="<?= $value('email') ?>"<?= $readOnly ?> required></label>
      <label>WhatsApp number *<input type="tel" name="phone" autocomplete="tel" dir="ltr" maxlength="30" placeholder="+964 750 123 4567" value="<?= $value('phone') ?>"<?= $readOnly ?> required></label>
    </div>
  </fieldset>
  <fieldset class="form-section">
    <legend>Sponsorship</legend>
    <label>Sponsoring company *<input name="sponsoring_company" list="sponsoring-companies" maxlength="160" placeholder="Enter or choose a company" value="<?= $value('sponsoring_company') ?>"<?= $readOnly ?> required><small class="muted">The company providing this guest's complimentary ticket.</small></label>
    <datalist id="sponsoring-companies"><?php foreach ($companies as $company): ?><option value="<?= $e($company['company']) ?>"></option><?php endforeach; ?></datalist>
  </fieldset>
  <?php if ($retrying && ($_POST['whatsapp_consent'] ?? '') === '1'): ?><input type="hidden" name="whatsapp_consent" value="1"><?php endif; ?>
  <label class="free-ticket-consent"><input type="checkbox"<?= $retrying ? ' disabled' : ' name="whatsapp_consent"' ?> value="1"<?= ($_POST['whatsapp_consent'] ?? '') === '1' ? ' checked' : '' ?>><span>The guest agrees to receive this ticket on WhatsApp.<small class="muted">Record the guest's agreement now. Without it, PSOOLA delivers by email only.</small></span></label>
  <p class="muted small">The guest pays nothing. PSOOLA records the order at its listed ticket price; the provider amount is saved separately from guest payments.</p>
  <div class="form-actions"><a class="btn ghost" href="registrations.php">Cancel</a><button class="btn violet" type="submit"<?= \Ismile\Admin\Page::confirm('violet','gift',$retrying?'Retry this ticket request?':'Issue this free ticket?','PSOOLA will issue the ticket for this sponsored guest. The guest pays nothing.',[],['form'=>'sponsored','note'=>'PSOOLA records its listed direct-order price. The sponsoring company and provider amount are kept on record.','yes'=>$retrying?'Retry ticket request':'Issue free ticket','no'=>'Go back']) ?>><?= $retrying?'Retry ticket request':'Issue free ticket' ?></button></div>
</form>
<?php $requests=\Ismile\Db::all('SELECT * FROM sponsored_ticket_orders WHERE created_by=? ORDER BY id DESC LIMIT 10',[$user['id']]); if($requests): ?>
<section class="card caller-form"><h2>Your recent free ticket requests</h2><div class="table-wrap"><table><thead><tr><th>Guest</th><th>Sponsoring company</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach($requests as $request): $guest=json_decode($request['guest_json'],true,512,JSON_THROW_ON_ERROR); ?><tr><td><?= $e(\Ismile\Registrations::fullName($guest)) ?></td><td><?= $e($guest['sponsoring_company']) ?></td><td><?= $e(match($request['status']){'issued'=>'Issued','rejected'=>'Not issued',default=>'Awaiting confirmation'}) ?></td><td><?php if($request['status']==='issued' && $request['registration_id']): ?><a class="btn small ghost" href="registration.php?id=<?= (int)$request['registration_id'] ?>">View guest</a><?php elseif($request['status']!=='issued'): ?><a class="btn small ghost" href="registration.php?new=free&amp;retry=<?= $e($request['attempt_key']) ?>"><?= $request['status']==='rejected'?'Correct details':'Retry request' ?></a><?php else: ?>Archived<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php \Ismile\Admin\Page::bottom();
