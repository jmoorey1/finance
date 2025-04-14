#!/usr/bin/env python3

import sys
import os
import mysql.connector
from ofxparse import OfxParser
from datetime import timedelta

# ---------- CONFIG ----------
DB_CONFIG = {
    'host': 'localhost',
    'user': 'john',
    'password': 'Thebluemole01',  # secure this in production
    'database': 'accounts'
}

if len(sys.argv) < 2:
    print("Usage: parse_ofx.py <path_to_ofx_file> [manual_account_id]")
    sys.exit(1)

ofx_path = sys.argv[1]
manual_account_id = int(sys.argv[2]) if len(sys.argv) > 2 and sys.argv[2].isdigit() else None

if not os.path.isfile(ofx_path):
    print(f"File not found: {ofx_path}")
    sys.exit(1)

with open(ofx_path, 'r', encoding='utf-8') as f:
    ofx = OfxParser.parse(f)

conn = mysql.connector.connect(**DB_CONFIG)
cursor = conn.cursor(dictionary=True)

inserted, flagged, potential = 0, 0, 0

for account in ofx.accounts:
    acct_id = account.account_id
    bank_id = getattr(account, 'routing_number', None)

    # resolve account_id
    account_id = manual_account_id
    if not account_id:
        query = "SELECT account_id FROM ofx_account_map WHERE acct_id = %s"
        params = [acct_id]
        if bank_id:
            query += " AND bank_id = %s"
            params.append(bank_id)
        cursor.execute(query, params)
        result = cursor.fetchone()
        account_id = result['account_id'] if result else None

    if not account_id:
        print(f"❌ Could not resolve account for ACCTID={acct_id}, BANKID={bank_id}")
        continue

    for txn in account.statement.transactions:
        date_str = txn.date.strftime('%Y-%m-%d')
        amount = txn.amount
        description = txn.payee
        memo = txn.memo or ''

        status = 'new'
        matched_id = None

        # Check for exact duplicate
        cursor.execute("""
            SELECT id FROM transactions
            WHERE account_id = %s AND date = %s AND ABS(amount - %s) < 0.01
            LIMIT 1
        """, (account_id, date_str, amount))
        match = cursor.fetchone()
        if match:
            status = 'duplicate'
            matched_id = match['id']
        else:
            # Check for potential duplicate: date within ±3 days
            cursor.execute("""
                SELECT id, date FROM transactions
                WHERE account_id = %s AND ABS(amount - %s) < 0.01
                      AND ABS(DATEDIFF(date, %s)) <= 3
                LIMIT 1
            """, (account_id, amount, date_str))
            potential_match = cursor.fetchone()
            if potential_match:
                status = 'potential_duplicate'
                matched_id = potential_match['id']

        if status in ('new', 'potential_duplicate'):
            cursor.execute("""
                INSERT INTO staging_transactions (
                    account_id, date, description, amount, status, original_memo, matched_transaction_id
                ) VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (
                account_id, date_str, description, amount,
                status, memo, matched_id
            ))

            if status == 'potential_duplicate':
                potential += 1
            else:
                inserted += 1
        else:
            flagged += 1

conn.commit()
cursor.close()
conn.close()

print(f"✅ Inserted: {inserted} new transactions.")
print(f"⚠️ Flagged as potential duplicates: {potential}")
print(f"❌ Exact duplicates: {flagged}")
