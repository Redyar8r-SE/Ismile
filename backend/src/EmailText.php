<?php
// Every word the emails and the PDF ticket use, in English, Arabic and
// Kurdish (Sorani). Dates and the venue are written the way the website
// writes them in each language.

declare(strict_types=1);

namespace Ismile;

final class EmailText
{
    private const WORDS = [
        'en' => [
            'dates_venue'       => '20–21 November 2026 · Grand Millennium Sulaimani',
            'hello'             => 'Dear {name},',
            'reference'         => 'Your reference',
            'questions'         => 'Questions? Write to info@ismile.krd{phone} and mention your reference.',
            'call'              => ' or call {phone}',
            'no_reply'          => 'This email was sent by the iSmile 2026 registration system.',
            'pay_button'        => 'Pay now',
            'pay_expires'       => 'This payment link is personal and works for {days} days. Do not forward it.',
            'type_professional' => 'Professional',
            'type_student'      => 'Student',
            'lunch_none'        => 'No lunch',
            'lunch_day1'        => 'Lunch day 1',
            'lunch_day2'        => 'Lunch day 2',
            'lunch_both'        => 'Lunch on both days',
            'amount'            => 'Amount',
            'refund_rule'       => 'Tickets are non-refundable.',
            'complimentary'     => 'Complimentary ticket',





            'pay_now_subject'   => 'iSmile 2026: complete your registration by paying ({ref})',
            'pay_now_body'      => 'We have your details from your phone call. You are registered only after paying: use the button below. Your ticket is emailed to you as soon as the payment is confirmed.',



            'ticket_subject'    => '🎉 You\'re in! Your iSmile 2026 registration ({ticket})',
            'ticket_body'       => 'Your payment is confirmed. Welcome to iSmile 2026! Your ticket is below and attached as a PDF. Show the QR code at the entrance, on your phone or printed.',
            'ticket_name_note'  => 'Your name is printed as you typed it; it will also appear on your certificate. If it is wrong, reply to this email.',
            'ticket_workshops'  => 'Hands-on workshops take place during the summit. See them at {url}',
            'ticket_no'         => 'Ticket number',
            'congrats'          => 'Congratulations, {name}! 🎉',
            'ticket_intro'      => 'Your payment is confirmed and you are officially registered for iSmile 2026, the 2nd edition of the summit. We are so happy to have you with us!',
            'box_you'           => 'Your registration',
            'box_event'         => 'The event',
            'row_name'          => 'Name',
            'row_ticket'        => 'Ticket',
            'row_university'    => 'University',
            'row_days'          => 'Attending',
            'days_both'         => 'Both days',
            'row_paid'          => 'Amount paid',
            'row_paid_by'       => 'Paid by',
            'row_paid_on'       => 'Paid on',
            'free_ticket'       => 'Free ticket (guest of iSmile)',
            'row_when'          => 'When',
            'row_where'         => 'Where',
            'row_program'       => 'Program',
            'from_time'         => 'starts at {time}',
            'map_link'          => 'Open in Google Maps',
            'program_link'      => 'See the full program',
            'entrance_title'    => 'At the entrance',
            'entrance_text'     => 'Give your name or your reference number at the registration desk.',
            'entrance_qr'       => 'Show the QR code above at the registration desk, on your phone or printed.',
            'entrance_student'  => 'Students: please bring your student ID card.',
            'see_you'           => 'See you in Sulaymaniyah!',

            'sponsor_subject'   => 'iSmile 2026: we received your request ({ref})',
            'sponsor_body'      => 'Thank you for your interest in iSmile 2026. We received your request and a member of our team will contact you soon.',
            'sponsor_kind'      => 'Request',
            'sponsor_sponsor'   => 'Sponsorship',
            'sponsor_booth'     => 'Exhibition booth',
            'sponsor_package'   => 'Package',

            'pdf_name'          => 'Name',
            'pdf_ticket_type'   => 'Ticket',
            'pdf_lunch'         => 'Lunch',
            'pdf_reference'     => 'Reference',
            'pdf_ticket_no'     => 'Ticket no.',
            'pdf_note'          => 'Note',
            'pdf_footer'        => 'Show this QR code at the entrance. The ticket is personal and can be used once. Tickets are non-refundable.',
        ],

        'ar' => [
            'dates_venue'       => '20 و21 تشرين الثاني 2026 · فندق غراند ميلينيوم السليمانية',
            'hello'             => 'عزيزي/عزيزتي {name}،',
            'reference'         => 'الرقم المرجعي',
            'questions'         => 'لديك سؤال؟ راسلنا على info@ismile.krd{phone} واذكر رقمك المرجعي.',
            'call'              => ' أو اتصل على {phone}',
            'no_reply'          => 'أُرسلت هذه الرسالة من نظام التسجيل في iSmile 2026.',
            'pay_button'        => 'ادفع الآن',
            'pay_expires'       => 'رابط الدفع هذا خاص بك وصالح لمدة {days} أيام. لا تُرسله لأحد.',
            'type_professional' => 'المهنيون',
            'type_student'      => 'الطلبة',
            'lunch_none'        => 'بدون غداء',
            'lunch_day1'        => 'غداء اليوم الأول',
            'lunch_day2'        => 'غداء اليوم الثاني',
            'lunch_both'        => 'الغداء في اليومين',
            'amount'            => 'المبلغ',
            'refund_rule'       => 'التذاكر غير قابلة للاسترداد.',
            'complimentary'     => 'تذكرة مجانية',





            'pay_now_subject'   => 'iSmile 2026: أكمل تسجيلك بالدفع ({ref})',
            'pay_now_body'      => 'لدينا بياناتك من مكالمتك الهاتفية. لا يكتمل تسجيلك إلا بعد الدفع: استخدم الزر أدناه. ستصلك تذكرتك عبر البريد الإلكتروني فور تأكيد الدفع.',



            'ticket_subject'    => '🎉 تم تسجيلك! تأكيد تسجيلك في iSmile 2026 ({ticket})',
            'ticket_body'       => 'تم تأكيد الدفع. أهلاً بك في iSmile 2026! تجد تذكرتك أدناه، كما أنها مرفقة بصيغة PDF. أظهر رمز QR عند المدخل من هاتفك أو مطبوعاً.',
            'ticket_name_note'  => 'طُبع اسمك كما كتبته، وسيظهر كذلك في شهادتك. إذا كان فيه خطأ، ردّ على هذه الرسالة.',
            'ticket_workshops'  => 'تُقام ورش عمل تطبيقية خلال أيام القمة. اطّلع عليها: {url}',
            'ticket_no'         => 'رقم التذكرة',
            'congrats'          => 'تهانينا يا {name}! 🎉',
            'ticket_intro'      => 'تم تأكيد الدفع، وأصبحت مسجّلاً رسمياً في iSmile 2026، النسخة الثانية من القمة. يسعدنا كثيراً أن تكون معنا!',
            'box_you'           => 'تفاصيل تسجيلك',
            'box_event'         => 'الفعالية',
            'row_name'          => 'الاسم',
            'row_ticket'        => 'نوع التذكرة',
            'row_university'    => 'الجامعة',
            'row_days'          => 'الحضور',
            'days_both'         => 'اليومان',
            'row_paid'          => 'المبلغ المدفوع',
            'row_paid_by'       => 'طريقة الدفع',
            'row_paid_on'       => 'تاريخ الدفع',
            'free_ticket'       => 'تذكرة مجانية (ضيف iSmile)',
            'row_when'          => 'الموعد',
            'row_where'         => 'المكان',
            'row_program'       => 'البرنامج',
            'from_time'         => 'يبدأ الساعة {time}',
            'map_link'          => 'افتح الموقع في خرائط Google',
            'program_link'      => 'اطّلع على البرنامج كاملاً',
            'entrance_title'    => 'عند المدخل',
            'entrance_text'     => 'اذكر اسمك أو رقمك المرجعي عند مكتب التسجيل.',
            'entrance_qr'       => 'أظهر رمز QR أعلاه عند مكتب التسجيل، من هاتفك أو مطبوعاً.',
            'entrance_student'  => 'للطلاب: يرجى إحضار بطاقة الطالب.',
            'see_you'           => 'نراك في السليمانية!',

            'sponsor_subject'   => 'iSmile 2026: استلمنا طلبك ({ref})',
            'sponsor_body'      => 'شكراً لاهتمامك بـ iSmile 2026. استلمنا طلبك، وسيتواصل معك أحد أعضاء فريقنا قريباً.',
            'sponsor_kind'      => 'الطلب',
            'sponsor_sponsor'   => 'رعاية',
            'sponsor_booth'     => 'جناح في المعرض',
            'sponsor_package'   => 'الباقة',

            'pdf_name'          => 'الاسم',
            'pdf_ticket_type'   => 'التذكرة',
            'pdf_lunch'         => 'الغداء',
            'pdf_reference'     => 'الرقم المرجعي',
            'pdf_ticket_no'     => 'رقم التذكرة',
            'pdf_note'          => 'ملاحظة',
            'pdf_footer'        => 'أظهر رمز QR هذا عند المدخل. التذكرة شخصية وتُستخدم مرة واحدة، وهي غير قابلة للاسترداد.',
        ],

        'ku' => [
            'dates_venue'       => '20 و 21ی تشرینی دووەمی 2026 · هۆتێلی گراند میلێنیۆم سلێمانی',
            'hello'             => 'بەڕێز {name}،',
            'reference'         => 'ژمارەی تۆمار',
            'questions'         => 'پرسیارت هەیە؟ بنووسە بۆ info@ismile.krd{phone} و ژمارەی تۆمارەکەت بنووسە.',
            'call'              => ' یان پەیوەندی بکە بە {phone}',
            'no_reply'          => 'ئەم ئیمەیڵە لە سیستەمی تۆمارکردنی iSmile 2026ەوە نێردراوە.',
            'pay_button'        => 'ئێستا پارە بدە',
            'pay_expires'       => 'ئەم بەستەرەی پارەدان تایبەتە بە تۆ و بۆ {days} ڕۆژ کار دەکات. بۆ کەسی تری مەنێرە.',
            'type_professional' => 'کەسانی پیشەیی',
            'type_student'      => 'خوێندکاران',
            'lunch_none'        => 'بەبێ نانی نیوەڕۆ',
            'lunch_day1'        => 'نانی نیوەڕۆی ڕۆژی یەکەم',
            'lunch_day2'        => 'نانی نیوەڕۆی ڕۆژی دووەم',
            'lunch_both'        => 'نانی نیوەڕۆ لە هەردوو ڕۆژ',
            'amount'            => 'بڕی پارە',
            'refund_rule'       => 'پارەی بلیت ناگەڕێندرێتەوە.',
            'complimentary'     => 'بلیتی خۆڕایی',





            'pay_now_subject'   => 'iSmile 2026: تۆمارکردنەکەت بە پارەدان تەواو بکە ({ref})',
            'pay_now_body'      => 'زانیارییەکانت لە پەیوەندییە تەلەفۆنییەکەتەوە لای ئێمەیە. تەنها دوای پارەدان تۆمار دەکرێیت: دوگمەی خوارەوە بەکاربهێنە. بلیتەکەت هەر کە پارەدانەکە پشتڕاست کرایەوە بە ئیمەیڵ بۆت دەنێردرێت.',



            'ticket_subject'    => '🎉 تۆمار کرایت! تۆمارکردنەکەت بۆ iSmile 2026 ({ticket})',
            'ticket_body'       => 'پارەدانەکەت پشتڕاست کرایەوە. بەخێربێیت بۆ iSmile 2026! بلیتەکەت لە خوارەوەیە و وەک PDF هاوپێچ کراوە. کۆدی QR لە دەروازە نیشان بدە، لە مۆبایلەکەت یان چاپکراو.',
            'ticket_name_note'  => 'ناوەکەت وەک خۆت نووسیوتە چاپ کراوە و لە بڕوانامەکەشتدا دەردەکەوێت. ئەگەر هەڵەی تێدایە، وەڵامی ئەم ئیمەیڵە بدەرەوە.',
            'ticket_workshops'  => 'لە ڕۆژانی لووتکەکەدا وۆرکشۆپی پراکتیکی هەیە. لێرە بیانبینە: {url}',
            'ticket_no'         => 'ژمارەی بلیت',
            'congrats'          => 'پیرۆزە {name}! 🎉',
            'ticket_intro'      => 'پارەدانەکەت پشتڕاست کرایەوە و بە فەرمی بۆ iSmile 2026، خولی دووەمی لووتکەکە، تۆمار کرایت. زۆر دڵخۆشین کە لەگەڵمانیت!',
            'box_you'           => 'زانیاریی تۆمارکردنەکەت',
            'box_event'         => 'بۆنەکە',
            'row_name'          => 'ناو',
            'row_ticket'        => 'جۆری بلیت',
            'row_university'    => 'زانکۆ',
            'row_days'          => 'ئامادەبوون',
            'days_both'         => 'هەردوو ڕۆژ',
            'row_paid'          => 'بڕی پارەی دراو',
            'row_paid_by'       => 'شێوازی پارەدان',
            'row_paid_on'       => 'ڕێکەوتی پارەدان',
            'free_ticket'       => 'بلیتی بێبەرامبەر (میوانی iSmile)',
            'row_when'          => 'کات',
            'row_where'         => 'شوێن',
            'row_program'       => 'بەرنامە',
            'from_time'         => 'کاتژمێر {time} دەست پێدەکات',
            'map_link'          => 'لە نەخشەی Google بیکەرەوە',
            'program_link'      => 'هەموو بەرنامەکە ببینە',
            'entrance_title'    => 'لە دەروازە',
            'entrance_text'     => 'ناوەکەت یان ژمارەی ئاماژەکەت لە مێزی تۆمارکردن بڵێ.',
            'entrance_qr'       => 'کۆدی QRـی سەرەوە لە مێزی تۆمارکردن نیشان بدە، لە مۆبایلەکەت یان چاپکراو.',
            'entrance_student'  => 'خوێندکاران: تکایە کارتی خوێندکاری لەگەڵ خۆتان بهێنن.',
            'see_you'           => 'لە سلێمانی دەتانبینین!',

            'sponsor_subject'   => 'iSmile 2026: داواکارییەکەتمان پێگەیشت ({ref})',
            'sponsor_body'      => 'سوپاس بۆ گرنگیدانت بە iSmile 2026. داواکارییەکەتمان پێگەیشت و بەم زووانە یەکێک لە تیمەکەمان پەیوەندیت پێوە دەکات.',
            'sponsor_kind'      => 'داواکاری',
            'sponsor_sponsor'   => 'سپۆنسەری',
            'sponsor_booth'     => 'جێگای پێشانگا',
            'sponsor_package'   => 'پاکێج',

            'pdf_name'          => 'ناو',
            'pdf_ticket_type'   => 'بلیت',
            'pdf_lunch'         => 'نانی نیوەڕۆ',
            'pdf_reference'     => 'ژمارەی تۆمار',
            'pdf_ticket_no'     => 'ژمارەی بلیت',
            'pdf_note'          => 'تێبینی',
            'pdf_footer'        => 'ئەم کۆدی QRـە لە دەروازە نیشان بدە. بلیتەکە تایبەتە و تەنها یەک جار بەکاردێت. پارەی بلیت ناگەڕێندرێتەوە.',
        ],
    ];

    public static function for(string $lang): array
    {
        return self::WORDS[Lang::pick($lang)];
    }

    public static function lunchLine(array $registration, string $lang): string
    {
        $words = self::for($lang);
        $one = (int) $registration['lunch_day1'] === 1;
        $two = (int) $registration['lunch_day2'] === 1;
        return match (true) {
            $one && $two => $words['lunch_both'],
            $one         => $words['lunch_day1'],
            $two         => $words['lunch_day2'],
            default      => $words['lunch_none'],
        };
    }
}
