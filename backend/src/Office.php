<?php
// What the office does from the admin, each change written to the audit log:
// fixing details, resending the ticket, cancelling, free tickets (Owner) and
// workshops booked by phone. A caller who registers by phone gets a payment
// link (Checkouts::createFromOffice) and is registered only after paying.

declare(strict_types=1);

namespace Ismile;

final class Office
{
    /** Fixes name, email or phone. A name change reissues the ticket (the name is on the certificate). */
    public static function editContact(array $registration, array $user, array $in): string
    {
        $first = Validate::text($in['first_name'] ?? '', 60);
        $father = Validate::text($in['father_name'] ?? '', 60);
        $grandfather = Validate::text($in['grandfather_name'] ?? '', 60);
        foreach ([$first, $father, $grandfather] as $part) {
            if (!Validate::namePart($part)) {
                throw new UserError('Each name part needs at least 2 letters.');
            }
        }
        $email = strtolower(Validate::text($in['email'] ?? '', 190));
        if (!Validate::email($email)) {
            throw new UserError('That email address is not valid.');
        }
        $phone = Validate::phone(Validate::text($in['phone'] ?? '', 30));
        if ($phone === null) {
            throw new UserError('That phone number is not valid (0750 123 4567 or +964 750 123 4567).');
        }
        $city = Validate::text($in['city'] ?? $registration['city'], 80) ?: $registration['city'];

        $changes = [];
        foreach (['first_name' => $first, 'father_name' => $father, 'grandfather_name' => $grandfather, 'email' => $email, 'phone' => $phone, 'city' => $city] as $column => $value) {
            if ((string) $registration[$column] !== $value) {
                $changes[$column] = ['from' => $registration[$column], 'to' => $value];
            }
        }
        if (!$changes) {
            return 'Nothing changed.';
        }
        Db::update('registrations', [
            'first_name' => $first, 'father_name' => $father, 'grandfather_name' => $grandfather,
            'email' => $email, 'phone' => $phone, 'city' => $city, 'updated_at' => App::now(),
        ], 'id = ?', [$registration['id']]);
        Audit::log((int) $user['id'], 'registration.edit', 'registration', (int) $registration['id'], $changes);

        $nameChanged = isset($changes['first_name']) || isset($changes['father_name']) || isset($changes['grandfather_name']);
        if ($nameChanged) {
            Db::run('UPDATE certificates SET recipient_name = ? WHERE registration_id = ?', [trim($first . ' ' . $father . ' ' . $grandfather), $registration['id']]);
        }
        $ticket = Tickets::forRegistration((int) $registration['id']);
        if ($nameChanged && $ticket && $ticket['cancelled_at'] === null) {
            // A new version: the old QR (with the old name) stops working at the door.
            Db::run('UPDATE tickets SET version = version + 1 WHERE id = ?', [$ticket['id']]);
            Outbox::queue('ticket', Registrations::find((int) $registration['id']));
            Audit::log((int) $user['id'], 'ticket.reissued', 'registration', (int) $registration['id']);
            return 'Saved. The name changed, so the ticket was reissued and emailed again (the old QR no longer works).';
        }
        return 'Saved.';
    }

    public static function resendTicket(array $registration, array $user): string
    {
        if (!in_array($registration['status'], ['paid', 'complimentary'], true)) {
            throw new UserError('Only a paid or complimentary registration has a ticket to send.');
        }
        Outbox::queue('ticket', $registration);
        Audit::log((int) $user['id'], 'ticket.resend', 'registration', (int) $registration['id'], ['to' => $registration['email']]);
        return 'The ticket will be emailed to ' . $registration['email'] . ' within a minute.';
    }

    public static function cancel(array $registration, array $user, string $reason): string
    {
        if ($registration['status'] === 'cancelled') {
            return 'Already cancelled.';
        }
        if ($reason === '') {
            throw new UserError('Write the reason for cancelling.');
        }
        // A paid workshop is never removed by staff (no refunds): only the Owner.
        if ($user['role'] !== 'owner' && Db::value("SELECT COUNT(*) FROM workshop_bookings WHERE registration_id = ? AND removed_at IS NULL AND payment_status = 'paid'", [$registration['id']])) {
            throw new UserError('This person has a PAID workshop. Only the Owner can cancel them (workshop money is not refunded).');
        }
        Db::transaction(static function () use ($registration, $user): void {
            $now = App::now();
            Db::run("UPDATE registrations SET status = 'cancelled', updated_at = ? WHERE id = ?", [$now, $registration['id']]);
            Db::run('UPDATE tickets SET cancelled_at = ? WHERE registration_id = ? AND cancelled_at IS NULL', [$now, $registration['id']]);
            // Their workshop seats go back on sale.
            Db::run('UPDATE workshop_bookings SET removed_at = ?, removed_by = ?, updated_at = ? WHERE registration_id = ? AND removed_at IS NULL', [$now, $user['id'], $now, $registration['id']]);
        });
        SiteData::syncWorkshopSeats();
        Audit::log((int) $user['id'], 'registration.cancel', 'registration', (int) $registration['id'], ['reason' => $reason, 'was' => $registration['status']]);
        $note = $registration['status'] === 'paid' ? ' It was paid: tickets are non-refundable, so no refund is made automatically.' : '';
        return 'Cancelled. The ticket no longer works at the door.' . $note;
    }

