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
    'password': 'Thebluemole01',
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

inserted, flagged, potential, predictions = 0, 0, 0, 0

for account in ofx.accounts:
    acct_id = account.account_id
    bank_id = getattr(account, 'routing_number', None)

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
        predicted_instance_id = None

        # 1️⃣ Check if it matches a real transaction
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
            cursor.execute("""
                SELECT id FROM transactions
                WHERE account_id = %s AND ABS(amount - %s) < 0.01
                      AND ABS(DATEDIFF(date, %s)) <= 3
                LIMIT 1
            """, (account_id, amount, date_str))
            potential_match = cursor.fetchone()
            if potential_match:
                status = 'potential_duplicate'
                matched_id = potential_match['id']

        # 2️⃣ Check if it matches a predicted instance
        if status == 'new':
            # Try matching exactly first
            cursor.execute("""
                SELECT id, from_account_id, to_account_id, amount
                FROM predicted_instances
                WHERE (from_account_id = %s OR to_account_id = %s)
                  AND ABS(DATEDIFF(scheduled_date, %s)) <= 3
            """, (account_id, account_id, date_str))
            candidates = cursor.fetchall()

            matched = None
            for pred in candidates:
                from_id = pred['from_account_id']
                to_id = pred['to_account_id']
                pred_amt = float(pred['amount'])

                if account_id == from_id and abs(amount + pred_amt) < 0.01:
                    matched = pred
                    break
                if account_id == to_id and abs(amount - pred_amt) < 0.01:
                    matched = pred
                    break

            if matched:
                status = 'fulfills_prediction'
                predicted_instance_id = matched['id']
                predictions += 1

            else:
                # Try matching variable predictions by fuzzy description
                cursor.execute("""
                    SELECT pi.id 
                    FROM predicted_instances pi
                    JOIN predicted_transactions pt ON pi.predicted_transaction_id = pt.id
                    WHERE pi.from_account_id = %s
                      AND pt.variable = 1
                      AND pi.description LIKE %s
                      AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                    LIMIT 1
                """, (account_id, f"%{description[:5]}%", date_str))
                prediction = cursor.fetchone()

                if prediction:
                    status = 'fulfills_prediction'
                    predicted_instance_id = prediction['id']
                    predictions += 1



        if status in ('new', 'potential_duplicate', 'fulfills_prediction'):
            cursor.execute("""
                INSERT INTO staging_transactions (
                    account_id, date, description, amount, status,
                    original_memo, matched_transaction_id, predicted_instance_id
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """, (
                account_id, date_str, description, amount, status,
                memo, matched_id, predicted_instance_id
            ))

            if status == 'potential_duplicate':
                potential += 1
            elif status == 'fulfills_prediction':
                predictions += 1
            else:
                inserted += 1
        else:
            flagged += 1

conn.commit()
cursor.close()
conn.close()

print(f"✅ Inserted: {inserted} new transactions.")
print(f"⚡ Matches to predicted instances: {predictions}")
print(f"⚠️ Potential duplicates: {potential}")
print(f"❌ Exact duplicates: {flagged}")
