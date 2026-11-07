<?php
declare(strict_types=1);
use Ismile\Admin\Page;
use Ismile\Db;
use Ismile\Workshops;
if(!isset($user,$e,$url)||$user['role']!=='owner'){http_response_code(404);exit;}
$settingsWorkshops=Workshops::all(true);
$settingsId=Page::query('settings')?:$open;
$settingsIds=array_column($settingsWorkshops,'id');
if($settingsId!=='__new'&&!in_array($settingsId,$settingsIds,true))$settingsId=$settingsIds[0]??'__new';
$settingsTab=in_array(Page::query('section'),['details','registration','visibility'],true)?Page::query('section'):'details';
$bookingHistory=array_column(Db::all('SELECT workshop_id,COUNT(*) AS n FROM workshop_bookings GROUP BY workshop_id'), 'n','workshop_id');
$settingsText=static fn(mixed $value):string=>is_array($value)?(string)(($value['en']??'')?:(array_values(array_filter($value))[0]??'')):(string)$value;
$input=static function(string $name,string $label,string $value='',string $attributes='',string $type='text')use($e):string{
    return '<label class="wss-field"><span>'.$e($label).'</span><input type="'.$e($type).'" name="'.$e($name).'" value="'.$e($value).'" '.$attributes.'></label>';
};
$optionList=static function(array $values,string $selected)use($e):string{$out='';foreach($values as $key=>$label)$out.='<option value="'.$e((string)$key).'"'.((string)$key===$selected?' selected':'').'>'.$e($label).'</option>';return $out;};
?>
<div class="wss" id="manage" data-workshop-settings data-selected="<?= $e($settingsId) ?>" data-section="<?= $e($settingsTab) ?>">
  <?php foreach($settingsMessages??[] as [$kind,$message]): ?><div class="flash <?= $kind==='ok'?'ok':'err' ?>" role="<?= $kind==='ok'?'status':'alert' ?>"><?= $e($message) ?></div><?php endforeach; ?>
  <div class="wss-intro"><p>Choose a workshop. Change only what you need.</p><a class="btn ghost" href="<?= $e($url(['settings'=>'__new'],'#manage')) ?>" data-settings-select="__new"><?= Page::navIcon('plus') ?>Add workshop</a></div>
  <label class="wss-mobile-picker">Workshop<select data-settings-picker><?php foreach($settingsWorkshops as $w): ?><option value="<?= $e($w['id']) ?>"<?= $w['id']===$settingsId?' selected':'' ?>><?= $e(Workshops::name($w)) ?><?= $w['status']==='hidden'?' (Hidden)':'' ?></option><?php endforeach; ?><option value="__new"<?= $settingsId==='__new'?' selected':'' ?>>Add a workshop</option></select></label>
  <div class="wss-layout">
    <aside class="wss-directory" aria-label="Choose a workshop to edit"><span class="ws-eyebrow">Your workshops</span>
      <?php foreach($settingsWorkshops as $w): ?><a class="wss-choice<?= $settingsId===$w['id']?' is-active':'' ?>" href="<?= $e($url(['settings'=>$w['id']],'#manage')) ?>" data-settings-select="<?= $e($w['id']) ?>"<?= $settingsId===$w['id']?' aria-current="true"':'' ?>>
        <?php $photo=(string)($w['photo']??'');if(preg_match('~^assets/(?:workshops|uploads/workshops)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g)$~i',$photo)&&!str_contains($photo,'..')): ?><span class="wss-choice-photo"><img src="../<?= $e($photo) ?>" alt="" loading="lazy" width="42" height="52"></span><?php else: ?><span class="wss-choice-photo"><?= Page::navIcon('user') ?></span><?php endif; ?>
        <span><strong><?= $e(Workshops::name($w)) ?></strong><small><?= $e($settingsText($w['speaker']??'Trainer to be announced')) ?></small><small><?= $w['status']==='active'?'Shown':'Hidden' ?> · <?= $w['organizer']==='ismile'?'iSmile':$e($settingsText($w['company']??'Company')) ?></small></span>
      </a><?php endforeach; ?><p class="wss-owner-note"><?= Page::navIcon('security') ?><span>Workshop settings are available to the Owner.</span></p>
    </aside>
    <div class="wss-editors">
    <?php foreach([...$settingsWorkshops,null] as $w):
        $new=$w===null;$wid=$new?'__new':$w['id'];$prefix=$new?'wss-create':'wss-edit-'.$w['id'];
        $v=static fn(string $field,string $lang='en'):string=>(string)($w[$field][$lang]??'');
        $hasHistory=!$new&&(int)($bookingHistory[$wid]??0)>0;
        $editing=$settingsId===$wid;
        $photo=(string)($w['photo']??'');$hasPhoto=preg_match('~^assets/(?:workshops|uploads/workshops)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g)$~i',$photo)&&!str_contains($photo,'..');
    ?>
    <section class="wss-editor" data-settings-editor="<?= $e($wid) ?>"<?= !$editing?' hidden':'' ?> aria-label="<?= $e($new?'New workshop':Workshops::name($w)) ?>">
      <header class="wss-editor-heading"><div><h3><?= $e($new?'Add a workshop':Workshops::name($w)) ?></h3><p><?= $new?'Add the details, then save your workshop.':$e($settingsText($w['speaker']??'')) ?></p></div><?php if(!$new): ?><span class="ws-badge <?= $w['status']==='active'?'green':'neutral' ?>"><?= $w['status']==='active'?'Shown on website':'Hidden from website' ?></span><?php endif; ?></header>
      <form method="post" class="wss-form" enctype="multipart/form-data" action="<?= $e($url(['settings'=>$wid],'#manage')) ?>" data-settings-form>
        <?= Page::csrfField() ?><input type="hidden" name="do" value="<?= $new?'ws_create':'ws_update' ?>"><input type="hidden" name="ws" value="<?= $e($new?'':$wid) ?>"><input type="hidden" name="settings_section" value="<?= $e($settingsTab) ?>"><input type="hidden" name="photo_style" value="<?= $e($w['photoStyle']??'photo') ?>">
        <div class="wss-tabs" role="tablist" aria-label="Workshop settings sections"><?php foreach(['details'=>'Details','registration'=>'Registration','visibility'=>'Visibility'] as $key=>$label): ?><button type="button" role="tab" id="<?= $e($prefix.'-'.$key.'-tab') ?>" aria-controls="<?= $e($prefix.'-'.$key) ?>" aria-selected="<?= $settingsTab===$key?'true':'false' ?>" tabindex="<?= $settingsTab===$key?'0':'-1' ?>" data-settings-tab="<?= $key ?>"><?= $label ?></button><?php endforeach; ?></div>
        <div class="wss-panel" role="tabpanel" id="<?= $e($prefix.'-details') ?>" aria-labelledby="<?= $e($prefix.'-details-tab') ?>" data-settings-panel="details">
          <?= $input('title_en','Workshop name',$v('title'),'maxlength="160"') ?>
          <div class="wss-trainer-edit">
            <div class="wss-photo-editor"><div class="wss-photo-preview"><img src="<?= $hasPhoto?'../'.$e($photo):'' ?>" alt="Trainer photo preview" data-settings-photo-preview<?= !$hasPhoto?' hidden':'' ?>><span data-settings-photo-empty<?= $hasPhoto?' hidden':'' ?>><?= Page::navIcon('user') ?></span></div><div class="wss-photo-actions"><label class="wss-photo-change">Change photo<input class="sr-only" type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-settings-photo></label><button type="button" class="wss-photo-remove" data-settings-photo-remove aria-label="Remove trainer photo"<?= !$hasPhoto?' hidden':'' ?>><?= Page::navIcon('trash') ?></button></div><input class="sr-only" type="checkbox" name="remove_photo" value="1" aria-label="Remove photo when saving" data-settings-remove-photo><small>JPG, PNG or WebP · Up to 5 MB<br>At least 200 × 200 pixels</small></div>
            <div class="wss-trainer-fields"><?= $input('speaker_en','Trainer name',$v('speaker'),'maxlength="160"') ?><p class="ws-hint">Keep the full head and shoulders visible, with a little space above the head.</p><details class="wss-extra"><summary>More workshop details</summary><div><?= $input('credentials_en','Trainer qualifications',$v('credentials'),'maxlength="200"') ?><?= $input('format_en','Workshop format',$v('format'),'maxlength="120"') ?><?= $input('duration_en','Duration',$v('duration'),'maxlength="60"') ?><?= $input('level_en','Experience level',$v('level'),'maxlength="120"') ?><label class="wss-field"><span>Icon when there is no photo</span><select name="icon"><?= $optionList(array_combine(Workshops::ICONS,array_map('ucfirst',Workshops::ICONS)),$w['icon']??'tools') ?></select></label></div></details><small class="ws-hint">Qualifications, format, duration, level and fallback icon.</small></div>
          </div>
          <div class="wss-schedule"><div class="wss-section-label"><h4>Schedule</h4><small>Iraq time</small></div><div class="wss-schedule-fields"><?= $input('scheduled_date','Date',(string)($w['scheduledDate']??''),'','date') ?><?= $input('start_time','Start time',(string)($w['startTime']??''),'','time') ?><?= $input('end_time','End time',(string)($w['endTime']??''),'','time') ?></div></div>
          <label class="wss-field"><span>Short description</span><textarea name="overview_en" rows="3" maxlength="2000"><?= $e($v('overview')) ?></textarea></label>
          <details class="wss-translations"><summary><?= Page::navIcon('communications') ?><span>Arabic &amp; Kurdish translations<small>Optional — edit when needed.</small></span></summary><div><?php foreach(['ar'=>'Arabic','ku'=>'Kurdish'] as $lang=>$language): ?><fieldset dir="rtl"><legend><?= $language ?></legend><?php foreach(['title'=>'Workshop name','speaker'=>'Trainer name','credentials'=>'Trainer qualifications','format'=>'Format','duration'=>'Duration','level'=>'Experience level'] as $field=>$label): ?><?= $input($field.'_'.$lang,$label,$v($field,$lang),'maxlength="'.(['credentials'=>200,'format'=>120,'duration'=>60,'level'=>120][$field]??160).'" dir="rtl"') ?><?php endforeach; ?><label class="wss-field"><span>Description</span><textarea name="overview_<?= $lang ?>" rows="3" dir="rtl" maxlength="2000"><?= $e($v('overview',$lang)) ?></textarea></label></fieldset><?php endforeach; ?></div></details>
        </div>
        <div class="wss-panel" role="tabpanel" id="<?= $e($prefix.'-registration') ?>" aria-labelledby="<?= $e($prefix.'-registration-tab') ?>" data-settings-panel="registration">
          <div class="wss-panel-intro"><h4>How people register</h4><p>Choose who manages this workshop’s bookings.</p></div>
          <fieldset class="wss-organizers"><legend class="sr-only">Organized by</legend><label><input type="radio" name="organizer" value="ismile"<?= ($w['organizer']??'ismile')==='ismile'?' checked':'' ?>><span><?= Page::navIcon('workshops') ?><strong>iSmile</strong><small>Register online using the summit email.</small></span></label><label><input type="radio" name="organizer" value="company"<?= ($w['organizer']??'')==='company'?' checked':'' ?>><span><?= Page::navIcon('sponsors') ?><strong>Company</strong><small>Contact the organizer to register.</small></span></label></fieldset>
          <div class="wss-company-fields" data-settings-company><?= $input('company_en','Company name',$v('company'),'maxlength="160"') ?><div class="wss-pair"><?= $input('company_phone','Company phone',(string)($w['companyPhone']??''),'maxlength="40" dir="ltr"','tel') ?><?= $input('company_phone_secondary','Second phone (optional)',(string)($w['companyPhones'][1]??''),'maxlength="40" dir="ltr"','tel') ?></div><details class="wss-extra"><summary>Company name translations</summary><div><?= $input('company_ar','Company name (Arabic)',$v('company','ar'),'maxlength="160" dir="rtl"') ?><?= $input('company_ku','Company name (Kurdish)',$v('company','ku'),'maxlength="160" dir="rtl"') ?></div></details></div>
          <div class="wss-price-section"><h4>Ticket price</h4><div class="wss-pair"><?= $input('price','Price per ticket',(string)($w['price']??0),'min="0" max="100000000" step="1"','number') ?><label class="wss-field"><span>Currency</span><select name="currency"><?= $optionList(['IQD'=>'IQD — Iraqi dinar','USD'=>'USD — US dollar'],$w['currency']??'IQD') ?></select></label></div><p class="ws-hint">Guest tickets are complimentary and stay at 0 IQD. Online tickets use the current PSOOLA price.</p></div>
          <div class="wss-capacity"><div class="wss-section-label"><h4>Booking capacity</h4><small>Owner only</small></div><label class="wss-capacity-input"><span class="sr-only">Booking capacity</span><button type="button" data-settings-capacity="-1" aria-label="One seat fewer">−</button><input type="number" name="total_seats" min="1" max="2000" value="<?= (int)($w['totalSeats']??25) ?>" required><button type="button" data-settings-capacity="1" aria-label="One seat more">+</button></label><p class="ws-hint">The limit cannot be lower than existing bookings. Guest pages show availability without numerical seat counts.</p></div>
        </div>
        <div class="wss-panel" role="tabpanel" id="<?= $e($prefix.'-visibility') ?>" aria-labelledby="<?= $e($prefix.'-visibility-tab') ?>" data-settings-panel="visibility">
          <div class="wss-panel-intro"><h4>Website visibility</h4><p>Control whether guests can find and book this workshop.</p></div>
          <label class="wss-visibility"><span><strong>Show on website</strong><small>Visible to guests and available for booking.</small></span><input type="hidden" name="website_status" value="hidden"><input type="checkbox" name="website_status" value="active"<?= ($w['status']??'active')==='active'?' checked':'' ?> role="switch"></label>
          <div class="wss-hiding-info"><h4>When you hide a workshop</h4><ul><li>It leaves the website’s workshop list.</li><li>Guests cannot make new bookings on this website.</li><li>Existing attendees and payment records are kept.</li></ul><p class="wss-note">You can show a hidden workshop again whenever you are ready.</p></div>
          <?php if(!$new): ?><div class="wss-danger"><div><h4>Delete workshop</h4><p>Only workshops with no booking history can be deleted.</p></div><button class="btn small ghost" type="submit" form="<?= $e($prefix.'-delete') ?>"<?= $hasHistory?' disabled':'' ?><?= !$hasHistory?Page::confirm('red','trash','Delete this workshop for good?','It is removed from the database and website. This cannot be undone.',[['calendar',Workshops::name($w)]],['yes'=>'Delete workshop','no'=>'Keep workshop']):'' ?>><?= Page::navIcon($hasHistory?'security':'trash') ?>Delete workshop</button><?php if($hasHistory): ?><small>This workshop has booking history. Hide it to stop new bookings.</small><?php endif; ?></div><?php endif; ?>
        </div>
        <footer class="wss-footer"><span class="wss-save-note" data-settings-save-note>Changes appear on the website after saving.</span><div><button class="btn ghost" type="button" data-settings-cancel>Cancel</button><button class="btn ws-primary" type="submit"><?= Page::navIcon('check') ?><?= $new?'Add workshop':'Save changes' ?></button></div></footer>
      </form>
      <?php if(!$new): ?><form method="post" id="<?= $e($prefix.'-delete') ?>" action="<?= $e($url(['settings'=>$wid],'#manage')) ?>"><?= Page::csrfField() ?><input type="hidden" name="do" value="ws_delete"><input type="hidden" name="ws" value="<?= $e($wid) ?>"></form><?php endif; ?>
    </section>
    <?php endforeach; ?>
    </div>
  </div>
  <noscript><style>.wss-editor[hidden]{display:block}.wss-editor{margin-bottom:24px}.wss-mobile-picker,.wss-tabs{display:none}</style></noscript>
</div>
