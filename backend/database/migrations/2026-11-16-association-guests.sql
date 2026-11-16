-- Association guests: people the Slemani Association registers (a PSOOLA cash
-- sale; the association pays iSmile later). Linked by the shared reference.
CREATE TABLE IF NOT EXISTS association_guests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref         CHAR(12)     NOT NULL,
  association VARCHAR(80)  NOT NULL DEFAULT 'Slemani Association',
  added_by    INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_association_guest_ref (ref),
  CONSTRAINT fk_association_guest_adder FOREIGN KEY (added_by) REFERENCES admin_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
