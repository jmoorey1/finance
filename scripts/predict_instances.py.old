from datetime import datetime, timedelta
import os
import mysql.connector
import holidays
import calendar
from dateutil.relativedelta import relativedelta

UK_HOLIDAYS = holidays.UnitedKingdom()

def is_business_day(date):
    return date.weekday() < 5 and date not in UK_HOLIDAYS

def adjust_date(date, mode):
    if mode == 'next_business_day':
        while not is_business_day(date):
            date += timedelta(days=1)
    elif mode == 'previous_business_day':
        while not is_business_day(date):
            date -= timedelta(days=1)
    return date

def get_nth_weekday(year, month, weekday, n):
    count, day = 0, 1
    while day <= calendar.monthrange(year, month)[1]:
        dt = datetime(year, month, day)
        if dt.weekday() == weekday:
            count += 1
            if count == n:
                return dt
        day += 1
    return None

def actual_txn_exists_for_predicted_transaction(cursor, predicted_transaction_id, scheduled_date):
    """
    If a real transaction already exists tied to predicted_transaction_id near the scheduled date,
    do not recreate the predicted_instance (prevents regenerated "stale" predictions after deletion).
    """
    cursor.execute("""
        SELECT 1
        FROM transactions
        WHERE predicted_transaction_id = %s
          AND ABS(DATEDIFF(date, %s)) <= 3
        LIMIT 1
    """, (predicted_transaction_id, scheduled_date))
    return cursor.fetchone() is not None

def actual_transfer_exists_by_category(cursor, from_account_id, category_id, scheduled_date):
    """
    Used for repayments (no predicted_transaction_id): if a transfer already happened on the paying account
    using the specific Transfer To category near the scheduled date, don't recreate the prediction.
    """
    cursor.execute("""
        SELECT 1
        FROM transactions
        WHERE account_id = %s
          AND category_id = %s
          AND type = 'transfer'
          AND ABS(DATEDIFF(date, %s)) <= 3
        LIMIT 1
    """, (from_account_id, category_id, scheduled_date))
    return cursor.fetchone() is not None

def schedule_instance(cursor, p, day):
    # Prevent regeneration of deleted instances after fulfilment:
    # if a real txn already exists for this predicted_transaction_id near this date, skip.
    if actual_txn_exists_for_predicted_transaction(cursor, p['id'], day):
        return

    amount = p['amount']
    if p.get('variable') and p.get('average_over_last'):
        cursor.execute("""
            SELECT AVG(pr.amount) AS avg_amount FROM
            (SELECT amount FROM transactions
             WHERE predicted_transaction_id = %s
             ORDER BY date DESC LIMIT %s) pr
        """, (p['id'], p['average_over_last']))
        result = cursor.fetchone()
        if result and result['avg_amount'] is not None:
            amount = round(result['avg_amount'], 2)

    cursor.execute("""
        INSERT INTO predicted_instances
        (predicted_transaction_id, scheduled_date, from_account_id, to_account_id,
         category_id, amount, description)
        VALUES (%s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            -- Don't overwrite anything if the instance has been actioned:
            -- fulfilled=1 (complete) or fulfilled=2 (partial transfer)
            amount = IF(confirmed = 1 OR COALESCE(fulfilled, 0) <> 0, amount, VALUES(amount)),
            description = IF(COALESCE(fulfilled, 0) <> 0, description, VALUES(description)),
            updated_at = IF(COALESCE(fulfilled, 0) <> 0, updated_at, CURRENT_TIMESTAMP)
    """, (
        p['id'], day, p['from_account_id'], p['to_account_id'],
        p['category_id'], amount, p['description']
    ))

