#!/usr/bin/env python3
"""
Create / update Home Finances BKL GitHub issues.

Why this exists:
- ChatGPT's GitHub connector can read the repo reliably, but issue creation
  was not returning reliable confirmations in-session.
- Running through the local `gh` CLI gives the repository owner a repeatable,
  visible, idempotent import path.
- This script creates one issue per BKL item and closes completed historical
  items so they can be added to a GitHub Project for traceability.

Requirements:
    gh auth login
    python3 scripts/admin/create_bkl_github_issues.py

Optional:
    GITHUB_REPO=jmoorey1/finance python3 scripts/admin/create_bkl_github_issues.py
    python3 scripts/admin/create_bkl_github_issues.py --dry-run
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from dataclasses import dataclass
from typing import Iterable


REPO = os.environ.get("GITHUB_REPO", "jmoorey1/finance")


@dataclass(frozen=True)
class BklIssue:
    bkl_id: str
    title: str
    state: str  # open | closed
    labels: tuple[str, ...]
    status: str
    context: str
    problem: str
    summary: str
    validation: str = ""
    notes: str = ""
    related: tuple[str, ...] = ()


LABELS: dict[str, tuple[str, str]] = {
    "bkl": ("7057ff", "Home Finances backlog item"),
    "historical": ("bfd4f2", "Imported historical work item"),
    "completed": ("0e8a16", "Completed item"),
    "open-backlog": ("fbca04", "Open backlog item"),
    "deferred": ("d4c5f9", "Deferred item"),
    "partial": ("f9d0c4", "Partially implemented item"),
    "epic": ("5319e7", "Epic or umbrella item"),
    "bug": ("d73a4a", "Bug or defect"),
    "feature": ("a2eeef", "Feature or enhancement"),
    "schema": ("1d76db", "Database schema or migration"),
    "transfer-model": ("0052cc", "Transfer model refactor"),
    "prediction": ("c5def5", "Prediction engine / predicted instances"),
    "dashboard": ("c2e0c6", "Dashboard / reporting"),
    "audit": ("fef2c0", "Audit or validation tooling"),
    "guardrail": ("fef2c0", "Guardrail / invariant validation"),
    "open-banking": ("006b75", "Open Banking ingestion"),
    "ai-watcher": ("6f42c1", "Finance watcher / analyst capability"),
    "hygiene": ("ededed", "Backlog or project hygiene"),
    "placeholder": ("cfd3d7", "Historical placeholder without enough implementation detail"),
}


def historical_unknown(bkl_id: str, state: str, status_note: str, labels: tuple[str, ...]) -> BklIssue:
    completed = state == "closed"
    return BklIssue(
        bkl_id=bkl_id,
        title=f"{bkl_id} — Historical backlog item ({status_note})",
        state=state,
        labels=labels,
        status=status_note,
        context=(
            "This item was recovered from prior Home Finances project conversation history, "
            "but the exact historical title/details were not available in the currently "
            "retrievable context."
        ),
        problem="Preserve the BKL number and status so the GitHub backlog reflects the known project chronology.",
        summary=(
            "Placeholder historical record. Do not treat this as a technical specification until "
            "the original conversation notes or implementation commits are re-linked."
        ),
        validation="N/A — historical placeholder.",
        notes=(
            "Known from prior project context: the earlier reconstructed backlog included BKL-001..031. "
            "Specific exceptions were identified: BKL-009 open, BKL-016 partial, BKL-018/BKL-019 open, "
            "BKL-022/BKL-023 deferred, and BKL-031 confirmed implemented."
        ),
    )


def issue_body(item: BklIssue) -> str:
    related = "\n".join(f"- {r}" for r in item.related) if item.related else "- None recorded"

    return f"""## Status
{item.status}

## Context
{item.context}

## Problem Statement
{item.problem}

## Implementation / Product Summary
{item.summary}

## Validation Performed
{item.validation or "Not recorded."}

## Related BKL Items
{related}

## Notes
{item.notes or "None."}

