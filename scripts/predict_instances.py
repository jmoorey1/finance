from datetime import datetime, timedelta
import calendar
from statistics import median

import holidays
import mysql.connector
from dateutil.relativedelta import relativedelta

from finance_env import get_db_config

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
    do not recreate the predicted_instance (prevents regenerated stale predictions after deletion).
    """
    cursor.execute("""
        SELECT 1
        FROM transactions
        WHERE predicted_transaction_id = %s
          AND ABS(DATEDIFF(date, %s)) <= 3
        LIMIT 1
    """, (predicted_transaction_id, scheduled_date))
    return cursor.fetchone() is not None


def get_last_actual_date(cursor, predicted_transaction_id):
    cursor.execute("""
        SELECT MAX(date) AS last_date
        FROM transactions
        WHERE predicted_transaction_id = %s
    """, (predicted_transaction_id,))
    row = cursor.fetchone()
    return row['last_date'] if row else None


def compute_monthly_anchor_date(p, year, month):
    """
    Compute the anchor date for a given month based on anchor_type.
    Returns a date (not datetime) or None.
    """
    anchor_type = p.get('anchor_type')
    dt = None

    if anchor_type == 'day_of_month':
        dom = p.get('day_of_month')
        if dom:
            last_day = calendar.monthrange(year, month)[1]
            dom = min(int(dom), last_day)
            dt = datetime(year, month, dom)

    elif anchor_type == 'nth_weekday':
        wd = p.get('weekday')
        nth = p.get('nth_weekday')
        if wd is not None and nth:
            dt = get_nth_weekday(year, month, int(wd), int(nth))

    elif anchor_type == 'last_business_day':
        last_day = calendar.monthrange(year, month)[1]
        dt = datetime(year, month, last_day)
        if p.get('is_business_day'):
            dt = adjust_date(dt, 'previous_business_day')

    if not dt:
        return None

    dt = adjust_date(dt, p.get('adjust_for_weekend') or 'none')
    return dt.date()


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
    if actual_txn_exists_for_predicted_transaction(cursor, p['id'], day):
        return

    amount = p['amount']
    if p.get('variable') and p.get('average_over_last'):
        cursor.execute("""
            SELECT AVG(pr.amount) AS avg_amount FROM
            (SELECT amount
             FROM transactions
             WHERE predicted_transaction_id = %s
             ORDER BY date DESC
             LIMIT %s) pr
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
            amount = IF(
                confirmed = 1
                OR COALESCE(fulfilled, 0) <> 0
                OR COALESCE(resolution_status, 'open') <> 'open',
                amount,
                VALUES(amount)
            ),
            description = IF(
                COALESCE(fulfilled, 0) <> 0
                OR COALESCE(resolution_status, 'open') <> 'open',
                description,
                VALUES(description)
            ),
            updated_at = IF(
                COALESCE(fulfilled, 0) <> 0
                OR COALESCE(resolution_status, 'open') <> 'open',
                updated_at,
                CURRENT_TIMESTAMP
            )
    """, (
        p['id'], day, p['from_account_id'], p['to_account_id'],
        p['category_id'], amount, p['description']
    ))


def predict_fixed_transactions(cursor, today, end_date):
    cursor.execute("SELECT * FROM predicted_transactions WHERE active=1")
    predictions = cursor.fetchall()

    for p in predictions:
        interval = int(p.get('repeat_interval') or 1)
        anchor_type = p['anchor_type']
        frequency = (p.get('frequency') or 'monthly')

        last_actual_date = get_last_actual_date(cursor, p['id'])

        if anchor_type == 'weekly':
            for i in range((end_date - today).days + 1):
                day = today + timedelta(days=i)
                if day.weekday() == p['weekday']:
                    schedule_instance(cursor, p, day)
            continue

        if frequency == 'custom' and interval:
            step = timedelta(days=7 * interval)
            if last_actual_date is None:
                next_date = today
            else:
                next_date = last_actual_date + step

            while next_date < today:
                next_date += step

            while next_date <= end_date:
                schedule_instance(cursor, p, next_date)
                next_date += step
            continue

        if frequency in ('weekly', 'fortnightly'):
            step_days = 7 if frequency == 'weekly' else 14
            step = timedelta(days=step_days)

            if last_actual_date is not None:
                next_date = last_actual_date + step
            else:
                seed = compute_monthly_anchor_date(p, today.year, today.month)
                next_date = seed if seed is not None else today

            while next_date < today:
                next_date += step

            while next_date <= end_date:
                schedule_instance(cursor, p, next_date)
                next_date += step
            continue

        month_step = max(1, interval)

        month_cursor = today.replace(day=1)
        if month_step > 1 and last_actual_date is not None:
            month_cursor = (last_actual_date.replace(day=1) + relativedelta(months=month_step))
            current_month = today.replace(day=1)
            while month_cursor < current_month:
                month_cursor = (month_cursor + relativedelta(months=month_step)).replace(day=1)

        while month_cursor <= end_date:
            d = compute_monthly_anchor_date(p, month_cursor.year, month_cursor.month)
            if d and (today <= d <= end_date):
                schedule_instance(cursor, p, d)

            month_cursor = (month_cursor + relativedelta(months=month_step)).replace(day=1)


def calc_min_payment(balance, floor_amt, percent):
    bal = max(0.0, float(balance or 0.0))
    floor_val = max(0.0, float(floor_amt or 0.0))
    pct_val = max(0.0, float(percent or 0.0))
    pct_amount = (pct_val / 100.0) * bal
    amt = max(floor_val, pct_amount)
    return round(min(amt, bal), 2)


def get_card_balance_as_of(cursor, card_id, as_of_date):
    """
    Uses the actual ledger balance on the credit account as of the given date.
    Negative balance means debt owed on the card.
    """
    cursor.execute("""
        SELECT COALESCE(a.starting_balance, 0) + COALESCE(SUM(t.amount), 0) AS balance
        FROM accounts a
        LEFT JOIN transactions t
          ON t.account_id = a.id
         AND t.date <= %s
        WHERE a.id = %s
        GROUP BY a.id, a.starting_balance
    """, (as_of_date, card_id))
    row = cursor.fetchone()
    balance = float(row['balance'] or 0.0) if row else 0.0
    return round(max(0.0, -balance), 2)


def estimate_balance_from_last_statement(cursor, card_id, last_stmt_date, last_stmt_end_balance, as_of_date):
    """
    Estimate debt balance at as_of_date based on last known statement end balance plus
    posted transactions since then.

    Balance change ~= -SUM(amount) because:
      - purchases are usually negative amounts -> increase debt
      - repayments/credits are positive amounts -> reduce debt
    """
    if last_stmt_date is None:
        return get_card_balance_as_of(cursor, card_id, as_of_date)

    if as_of_date <= last_stmt_date:
        return round(abs(float(last_stmt_end_balance or 0.0)), 2)

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
    est = max(0.0, base - sum_amount)
    return round(est, 2)


def get_recent_statements(cursor, card_id, as_of_date, limit=6):
    cursor.execute("""
        SELECT id, statement_date, end_balance, payment_due_date, minimum_payment_due
        FROM statements
        WHERE account_id = %s
          AND statement_date <= %s
        ORDER BY statement_date DESC
        LIMIT %s
    """, (card_id, as_of_date, limit))
    return cursor.fetchall()


def find_statement_near_date(cursor, card_id, statement_date):
    cursor.execute("""
        SELECT id, statement_date, end_balance, payment_due_date, minimum_payment_due
        FROM statements
        WHERE account_id = %s
          AND ABS(DATEDIFF(statement_date, %s)) <= 3
        ORDER BY ABS(DATEDIFF(statement_date, %s)) ASC
        LIMIT 1
    """, (card_id, statement_date, statement_date))
    return cursor.fetchone()


def get_historical_statement_balance_median(statements, limit=4):
    values = []
    for stmt in statements[:limit]:
        if stmt.get('end_balance') is not None:
            values.append(abs(float(stmt['end_balance'] or 0.0)))

    if not values:
        return 0.0

    return round(float(median(values)), 2)


def get_historical_daily_spend_median(cursor, card_id, statements, limit=4):
    """
    Estimate a stable remaining-spend-per-day value using completed historical statement cycles.
    This is much less noisy than using the current month's spend rate.
    """
    if len(statements) < 2:
        return 0.0

    ordered = sorted(statements, key=lambda s: s['statement_date'])
    daily_values = []

    for prev_stmt, curr_stmt in zip(ordered[:-1], ordered[1:]):
        prev_date = prev_stmt['statement_date']
        curr_date = curr_stmt['statement_date']
        days = max(1, (curr_date - prev_date).days)

        cursor.execute("""
            SELECT SUM(amount) AS sum_amount
            FROM transactions
            WHERE account_id = %s
              AND date > %s
              AND date <= %s
        """, (card_id, prev_date, curr_date))
        row = cursor.fetchone()
        sum_amount = float(row['sum_amount'] or 0.0)

        cycle_debt_added = max(0.0, -sum_amount)
        daily_values.append(cycle_debt_added / days)

    if not daily_values:
        return 0.0

    trimmed = daily_values[-limit:]
    return round(float(median(trimmed)), 2)


def compute_cycle_dates(card, year, month):
    try:
        statement_date_dt = datetime(year, month, int(card['statement_day']))
        statement_date_dt = adjust_date(statement_date_dt, 'next_business_day')
    except ValueError:
        return None, None

    if int(card['payment_day']) < int(card['statement_day']):
        pay_ref = datetime(year, month, 1) + relativedelta(months=1)
        pay_year, pay_month = pay_ref.year, pay_ref.month
    else:
        pay_year, pay_month = year, month

    try:
        payment_date_dt = datetime(pay_year, pay_month, int(card['payment_day']))
        payment_date_dt = adjust_date(payment_date_dt, 'next_business_day')
    except ValueError:
        return None, None

    return statement_date_dt.date(), payment_date_dt.date()


def estimate_statement_balance_for_cycle(
    cursor,
    card,
    target_statement_date,
    today,
    cycle_index,
    last_stmt_date,
    last_stmt_end_balance,
    recent_statements,
    historical_statement_balance,
    historical_daily_spend,
):
    """
    Estimation hierarchy:
      1. Use actual statement if present.
      2. For the next upcoming repayment cycle:
         - start from currently posted debt since last statement
         - add historical median remaining spend for days left until statement
      3. For later cycles:
         - use historical median statement balance
    """
    stmt = find_statement_near_date(cursor, card['id'], target_statement_date)
    if stmt:
        statement_id = stmt['id']
        statement_balance = abs(float(stmt['end_balance'] or 0.0))
        return statement_id, round(statement_balance, 2), 'actual_statement'

    if cycle_index == 0:
        observed_cutoff = min(today, target_statement_date)
        posted_balance = estimate_balance_from_last_statement(
            cursor=cursor,
            card_id=card['id'],
            last_stmt_date=last_stmt_date,
            last_stmt_end_balance=last_stmt_end_balance,
            as_of_date=observed_cutoff,
        )

        if target_statement_date > today:
            days_remaining = max(0, (target_statement_date - today).days)
            projected_remaining_spend = historical_daily_spend * days_remaining
            estimate = posted_balance + projected_remaining_spend
            basis = 'posted_balance_plus_historical_remaining_spend'
        else:
            estimate = posted_balance
            basis = 'posted_balance_to_statement_cutoff'
    else:
        estimate = historical_statement_balance
        basis = 'historical_median_statement_balance'

        if estimate < 1.0:
            fallback_today_balance = estimate_balance_from_last_statement(
                cursor=cursor,
                card_id=card['id'],
                last_stmt_date=last_stmt_date,
                last_stmt_end_balance=last_stmt_end_balance,
                as_of_date=today,
            )
            estimate = fallback_today_balance
            basis = 'fallback_current_posted_balance'

    return None, round(max(0.0, estimate), 2), basis


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
        repayment_method = card.get('repayment_method') or 'full'
        print(f"\n🧾 Processing card: {card['name']} (method={repayment_method})")

        cursor.execute("""
            SELECT id
            FROM categories
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

        recent_statements = get_recent_statements(cursor, card['id'], today, limit=6)
        last_stmt = recent_statements[0] if recent_statements else None
        last_stmt_date = last_stmt['statement_date'] if last_stmt else None
        last_stmt_end_balance = abs(float(last_stmt['end_balance'])) if last_stmt and last_stmt['end_balance'] is not None else 0.0

        historical_statement_balance = get_historical_statement_balance_median(recent_statements, limit=4)
        historical_daily_spend = get_historical_daily_spend_median(cursor, card['id'], recent_statements, limit=4)

        print(
            f"ℹ️ Historical median statement balance: £{historical_statement_balance:.2f} | "
            f"historical median daily spend: £{historical_daily_spend:.2f}"
        )

        for i in range(6):
            ref = today.replace(day=1) + relativedelta(months=i)
            statement_date, payment_date = compute_cycle_dates(card, ref.year, ref.month)

            if not statement_date or not payment_date:
                print(f"⚠️ Invalid statement/payment date for {card['name']} in {ref.month}/{ref.year}")
                continue

            print(f"📆 Statement date: {statement_date} | Payment date: {payment_date}")

            if not (today <= payment_date <= end_date):
                print("⏩ Payment date outside forecast window")
                continue

            if actual_transfer_exists_by_category(cursor, int(card['paid_from']), int(category_id), payment_date):
                print("⏩ Skipping – actual repayment already found (Transfer To category)")
                continue

            statement_id, statement_balance, estimation_basis = estimate_statement_balance_for_cycle(
                cursor=cursor,
                card=card,
                target_statement_date=statement_date,
                today=today,
                cycle_index=i,
                last_stmt_date=last_stmt_date,
                last_stmt_end_balance=last_stmt_end_balance,
                recent_statements=recent_statements,
                historical_statement_balance=historical_statement_balance,
                historical_daily_spend=historical_daily_spend,
            )

            if statement_id is not None:
                stmt = find_statement_near_date(cursor, card['id'], statement_date)

                if stmt and stmt.get('payment_due_date') is None:
                    cursor.execute("""
                        UPDATE statements
                        SET payment_due_date = %s
                        WHERE id = %s
                    """, (payment_date, statement_id))

                if stmt and repayment_method == 'minimum' and stmt.get('minimum_payment_due') is None:
                    min_due = calc_min_payment(statement_balance, card.get('min_payment_floor'), card.get('min_payment_percent'))
                    cursor.execute("""
                        UPDATE statements
                        SET minimum_payment_due = %s
                        WHERE id = %s
                    """, (min_due, statement_id))

            if statement_balance is None or round(statement_balance, 2) < 1.00:
                print(f"⏩ Skipping – predicted balance < £1.00 (basis={estimation_basis})")
                continue

            if repayment_method == 'full':
                amount_to_insert = round(statement_balance, 2)
            elif repayment_method == 'fixed':
                fixed_amt = float(card.get('fixed_payment_amount') or 0.0)
                amount_to_insert = round(min(max(0.0, fixed_amt), statement_balance), 2)
            elif repayment_method == 'minimum':
                amount_to_insert = calc_min_payment(
                    statement_balance,
                    card.get('min_payment_floor'),
                    card.get('min_payment_percent')
                )
            else:
                amount_to_insert = round(statement_balance, 2)

            if amount_to_insert < 1.00:
                print(f"⏩ Skipping – predicted payment < £1.00 (basis={estimation_basis})")
                continue

            cursor.execute("""
                SELECT id
                FROM predicted_instances
                WHERE from_account_id = %s
                  AND to_account_id = %s
                  AND scheduled_date = %s
                  AND (
                        confirmed = 1
                        OR COALESCE(fulfilled, 0) <> 0
                        OR COALESCE(resolution_status, 'open') <> 'open'
                      )
                LIMIT 1
            """, (card['paid_from'], card['id'], payment_date))
            if cursor.fetchone():
                print("⏩ Skipping INSERT – already confirmed/fulfilled/skipped predicted instance (repayment key)")
                continue

            cursor.execute("""
                INSERT INTO predicted_instances
                    (scheduled_date, from_account_id, to_account_id, category_id, amount, description, statement_id)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    amount = IF(
                        confirmed = 1
                        OR COALESCE(fulfilled, 0) <> 0
                        OR COALESCE(resolution_status, 'open') <> 'open',
                        amount,
                        VALUES(amount)
                    ),
                    statement_id = IF(
                        COALESCE(fulfilled, 0) <> 0
                        OR COALESCE(resolution_status, 'open') <> 'open',
                        statement_id,
                        COALESCE(statement_id, VALUES(statement_id))
                    ),
                    description = IF(
                        COALESCE(fulfilled, 0) <> 0
                        OR COALESCE(resolution_status, 'open') <> 'open',
                        description,
                        VALUES(description)
                    ),
                    updated_at = IF(
                        COALESCE(fulfilled, 0) <> 0
                        OR COALESCE(resolution_status, 'open') <> 'open',
                        updated_at,
                        CURRENT_TIMESTAMP
                    )
            """, (
                payment_date,
                card['paid_from'],
                card['id'],
                category_id,
                amount_to_insert,
                f"Credit card repayment: {card['name']}",
                statement_id
            ))

            print(
                f"✅ Inserted/updated prediction: {payment_date} | £{amount_to_insert:.2f} | "
                f"basis={estimation_basis} | statement_id={statement_id}"
            )


def main():
    try:
        db = mysql.connector.connect(**get_db_config())
    except Exception as e:
        raise SystemExit(f"DB connection failed: {e}")

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
