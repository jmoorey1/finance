#!/usr/bin/env python3

import csv
import os
import re
import sys
from collections import Counter
from datetime import datetime
from decimal import Decimal, InvalidOperation

import mysql.connector

from finance_env import get_db_config

DB_CONFIG = get_db_config()


def normalize_text(value) -> str:
    return (value or "").strip()


def canonical_text(value) -> str:
    s = normalize_text(value).lower()
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


def normalize_amount(value) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.01"))


def exact_key(account_id: int, txn_date, amount: Decimal, description: str):
    return (
        str(account_id),
        txn_date.isoformat(),
        format(normalize_amount(amount), "f"),
        canonical_text(description),
    )


def descriptions_similar(a: str, b: str) -> bool:
    ca = canonical_text(a)
    cb = canonical_text(b)

    if not ca or not cb:
        return False

    if ca == cb:
        return True

    min_prefix = 8
    if len(ca) >= min_prefix and len(cb) >= min_prefix and ca[:min_prefix] == cb[:min_prefix]:
        return True

    shorter = ca if len(ca) <= len(cb) else cb
    longer = cb if len(ca) <= len(cb) else ca
    if len(shorter) >= 8 and shorter in longer:
        return True

    return False


def count_exact_existing(cursor, account_id: int, txn_date, amount: Decimal, description: str) -> int:
    wanted_desc = canonical_text(description)

    cursor.execute(
        """
        SELECT description
        FROM transactions
        WHERE account_id = %s
          AND date = %s
          AND ABS(amount - %s) < 0.01
        """,
        (account_id, txn_date, amount),
    )
    tx_count = sum(1 for row in cursor.fetchall() if canonical_text(row.get("description")) == wanted_desc)

    cursor.execute(
        """
        SELECT description
        FROM staging_transactions
        WHERE account_id = %s
          AND date = %s
          AND ABS(amount - %s) < 0.01
        """,
        (account_id, txn_date, amount),
    )
    staging_count = sum(1 for row in cursor.fetchall() if canonical_text(row.get("description")) == wanted_desc)

    return tx_count + staging_count


def find_potential_duplicate(cursor, account_id: int, txn_date, amount: Decimal, description: str):
    cursor.execute(
        """
        SELECT id, date, description
        FROM transactions
        WHERE account_id = %s
          AND ABS(amount - %s) < 0.01
          AND ABS(DATEDIFF(date, %s)) <= 3
        ORDER BY ABS(DATEDIFF(date, %s)), id
        LIMIT 25
        """,
        (account_id, amount, txn_date, txn_date),
    )
    candidates = cursor.fetchall()

    for candidate in candidates:
        cand_desc = candidate.get("description") or ""

        # Exact same normalized description + same date should already have been suppressed
        if candidate.get("date") == txn_date and canonical_text(cand_desc) == canonical_text(description):
            continue

        if descriptions_similar(description, cand_desc):
            return candidate["id"]

    return None


def load_csv_rows(csv_path: str, account_id: int):
    parsed_rows = []
    repaired_rows = 0
    skipped_malformed = 0
    skipped_non_billed = 0

    with open(csv_path, newline="", encoding="utf-8-sig") as csvfile:
        reader = csv.reader(csvfile)

        try:
            header = next(reader)
        except StopIteration:
            return parsed_rows, repaired_rows, skipped_malformed, skipped_non_billed

        header = [normalize_text(h) for h in header]
        expected_len = len(header)
        merchant_idx = header.index("Merchant") if "Merchant" in header else None

        for line_no, raw_row in enumerate(reader, start=2):
            if not raw_row or all(not str(cell).strip() for cell in raw_row):
                continue

            row_values = list(raw_row)
            repaired_this_row = False

            if len(row_values) > expected_len:
                if merchant_idx is None:
                    print(f"⚠️ Skipping malformed CSV row {line_no}: too many columns and no Merchant field to repair")
                    skipped_malformed += 1
                    continue

                extra_cols = len(row_values) - expected_len
                row_values = (
                    row_values[:merchant_idx]
                    + [",".join(row_values[merchant_idx : merchant_idx + extra_cols + 1])]
                    + row_values[merchant_idx + extra_cols + 1 :]
                )
                repaired_this_row = True

            if len(row_values) != expected_len:
                print(
                    f"⚠️ Skipping malformed CSV row {line_no}: expected {expected_len} columns, found {len(raw_row)}"
                )
                skipped_malformed += 1
                continue

            row = {header[i]: normalize_text(row_values[i]) for i in range(expected_len)}

            if row.get("Status", "").upper() != "BILLED":
                skipped_non_billed += 1
                continue

            try:
                txn_date = datetime.strptime(row["Transaction Date"], "%Y-%m-%d").date()
            except (ValueError, KeyError):
                print(f"⚠️ Invalid date on CSV row {line_no}: {row.get('Transaction Date')}")
                skipped_malformed += 1
                continue

            try:
                raw_amount = normalize_amount(row["Billing Amount"])
            except (InvalidOperation, KeyError, TypeError):
                print(f"⚠️ Invalid amount on CSV row {line_no}: {row.get('Billing Amount')}")
                skipped_malformed += 1
                continue

            txn_type = row.get("Debit or Credit", "").upper()
            amount = raw_amount if txn_type == "CRDT" else -raw_amount

            description = row.get("Merchant", "")
            original_memo = ", ".join(
                [
                    row.get("Merchant City", ""),
                    row.get("Merchant State", ""),
                    row.get("Merchant Postcode", ""),
                    row.get("Card Used", ""),
                ]
            ).strip(", ")

            parsed_rows.append(
                {
                    "account_id": account_id,
                    "txn_date": txn_date,
                    "amount": amount,
                    "description": description,
                    "raw_description": description,
                    "original_memo": original_memo,
                    "exact_key": exact_key(account_id, txn_date, amount, description),
                }
            )

            if repaired_this_row:
                repaired_rows += 1

    return parsed_rows, repaired_rows, skipped_malformed, skipped_non_billed