---
Imported into GitHub Issues from the Home Finances System BKL backlog.
"""


def run_gh(args: list[str], *, dry_run: bool = False, capture: bool = False, check: bool = True) -> subprocess.CompletedProcess[str]:
    cmd = ["gh", *args]
    if dry_run:
        print("[dry-run]", " ".join(cmd))
        return subprocess.CompletedProcess(cmd, 0, stdout="[]", stderr="")

    return subprocess.run(
        cmd,
        check=check,
        text=True,
        capture_output=capture,
    )


def ensure_gh_available() -> None:
    try:
        subprocess.run(["gh", "--version"], check=True, capture_output=True, text=True)
        subprocess.run(["gh", "auth", "status"], check=True, capture_output=True, text=True)
    except subprocess.CalledProcessError as exc:
        print("ERROR: GitHub CLI is not authenticated or not available.", file=sys.stderr)
        print("Run: gh auth login", file=sys.stderr)
        print(exc.stderr, file=sys.stderr)
        sys.exit(1)


def ensure_labels(dry_run: bool) -> None:
    print("Ensuring labels...")
    for name, (color, description) in LABELS.items():
        # `gh label create --force` updates if the label already exists.
        run_gh(
            [
                "label",
                "create",
                name,
                "--repo",
                REPO,
                "--color",
                color,
                "--description",
                description,
                "--force",
            ],
            dry_run=dry_run,
            check=False,
        )


def find_issue_number(bkl_id: str, dry_run: bool) -> int | None:
    if dry_run:
        return None

    result = run_gh(
        [
            "issue",
            "list",
            "--repo",
            REPO,
            "--state",
            "all",
            "--search",
            f'"{bkl_id}" in:title',
            "--limit",
            "100",
            "--json",
            "number,title,state",
        ],
        capture=True,
    )

    try:
        issues = json.loads(result.stdout)
    except json.JSONDecodeError:
        print(f"ERROR: Could not parse gh issue list output for {bkl_id}", file=sys.stderr)
        print(result.stdout, file=sys.stderr)
        raise

    for issue in issues:
        title = str(issue.get("title", ""))
        if title.startswith(f"{bkl_id} "):
            return int(issue["number"])

    return None


def set_labels(number: int, labels: Iterable[str], dry_run: bool) -> None:
    label_list = list(dict.fromkeys(labels))
    if not label_list:
        return

    for label in label_list:
        run_gh(
            [
                "issue",
                "edit",
                str(number),
                "--repo",
                REPO,
                "--add-label",
                label,
            ],
            dry_run=dry_run,
            check=False,
        )


def create_or_update_issue(item: BklIssue, dry_run: bool) -> int | None:
    labels = tuple(dict.fromkeys(("bkl", *item.labels)))
    body = issue_body(item)

    existing = find_issue_number(item.bkl_id, dry_run=dry_run)

    if existing is None:
        print(f"Creating {item.bkl_id}: {item.title}")
        args = [
            "issue",
            "create",
            "--repo",
            REPO,
            "--title",
            item.title,
            "--body",
            body,
        ]

        for label in labels:
            args.extend(["--label", label])

        result = run_gh(args, dry_run=dry_run, capture=True)

        if dry_run:
            number = None
        else:
            # gh usually prints the issue URL. Fetch the number from the URL tail.
            url = result.stdout.strip()
            try:
                number = int(url.rstrip("/").split("/")[-1])
            except ValueError:
                print(f"WARNING: Could not determine issue number from gh output: {url}", file=sys.stderr)
                number = find_issue_number(item.bkl_id, dry_run=False)
    else:
        number = existing
        print(f"Updating #{number} {item.bkl_id}: {item.title}")
        run_gh(
            [
                "issue",
                "edit",
                str(number),
                "--repo",
                REPO,
                "--title",
                item.title,
                "--body",
                body,
            ],
            dry_run=dry_run,
        )
        set_labels(number, labels, dry_run=dry_run)

    if number is not None:
        if item.state == "closed":
            print(f"Closing #{number} {item.bkl_id}")
            run_gh(
                [
                    "issue",
                    "close",
                    str(number),
                    "--repo",
                    REPO,
                    "--reason",
                    "completed",
                ],
                dry_run=dry_run,
                check=False,
            )
        elif item.state == "open":
            print(f"Ensuring open #{number} {item.bkl_id}")
            run_gh(
                [
                    "issue",
                    "reopen",
                    str(number),
                    "--repo",
                    REPO,
                ],
                dry_run=dry_run,
                check=False,
            )

    return number


def completed_item(
    bkl_id: str,
    title: str,
    labels: tuple[str, ...],
    context: str,
    problem: str,
    summary: str,
    validation: str,
    related: tuple[str, ...] = (),
    notes: str = "",
) -> BklIssue:
    return BklIssue(
        bkl_id=bkl_id,
        title=f"{bkl_id} — {title}",
        state="closed",
        labels=("historical", "completed", *labels),
        status="Completed.",
        context=context,
        problem=problem,
        summary=summary,
        validation=validation,
        related=related,
        notes=notes,
    )


def open_item(
    bkl_id: str,
    title: str,
    labels: tuple[str, ...],
    context: str,
    problem: str,
    summary: str,
    related: tuple[str, ...] = (),
    notes: str = "",
) -> BklIssue:
    return BklIssue(
        bkl_id=bkl_id,
        title=f"{bkl_id} — {title}",
        state="open",
        labels=("open-backlog", *labels),
        status="Open.",
        context=context,
        problem=problem,
        summary=summary,
        validation="Pending implementation.",
        related=related,
        notes=notes,
    )


def backlog_items() -> list[BklIssue]:
    items: list[BklIssue] = []

    # Earlier reconstructed backlog.
    completed_earlier = [
        "BKL-001", "BKL-002", "BKL-003", "BKL-004", "BKL-005", "BKL-006", "BKL-007", "BKL-008",
        "BKL-010", "BKL-011", "BKL-012", "BKL-013", "BKL-014", "BKL-015", "BKL-017",
        "BKL-020", "BKL-021", "BKL-024", "BKL-025", "BKL-026", "BKL-027", "BKL-028",
        "BKL-029", "BKL-030", "BKL-031",
    ]

    for bkl_id in completed_earlier:
        status_note = "completed"
        extra_labels = ("historical", "completed")
        if bkl_id == "BKL-031":
            status_note = "completed / confirmed implemented"
        items.append(historical_unknown(bkl_id, "closed", status_note, extra_labels))

    items.extend([
        historical_unknown("BKL-009", "open", "open / details to recover", ("historical", "open-backlog")),
        historical_unknown("BKL-016", "open", "partial / details to recover", ("historical", "partial")),
        historical_unknown("BKL-018", "open", "open / details to recover", ("historical", "open-backlog")),
        historical_unknown("BKL-019", "open", "open / details to recover", ("historical", "open-backlog")),
        historical_unknown("BKL-022", "open", "deferred / details to recover", ("historical", "deferred")),
        historical_unknown("BKL-023", "open", "deferred / details to recover", ("historical", "deferred")),
    ])

    # Open Banking / AI watcher items recovered as future candidate epics/items.
    for bkl_id in ["BKL-033", "BKL-034", "BKL-035", "BKL-036", "BKL-037"]:
        items.append(open_item(
            bkl_id,
            "Open Banking ingestion spike/build item",
            ("feature", "open-banking", "historical"),
            "Recovered from prior Home Finances project planning context.",
            "Assess and/or implement Open Banking ingestion so transaction intake can become less manual over time.",
            "Placeholder item for the Open Banking spike/build stream. Exact item breakdown should be refined before implementation.",
            notes="Prior context referenced BKL-033..037 as Open Banking spike/build backlog items.",
        ))

    # BKL-038 unknown gap.
    items.append(historical_unknown("BKL-038", "open", "unrecovered / needs triage", ("historical", "open-backlog")))

    for bkl_id in ["BKL-039", "BKL-040", "BKL-041", "BKL-042", "BKL-043", "BKL-044"]:
        items.append(open_item(
            bkl_id,
            "Finance watcher / analyst capability item",
            ("feature", "ai-watcher", "historical"),
            "Recovered from prior Home Finances project planning context.",
            "Develop the Finance Watcher / analyst capability so the system can identify unusual spending, forecast issues, or advisory insights.",
            "Placeholder item for the AI watcher stream. Exact item breakdown should be refined before implementation.",
            notes="Prior context referenced BKL-039..044 for the AI watcher / analyst stream.",
        ))

    # Recent completed items with known detail.
    items.extend([
        completed_item(
            "BKL-052",
            "Forecast view semantics",
            ("dashboard", "prediction"),
            "Implemented during the recent forecast/dashboard clean-up sequence.",
            "Forecast/committed values needed clearer semantics across views.",
            "Adjusted forecast view semantics so the dashboard reflects committed and forecast values more consistently.",
            "Implemented, smoke-tested, and committed before the GitHub issue migration.",
        ),
        completed_item(
            "BKL-055",
            "Decouple split transactions from category ID",
            ("schema",),
            "Split transactions historically depended on category_id 197 because that happened to be the next free imported Microsoft Money category ID.",
            "Using a specific category ID to mean split transaction was fragile and tied behaviour to historical import accident.",
            "Introduced an explicit split marker/model so split transaction behaviour no longer depends on category_id 197.",
            "Implemented, smoke-tested across review/edit/ledger flows, and committed.",
        ),
        completed_item(
            "BKL-056",
            "Transfer model refactor epic",
            ("epic", "transfer-model", "schema"),
            "Umbrella epic for replacing synthetic transfer categories with transfer group metadata and prediction type semantics.",
            "Transfers depended on fragile `Transfer To : ...` / `Transfer From : ...` category rows inherited from Microsoft Money.",
            "Completed the staged refactor from BKL-056A through BKL-056N.",
            "Final strict audit and guardrails passed; legacy transfer categories removed.",
            related=tuple(f"BKL-056{x}" for x in ["A", "B", "C", "D", "E", "F", "G", "H", "H.1", "I", "J", "K", "K.1", "L", "M", "N"]),
        ),
        completed_item(
            "BKL-056A",
            "Repair transfer group integrity",
            ("transfer-model", "schema"),
            "Pre-cleanup showed some transfer_group_id pairings did not net to zero.",
            "Some non-zero transfer group totals meant the transfer model could not safely become the source of truth.",
            "Added audit/repair tooling and corrected only `transactions.transfer_group_id` values where pairs were misgrouped.",
            "Confirmed remaining non-zero transfer groups: 0.",
            related=("BKL-056",),
        ),
        completed_item(
            "BKL-056B",
            "Add and backfill transfer group metadata",
            ("transfer-model", "schema"),
            "After transfer groups balanced, metadata was needed to describe from/to/amount/date at group level.",
            "The app needed transfer_groups to carry business meaning rather than relying on category names.",
            "Added and backfilled transfer group metadata including status, from_account_id, to_account_id, expected_amount, and transfer_date.",
            "Validation showed complete groups had metadata and valid accounting shape.",
            related=("BKL-056", "BKL-056A"),
        ),
        completed_item(
            "BKL-056C",
            "Populate transfer group metadata in creation flows",
            ("transfer-model",),
            "New transfer groups needed to be created with metadata, not as empty rows later inferred from categories.",
            "Creation flows still inserted raw transfer_groups without from/to metadata.",
            "Introduced shared helper logic and patched review/manual/reconciliation creation paths to populate metadata.",
            "PHP lint and review/import fixtures passed.",
            related=("BKL-056", "BKL-056B"),
        ),
        completed_item(
            "BKL-056D",
            "Render transfers from transfer group metadata",
            ("transfer-model", "feature"),
            "Once transfer_groups carried metadata, UI rendering should no longer need transfer category labels.",
            "Ledger/review display needed to show transfer direction/account text from metadata.",
            "Updated rendering logic to derive transfer display from transfer group metadata.",
            "Implemented, tested, and committed.",
            related=("BKL-056",),
        ),
        completed_item(
            "BKL-056E",
            "Stop assigning transfer categories to new transfers",
            ("transfer-model",),
            "New actual transfers still assigned legacy transfer categories even after metadata existed.",
            "Continuing to assign transfer categories would keep the old dependency alive.",
            "Stopped new transfer creation flows from assigning transfer categories.",
            "Implemented, tested, and committed.",
            related=("BKL-056",),
        ),
        completed_item(
            "BKL-056F",
            "Backfill historic transfer transaction categories to NULL",
            ("transfer-model", "schema"),
            "Historical transfer transactions still carried category_id values pointing at legacy transfer categories.",
            "Old category references would block final retirement of transfer categories.",
            "Backfilled transfer transaction category_id values to NULL where transfer_group metadata carried the model.",
            "Implemented, tested, and committed.",
            related=("BKL-056", "BKL-056E"),
        ),
        completed_item(
            "BKL-056G",
            "Add prediction_type to prediction model",
            ("prediction", "schema", "transfer-model"),
            "Predicted transfers still depended on transfer categories to identify transfer behaviour.",
            "Prediction category type was overloaded; transfers needed explicit prediction semantics.",
            "Added `prediction_type` to prediction rules and instances, backfilled from category type, and patched prediction/reconciliation flows.",
            "Validation confirmed no prediction/category type mismatches and open transfer instances had to_account_id.",
            related=("BKL-056",),
        ),
        completed_item(
            "BKL-056H",
            "Use rule type for prediction rule UI",
            ("prediction", "feature"),
            "Prediction rule UI still exposed transfer categories after prediction_type existed.",
            "Users should choose rule type rather than synthetic transfer category rows.",
            "Updated prediction rule UI to use Rule Type and hide category for transfer rules.",
            "Implemented, tested, and committed.",
            related=("BKL-056G",),
        ),
        completed_item(
            "BKL-056H.1",
            "Align predicted instance transfer display",
            ("prediction", "feature"),
            "Predicted instance rows still showed legacy transfer category text while rules showed Transfer markers.",
            "UI inconsistency made it look as if transfer categories were still active.",
            "Updated predicted instance display to show a Transfer badge for transfer predicted instances.",
            "Implemented, tested, and committed.",
            related=("BKL-056H",),
        ),
        completed_item(
            "BKL-056I",
            "Make transfer predictions category-free",
            ("prediction", "schema", "transfer-model"),
            "Prediction tables still required category_id, forcing compatibility categories for transfer predictions.",
            "Transfer predictions should be modelled by prediction_type/from/to accounts, not category rows.",
            "Made prediction category_id nullable for transfer rules/instances and removed category dependency from repayment prediction generation.",
            "Post-migration validation confirmed transfer predictions carried NULL category_id and income/expense predictions retained categories.",
            related=("BKL-056G", "BKL-056H"),
        ),
        completed_item(
            "BKL-056J",
            "Deprecate transfer categories in maintenance",
            ("transfer-model", "feature"),
            "Transfer categories were still visible/editable in category maintenance.",
            "Users could still maintain legacy transfer categories even though new flows no longer used them.",
            "Made transfer categories read-only legacy artefacts in category maintenance.",
            "Implemented and committed.",
            related=("BKL-056I",),
        ),
        completed_item(
            "BKL-056K",
            "Add transfer category reference audit",
            ("audit", "transfer-model"),
            "Before deleting transfer categories, the system needed proof they were not referenced by data or active code.",
            "Deletion without evidence could break reports, imports, or predictions.",
            "Added strict audit script for transfer-category data and source references.",
            "Strict audit eventually passed after classifier refinements.",
            related=("BKL-056J",),
        ),
        completed_item(
            "BKL-056K.1",
            "Remove active transfer category dependencies",
            ("transfer-model",),
            "Strict audit found active account/finalize-reconciliation paths still creating or depending on transfer categories.",
            "Account creation/edit and card statement finalisation could recreate category dependencies.",
            "Removed active transfer-category creation/maintenance paths and updated reconciliation to create transfer predictions category-free.",
            "Guardrails and strict audit passed.",
            related=("BKL-056K",),
        ),
        completed_item(
            "BKL-056L",
            "Add transfer model guardrails",
            ("guardrail", "audit", "transfer-model"),
            "After model changes, permanent regression checks were needed.",
            "Future imports or repairs could reintroduce transfer-category dependencies or invalid transfer groups.",
            "Added `scripts/admin/validate_transfer_model_guardrails.php`.",
            "Guardrails passed and were committed.",
            related=("BKL-056K.1",),
        ),
        completed_item(
            "BKL-056M",
            "Retire legacy transfer categories",
            ("schema", "transfer-model"),
            "Strict audit and guardrails proved legacy transfer category rows were unused.",
            "Rows could be safely removed once FK references were proven absent.",
            "Added migration to delete legacy transfer category rows and exported schema.",
            "Post-migration validation showed remaining transfer category rows: 0; guardrails and strict audit passed.",
            related=("BKL-056L",),
        ),
        completed_item(
            "BKL-056N",
            "Remove dead transfer category UI",
            ("transfer-model", "feature"),
            "After deleting transfer category rows, legacy read-only UI/code was dead.",
            "Category maintenance still contained legacy transfer-category display/edit branches.",
            "Removed dead legacy transfer-category UI/code from category maintenance and audit classifier.",
            "Implemented, smoke-tested, guardrails/audit passed, and committed.",
            related=("BKL-056M",),
        ),
        completed_item(
            "BKL-057",
            "Include missed open predictions in committed views",
            ("bug", "dashboard", "prediction"),
            "dashboard.php, dashboard_ytd.php and ledger.php excluded missed open predicted_instances.",
            "Missed predictions are still open liabilities/expected cash movements and should contribute to committed totals and variance.",
            "Removed future-only filters so open missed predictions within the selected period are included.",
            "Implemented, tested, and committed.",
        ),
    ])

    # Suggested open backlog after the transfer chain.
    items.extend([
        open_item(
            "BKL-058",
            "Add backlog/project hygiene and issue templates",
            ("hygiene", "feature"),
            "The project is moving BKL history into GitHub Issues / GitHub Projects.",
            "Backlog discipline will degrade without standard issue templates, labels, and operating conventions.",
            "Add issue templates and backlog labels for bugs, schema work, guardrails, features, and historical imports.",
        ),
        open_item(
            "BKL-059",
            "Add dashboard data freshness indicators",
            ("dashboard", "feature"),
            "The household finance views need to communicate how current/imported/reviewed the data is.",
            "Dashboard figures are only trustworthy if the user can see whether account data is stale.",
            "Show upload/review freshness by account and surface stale-data warnings on dashboard pages.",
        ),
        open_item(
            "BKL-060",
            "Email dashboard summary on schedule",
            ("dashboard", "feature"),
            "The dashboard should be shared regularly without requiring manual login.",
            "John and India want recurring visibility of monthly finance position.",
            "Create a scheduled email summary using dashboard.php data, with logging and dry-run support.",
        ),
        open_item(
            "BKL-061",
            "Improve prediction resolution workflow for missed items",
            ("prediction", "feature"),
            "BKL-057 made missed open predictions visible; next step is operational handling.",
            "Users need a clear workflow for resolving, skipping, fulfilling, or rescheduling missed predicted_instances.",
            "Improve UI/actions around missed predictions so they do not remain indefinitely open without review.",
            related=("BKL-057",),
        ),
        open_item(
            "BKL-062",
            "Add admin validation runner / health-check dashboard",
            ("guardrail", "audit", "feature"),
            "Several admin validation scripts now exist, including transfer model guardrails and strict audits.",
            "Running validations manually is easy to forget.",
            "Create an admin health-check page or runner that executes key validation scripts and reports pass/fail status.",
            related=("BKL-056L",),
        ),
    ])

    placeholder_without_detail_ids = {
        "BKL-009", "BKL-016", "BKL-018", "BKL-019", "BKL-022", "BKL-023",
        "BKL-033", "BKL-034", "BKL-035", "BKL-036", "BKL-037", "BKL-038",
        "BKL-039", "BKL-040", "BKL-041", "BKL-042", "BKL-043", "BKL-044",
    }

    normalised_items: list[BklIssue] = []
    for item in items:
        if item.bkl_id not in placeholder_without_detail_ids:
            normalised_items.append(item)
            continue

        labels = tuple(dict.fromkeys((
            "historical",
            "placeholder",
            *[label for label in item.labels if label not in ("open-backlog", "deferred", "partial", "completed")],
        )))

        notes = item.notes
        if notes:
            notes += "\n\n"
        notes += (
            "Closed during backlog hygiene because this issue was only a recovered placeholder "
            "without enough implementation detail to act on. Reopen only if the original detail is recovered "
            "or a fresh actionable specification is written."
        )

        normalised_items.append(BklIssue(
            bkl_id=item.bkl_id,
            title=item.title,
            state="closed",
            labels=labels,
            status="Closed — placeholder without sufficient implementation detail.",
            context=item.context,
            problem=item.problem,
            summary=item.summary,
            validation=item.validation,
            notes=notes,
            related=item.related,
        ))

    return normalised_items


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true", help="Print gh commands without creating/updating issues.")
    parser.add_argument("--skip-labels", action="store_true", help="Do not create/update labels.")
    args = parser.parse_args()

    if not args.dry_run:
        ensure_gh_available()

    print(f"Repository: {REPO}")

    if not args.skip_labels:
        ensure_labels(dry_run=args.dry_run)

    created_or_updated: list[tuple[str, int | None]] = []

    for item in backlog_items():
        number = create_or_update_issue(item, dry_run=args.dry_run)
        created_or_updated.append((item.bkl_id, number))

    print("\nSummary")
    print("-------")
    for bkl_id, number in created_or_updated:
        if number is None:
            print(f"{bkl_id}: dry-run / number unavailable")
        else:
            print(f"{bkl_id}: #{number}")

    print("\nDone.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
