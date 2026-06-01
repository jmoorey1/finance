# Change Implementation Operating Procedure

## Purpose

This procedure defines the standard way to implement, verify, and land changes in the Home Finances System.

The goals are to:

- keep every change reversible while it is in progress
- make the implementation path repeatable for someone joining the project cold
- ensure testing happens before cleanup and commit
- include migration governance for schema changes
- keep temporary patch artefacts out of GitHub

This process should be followed for all non-trivial changes.

---

## Standard Workflow

### 1. Back up the files you are about to change

Before touching any file, create backups for every source file that will be modified.

Use a clear suffix tied to the backlog item or bugfix, for example:

- `.bak_bkl_051a`
- `.bak_bugfix_cash_planner`

Backups are temporary working safeguards only. They are never committed.

---

### 2. Name the patch script and create it

Create a patch script in:

```bash
/var/www/html/finance/scripts/admin/
```

Name it clearly and specifically.

Good examples:

- `apply_bkl_048_patch.sh`
- `apply_cash_planner_late_today_fix.sh`
- `apply_projects_split_project_fix.sh`

Bad examples:

- `fix.sh`
- `patch1.sh`
- `temp_update.sh`

Open the file in a text editor and write the full patch content into it.

The patch script should be deterministic. Someone else should be able to run it and get the same result.

---

### 3. Write the patch script properly

Patch scripts should start with:

```bash
#!/usr/bin/env bash
set -euo pipefail
```

The script should:

- target the correct project root
- update only the intended files
- fail loudly if expected source text is not found
- print clear output showing what changed
- avoid unrelated edits

For content replacements, prefer exact-match replacements that fail if the expected block is missing. Silent partial replacements are not acceptable.

If the change affects live SQL views or schema-dependent behaviour, include the necessary database-side step deliberately and explicitly.

---

### 4. For schema changes, create a migration and run the migration runner

If the change alters schema, views, constraints, indexes, or any database object tracked through governance, the patch must also create a migration file under `/migrations` and run the migration process.

Minimum required steps:

1. write the new migration file into `/migrations`
2. run migration status first:

```bash
php scripts/admin/migrate.php status
```

3. apply pending migrations:

```bash
php scripts/admin/migrate.php migrate
```

4. export schema so `config/schema.sql` reflects the new state

The migration runner is CLI-only and supports `status`, `baseline`, and `migrate`. It refuses to run if migration drift or out-of-order migration files are detected, and it expects the schema export to be refreshed after migration. The exact operational commands are documented in `scripts/admin/migrate.php`.

Do not make schema-only changes directly in `config/schema.sql` and stop there. `config/schema.sql` is the post-migration source-of-truth export, not the governance mechanism itself.

---

### 5. Run the patch script

Make the script executable and run it:

```bash
chmod +x /var/www/html/finance/scripts/admin/<script_name>.sh
bash /var/www/html/finance/scripts/admin/<script_name>.sh
```

Read the output carefully.

If the script partially succeeds and then fails:

- stop immediately
- inspect what already changed
- do not continue blindly
- either repair the partial state with a tightly scoped follow-up patch, or restore from the backups and start again

---

### 6. Lint every relevant file

After the patch runs, lint every affected PHP file.

Typical command:

```bash
php -l /var/www/html/finance/path/to/file.php
```

Lint all changed PHP files, not just the obvious entry page.

If shell or JavaScript logic changed materially, do an appropriate syntax sanity check there too.

Linting is required before functional testing begins.

---

### 7. Run the regression fixture checks where relevant

This repo now includes a lightweight DB-free regression fixture pack for the Review and import workflows.

Run the fixture checks from the application root when the change touches:

- Review workflow logic
- import parsing or upload handling
- duplicate confirmation or categorisation guardrails
- predicted transfer fulfilment guardrails
- any area covered by the current fixture pack

Command:

```bash
php8.2 scripts/tests/run_review_import_fixture_checks.php
```

These checks:

- read only from the working tree
- do not connect to MySQL
- do not insert or delete rows
- do not call the upload or review endpoints

Current fixture coverage is documented in `docs/REGRESSION_FIXTURES.md`, including:

- `tests/fixtures/review/review_workflows.json`
- `tests/fixtures/import/credit_card_sample.csv`
- `tests/fixtures/import/credit_card_sample.expected.json`

If the change is outside that scope, say so explicitly and continue with manual testing. Do not pretend the fixture suite covers things it does not.

---

### 8. Perform functional testing

After linting passes, test the actual feature.

Testing should include:

- the primary success path
- at least one realistic user path
- at least one edge case
- any linked pages, rollups, or filters affected by the change
- any totals, dates, project/earmark attribution, or drill-through links affected by the change
- any database persistence or reporting implications

