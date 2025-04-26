#!/usr/bin/env python3

import csv
import sys
import os
import mysql.connector
from datetime import datetime, timedelta
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

with open(csv_path, newline='') as csvfile:
    reader = csv.DictReader(csvfile)
    for row in reader:
        if row['Status'].strip().upper() != 'BILLED':
            continue

        try:
            txn_date = datetime.strptime(row['Transaction Date'], '%Y-%m-%d').date()
        except ValueError:
            print(f"⚠️ Invalid date: {row['Transaction Date']}")
            continue

        raw_amount = Decimal(row['Billing Amount'])
        txn_type = row['Debit or Credit'].strip().upper()
        amount = raw_amount if txn_type == 'CRDT' else -raw_amount
        description = row['Merchant'].strip()
        original_memo = ', '.join([
            row.get('Merchant City', ''),
            row.get('Merchant State', ''),
            row.get('Merchant Postcode', ''),
            row.get('Card Used', '')
        ]).strip(', ')

        status = 'new'
        matched_id = None
        predicted_instance_id = None

        # 1️⃣ Check for real transaction match
        select_cursor.execute("""
            SELECT id FROM transactions
            WHERE account_id = %s
              AND date = %s
              AND ABS(amount - %s) < 0.01
            LIMIT 1
        """, (account_id, txn_date, amount))
        match = select_cursor.fetchone()
        if match:
            status = 'duplicate'
            matched_id = match['id']
            duplicates += 1
        else:
            select_cursor.execute("""
                SELECT id, date FROM transactions
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

        # 2️⃣ Check for match to predicted_instance
        if status == 'new':
            select_cursor.execute("""
                SELECT id FROM predicted_instances
                WHERE from_account_id = %s
                  AND ABS(amount - %s) < 0.01
                  AND ABS(DATEDIFF(scheduled_date, %s)) <= 3
                LIMIT 1
            """, (account_id, amount, txn_date))
            prediction = select_cursor.fetchone()
            if prediction:
                status = 'fulfills_prediction'
                predicted_instance_id = prediction['id']
                predictions += 1
            else:
                select_cursor.execute("""
                    SELECT pi.id 
                    FROM predicted_instances pi
                    JOIN predicted_transactions pt ON pi.predicted_transaction_id = pt.id
                    WHERE pi.from_account_id = %s
                      AND pt.variable = 1
                      AND pi.description LIKE %s
                      AND ABS(DATEDIFF(pi.scheduled_date, %s)) <= 3
                    LIMIT 1
                """, (account_id, f"%{description[:5]}%", txn_date))
                prediction = select_cursor.fetchone()
                if prediction:
                    status = 'fulfills_prediction'
                    predicted_instance_id = prediction['id']
                    predictions += 1

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
