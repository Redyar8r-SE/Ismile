<?php
declare(strict_types=1);
use Ismile\Admin\Page;
use Ismile\WorkshopCashSale;
if(!isset($user,$person,$selected,$canBook)||!$canBook){http_response_code(404);exit;}
$cashQuote=null;
try{$cashQuote=WorkshopCashSale::quote($selected,$selected);}catch(\Ismile\UserError){}
?>
<?php if(!$cashQuote||empty($selected['providerEventId'])): ?><p class="ws-book-notice">The current PSOOLA workshop price must be verified before registration.</p>
<?php elseif($cashQuote['iqd']===0): ?>
<form method="post" action="<?= $e($bookingUrl) ?>" class="stack ws-paid-form" data-ws-issue-form data-workshop-free>
<?= Page::csrfField() ?><input type="hidden" name="do" value="workshop_cash_sale"><input type="hidden" name="rid" value="<?= (int)$person['id'] ?>"><input type="hidden" name="workshop" value="<?= $e($open) ?>"><input type="hidden" name="free_registration" value="1">
<h3>Free workshop registration</h3>
<div class="ws-book-review"><p><span>Workshop registration</span><strong>0 IQD</strong></p><small>No cash payment is required. Register this attendee and PSOOLA sends their workshop ticket.</small></div>
<footer class="ws-book-footer"><button type="button" class="btn ghost" data-ws-close>Cancel</button><button class="btn ws-primary"<?= $availabilityLabel!=='Available'?' disabled':'' ?>>Register &amp; issue free ticket</button></footer>
</form>
<?php else: ?>
<form method="post" action="<?= $e($bookingUrl) ?>" class="stack ws-paid-form" data-ws-issue-form data-workshop-cash data-iqd="<?= (int)$cashQuote['iqd'] ?>" data-usd-minor="<?= (int)$cashQuote['usdMinor'] ?>">
<?= Page::csrfField() ?><input type="hidden" name="do" value="workshop_cash_sale"><input type="hidden" name="rid" value="<?= (int)$person['id'] ?>"><input type="hidden" name="workshop" value="<?= $e($open) ?>">
<h3>Paid workshop registration</h3>
<div class="ws-book-review"><p><span>PSOOLA ticket price</span><strong><?= $e(number_format($cashQuote['iqd'])) ?> IQD</strong></p><?php if($cashQuote['usdMinor']): ?><p><span>Saved dollar price</span><strong>$<?= number_format($cashQuote['usdMinor']/100,2) ?></strong></p><small>USD cash uses this saved dollar price and the current PSOOLA dinar price.</small><?php else: ?><small>This workshop has no saved USD price. Receive payment in IQD.</small><?php endif; ?></div>
<label>Cash currency<select name="cash_currency"><option value="IQD">IQD — Iraqi dinar</option><?php if($cashQuote['usdMinor']): ?><option value="USD">USD — US dollar</option><?php endif; ?></select></label>
<label>Cash amount received<input name="cash_amount" type="number" inputmode="decimal" min="1" max="1000000000" step="1" required value="<?= (int)$cashQuote['iqd'] ?>"></label>
<p class="ws-book-notice" role="status">IQD amount for PSOOLA: <strong data-cash-converted><?= number_format($cashQuote['iqd']) ?> IQD</strong><br><span data-cash-match>Must match the current ticket price.</span></p>
<label class="inline"><input type="checkbox" name="cash_received" value="1" required> I have received this cash payment.</label>
<p class="ws-hint">Saves the received currency and amount, records the paid amount in IQD, and sends a paid workshop order to PSOOLA. PSOOLA sends the attendee's ticket.</p>
<footer class="ws-book-footer"><button type="button" class="btn ghost" data-ws-close>Cancel</button><button class="btn ws-primary"<?= $availabilityLabel!=='Available'?' disabled':'' ?>>Register &amp; issue paid ticket</button></footer>
</form>
<?php endif; ?>
