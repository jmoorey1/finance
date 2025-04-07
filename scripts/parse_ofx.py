#!/usr/bin/env python3

import sys
import os
import mysql.connector
from ofxparse import OfxParser

# ---------- CONFIG ----------
DB_CONFIG = {
    'host': 'localhost',
    'user': 'john',
    'password': 'Thebluemole01',  # <-- replace this securely
    'database': 'accounts'
}

# ---------- INPUT VALIDATION ----------
if len(sys.argv) < 2:
    print("Usage: parse_ofx.py <path_to_ofx_file> [manual_account_id]")
    sys.exit(1)

ofx_path = sys.argv[1]
manual_account_id = int(sys.argv[2]) if len(sys.argv) > 2 and sys.argv[2].isdigit() else None

if not os.path.isfile(ofx_path):
    print(f"File not found: {ofx_path}")
    sys.exit(1)

# ---------- PARSE OFX ----------
with open(ofx_path, 'r', encoding='utf-8') as f:
    ofx = OfxParser.parse(f)

# ---------- CONNECT TO DB ----------
conn = mysql.connector.connect(**DB_CONFIG)
cursor = conn.cursor(dictionary=True)

inserted = 0
flagged = 0

for account in ofx.accounts:
    acct_id = account.account_id
    bank_id = getattr(account, 'routing_number', None)

    account_id = None

    if manual_account_id:
        account_id = manual_account_id
    else:
        query = "SELECT account_id FROM ofx_account_map WHERE acct_id = %s"
        params = [acct_id]
        if bank_id:
            query += " AND bank_id = %s"
            params.append(bank_id)
        cursor.execute(query, params)
        row = cursor.fetchone()
        if row:
            account_id = row['account_id']

    if not account_id:
        print(f"❌ Could not resolve account for ACCTID={acct_id}, BANKID={bank_id}")
        continue

    for txn in account.statement.transactions:
        date_str = txn.date.strftime('%Y-%m-%d')
        amount = txn.amount
        description = txn.payee
        memo = txn.memo or ''

        # Relaxed duplicate check: ignore description, allow for amount rounding
        match_id = None
        cursor.execute("""
            SELECT id FROM transactions
            WHERE account_id = %s AND date = %s AND ABS(amount - %s) < 0.01
            LIMIT 1
        """, (account_id, date_str, amount))
        match = cursor.fetchone()
        if match:
            match_id = match['id']

        cursor.execute("""
            SELECT id FROM staging_transactions
            WHERE account_id = %s AND date = %s AND ABS(amount - %s) < 0.01
            LIMIT 1
        """, (account_id, date_str, amount))
        if cursor.fetchone():
            match_id = match_id or -1

        status = 'duplicate' if match_id else 'new'
        matched_transaction_id = match_id if match_id and match_id > 0 else None

        cursor.execute("""
            INSERT INTO staging_transactions (
                account_id, date, description, amount,
                status, original_memo, matched_transaction_id
            )
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (
            account_id, date_str, description, amount,
            status, memo, matched_transaction_id
        ))

        if status == 'duplicate':
            flagged += 1
        else:
            inserted += 1

conn.commit()
cursor.close()
conn.close()

print(f"✅ Inserted: {inserted} new transactions.")
print(f"⚠️ Flagged as duplicates: {flagged}")
