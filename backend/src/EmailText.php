<?php
// Every word the emails and the PDF ticket use. Emails are sent in English
// only, whatever language the person used on the website.

declare(strict_types=1);

namespace Ismile;

final class EmailText
{
    private const WORDS = [
        'dates_venue'       => '20–21 November 2026 · Grand Millennium Sulaimani',
        'hello'             => 'Dear {name},',
        'reference'         => 'Your reference',
        'questions'         => 'Questions? Write to ismile@italk.krd{phone} and mention your reference.',
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
        'ticket_name_note'  => 'Your name is printed as you typed it; it will also appear on your certificate. If it is wrong, reply to this email.',
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
        'entrance_qr'       => 'Show this QR at the entrance on both days, on your phone or printed. It admits you once on Day 1 and once on Day 2. Repeated check-in on the same day is blocked. Your participation certificate becomes available after check-in.',
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
        'pdf_footer'        => 'Keep this QR for both days: one admission on Day 1 and one on Day 2. Repeat entry on the same day is blocked. Certificates require check-in. Tickets are non-refundable.',
    ];

    public static function words(): array
    {
        return self::WORDS;
    }

    public static function lunchLine(array $registration): string
    {
        $words = self::WORDS;
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