Testing may be manual, automated, or both.

Where useful, write the checks out explicitly, for example:

- open page X
- perform action Y
- confirm row Z appears
- verify total T matches the database
- confirm the generated ledger link date range is correct
- confirm edited data survives refresh and re-query

If the change affects SQL-backed reporting or a database view, validate at least one result directly in MySQL.

---

### 9. Keep patch artefacts until sign-off

Do not clean up the patch script or backups immediately after the page appears to work.

Cleanup happens only after the change is explicitly signed off or marked complete.

Until then, keep:

- the patch script
- the backup files
- any temporary implementation support needed to recover quickly

---

### 10. Clean up after sign-off

Once signed off:

- remove the backup files created for that change
- remove the patch script(s) created for that change
- remove any other temporary implementation artefacts that should not live in the repo

Also restore runtime or environment-specific files that should not be committed, for example:

- `logs/predict_instances_state.json`

Only intentional code, config, migration, and schema-export changes should remain.

---

### 11. Review the working tree

Run:

```bash
git status
```

Check that only the intended real changes remain.

Then review the staged diff/stat before commit:

```bash
git diff --cached --stat
```

This is the point to catch:

- accidental edits
- runtime state changes
- leftover backup files
- leftover patch scripts
- unrelated modifications

---

### 12. Commit with a precise message

Use a commit message that reflects the actual implemented change.

Examples:

- `BKL-048 Add category UI for watcher treatment settings`
- `BKL-049 Add funding explainer page for month-level shortfall analysis`
- `Fix cash planner to include late and same-day predicted events`

Avoid vague commit messages such as:

- `updates`
- `misc fixes`
- `more changes`

---

### 13. Push to GitHub

After commit:

```bash
git push
```

Only push once the tree is clean and the change has passed the required checks.

---

## Required Decision Points

### When do fixture checks apply?

Run them whenever the change touches the currently covered Review/import paths. If the change does not touch those paths, record that the fixture pack is not applicable and continue with targeted manual testing.

### When is a migration required?

A migration is required whenever the change affects database structure or governed SQL objects, including:

- tables
- columns
- indexes
- constraints
- views
- governed schema objects represented in migrations

If in doubt, bias toward creating a migration.

### When is direct SQL validation required?

Direct MySQL validation is required whenever the change affects:

- reporting totals
- date windows
- project/earmark attribution
- split transaction behaviour
- view-driven filtering
- watcher or explainer outputs sourced from SQL views or rollups

---

## File Hygiene Rules

### Backups

Backups are temporary local safety nets. Never commit them.

### Patch scripts

Patch scripts are implementation tools, not product code. Remove them after sign-off unless there is a deliberate reason to retain one permanently.

### Runtime files

Runtime or generated state files are not source code. Restore or exclude them before commit.

### Migrations and schema export

For schema changes, both are required:

- a governed migration file in `/migrations`
- an updated `config/schema.sql` export after migration

One without the other is incomplete.

---

## Quality Bar

A change is not complete because a page loads.

A change is complete only when all of the following are true:

- backups were taken before modification
- the patch script applied cleanly
- required migrations were created and applied where relevant
- schema export was refreshed where relevant
- syntax/lint checks passed
- regression fixture checks were run where relevant
- functional testing passed
- linked surfaces were checked
- database validation was performed where relevant
- temporary artefacts were removed after sign-off
- the Git working tree is clean apart from intended source changes
- the commit message is accurate
- the code has been pushed to GitHub

---

## Quick Reference

```bash
1. backup files
2. create patch script in scripts/admin/
3. write patch content
4. if schema change:
   - create migration file
   - php scripts/admin/migrate.php status
   - php scripts/admin/migrate.php migrate
   - export schema
5. run patch script
6. php -l all changed PHP files
7. run fixture checks where relevant:
   - php8.2 scripts/tests/run_review_import_fixture_checks.php
8. perform manual / SQL validation tests
9. once signed off:
   - remove backups
   - remove patch scripts
   - restore runtime/state files
   - git add intended files
   - git commit with accurate message
   - git push
```

---

## Notes For Someone Joining Cold

- never edit live source first without taking backups
- never rely on a patch script that makes silent replacements
- never skip linting
- never skip migrations for schema-governed changes
- never assume the fixture pack covers more than `docs/REGRESSION_FIXTURES.md` says it covers
- never commit backup files or patch scripts
- never trust UI success alone for reporting, rollup, or attribution changes
- never treat `config/schema.sql` as a substitute for a migration file

This system has a lot of linked reporting and view logic. Small changes can affect dashboards, ledger filters, watcher outputs, funding views, project rollups, and split attribution. Work methodically and verify the real data path before sign-off.
