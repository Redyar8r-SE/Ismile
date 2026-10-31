-- Reservation IDs are unchanged. Display numbers and sizes are synchronized
-- from data/booth-tiers.json by BoothPlan::syncDatabase() during installation.
CREATE TABLE IF NOT EXISTS sponsor_map_positions (
  position_id SMALLINT UNSIGNED NOT NULL,
  display_number SMALLINT UNSIGNED NOT NULL,
  tier ENUM('platinum','gold','silver','bronze') NOT NULL,
  size VARCHAR(30) NOT NULL,
  PRIMARY KEY (position_id),
  UNIQUE KEY uq_map_display_number (display_number),
  CONSTRAINT ck_map_display_number CHECK (display_number BETWEEN 1 AND 39)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stable booking position IDs and their visible 1-39 map numbers';
