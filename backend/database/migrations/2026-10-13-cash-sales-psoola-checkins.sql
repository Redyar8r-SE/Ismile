-- Cash sales: the office takes the money and PSOOLA issues the ticket at once
-- (POST /orders). Recorded with the payment method 'cash'.
ALTER TABLE checkouts
  MODIFY pay_method ENUM('visa','mastercard','fib','fastpay','cash') NULL DEFAULT NULL;
ALTER TABLE registrations
  MODIFY pay_method ENUM('visa','mastercard','fib','fastpay','cash') NULL;
ALTER TABLE payments
  MODIFY method ENUM('visa','mastercard','fib','fastpay','cash') NULL;

-- PSOOLA scans the tickets at the door; its check-ins are copied in as 'psoola'.
ALTER TABLE ticket_attendance
  MODIFY checkin_method ENUM('signed_qr','manual_lookup','psoola') NOT NULL DEFAULT 'signed_qr';
