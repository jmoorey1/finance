#!/usr/bin/env python3

import csv
import sys
import os
import mysql.connector
from datetime import datetime
from decimal import Decimal

DB_CONFIG = {
    'user': 'john',
    'password': 'Thebluemole01',
    'host': 'localhost',
    'database': 'accounts'
}

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

inserted = 0
predictions = 0
potential = 0
duplicates = 0

used_predictions = set()

with open(csv_path, newline='') as csvfile:
    reader = csv.DictReader(csvfile)
    for row in reader:
        if row.get('Status', '').strip().upper() != 'BILLED':
            continue

        try:
            txn_date = datetime.strptime(row['Transaction Date'], '%Y-%m-%d').date()
        except (ValueError, KeyError):
            print(f"⚠️ Invalid date: {row.get('Transaction Date')}")
            continue

        try:
            raw_amount = Decimal(row['Billing Amount'])
        except Exception:
            print(f"⚠️ Invalid amount: {row.get('Billing Amount')}")
            continue

        txn_type = row.get('Debit or Credit', '').strip().upper()
        amount = raw_amount if txn_type == 'CRDT' else -raw_amount

        description = (row.get('Merchant') or '').strip()
        original_memo = ', '.join([
            row.get('Merchant City', '') or '',
            row.get('Merchant State', '') or '',
            row.get('Merchant Postcode', '') or '',
            row.get('Card Used', '') or ''
        ]).strip(', ')

        status = 'new'
        matched_id = None
        predicted_instance_id = None

        # 1️⃣ Check for real transaction match (exact)
        select_cursor.execute("""
            SELECT id FROM transactions
            WHERE account_id = %s AND date = %s AND ABS(amount - %s) < 0.01
            LIMIT 1
        """, (account_id, txn_date, amount))
        match = select_cursor.fetchone()
        if match:
            status = 'duplicate'
            matched_id = match['id']
            duplicates += 1
        else:
            # 1b️⃣ Potential duplicate: same amount within ±3 days
            select_cursor.execute("""
                SELECT id FROM transactions
                WHERE account_id = %s
                  AND ABS(amount - %s) < 0.01
                  AND ABS(DATEDIFF(date, %s)) <= 3
                LIMIT 1
            """, (account_id, amount, txn_date))
            potential_match = select_cursor.fetchone()
            if potential_match:
                status = 'potential_duplicate'
                matched_id = potential_match['id']
                potential += 1

        # 2️⃣ Check for predicted match (exclude fulfilled=1; allow 0 + 2)
        if status == 'new':
            select_cursor.execute("""
                SELECT pi.id, pi.from_account_id, pi.to_account_id, pi.amount, c.type AS cat_type
                FROM predicted_instances pi
                JOIN categories c ON pi.category_id = c.id
                WHERE (pi.from_account_id = %s OR pi.to_account_id = %s)
                  AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                  AND COALESCE(pi.fulfilled, 0) IN (0, 2)
            """, (account_id, account_id, txn_date))
            candidates = select_cursor.fetchall()

            for pred in candidates:
                pred_id = pred['id']
                if pred_id in used_predictions:
                    continue

                # Some predictions can have NULL amount (variable); skip these here and let fallback handle it
                if pred.get('amount') is None:
                    continue

                pred_amt = Decimal(str(pred['amount']))
                from_id = pred['from_account_id']
                to_id = pred['to_account_id']
                cat_type = pred['cat_type']

                if cat_type == 'transfer':
                    # Outgoing side: predicted amount stored positive, actual is negative on from_account
                    if account_id == from_id and abs(amount + pred_amt) < Decimal('0.01'):
                        predicted_instance_id = pred_id
                        status = 'fulfills_prediction'
                        used_predictions.add(pred_id)
                        predictions += 1
                        break
                    # Incoming side: actual is positive on to_account
                    if account_id == to_id and abs(amount - pred_amt) < Decimal('0.01'):
                        predicted_instance_id = pred_id
                        status = 'fulfills_prediction'
                        used_predictions.add(pred_id)
                        predictions += 1
                        break
                else:
                    # Regular income/expense: match amount on from_account
                    if account_id == from_id and abs(amount - pred_amt) < Decimal('0.01'):
                        predicted_instance_id = pred_id
                        status = 'fulfills_prediction'
                        used_predictions.add(pred_id)
                        predictions += 1
                        break

            # 3️⃣ Fallback: fuzzy match on variable predictions (exclude fulfilled=1; allow 0 + 2)
            if status == 'new' and description:
                select_cursor.execute("""
                    SELECT pi.id
                    FROM predicted_instances pi
                    JOIN predicted_transactions pt ON pi.predicted_transaction_id = pt.id
                    WHERE (pi.from_account_id = %s OR pi.to_account_id = %s)
                      AND pi.description LIKE %s
                      AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                      AND COALESCE(pi.fulfilled, 0) IN (0, 2)
                    LIMIT 1
                """, (account_id, account_id, f"%{description[:5]}%", txn_date))
                prediction = select_cursor.fetchone()

                if prediction and prediction['id'] not in used_predictions:
                    predicted_instance_id = prediction['id']
                    status = 'fulfills_prediction'
                    used_predictions.add(predicted_instance_id)
                    predictions += 1

        # Insert into staging (even potential duplicates & fulfills_prediction so you can review/confirm)
        if status in ('new', 'potential_duplicate', 'fulfills_prediction'):
            insert_cursor.execute("""
                INSERT INTO staging_transactions (
                    account_id, date, description, amount,
                    raw_description, original_memo, status,
                    matched_transaction_id, predicted_instance_id
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (
                account_id,
                txn_date,
                description,
                amount,
                description,
                original_memo,
                status,
                matched_id,
                predicted_instance_id
            ))
            inserted += 1

conn.commit()
select_cursor.close()
insert_cursor.close()
conn.close()

print(f"✅ Inserted: {inserted} transaction(s) from CSV.")
print(f"⚡ Matches to predicted instances: {predictions}")
print(f"⚠️ Potential duplicates: {potential}")
print(f"❌ Exact duplicates: {duplicates}")
