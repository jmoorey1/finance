import mysql.connector
from datetime import datetime, timedelta
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

def predict_fixed_transactions(cursor, today, end_date):
    cursor.execute("SELECT * FROM predicted_transactions WHERE active=1")
    predictions = cursor.fetchall()

    for p in predictions:
        for month_offset in range(3):  # Next 3 months
            base = today.replace(day=1) + relativedelta(months=month_offset)
            year, month = base.year, base.month

            scheduled_date = None
            if p['anchor_type'] == 'day_of_month':
                try:
                    scheduled_date = datetime(year, month, p['day_of_month'])
                    scheduled_date = adjust_date(scheduled_date, p['adjust_for_weekend'])
                except ValueError:
                    continue  # skip invalid dates like Feb 30
            elif p['anchor_type'] == 'nth_weekday':
                scheduled_date = get_nth_weekday(year, month, p['weekday'], p['nth_weekday'])
            elif p['anchor_type'] == 'last_business_day':
                last_day = calendar.monthrange(year, month)[1]
                scheduled_date = datetime(year, month, last_day)
                scheduled_date = adjust_date(scheduled_date, 'previous_business_day')

            if scheduled_date and today <= scheduled_date.date() <= end_date:
                amount = p['amount']
                if p['variable'] and p['average_over_last']:
                    cursor.execute("""
                        SELECT AVG(amount) AS avg_amount FROM transactions
                        WHERE description = %s AND account_id = %s
                        ORDER BY date DESC LIMIT %s
                    """, (p['description'], p['from_account_id'], p['average_over_last']))
                    result = cursor.fetchone()
                    if result and result['avg_amount'] is not None:
                        amount = round(result['avg_amount'], 2)

                cursor.execute("""
                    INSERT INTO predicted_instances
                    (predicted_transaction_id, scheduled_date, from_account_id, to_account_id,
                     category_id, amount, description)
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount)
                """, (
                    p['id'], scheduled_date.date(), p['from_account_id'], p['to_account_id'],
                    p['category_id'], amount, p['description']
                ))

def predict_credit_card_repayments(cursor, today, end_date):
    cursor.execute("""
        SELECT * FROM accounts 
        WHERE type='credit' AND active=1 
          AND statement_day IS NOT NULL 
          AND payment_day IS NOT NULL
          AND paid_from IS NOT NULL
    """)
    cards = cursor.fetchall()

    for card in cards:
        for i in range(3):
            ref = today.replace(day=1) + relativedelta(months=i)
            year, month = ref.year, ref.month

            try:
                statement_date = datetime(year, month, card['statement_day'])
            except ValueError:
                continue
            statement_date = adjust_date(statement_date, 'next_business_day')

            if card['payment_day'] < card['statement_day']:
                pay_month = month + 1 if month < 12 else 1
                pay_year = year if month < 12 else year + 1
            else:
                pay_month = month
                pay_year = year

            try:
                payment_date = datetime(pay_year, pay_month, card['payment_day'])
            except ValueError:
                continue
            payment_date = adjust_date(payment_date, 'next_business_day')

            if today <= payment_date.date() <= end_date:
                cursor.execute("""
                    SELECT SUM(amount) AS balance FROM transactions
                    WHERE account_id = %s AND date <= %s
                """, (card['id'], statement_date.date()))
                result = cursor.fetchone()
                balance = result['balance'] if result and result['balance'] is not None else 0.00

                if abs(balance) >= 1.00:
                    cursor.execute("""
                        SELECT id FROM categories
                        WHERE linked_account_id = %s AND name LIKE 'Transfer To : %%'
                        LIMIT 1
                    """, (card['id'],))
                    category_result = cursor.fetchone()
                    if not category_result:
                        continue

                    category_id = category_result['id']

                    cursor.execute("""
                        INSERT INTO predicted_instances
                        (scheduled_date, from_account_id, to_account_id, category_id, amount, description)
                        VALUES (%s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE amount = VALUES(amount)
                    """, (
                        payment_date.date(),
                        card['paid_from'],
                        card['id'],
                        category_id,
                        round(-balance, 2),
                        "Predicted Credit Card Payment"
                    ))

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
