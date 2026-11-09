ALTER TABLE sponsor_map_positions DROP CHECK ck_map_display_number,
 ADD CONSTRAINT ck_map_display_number CHECK (display_number BETWEEN 1 AND 40);
