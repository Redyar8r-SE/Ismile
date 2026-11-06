<?php
declare(strict_types=1);
namespace Ismile\Admin;

final class GuestTicketText
{
    public static function locale(mixed $value): string {return is_string($value)&&in_array($value,['en','ar','ckb','kmr'],true)?$value:'en';}
    public static function all(string $locale): array
    {
        $en=['title'=>'Guest tickets','intro'=>'Issue complimentary general-admission tickets through PSOOLA.',
            'back'=>'All registrations','interface'=>'Interface language','event'=>'Event','show'=>'Show','type'=>'Ticket type','quantity'=>'Quantity',
            'addons'=>'Complimentary add-ons','guest'=>'Guest details','name'=>'Guest name (optional)','email'=>'Email address','phone'=>'Phone number',
            'reason'=>'Reason for complimentary issuance','company'=>'Sponsoring company (optional)','specialty'=>'Specialty',
            'locale'=>'Ticket language','choose'=>'Choose an option','refresh'=>'Refresh availability','review'=>'Review guest tickets',
            'total'=>'Total: 0 IQD','total_label'=>'Total','issue'=>'Issue free tickets','issuing'=>'Issuing…','issued'=>'Guest tickets issued','complimentary'=>'Complimentary',
            'consent'=>'The guest agrees now to receive ticket messages on WhatsApp.','consent_note'=>'Leave unchecked without the guest’s agreement. Email and ticket links work independently.',
            'history'=>'Guest order history','staff'=>'Issued by','status'=>'Issuance status','action'=>'Action','details'=>'View order',
            'pending'=>'Awaiting reconciliation','rejected'=>'Not issued','retry'=>'Retry saved request','correct'=>'Correct details in a new request',
            'saved'=>'The original request and key are preserved. Retrying cannot change its guest, reason or ticket selection.',
            'view'=>'View tickets','copy'=>'Copy ticket link','copied'=>'Ticket link copied.','ready'=>'Private ticket link ready.','open'=>'Open ticket page',
            'email_status'=>'Email delivery','whatsapp_status'=>'WhatsApp delivery','delivery_note'=>'Issuance and delivery are separate. Queued or sent does not confirm delivery.',
            'unknown'=>'Not confirmed','queued'=>'Queued','sent'=>'Sent to provider','delivered'=>'Delivered','failed'=>'Failed','pending_delivery'=>'Pending',
            'reference'=>'Reference','discount'=>'Waived amount','empty'=>'No guest orders yet.','error'=>'The request could not be completed. Use the saved order to retry safely.',
            'catalogue_error'=>'The catalogue is unavailable. Refresh before issuing.','legacy'=>'Historical cash order — not zero-price complimentary issuance',
            'support'=>'PSOOLA support reference','retry_after'=>'Retry after','required'=>'Required fields are marked *.',
            'confirm'=>'Issue these complimentary tickets?','confirm_note'=>'PSOOLA must confirm complimentary issuance and a total of 0 IQD. No paid fallback is allowed.',
            'cancel'=>'Go back','no_addons'=>'No supported add-ons for this ticket.',
            'setup_required'=>'Issuance needs setup: PSOOLA must grant complimentary permission and an authorized staging test must pass before production issuance is enabled.'];
        $ar=['title'=>'تذاكر الضيوف','intro'=>'إصدار تذاكر مجانية للدخول العام عبر PSOOLA.','back'=>'كل التسجيلات','interface'=>'لغة الواجهة',
            'event'=>'الفعالية','show'=>'الموعد','type'=>'نوع التذكرة','quantity'=>'العدد','addons'=>'الإضافات المجانية','guest'=>'بيانات الضيف',
            'name'=>'اسم الضيف (اختياري)','email'=>'البريد الإلكتروني','phone'=>'رقم الهاتف','reason'=>'سبب إصدار التذاكر المجانية',
            'company'=>'الشركة الراعية (اختياري)','specialty'=>'التخصص','locale'=>'لغة التذكرة','choose'=>'اختر','refresh'=>'تحديث التوفر',
            'review'=>'مراجعة تذاكر الضيوف','total'=>'المجموع: 0 دينار عراقي','issue'=>'إصدار تذاكر مجانية','issuing'=>'جارٍ الإصدار…',
            'issued'=>'تم إصدار تذاكر الضيوف','complimentary'=>'مجانية','consent'=>'يوافق الضيف الآن على استلام رسائل التذاكر عبر واتساب.',
            'consent_note'=>'اترك الخيار دون تحديد إذا لم يوافق الضيف. يبقى البريد الإلكتروني ورابط التذاكر متاحين.',
            'history'=>'سجل طلبات الضيوف','staff'=>'الموظف المُصدر','status'=>'حالة الإصدار','action'=>'الإجراء','details'=>'عرض الطلب',
            'pending'=>'بانتظار التحقق','rejected'=>'لم تُصدر','retry'=>'إعادة محاولة الطلب المحفوظ','correct'=>'تصحيح البيانات في طلب جديد',
            'saved'=>'تم حفظ الطلب ومفتاحه الأصليين. لا تغيّر بيانات الضيف أو السبب أو التذاكر عند إعادة المحاولة.',
            'view'=>'عرض التذاكر','copy'=>'نسخ رابط التذاكر','copied'=>'تم نسخ رابط التذاكر.','ready'=>'رابط التذاكر الخاص جاهز.','open'=>'فتح صفحة التذاكر',
            'email_status'=>'تسليم البريد الإلكتروني','whatsapp_status'=>'تسليم واتساب','delivery_note'=>'الإصدار منفصل عن التسليم. الانتظار أو الإرسال لا يؤكدان الوصول.',
            'unknown'=>'غير مؤكد','queued'=>'في قائمة الانتظار','sent'=>'أُرسل للمزوّد','delivered'=>'تم التسليم','failed'=>'فشل','pending_delivery'=>'قيد الانتظار',
            'reference'=>'المرجع','discount'=>'المبلغ المُعفى','empty'=>'لا توجد طلبات ضيوف بعد.','support'=>'مرجع دعم PSOOLA','retry_after'=>'أعد المحاولة بعد',
            'required'=>'الحقول المطلوبة مميزة بعلامة *.', 'confirm'=>'إصدار هذه التذاكر المجانية؟','cancel'=>'رجوع',
            'confirm_note'=>'يجب أن تؤكد PSOOLA الإصدار المجاني ومجموعاً قدره 0 دينار عراقي. لا يُسمح بالتحويل إلى بيع مدفوع.',
            'catalogue_error'=>'الكتالوج غير متاح. حدّث التوفر قبل الإصدار.','no_addons'=>'لا توجد إضافات مدعومة لهذه التذكرة.',
            'setup_required'=>'يلزم تفعيل إذن الإصدار المجاني من PSOOLA والتحقق في بيئة الاختبار قبل تفعيل الإصدار الفعلي.'];
        $ckb=['title'=>'پسووڵەکانی میوانان','intro'=>'دەرکردنی پسووڵەی بەخۆڕایی بۆ چوونەژوورەوەی گشتی لە ڕێگەی PSOOLA.',
            'back'=>'هەموو تۆمارکردنەکان','interface'=>'زمانی ڕووکار','event'=>'بۆنە','show'=>'کات','type'=>'جۆری پسووڵە','quantity'=>'ژمارە',
            'addons'=>'زیادکراوە بەخۆڕاییەکان','guest'=>'زانیاری میوان','name'=>'ناوی میوان (ئارەزوومەندانە)','email'=>'ئیمەیڵ','phone'=>'ژمارەی تەلەفۆن',
            'reason'=>'هۆکاری دەرکردنی پسووڵەی بەخۆڕایی','company'=>'کۆمپانیای پاڵپشت (ئارەزوومەندانە)','specialty'=>'پسپۆڕی','locale'=>'زمانی پسووڵە',
            'choose'=>'هەڵبژێرە','refresh'=>'نوێکردنەوەی بەردەستبوون','review'=>'پێداچوونەوەی پسووڵەکان','total'=>'کۆی گشتی: 0 دیناری عێراقی',
            'issue'=>'دەرکردنی پسووڵەکان بەخۆڕایی','issuing'=>'لە دەرکردندایە…','issued'=>'پسووڵەکانی میوانان دەرکران','complimentary'=>'بەخۆڕایی',
            'consent'=>'میوان ئێستا ڕازییە پەیامی پسووڵەکان لە واتساپ وەربگرێت.',
            'consent_note'=>'بەبێ ڕەزامەندی میوان هەڵینەمەبژێرە. ئیمەیڵ و بەستەری پسووڵەکان هەر بەردەستن.',
            'history'=>'مێژووی داواکارییەکانی میوانان','staff'=>'دەرکراوە لەلایەن','status'=>'دۆخی دەرکردن','action'=>'کردار','details'=>'پیشاندانی داواکاری',
            'pending'=>'چاوەڕوانی پشتڕاستکردنەوە','rejected'=>'دەرنەکراوە','retry'=>'دووبارە هەوڵدان بۆ داواکاریی پاشەکەوتکراو','correct'=>'ڕاستکردنەوەی زانیاری لە داواکارییەکی نوێدا',
            'saved'=>'داواکاری و کلیلی سەرەکی پارێزراون. لە دووبارە هەوڵداندا زانیاری میوان و هۆکار و پسووڵەکان ناگۆڕدرێن.',
            'view'=>'پیشاندانی پسووڵەکان','copy'=>'لەبەرگرتنەوەی بەستەری پسووڵەکان','copied'=>'بەستەری پسووڵەکان لەبەرگیرایەوە.','ready'=>'بەستەری تایبەتی پسووڵەکان ئامادەیە.','open'=>'کردنەوەی پەڕەی پسووڵەکان',
            'email_status'=>'گەیشتنی ئیمەیڵ','whatsapp_status'=>'گەیشتنی واتساپ','delivery_note'=>'دەرکردن جیاوازە لە گەیشتن. لە ڕیزدایە یان نێردراوە واتای گەیشتن نییە.',
            'unknown'=>'پشتڕاست نەکراوەتەوە','queued'=>'لە ڕیزدایە','sent'=>'بۆ دابینکەر نێردراوە','delivered'=>'گەیشتووە','failed'=>'سەرکەوتوو نەبوو','pending_delivery'=>'چاوەڕوانە',
            'reference'=>'ژمارەی بەدواداچوون','discount'=>'بڕی لێخۆشبوون','empty'=>'هێشتا هیچ داواکارییەکی میوان نییە.','support'=>'ژمارەی پشتگیری PSOOLA','retry_after'=>'دووبارە هەوڵ بدەرەوە دوای',
            'required'=>'خانە پێویستەکان بە * نیشان دراون.','confirm'=>'ئەم پسووڵە بەخۆڕاییانە دەربکرێن؟','cancel'=>'گەڕانەوە',
            'confirm_note'=>'PSOOLA دەبێت دەرکردنی بەخۆڕایی و کۆی گشتی 0 دیناری عێراقی پشتڕاست بکاتەوە. هیچ فرۆشتنی پارەدار نابێت.',
            'catalogue_error'=>'لیستی پسووڵەکان بەردەست نییە. پێش دەرکردن نوێی بکەرەوە.','no_addons'=>'هیچ زیادکراوەیەکی پشتگیریکراو بۆ ئەم پسووڵەیە نییە.',
            'setup_required'=>'پێش چالاککردنی دەرکردنی ڕاستەقینە، PSOOLA دەبێت مۆڵەتی دەرکردنی بەخۆڕایی بدات و تاقیکردنەوەی مۆڵەتپێدراو سەرکەوتوو بێت.'];
        $kmr=['title'=>'Bilêtên mêvanan','intro'=>'Bilêtên belaş ên ketina giştî bi PSOOLA derxin.','back'=>'Hemû qeydkirin',
            'interface'=>'Zimanê rûkarê','event'=>'Bûyer','show'=>'Dem','type'=>'Cureyê bilêtê','quantity'=>'Hejmara bilêtan','addons'=>'Vebijêrkên belaş',
            'guest'=>'Agahiyên mêvan','name'=>'Navê mêvan (ne mecbûrî)','email'=>'E-name','phone'=>'Hejmara telefonê','reason'=>'Sedema bilêtên belaş',
            'company'=>'Şîrketa piştgir (ne mecbûrî)','specialty'=>'Pisporî','locale'=>'Zimanê bilêtê','choose'=>'Hilbijêre','refresh'=>'Nûkirina cihên berdest',
            'review'=>'Bilêtên mêvanan kontrol bike','total'=>'Tevahî: 0 IQD','issue'=>'Bilêtên belaş derxe','issuing'=>'Tê derxistin…',
            'issued'=>'Bilêtên mêvanan hatin derxistin','complimentary'=>'Belaş','history'=>'Dîroka daxwazên mêvanan','staff'=>'Ji aliyê','status'=>'Rewşa derxistinê',
            'action'=>'Çalakî','details'=>'Daxwazê bibîne','pending'=>'Li benda piştrastkirinê','rejected'=>'Nehatiye derxistin',
            'retry'=>'Daxwaza tomarkirî dubare bike','correct'=>'Agahiyan di daxwazeke nû de rast bike','view'=>'Bilêtan bibîne','copy'=>'Girêdana bilêtan kopî bike',
            'copied'=>'Girêdan hat kopîkirin.','ready'=>'Girêdana taybet amade ye.','open'=>'Rûpela bilêtan veke','email_status'=>'Radestkirina e-nameyê',
            'whatsapp_status'=>'Radestkirina WhatsApp','unknown'=>'Nehatiye piştrastkirin','queued'=>'Di rêzê de','sent'=>'Ji pêşkêşker re hat şandin',
            'delivered'=>'Hat radestkirin','failed'=>'Neserkeftî','pending_delivery'=>'Li bendê','reference'=>'Referans','discount'=>'Mîqdara lêborînê',
            'empty'=>'Hîn daxwazên mêvanan tune ne.','required'=>'Qadên mecbûrî bi * hatine nîşankirin.','confirm'=>'Van bilêtên belaş derxe?','cancel'=>'Vegere'];
        $ar+=['total_label'=>'المجموع'];
        $ckb+=['total_label'=>'کۆی گشتی'];
        $kmr+=[
            'total_label'=>'Tevahî','consent'=>'Mêvan niha razî ye ku peyamên bilêtan bi WhatsApp werbigire.',
            'consent_note'=>'Bê razîbûna mêvan vê nehilbijêre. E-name û girêdana bilêtan jî berdest in.',
            'saved'=>'Daxwaz û mifteya wê ya destpêkê hatine parastin. Di dubarekirinê de agahiyên mêvan, sedem û bilêtan naguherin.',
            'delivery_note'=>'Derxistin û radestkirin cihê ne. Di rêzê de an hat şandin nayê wateya hat radestkirin.',
            'error'=>'Daxwaz nehat temamkirin. Daxwaza tomarkirî bi heman mifteyê dubare bike.',
            'catalogue_error'=>'Lîsteya bilêtan ne berdest e. Berî derxistinê nû bike.',
            'legacy'=>'Daxwaza pereyê destan a berê; ne bilêta belaş a bi tevahî sifir e.',
            'support'=>'Referansa piştgiriyê ya PSOOLA','retry_after'=>'Piştî vê demê dubare bike',
            'confirm_note'=>'PSOOLA divê derxistina belaş û tevahiya 0 IQD piştrast bike. Daxwaza bi pere nayê şandin.',
            'no_addons'=>'Ji bo vê bilêtê vebijêrkên piştgirîkirî tune ne.',
            'setup_required'=>'Berî çalakkirina derxistina rastîn, divê PSOOLA destûra bilêtên belaş bide û ceribandina destûrdayî serkeftî be.',
        ];
        return array_replace($en,match($locale){'ar'=>$ar,'ckb'=>$ckb,'kmr'=>$kmr,default=>[]});
    }
}
