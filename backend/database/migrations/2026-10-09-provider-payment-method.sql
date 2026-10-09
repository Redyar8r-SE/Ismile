-- Preserve existing choices; new checkouts defer method selection to the provider.
ALTER TABLE checkouts
  MODIFY pay_method ENUM('visa','mastercard','fib','fastpay') NULL DEFAULT NULL;
