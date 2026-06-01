# Database Migrations

## Purpose

This repo supports tracked, forward-only SQL migrations for schema changes made after the migration system was introduced.

The live database existed before the migration history was fully represented in `/migrations`, so the repo keeps two related records:

- `migrations/*.sql` contains new forward-only migration files.
- `config/schema.sql` is the exported current-state schema snapshot.

The migration runner is intentionally strict for file-backed migrations: once a migration has been applied, editing its SQL file will be reported as drift.

## Commands

Check the current migration state:

```bash
php scripts/admin/migrate.php status
```

Apply pending migrations:

```bash
php scripts/admin/migrate.php migrate
```

Create a new migration file:

```bash
php scripts/admin/new_migration.php add_index_on_transactions
```

After applying schema changes, export the live schema snapshot:

```bash
scripts/admin/export_schema.sh
```

## Existing Live Database Setup

For a database that already has application tables, create or identify a baseline migration and record it once:

```bash
php scripts/admin/migrate.php baseline 20260415_000000_baseline_current_schema.sql
```

Do not edit migration files after they have been applied. Add a new migration instead.

## Acknowledged Legacy History

The live `schema_migrations` table contains three historical rows that were recorded before the corresponding migration files were committed to the repo:

- `20260415_000000`
- `20260417_100000`
- `20260418_100000`

These versions are explicitly acknowledged by the migration runner as legacy applied history. They are not treated as drift when no matching file exists in `/migrations`.

This exception is deliberately narrow. Any non-legacy applied migration still requires a matching migration file with the same checksum.

## Normal Change Process

1. Create a migration with `scripts/admin/new_migration.php`.
2. Write forward-only SQL in the generated file.
3. Run `php scripts/admin/migrate.php status`.
4. Run `php scripts/admin/migrate.php migrate`.
5. Run `scripts/admin/export_schema.sh`.
6. Commit both the migration file and `config/schema.sql`.