def predict_fixed_transactions(cursor, today, end_date):
    cursor.execute("SELECT * FROM predicted_transactions WHERE active=1")
    predictions = cursor.fetchall()

    for p in predictions:
        interval = p.get('repeat_interval') or 1
        anchor_type = p['anchor_type']
        frequency = p.get('frequency')

        # Weekly predictions (explicit weekday every week)
        if anchor_type == 'weekly':
            for i in range((end_date - today).days + 1):
                day = today + timedelta(days=i)
                if day.weekday() == p['weekday']:
                    schedule_instance(cursor, p, day)
            continue

        # Custom frequency (e.g. every N weeks) anchored from last actual transaction
        if frequency == 'custom' and interval:
            cursor.execute("""
                SELECT MAX(date) as last_date
                FROM transactions
                WHERE predicted_transaction_id = %s
            """, (p['id'],))
            last_row = cursor.fetchone()
            last_date = last_row['last_date'] if last_row else None

            if last_date is None:
                next_date = today
            else:
                next_date = (last_date + timedelta(days=7 * interval))

            while next_date <= end_date:
                schedule_instance(cursor, p, next_date)
                next_date += timedelta(days=7 * interval)
            continue

        # Monthly / fortnightly / weekly via anchor types
        month_cursor = today.replace(day=1)
        while month_cursor <= end_date:
            year = month_cursor.year
            month = month_cursor.month

            day = None

            if anchor_type == 'day_of_month':
                dom = p.get('day_of_month')
                if dom:
                    last_day = calendar.monthrange(year, month)[1]
                    dom = min(int(dom), last_day)
                    day = datetime(year, month, dom)

            elif anchor_type == 'nth_weekday':
                wd = p.get('weekday')
                nth = p.get('nth_weekday')
                if wd is not None and nth:
                    day = get_nth_weekday(year, month, int(wd), int(nth))

            elif anchor_type == 'last_business_day':
                last_day = calendar.monthrange(year, month)[1]
                day = datetime(year, month, last_day)
                if p.get('is_business_day'):
                    day = adjust_date(day, 'previous_business_day')

            if day:
                day = adjust_date(day, p.get('adjust_for_weekend') or 'none')
                d = day.date()
                if d >= today and d <= end_date:
                    schedule_instance(cursor, p, d)

                # Fortnightly
                if frequency == 'fortnightly':
                    d2 = d + timedelta(days=14)
                    if d2 <= end_date:
                        schedule_instance(cursor, p, d2)

                # Weekly (from the monthly anchor)
                if frequency == 'weekly':
                    dW = d + timedelta(days=7)
                    while dW <= end_date:
                        schedule_instance(cursor, p, dW)
                        dW += timedelta(days=7)

            month_cursor = (month_cursor + relativedelta(months=1)).replace(day=1)

def calc_min_payment(balance, floor_amt, percent):
    bal = max(0.0, float(balance or 0.0))
    floor_val = max(0.0, float(floor_amt or 0.0))
    pct_val = max(0.0, float(percent or 0.0))
    pct_amount = (pct_val / 100.0) * bal
    amt = max(floor_val, pct_amount)
    return round(min(amt, bal), 2)

def estimate_balance_from_last_statement(cursor, card_id, last_stmt_date, last_stmt_end_balance, as_of_date):
    """
    Estimate debt balance at as_of_date based on last known statement end balance plus net transactions since then.
    Balance change ~= -SUM(amount) because:
      - purchases are usually negative amounts -> increase debt
      - repayments/credits are positive amounts -> reduce debt
    """
    base = abs(float(last_stmt_end_balance or 0.0))
    cursor.execute("""
        SELECT SUM(amount) AS sum_amount
        FROM transactions
        WHERE account_id = %s
          AND date > %s
          AND date <= %s
    """, (card_id, last_stmt_date, as_of_date))
    row = cursor.fetchone()
    sum_amount = float(row['sum_amount'] or 0.0)
    est = max(0.0, base - sum_amount)  # base + (-SUM(amount))
    return round(est, 2)

