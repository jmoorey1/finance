from datetime import datetime, timedelta
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

def schedule_instance(cursor, p, day):
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
            amount = IF(confirmed = 1, amount, VALUES(amount))
    """, (
        p['id'], day, p['from_account_id'], p['to_account_id'],
        p['category_id'], amount, p['description']
    ))

def predict_fixed_transactions(cursor, today, end_date):
    cursor.execute("SELECT * FROM predicted_transactions WHERE active=1")
    predictions = cursor.fetchall()

    for p in predictions:
        pid = p['id']
        interval = p.get('repeat_interval')
        anchor_type = p['anchor_type']
        frequency = p.get('frequency')

        # Weekly predictions
        if anchor_type == 'weekly':
            for i in range((end_date - today).days + 1):
                day = today + timedelta(days=i)
                if day.weekday() == p['weekday']:
                    schedule_instance(cursor, p, day)
            continue

        # Custom frequency (e.g. every N weeks)
        if frequency == 'custom' and interval:
            cursor.execute("""
                SELECT MAX(date) as last_date FROM transactions
                WHERE predicted_transaction_id = %s
            """, (pid,))
            result = cursor.fetchone()
            start_from = result['last_date'] or today.isoformat()
            if isinstance(start_from, str):
                start_from = datetime.strptime(start_from, "%Y-%m-%d")
            if isinstance(start_from, datetime):
                start_from = start_from.date()
            current = start_from + timedelta(weeks=interval)
            while current <= end_date:
                schedule_instance(cursor, p, current)
                current += timedelta(weeks=interval)
            continue

        # Default to 1 month if repeat_interval is missing
        months_interval = int(p.get('repeat_interval') or 1)

        # Start from last actual transaction or today
        cursor.execute("""
            SELECT MAX(date) as last_date FROM transactions
            WHERE predicted_transaction_id = %s
        """, (pid,))
        result = cursor.fetchone()
        start_from = result['last_date'] or today.isoformat()
        if isinstance(start_from, str):
            start_from = datetime.strptime(start_from, "%Y-%m-%d")
        if isinstance(start_from, datetime):
            start_from = start_from.date()

        current = start_from + relativedelta(months=months_interval)

        while current <= end_date:
            year, month = current.year, current.month
            scheduled_date = None

            if anchor_type == 'day_of_month' and p.get('day_of_month'):
                try:
                    scheduled_date = datetime(year, month, p['day_of_month'])
                    scheduled_date = adjust_date(scheduled_date, p['adjust_for_weekend'])
                except ValueError:
                    current += relativedelta(months=months_interval)
                    continue

            elif anchor_type == 'nth_weekday':
                scheduled_date = get_nth_weekday(year, month, p['weekday'], p['nth_weekday'])

            elif anchor_type == 'last_business_day':
                last_day = calendar.monthrange(year, month)[1]
                scheduled_date = datetime(year, month, last_day)
                scheduled_date = adjust_date(scheduled_date, 'previous_business_day')

            if scheduled_date and today <= scheduled_date.date() <= end_date:
                schedule_instance(cursor, p, scheduled_date.date())

            current += relativedelta(months=months_interval)


def predict_credit_card_repayments(cursor, today, end_date):
    print("🔄 Starting credit card repayment predictions")

    cursor.execute("""
        SELECT * FROM accounts 
        WHERE type='credit' AND active=1 
          AND statement_day IS NOT NULL 
          AND payment_day IS NOT NULL
          AND paid_from IS NOT NULL
    """)
    cards = cursor.fetchall()

    for card in cards:
        print(f"\n🧾 Processing card: {card['name']}")

        avg_daily = None
        previous_statement_date = None

        for i in range(3):
            ref = today.replace(day=1) + relativedelta(months=i)
            year, month = ref.year, ref.month

            try:
                statement_date = datetime(year, month, card['statement_day'])
                statement_date = adjust_date(statement_date, 'next_business_day')
            except ValueError:
                print(f"⚠️ Invalid statement date for {card['name']} in {month}/{year}")
                continue

            if card['payment_day'] < card['statement_day']:
                pay_month = month + 1 if month < 12 else 1
                pay_year = year if month < 12 else year + 1
            else:
                pay_month = month
                pay_year = year

            try:
                payment_date = datetime(pay_year, pay_month, card['payment_day'])
                payment_date = adjust_date(payment_date, 'next_business_day')
            except ValueError:
                print(f"⚠️ Invalid payment date for {card['name']} in {pay_month}/{pay_year}")
                continue

            print(f"📆 Statement date: {statement_date.date()} | Payment date: {payment_date.date()}")

            if not (today <= payment_date.date() <= end_date):
                print("⏩ Payment date outside forecast window")
                continue

            # Skip if real transaction already happened
            cursor.execute("""
                SELECT 1 FROM transactions
                WHERE (account_id = %s OR account_id = %s)
                  AND ABS(DATEDIFF(date, %s)) <= 3
                  AND ABS(amount) >= 1
                LIMIT 1
            """, (card['paid_from'], card['id'], payment_date.date()))
            if cursor.fetchone():
                print("⏩ Skipping – actual repayment already found")
                continue

            if i == 0:
                # First iteration — determine last actual statement date
                stmt_day = card['statement_day']
                if today.day >= stmt_day:
                    last_stmt_month = today.month
                    last_stmt_year = today.year
                else:
                    last_stmt_month = today.month - 1 if today.month > 1 else 12
                    last_stmt_year = today.year if today.month > 1 else today.year - 1

                try:
                    previous_statement_date = datetime(last_stmt_year, last_stmt_month, stmt_day)
                    previous_statement_date = adjust_date(previous_statement_date, 'next_business_day')
                except ValueError:
                    print("⚠️ Could not calculate initial last statement date – skipping")
                    continue

                start_of_cycle = previous_statement_date.date()
                end_of_cycle = statement_date.date()
                days_so_far = (today - start_of_cycle).days or 1
                days_total = (end_of_cycle - start_of_cycle).days or 1

                # Get real spending in this cycle so far
                cursor.execute("""
                    SELECT SUM(t.amount) AS current_balance
                    FROM transactions t
                    JOIN categories c ON t.category_id = c.id
                    WHERE t.account_id = %s
                      AND t.date BETWEEN %s AND %s
                      AND c.type != 'transfer'
                """, (card['id'], start_of_cycle, today))
                spend_now_row = cursor.fetchone()
                current_balance = spend_now_row['current_balance'] or 0

                avg_daily = current_balance / days_so_far
                estimated_future_spend = avg_daily * (days_total - days_so_far)
                balance = current_balance + estimated_future_spend

            else:
                if avg_daily is None or previous_statement_date is None:
                    print("⚠️ Cannot project future repayment — missing previous data")
                    continue

                start_of_cycle = previous_statement_date.date()
                end_of_cycle = statement_date.date()
                days_total = (end_of_cycle - start_of_cycle).days or 1
                balance = avg_daily * days_total
                current_balance = 0  # simulated
                days_so_far = days_total
                estimated_future_spend = balance

            print(f"📌 Last statement date: {start_of_cycle}")
            print(f"💸 Current balance{' (simulated)' if i > 0 else ' (excluding transfers)'}: £{current_balance:.2f}")
            print(f"📈 Days so far: {days_so_far}, total days in cycle: {days_total}")
            print(f"📊 Avg daily: £{avg_daily:.2f} | Est future spend: £{estimated_future_spend:.2f}")
            print(f"🧮 Predicted statement balance: £{balance:.2f}")

            if round(abs(balance), 2) < 1.00:
                print("⏩ Skipping – predicted amount < £1.00")
                previous_statement_date = statement_date
                continue

            # Get category for repayment
            cursor.execute("""
                SELECT id FROM categories
                WHERE linked_account_id = %s AND name LIKE 'Transfer To : %%'
                LIMIT 1
            """, (card['id'],))
            category_result = cursor.fetchone()
            if not category_result:
                print("⚠️ No category found for this repayment")
                previous_statement_date = statement_date
                continue

            category_id = category_result['id']
            amount_to_insert = round(-balance, 2)

            # Check if this repayment was already confirmed — skip INSERT but preserve state
            cursor.execute("""
                SELECT id FROM predicted_instances
                WHERE from_account_id = %s AND to_account_id = %s
                  AND scheduled_date = %s AND confirmed = 1
            """, (card['paid_from'], card['id'], payment_date.date()))
            if cursor.fetchone():
                print("⏩ Skipping INSERT – already confirmed predicted instance")
                previous_statement_date = statement_date
                continue

            cursor.execute("""
                INSERT INTO predicted_instances
                (scheduled_date, from_account_id, to_account_id, category_id, amount, description)
                VALUES (%s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE 
                    amount = IF(confirmed = 1, amount, VALUES(amount))
            """, (
                payment_date.date(),
                card['paid_from'],
                card['id'],
                category_id,
                amount_to_insert,
                card['name']
            ))
            print(f"✅ Inserted prediction: {payment_date.date()} | £{amount_to_insert:.2f}")

            # Update previous statement anchor for next cycle
            previous_statement_date = statement_date

    print("\n✅ Credit card repayment predictions complete.")




def main():
    db = mysql.connector.connect(
        host="localhost",
        user="john",
        password="Thebluemole01",
        database="accounts"
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
