import mysql.connector
from datetime import datetime, timedelta
from decimal import Decimal
from collections import defaultdict

db = mysql.connector.connect(
    host="localhost",
    user="john",
    password="Thebluemole01",
    database="accounts"
)
cursor = db.cursor(dictionary=True)

today = datetime.now().date()
forecast_days = 90
shortfall_window = 31  # Reported window
end_date = today + timedelta(days=forecast_days)

# Active current accounts
cursor.execute("""
    SELECT id, name, starting_balance
    FROM accounts
    WHERE active = 1 AND type = 'current'
""")
accounts = cursor.fetchall()

# Actual transactions up to today
cursor.execute("""
    SELECT account_id, date, amount, description
    FROM transactions
    WHERE date <= %s
""", (today,))
transactions = cursor.fetchall()

# Predicted future events (EXCLUDE fulfilled and partial transfers)
cursor.execute("""
    SELECT p.scheduled_date AS date,
           p.amount,
           c.type AS category_type,
           p.from_account_id,
           p.to_account_id,
           p.description
    FROM predicted_instances p
    INNER JOIN categories c ON p.category_id = c.id
    WHERE p.scheduled_date > %s
      AND COALESCE(p.fulfilled, 0) = 0
""", (today,))
predictions = cursor.fetchall()

account_entries = defaultdict(lambda: defaultdict(list))

# Build actual entries
for tx in transactions:
    acct = tx['account_id']
    date = tx['date']
    amount = Decimal(str(tx['amount']))
    desc = tx['description'] or ""
    account_entries[acct][date].append((amount, desc))

# Build predicted entries (unfulfilled only)
skipped_null_amount_predictions = 0
for p in predictions:
    date = p['date']
    if p['amount'] is None:
        skipped_null_amount_predictions += 1
        continue

    amt = Decimal(str(p['amount']))
    desc = p['description'] or ""

    if p['category_type'] in ('income', 'expense'):
        account_entries[p['from_account_id']][date].append((amt, desc))
    elif p['category_type'] == 'transfer':
        # For transfers, predicted amount is stored as positive.
        account_entries[p['from_account_id']][date].append((-amt, desc))
        if p['to_account_id']:
            account_entries[p['to_account_id']][date].append((amt, desc))

final_output = []

for acct in accounts:
    acct_id = acct['id']
    acct_name = acct['name']
    starting_balance = Decimal(str(acct['starting_balance']))

    # Today's balance = starting_balance + sum(actual entries <= today)
    actual_total = sum(
        amt for d, entries in account_entries[acct_id].items()
        for amt, _ in entries if d <= today
    )
    today_balance = starting_balance + actual_total

    # Future entries only
    future_entries = defaultdict(list)
    for date, entries in account_entries[acct_id].items():
        if date > today:
            for amt, desc in entries:
                future_entries[date].append((amt, desc))

    # Walk forward and capture balance after each event
    running_balance = today_balance
    balance_by_event = []

    for i in range(forecast_days):
        day = today + timedelta(days=i)
        for amt, desc in future_entries.get(day, []):
            running_balance += amt
            balance_by_event.append((day, amt, desc, running_balance))

    in_deficit = False
    dip_started = None
    lowest_point = (None, Decimal('999999999'))
    events = []

    for day, amt, desc, bal in balance_by_event:
        if bal < 0 and not in_deficit:
            in_deficit = True
            dip_started = day

        if in_deficit:
            events.append((day, amt, desc, bal))
            if bal < lowest_point[1]:
                lowest_point = (day, bal)

            # Stop tracking once recovered
            if bal >= 0:
                break

    if lowest_point[1] < 0:
        final_output.append({
            'account_name': acct_name,
            'today_balance': today_balance,
            'min_day': lowest_point[0],
            'min_balance': lowest_point[1],
            'top_up': -lowest_point[1],
            'start_day': dip_started,
            'events': events
        })

cursor.close()
db.close()

print("\nForecasted Balance Issues (next 31 days):")
print("------------------------------------------------------------")
if skipped_null_amount_predictions:
    print(f"⚠️ Skipped {skipped_null_amount_predictions} predicted instance(s) with NULL amount (unforecastable).")
    print("------------------------------------------------------------")

window_end = today + timedelta(days=shortfall_window)

displayed_any = False
for f in final_output:
    min_day = f['min_day']
    start_day = f['start_day']

    if (start_day and start_day <= window_end) or (min_day and min_day <= window_end):
        displayed_any = True
        print(f"💸 {f['account_name']}:")
        print(f"   Today’s Balance: £{f['today_balance']:.2f}")
        print(f"   Projected to hit £{f['min_balance']:.2f} on {f['min_day']}.")
        print(f"👉 Recommended Top-Up: £{f['top_up']:.2f}")
        print(f"🔍 Forecast window: {f['start_day']} ➞ {f['min_day']}\n")

        for event_date, amount, desc, bal in f['events']:
            sign = "+" if amount > 0 else ""
            desc_txt = (desc or "").strip()
            if len(desc_txt) > 80:
                desc_txt = desc_txt[:77] + "..."
            print(f"    ➤ {event_date}: £{sign}{amount:.2f} — {desc_txt} (bal £{bal:.2f})")

        print("")

if not displayed_any:
    print("✅ No projected balance shortfalls within the next 31 days.")