def predict_credit_card_repayments(cursor, today, end_date):
    print("🔄 Starting credit card repayment predictions")

    cursor.execute("""
        SELECT
            id, name, statement_day, payment_day, paid_from,
            repayment_method, fixed_payment_amount,
            min_payment_floor, min_payment_percent, min_payment_calc
        FROM accounts
        WHERE type='credit' AND active=1
          AND statement_day IS NOT NULL
          AND payment_day IS NOT NULL
          AND paid_from IS NOT NULL
    """)
    cards = cursor.fetchall()

    for card in cards:
        print(f"\n🧾 Processing card: {card['name']} (method={card.get('repayment_method','full')})")

        # Transfer category for repayments: "Transfer To : <Card>"
        cursor.execute("""
            SELECT id FROM categories
            WHERE type='transfer'
              AND parent_id = 275
              AND linked_account_id = %s
              AND name LIKE 'Transfer To : %%'
            LIMIT 1
        """, (card['id'],))
        category_row = cursor.fetchone()
        if not category_row:
            print("⚠️ No 'Transfer To' category found for this card — skipping")
            continue
        category_id = category_row['id']

        repayment_method = card.get('repayment_method') or 'full'

        # Anchor for estimation
        cursor.execute("""
            SELECT id, statement_date, end_balance, payment_due_date, minimum_payment_due
            FROM statements
            WHERE account_id = %s
              AND statement_date <= %s
            ORDER BY statement_date DESC
            LIMIT 1
        """, (card['id'], today))
        last_stmt = cursor.fetchone()

        last_stmt_date = last_stmt['statement_date'] if last_stmt else None
        last_stmt_end_balance = abs(float(last_stmt['end_balance'])) if last_stmt and last_stmt['end_balance'] is not None else 0.0

        # Generate repayments for ~3 cycles (keeps current behaviour)
        for i in range(3):
            ref = today.replace(day=1) + relativedelta(months=i)
            year, month = ref.year, ref.month

            # Statement date
            try:
                statement_date_dt = datetime(year, month, int(card['statement_day']))
                statement_date_dt = adjust_date(statement_date_dt, 'next_business_day')
            except ValueError:
                print(f"⚠️ Invalid statement date for {card['name']} in {month}/{year}")
                continue

            # Payment date month logic
            if int(card['payment_day']) < int(card['statement_day']):
                pay_ref = (datetime(year, month, 1) + relativedelta(months=1))
                pay_year, pay_month = pay_ref.year, pay_ref.month
            else:
                pay_year, pay_month = year, month

            try:
                payment_date_dt = datetime(pay_year, pay_month, int(card['payment_day']))
                payment_date_dt = adjust_date(payment_date_dt, 'next_business_day')
            except ValueError:
                print(f"⚠️ Invalid payment date for {card['name']} in {pay_month}/{pay_year}")
                continue

            statement_date = statement_date_dt.date()
            payment_date = payment_date_dt.date()

            print(f"📆 Statement date: {statement_date} | Payment date: {payment_date}")

            if not (today <= payment_date <= end_date):
                print("⏩ Payment date outside forecast window")
                continue

            # Skip if real repayment already happened (precise check using Transfer To category on paid_from)
            if actual_transfer_exists_by_category(cursor, int(card['paid_from']), int(category_id), payment_date):
                print("⏩ Skipping – actual repayment already found (Transfer To category)")
                continue

            # Find statement row (±3 days)
            cursor.execute("""
                SELECT id, statement_date, end_balance, payment_due_date, minimum_payment_due
                FROM statements
                WHERE account_id = %s
                  AND ABS(DATEDIFF(statement_date, %s)) <= 3
                ORDER BY ABS(DATEDIFF(statement_date, %s)) ASC
                LIMIT 1
            """, (card['id'], statement_date, statement_date))
            stmt = cursor.fetchone()

            statement_id = None
            statement_balance = None

            if stmt:
                statement_id = stmt['id']
                statement_balance = abs(float(stmt['end_balance'] or 0.0))

                # Backfill payment_due_date
                if stmt.get('payment_due_date') is None:
                    cursor.execute("""
                        UPDATE statements
                        SET payment_due_date = %s
                        WHERE id = %s
                    """, (payment_date, statement_id))

                # Backfill minimum_payment_due
                if repayment_method == 'minimum' and stmt.get('minimum_payment_due') is None:
                    min_due = calc_min_payment(statement_balance, card.get('min_payment_floor'), card.get('min_payment_percent'))
                    cursor.execute("""
                        UPDATE statements
                        SET minimum_payment_due = %s
                        WHERE id = %s
                    """, (min_due, statement_id))
            else:
                # Estimate when statement not created yet
                if last_stmt_date is not None:
                    statement_balance = estimate_balance_from_last_statement(
                        cursor,
                        card_id=card['id'],
                        last_stmt_date=last_stmt_date,
                        last_stmt_end_balance=last_stmt_end_balance,
                        as_of_date=today
                    )
                else:
                    statement_balance = 0.0

            if statement_balance is None or round(statement_balance, 2) < 1.00:
                print("⏩ Skipping – predicted balance < £1.00")
                continue

            # Compute repayment amount
            if repayment_method == 'full':
                amount_to_insert = round(statement_balance, 2)
            elif repayment_method == 'fixed':
                fixed_amt = float(card.get('fixed_payment_amount') or 0.0)
                amount_to_insert = round(min(max(0.0, fixed_amt), statement_balance), 2)
            elif repayment_method == 'minimum':
                amount_to_insert = calc_min_payment(statement_balance, card.get('min_payment_floor'), card.get('min_payment_percent'))
            else:
                amount_to_insert = round(statement_balance, 2)

            if amount_to_insert < 1.00:
                print("⏩ Skipping – predicted payment < £1.00")
                continue

            # Don't stomp actioned predictions for this repayment key (confirmed OR fulfilled!=0)
            cursor.execute("""
                SELECT id
                FROM predicted_instances
                WHERE from_account_id = %s AND to_account_id = %s
                  AND scheduled_date = %s
                  AND (confirmed = 1 OR COALESCE(fulfilled,0) <> 0)
                LIMIT 1
            """, (card['paid_from'], card['id'], payment_date))
            if cursor.fetchone():
                print("⏩ Skipping INSERT – already confirmed/fulfilled predicted instance (repayment key)")
                continue

            cursor.execute("""
                INSERT INTO predicted_instances
                    (scheduled_date, from_account_id, to_account_id, category_id, amount, description, statement_id)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    amount = IF(confirmed = 1 OR COALESCE(fulfilled, 0) <> 0, amount, VALUES(amount)),
                    statement_id = IF(COALESCE(fulfilled, 0) <> 0, statement_id, COALESCE(statement_id, VALUES(statement_id))),
                    description = IF(COALESCE(fulfilled, 0) <> 0, description, VALUES(description)),
                    updated_at = IF(COALESCE(fulfilled, 0) <> 0, updated_at, CURRENT_TIMESTAMP)
            """, (
                payment_date,
                card['paid_from'],
                card['id'],
                category_id,
                amount_to_insert,
                f"Credit card repayment: {card['name']}",
                statement_id
            ))

            print(f"✅ Inserted/updated prediction: {payment_date} | £{amount_to_insert:.2f} | statement_id={statement_id}")

def main():
    host = os.getenv("FINANCE_DB_HOST", "localhost")
    user = os.getenv("FINANCE_DB_USER", "john")
    database = os.getenv("FINANCE_DB_NAME", "accounts")
    password = os.getenv("FINANCE_DB_PASSWORD", "Thebluemole01")  # recommended for cron

    try:
        if password:
            db = mysql.connector.connect(host=host, user=user, password=password, database=database)
        else:
            # Works for local socket auth. If you use password auth, set FINANCE_DB_PASSWORD.
            db = mysql.connector.connect(host=host, user=user, database=database)
    except mysql.connector.Error as e:
        raise SystemExit(
            "DB connection failed. If you use password auth, set FINANCE_DB_PASSWORD in your shell/cron environment.\n"
            f"Host={host} User={user} DB={database}\n"
            f"MySQL error: {e}"
        )

    cursor = db.cursor(dictionary=True)

    today = datetime.now().date()
    end_date = today + timedelta(days=90)

    predict_fixed_transactions(cursor, today, end_date)
    predict_credit_card_repayments(cursor, today, end_date)

    db.commit()
    cursor.close()
    db.close()

if __name__ == "__main__":
    main()
