# Database upgrades

`../schema.sql` is always the complete, current design. A new database is
built from it alone.

Once the live database holds real registrations, a change to the design needs
**two** edits:

1. Change `schema.sql`, so new databases get the new design.
2. Add a file here that makes the same change to an existing database, named
   with the date and a short description, for example
   `2026-10-05-add-dietary-note.sql`:

   ```sql
   ALTER TABLE registrations ADD COLUMN dietary_note VARCHAR(120) NULL AFTER lunch_day2;
   ```

`php tools/install.php` (run by the deploy script) applies each file here once,
in name order, and records it in the `schema_migrations` table. Never edit or
rename a file after it has been applied on the live site; add a new one.

Always run the upgrade on the test site first, then take a backup of the live
database (Admin → it is also done nightly), then deploy to live.