    /**
     * Owner only: registers someone directly with a free ticket (a guest, a
     * speaker's assistant...). The only way into the registrations without a
     * payment; logged with who and why.
     */
    public static function createComplimentary(array $user, array $in): array
    {
        if ($user['role'] !== 'owner') {
            throw new UserError('Only the Owner can give a free ticket.');
        }
        $reason = Validate::text($in['comp_reason'] ?? '', 255);
        if ($reason === '') {
            throw new UserError('Write the reason for the free ticket.');
        }
        $row = Checkouts::officeRow($in);
        $now = App::now();
        $id = Db::transaction(static function () use ($row, $user, $reason, $now): int {
            $duplicateOf = Db::value("SELECT id FROM registrations WHERE (email = ? OR phone = ?) AND status <> 'cancelled' LIMIT 1", [$row['email'], $row['phone']]);
            $id = Db::insert('registrations', ['pay_method' => null] + $row + [
                'ref' => Checkouts::newReference(), 'status' => 'complimentary', 'comp_reason' => $reason,
                'possible_duplicate' => $duplicateOf ? 1 : 0, 'view_nonce' => Links::nonce(), 'created_by' => (int) $user['id'],
                'created_ip' => 'office', 'created_at' => $now, 'paid_at' => $now, 'updated_at' => $now,
            ]);
            Tickets::issue($id, 'complimentary', null, (int) $user['id']);
            return $id;
        });
        Audit::log((int) $user['id'], 'ticket.complimentary', 'registration', $id, ['reason' => $reason]);
        $registration = Registrations::find($id);
        Outbox::queue('ticket', $registration);
        return $registration;
    }

    public static function saveNotes(array $registration, array $user, string $notes): string
    {
        Db::update('registrations', ['notes' => $notes !== '' ? $notes : null, 'updated_at' => App::now()], 'id = ?', [$registration['id']]);
        Audit::log((int) $user['id'], 'registration.notes', 'registration', (int) $registration['id']);
        return 'Notes saved.';
    }

    // ---------------- workshops booked by phone ----------------

    public static function bookedCount(string $workshopId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM workshop_bookings WHERE workshop_id = ? AND removed_at IS NULL', [$workshopId]);
    }

    public static function addWorkshop(array $registration, array $user, array $in): string
    {
        $workshop = SiteData::workshop((string) ($in['workshop'] ?? ''));
        if ($workshop === null) {
            throw new UserError('Choose a workshop.');
        }
        $isOwner = $user['role'] === 'owner';
        // Only registered people (they paid) take a workshop seat.
        if (!in_array($registration['status'], ['paid', 'complimentary'], true)) {
            throw new UserError(Registrations::fullName($registration) . ' is not registered (cancelled).');
        }
        if ($workshop['status'] !== 'active') {
            throw new UserError('That workshop is hidden (not on offer). The Owner can show it again on the Workshops page.');
        }
        // The price is the workshop's own. Only the Owner may agree a different one.
        $typedPrice = trim((string) ($in['price'] ?? ''));
        $typedPrice = preg_replace('/\D/', '', $typedPrice) ?? '';
        $price = $isOwner && $typedPrice !== '' ? (int) $typedPrice : (int) ($workshop['price'] ?? 0);
        if ($price > 10_000_000) {
            throw new UserError('That price (' . number_format($price) . ' IQD) looks wrong. Check the number.');
        }
        if ($price <= 0 && !$isOwner) {
            throw new UserError('This workshop has no price yet. The Owner sets it on the Workshops page first.');
        }
        $payment = self::workshopPayment($in, $price, $user);
        $override = ($in['override'] ?? '') === '1' && $isOwner;
        // The workshop row is locked while the seat is taken, so two people
        // booking the last seat at the same moment cannot both get it.
        [$id, $full] = Db::transaction(static function () use ($registration, $workshop, $user, $in, $price, $payment, $override): array {
            $limit = (int) Db::value('SELECT total_seats FROM workshops WHERE id = ? FOR UPDATE', [$workshop['id']]);
            $already = Db::value('SELECT id FROM workshop_bookings WHERE registration_id = ? AND workshop_id = ? AND removed_at IS NULL', [$registration['id'], $workshop['id']]);
            if ($already) {
                throw new UserError('This person is already booked on that workshop.');
            }
            $full = self::bookedCount($workshop['id']) >= $limit;
            if ($full && !$override) {
                throw new UserError("That workshop is full ($limit seats). The Owner can add seats on the Workshops page, or tick \"over capacity\".");
            }
            $now = App::now();
            $id = Db::insert('workshop_bookings', [
                'registration_id' => $registration['id'], 'workshop_id' => $workshop['id'], 'price_agreed' => $price,
                'booked_by' => $user['id'], 'over_capacity' => $full ? 1 : 0,
                'notes' => Validate::text($in['notes'] ?? '', 500) ?: null,
                'created_at' => $now, 'updated_at' => $now,
            ] + $payment);
            return [$id, $full];
        });
        SiteData::syncWorkshopSeats();
        Audit::log((int) $user['id'], 'workshop.add', 'registration', (int) $registration['id'], ['workshop' => $workshop['id'], 'booking' => $id, 'payment' => $payment, 'over_capacity' => $full]);
        $paidNote = match ($payment['payment_status']) {
            'paid' => ' Paid: ' . number_format($price) . ' IQD.',
            'complimentary' => ' Free (complimentary).',
            default => ' Not paid yet: ' . number_format($price) . ' IQD to collect.',
        };
        return Registrations::fullName($registration) . ' is booked on ' . SiteData::workshopName($workshop) . '.' . $paidNote;
    }

