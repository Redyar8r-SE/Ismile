ALTER TABLE sponsored_ticket_orders ADD COLUMN is_complimentary TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE sponsored_ticket_orders ADD COLUMN complimentary_reason VARCHAR(500) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN event_id VARCHAR(190) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN show_id VARCHAR(190) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN event_name VARCHAR(250) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN show_name VARCHAR(250) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN ticket_quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE sponsored_ticket_orders ADD COLUMN discount_amount BIGINT UNSIGNED NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN provider_status VARCHAR(40) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN support_request_id VARCHAR(190) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN last_webhook_id VARCHAR(190) NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN retry_after DATETIME NULL;
ALTER TABLE sponsored_ticket_orders ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS sponsored_ticket_events (
  event_id VARCHAR(190) COLLATE utf8mb4_bin NOT NULL,
  order_id INT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (event_id),
  CONSTRAINT fk_guest_webhook_order FOREIGN KEY (order_id) REFERENCES sponsored_ticket_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
