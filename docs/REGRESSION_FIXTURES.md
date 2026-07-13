# Regression Fixtures

This repo includes a small DB-free fixture pack for the Review and import workflows. It is intended to protect the high-risk paths that have recently been hardened without needing live Review rows or example bank uploads.

## Run The Checks

From the application root:

```bash
php8.2 scripts/tests/run_review_import_fixture_checks.php
```

The runner only reads files from the working tree. It does not connect to MySQL, insert rows, delete rows, or call the upload/review endpoints.

## Fixtures

- `tests/fixtures/review/review_workflows.json` documents the critical Review cases and the source guardrails they depend on.
- `tests/fixtures/import/credit_card_sample.csv` is a synthetic CSV upload with one repaired merchant field, one credit row, one non-billed row, and one malformed amount.
- `tests/fixtures/import/credit_card_sample.expected.json` records the expected parse summary for that CSV fixture.

## Current Scope

The checks deliberately stay lightweight:

- Review fixtures validate the case definitions and assert that the production Review action still contains the guardrails for duplicate confirmation, regular categorisation, split categorisation, manual transfers, transfer placeholder reuse, and predicted transfer fulfillment.
- The transfer placeholder reuse fixture verifies the important semantics: the uploaded transaction and placeholder use the same account and signed amount, the existing partial group is reused, and the completed result contains two real transactions with no placeholder.
- Import fixtures parse the synthetic CSV fixture and assert the expected billed/repaired/malformed/non-billed counts and signed amounts.
- Source assertions support both `contains` and `not_contains`, making removal of required guardrails—or reintroduction of known-bad source patterns—noisy during a manual server check.
- A bug fix in an already-covered Review or import workflow must add or update a regression case. Merely running the existing suite is not evidence that the newly fixed defect is covered.

## Limits

This is not a full database replay suite yet. The next step would be a disposable test database runner that loads a tiny schema/data seed, executes the Review actions against that database, and rolls the whole thing away after each case.