    public static function changeWorkshop(int $bookingId, array $user, array $in): string
    {
        $booking = Db::one('SELECT * FROM workshop_bookings WHERE id = ? AND removed_at IS NULL', [$bookingId]);
        if ($booking === null) {
            throw new UserError('Booking not found.');
        }
        $isOwner = $user['role'] === 'owner';
        // Money received is never given back. Only the Owner may correct a
        // mistake (a payment recorded on the wrong person or workshop).
        $wasPaid = $booking['payment_status'] === 'paid';
        if (($in['remove'] ?? '') === '1') {
            if ($wasPaid && !$isOwner) {
                throw new UserError('This workshop is paid. Workshop money is not refunded, so only the Owner can remove a paid booking.');
            }
            Db::update('workshop_bookings', ['removed_at' => App::now(), 'removed_by' => $user['id'], 'updated_at' => App::now()], 'id = ?', [$bookingId]);
            SiteData::syncWorkshopSeats();
            Audit::log((int) $user['id'], 'workshop.remove', 'registration', (int) $booking['registration_id'], ['workshop' => $booking['workshop_id'], 'booking' => $bookingId, 'was_paid' => $wasPaid]);
            return 'Workshop removed. The seat is free again on the website.' . ($wasPaid ? ' The money is not refunded.' : '');
        }
        $payment = self::workshopPayment($in + ['payment_status' => $booking['payment_status']], (int) $booking['price_agreed'], $user);
        if ($wasPaid && $payment['payment_status'] !== 'paid' && !$isOwner) {
            throw new UserError('This workshop is already paid. Workshop money is not refunded; only the Owner can correct a mistake.');
        }
        if ($wasPaid && $payment['payment_status'] === 'paid') {
            return 'Already paid. Nothing changed.';
        }
        Db::update('workshop_bookings', $payment + ['updated_at' => App::now()], 'id = ?', [$bookingId]);
        Audit::log((int) $user['id'], 'workshop.payment', 'registration', (int) $booking['registration_id'], ['booking' => $bookingId, 'from' => $booking['payment_status']] + $payment);
        return match ($payment['payment_status']) {
            'paid' => 'Workshop marked PAID: ' . number_format((int) $booking['price_agreed']) . ' IQD received.',
            'complimentary' => 'Workshop marked free (complimentary).',
            default => 'Workshop marked NOT paid.',
        };
    }

    /**
     * The payment columns for a workshop booking, checked. "Paid" needs the
     * exact amount received (the agreed price) and how it was paid; anything
     * else is refused, so every workshop is paid correctly or not at all.
     */
    private static function workshopPayment(array $in, int $price, array $user): array
    {
        $status = Validate::oneOf($in['payment_status'] ?? null, ['unpaid', 'paid', 'complimentary'], 'unpaid');
        if ($status === 'complimentary' && $user['role'] !== 'owner') {
            throw new UserError('Only the Owner can give a workshop for free.');
        }
        if ($status !== 'paid') {
            return ['payment_status' => $status, 'amount_paid' => null, 'paid_how' => null, 'paid_at' => null, 'paid_recorded_by' => null];
        }
        if ($price <= 0) {
            throw new UserError('This workshop has no price yet, so it cannot be marked paid. Set the price first, or choose "free" (Owner).');
        }
        $typed = preg_replace('/\D/', '', (string) ($in['amount_paid'] ?? '')) ?? '';
        if ($typed === '') {
            throw new UserError('Type the amount received (' . number_format($price) . ' IQD).');
        }
        if ((int) $typed !== $price) {
            throw new UserError('The amount received (' . number_format((int) $typed) . ' IQD) is not the workshop price (' . number_format($price) . ' IQD). It must be paid in full and exactly.');
        }
        $how = (string) Validate::oneOf($in['paid_how'] ?? null, ['cash', 'transfer', 'psoola'], '');
        if ($how === '') {
            throw new UserError('Choose how it was paid: cash, transfer or Psoola.');
        }
        return ['payment_status' => 'paid', 'amount_paid' => $price, 'paid_how' => $how, 'paid_at' => App::now(), 'paid_recorded_by' => (int) $user['id']];
    }
}