if len(sys.argv) < 3:
    print("Usage: parse_csv.py <path_to_csv> <account_id>")
    sys.exit(1)

csv_path = sys.argv[1]
account_id = int(sys.argv[2])

if not os.path.exists(csv_path):
    print(f"❌ CSV file not found: {csv_path}")
    sys.exit(1)

conn = mysql.connector.connect(**DB_CONFIG)
insert_cursor = conn.cursor()
select_cursor = conn.cursor(dictionary=True)

parsed_rows, repaired_rows, skipped_malformed, skipped_non_billed = load_csv_rows(csv_path, account_id)

exact_upload_counts = Counter(row["exact_key"] for row in parsed_rows)
sample_rows = {}
for row in parsed_rows:
    sample_rows.setdefault(row["exact_key"], row)

exact_existing_counts = {}
for key, row in sample_rows.items():
    exact_existing_counts[key] = count_exact_existing(
        select_cursor,
        row["account_id"],
        row["txn_date"],
        row["amount"],
        row["description"],
    )

seen_upload_counts = Counter()
used_predictions = set()

staged_new = 0
predictions = 0
potential = 0
suppressed_exact = 0

for row in parsed_rows:
    key = row["exact_key"]
    seen_upload_counts[key] += 1

    if seen_upload_counts[key] <= exact_existing_counts.get(key, 0):
        suppressed_exact += 1
        continue

    status = "new"
    matched_id = None
    predicted_instance_id = None

    potential_match_id = find_potential_duplicate(
        select_cursor,
        row["account_id"],
        row["txn_date"],
        row["amount"],
        row["description"],
    )
    if potential_match_id:
        status = "potential_duplicate"
        matched_id = potential_match_id

    if status == "new":
        select_cursor.execute(
            """
            SELECT pi.id, pi.from_account_id, pi.to_account_id, pi.amount, c.type AS cat_type
            FROM predicted_instances pi
            JOIN categories c ON pi.category_id = c.id
            WHERE (pi.from_account_id = %s OR pi.to_account_id = %s)
              AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
              AND COALESCE(pi.fulfilled, 0) IN (0, 2)
            """,
            (row["account_id"], row["account_id"], row["txn_date"]),
        )
        candidates = select_cursor.fetchall()

        for pred in candidates:
            pred_id = pred["id"]
            if pred_id in used_predictions:
                continue

            if pred.get("amount") is None:
                continue

            pred_amt = normalize_amount(pred["amount"])
            from_id = pred["from_account_id"]
            to_id = pred["to_account_id"]
            cat_type = pred["cat_type"]

            if cat_type == "transfer":
                if row["account_id"] == from_id and abs(row["amount"] + pred_amt) < Decimal("0.01"):
                    predicted_instance_id = pred_id
                    status = "fulfills_prediction"
                    used_predictions.add(pred_id)
                    break
                if row["account_id"] == to_id and abs(row["amount"] - pred_amt) < Decimal("0.01"):
                    predicted_instance_id = pred_id
                    status = "fulfills_prediction"
                    used_predictions.add(pred_id)
                    break
            else:
                if row["account_id"] == from_id and abs(row["amount"] - pred_amt) < Decimal("0.01"):
                    predicted_instance_id = pred_id
                    status = "fulfills_prediction"
                    used_predictions.add(pred_id)
                    break

        if status == "new" and row["description"]:
            select_cursor.execute(
                """
                SELECT pi.id
                FROM predicted_instances pi
                JOIN predicted_transactions pt ON pi.predicted_transaction_id = pt.id
                WHERE (pi.from_account_id = %s OR pi.to_account_id = %s)
                  AND pi.description LIKE %s
                  AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                  AND COALESCE(pi.fulfilled, 0) IN (0, 2)
                LIMIT 1
                """,
                (
                    row["account_id"],
                    row["account_id"],
                    f"%{row['description'][:5]}%",
                    row["txn_date"],
                ),
            )
            prediction = select_cursor.fetchone()

            if prediction and prediction["id"] not in used_predictions:
                predicted_instance_id = prediction["id"]
                status = "fulfills_prediction"
                used_predictions.add(predicted_instance_id)

    if status in ("new", "potential_duplicate", "fulfills_prediction"):
        insert_cursor.execute(
            """
            INSERT INTO staging_transactions (
                account_id, date, description, amount,
                raw_description, original_memo, status,
                matched_transaction_id, predicted_instance_id
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                row["account_id"],
                row["txn_date"],
                row["description"],
                row["amount"],
                row["raw_description"],
                row["original_memo"],
                status,
                matched_id,
                predicted_instance_id,
            ),
        )

        if status == "potential_duplicate":
            potential += 1
        elif status == "fulfills_prediction":
            predictions += 1
        else:
            staged_new += 1

conn.commit()
select_cursor.close()
insert_cursor.close()
conn.close()

print(f"📄 Billed rows parsed: {len(parsed_rows)}")
print(f"✅ Staged as new: {staged_new}")
print(f"⚡ Matches to predicted instances: {predictions}")
print(f"⚠️ Potential duplicates for review: {potential}")
print(f"♻️ Exact duplicates suppressed: {suppressed_exact}")
print(f"🛠 CSV rows repaired: {repaired_rows}")
print(f"⚠️ Malformed CSV rows skipped: {skipped_malformed}")
print(f"ℹ️ Non-billed CSV rows ignored: {skipped_non_billed}")
