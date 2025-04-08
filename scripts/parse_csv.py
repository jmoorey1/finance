#!/usr/bin/env python3

import csv
import sys
import os
import mysql.connector
from datetime import datetime, timedelta
from decimal import Decimal

# Config
DB_CONFIG = {
    'user': 'john',
    'password': 'Thebluemole01',  # Fill in if needed
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
select_cursor = conn.cursor()

inserted = 0

with open(csv_path, newline='') as csvfile:
    reader = csv.DictReader(csvfile)
    for row in reader:
        if row['Status'].strip().upper() != 'BILLED':
            continue  # Skip pending transactions

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

        # Duplicate check
        select_cursor.execute("""
            SELECT id, date
            FROM transactions
            WHERE account_id = %s
              AND ABS(amount - %s) <= 0.01
              AND date BETWEEN %s AND %s
        """, (
            account_id,
            amount,
            txn_date - timedelta(days=3),
            txn_date + timedelta(days=3)
        ))

        match = select_cursor.fetchall()
        status = 'new'
        matched_id = None

        for match_id, match_date in match:
            if match_date == txn_date:
                status = 'duplicate'
                matched_id = match_id
                break
            elif status != 'duplicate':
                status = 'potential_duplicate'
                matched_id = match_id

        # Insert into staging_transactions
        insert_cursor.execute("""
            INSERT INTO staging_transactions (account_id, date, description, amount, raw_description, original_memo, status, matched_transaction_id)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """, (
            account_id,
            txn_date,
            description,
            amount,
            description,  # raw_description
            original_memo,
            status,
            matched_id
        ))

        inserted += 1

conn.commit()
select_cursor.close()
insert_cursor.close()
conn.close()

print(f"✅ Inserted: {inserted} transaction(s) from CSV.")
