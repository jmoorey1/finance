#!/usr/bin/env python3

import os
import re
import sys
from collections import Counter
from decimal import Decimal

import mysql.connector
from ofxparse import OfxParser

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

        if candidate.get("date") == txn_date and canonical_text(cand_desc) == canonical_text(description):
            continue

        if descriptions_similar(description, cand_desc):
            return candidate["id"]

    return None


def resolve_account_id(cursor, acct_id: str, bank_id, manual_account_id):
    if manual_account_id:
        return manual_account_id

    query = "SELECT account_id FROM ofx_account_map WHERE acct_id = %s"
    params = [acct_id]
    if bank_id:
        query += " AND bank_id = %s"
        params.append(bank_id)

    cursor.execute(query, params)
    result = cursor.fetchone()
    return result["account_id"] if result else None


def parse_account_rows(account_id: int, transactions):
    rows = []
    for txn in transactions:
        txn_date = txn.date.date()
        amount = normalize_amount(txn.amount)
        description = normalize_text(txn.payee or "")
        original_memo = normalize_text(txn.memo or "")

        rows.append(
            {
                "account_id": account_id,
                "txn_date": txn_date,
                "amount": amount,
                "description": description,
                "original_memo": original_memo,
                "exact_key": exact_key(account_id, txn_date, amount, description),
            }
        )

    return rows


if len(sys.argv) < 2:
    print("Usage: parse_ofx.py <path_to_ofx_file> [manual_account_id]")
    sys.exit(1)

ofx_path = sys.argv[1]
manual_account_id = int(sys.argv[2]) if len(sys.argv) > 2 and sys.argv[2].isdigit() else None

if not os.path.isfile(ofx_path):
    print(f"File not found: {ofx_path}")
    sys.exit(1)

with open(ofx_path, "r", encoding="utf-8") as f:
    ofx = OfxParser.parse(f)

conn = mysql.connector.connect(**DB_CONFIG)
cursor = conn.cursor(dictionary=True)

staged_new = 0
suppressed_exact = 0
potential = 0
predictions = 0
unresolved_accounts = 0

for account in ofx.accounts:
    acct_id = account.account_id
    bank_id = getattr(account, "routing_number", None)

    account_id = resolve_account_id(cursor, acct_id, bank_id, manual_account_id)

    if not account_id:
        print(f"❌ Could not resolve account for ACCTID={acct_id}, BANKID={bank_id}")
        unresolved_accounts += 1
        continue

    parsed_rows = parse_account_rows(account_id, account.statement.transactions)

    sample_rows = {}
    for row in parsed_rows:
        sample_rows.setdefault(row["exact_key"], row)

    exact_existing_counts = {}
    for key, row in sample_rows.items():
        exact_existing_counts[key] = count_exact_existing(
            cursor,
            row["account_id"],
            row["txn_date"],
            row["amount"],
            row["description"],
        )

    seen_upload_counts = Counter()
    used_predictions = set()

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
            cursor,
            row["account_id"],
            row["txn_date"],
            row["amount"],
            row["description"],
        )
        if potential_match_id:
            status = "potential_duplicate"
            matched_id = potential_match_id

        if status == "new":
            cursor.execute(
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
            candidates = cursor.fetchall()

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
                cursor.execute(
                    """
                    SELECT pi.id
                    FROM predicted_instances pi
                    JOIN predicted_transactions pt ON pi.predicted_transaction_id = pt.id
                    WHERE pi.from_account_id = %s
                      AND pi.description LIKE %s
                      AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                      AND COALESCE(pi.fulfilled, 0) IN (0, 2)
                    LIMIT 1
                    """,
                    (
                        row["account_id"],
                        f"%{row['description'][:5]}%",
                        row["txn_date"],
                    ),
                )
                prediction = cursor.fetchone()

                if prediction and prediction["id"] not in used_predictions:
                    predicted_instance_id = prediction["id"]
                    status = "fulfills_prediction"
                    used_predictions.add(predicted_instance_id)

        if status in ("new", "potential_duplicate", "fulfills_prediction"):
            cursor.execute(
                """
                INSERT INTO staging_transactions (
                    account_id, date, description, amount, status,
                    original_memo, matched_transaction_id, predicted_instance_id
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    row["account_id"],
                    row["txn_date"],
                    row["description"],
                    row["amount"],
                    status,
                    row["original_memo"],
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
cursor.close()
conn.close()

print(f"✅ Staged as new: {staged_new}")
print(f"⚡ Matches to predicted instances: {predictions}")
print(f"⚠️ Potential duplicates for review: {potential}")
print(f"♻️ Exact duplicates suppressed: {suppressed_exact}")
print(f"ℹ️ Unresolved OFX accounts skipped: {unresolved_accounts}")
