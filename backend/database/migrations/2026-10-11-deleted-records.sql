-- A copy of every record the main Owner deletes, so a mistake can be undone by hand.
CREATE TABLE IF NOT EXISTS deleted_records (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind        ENUM('registration','sponsor','booth') NOT NULL,
  ref         VARCHAR(20)   NOT NULL,
  label       VARCHAR(190)  NOT NULL,
  data        MEDIUMTEXT    NOT NULL,
  deleted_by  INT UNSIGNED  NULL,
  deleted_at  DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY k_deleted_at (deleted_at),
  CONSTRAINT fk_deleted_by FOREIGN KEY (deleted_by) REFERENCES admin_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Copies of deleted records';
