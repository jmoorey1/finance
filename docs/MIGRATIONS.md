# Database Migrations

## Purpose

This repo now supports **tracked, forward-only SQL migrations**.

This was introduced as a **baseline migration system** for an existing live database.  
It does **not** attempt to replay the whole historic schema build from scratch.

`config/schema.sql` remains the **exported current-state schema snapshot**.

---

## Files

- `migrations/*.sql` — forward-only migration files
- `scripts/admin/migrate.php` — migration runner
- `scripts/admin/new_migration.php` — helper to create a new migration file
- `scripts/admin/export_schema.sh` — export the live schema back to `config/schema.sql`

---

## First-time setup on the existing live database

Check status:

```bash
php scripts/admin/migrate.php status
