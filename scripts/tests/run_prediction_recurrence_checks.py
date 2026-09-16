#!/usr/bin/env python3
from datetime import date
from pathlib import Path
import sys

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SCRIPTS_DIR = PROJECT_ROOT / "scripts"
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

from prediction_recurrence import generate_recurrence_dates


def expect(label, actual, expected):
    if actual != expected:
        raise AssertionError(f"{label}: expected {expected!r}, got {actual!r}")
    print(f"PASS: {label}")


def main():
    expect(
        "Jennifer Fegan fortnightly phase remains anchored to 16-Jun-2026",
        generate_recurrence_dates(
            {
                "recurrence_unit": "week",
                "recurrence_interval": 2,
                "anchor_date": "2026-06-16",
                "schedule_pattern": "anchor_date",
                "business_day_adjustment": "none",
            },
            date(2026, 9, 16),
            date(2026, 11, 1),
        ),
        [date(2026, 9, 22), date(2026, 10, 6), date(2026, 10, 20)],
    )

    expect(
        "Cranfield four-week phase remains anchored to 28-Jun-2026",
        generate_recurrence_dates(
            {
                "recurrence_unit": "week",
                "recurrence_interval": 4,
                "anchor_date": "2026-06-28",
                "schedule_pattern": "anchor_date",
                "business_day_adjustment": "none",
            },
            date(2026, 9, 16),
            date(2026, 12, 31),
        ),
        [date(2026, 9, 20), date(2026, 10, 18), date(2026, 11, 15), date(2026, 12, 13)],
    )

    expect(
        "Healthy four-week Lottery control keeps its original phase",
        generate_recurrence_dates(
            {
                "recurrence_unit": "week",
                "recurrence_interval": 4,
                "anchor_date": "2026-04-21",
                "schedule_pattern": "anchor_date",
                "business_day_adjustment": "none",
            },
            date(2026, 9, 1),
            date(2026, 11, 30),
        ),
        [date(2026, 9, 8), date(2026, 10, 6), date(2026, 11, 3)],
    )

    expect(
        "Every-three-month rule uses anchor month as immutable phase",
        generate_recurrence_dates(
            {
                "recurrence_unit": "month",
                "recurrence_interval": 3,
                "anchor_date": "2026-02-10",
                "schedule_pattern": "day_of_month",
                "day_of_month": 15,
                "business_day_adjustment": "none",
            },
            date(2026, 9, 1),
            date(2026, 12, 31),
        ),
        [date(2026, 11, 15)],
    )

    expect(
        "Day 31 clamps to the final day of shorter months",
        generate_recurrence_dates(
            {
                "recurrence_unit": "month",
                "recurrence_interval": 1,
                "anchor_date": "2027-01-31",
                "schedule_pattern": "day_of_month",
                "day_of_month": 31,
                "business_day_adjustment": "none",
            },
            date(2027, 1, 1),
            date(2027, 3, 31),
        ),
        [date(2027, 1, 31), date(2027, 2, 28), date(2027, 3, 31)],
    )

    expect(
        "Month-end can move to previous UK business day",
        generate_recurrence_dates(
            {
                "recurrence_unit": "month",
                "recurrence_interval": 1,
                "anchor_date": "2026-10-01",
                "schedule_pattern": "month_end",
                "business_day_adjustment": "previous_business_day",
            },
            date(2026, 10, 1),
            date(2026, 10, 31),
        ),
        [date(2026, 10, 30)],
    )

    expect(
        "Annual recurrence is supported",
        generate_recurrence_dates(
            {
                "recurrence_unit": "year",
                "recurrence_interval": 1,
                "anchor_date": "2026-11-15",
                "schedule_pattern": "anchor_date",
                "business_day_adjustment": "none",
            },
            date(2026, 9, 1),
            date(2028, 1, 1),
        ),
        [date(2026, 11, 15), date(2027, 11, 15)],
    )

    source = (SCRIPTS_DIR / "predict_instances.py").read_text(encoding="utf-8")
    forbidden = [
        "get_last_actual_date(",
        "phase_date = last_actual_date",
        "most recent actual transaction",
        "repeat_interval",
        "anchor_type",
        "p.get('frequency')",
    ]
    for token in forbidden:
        if token in source:
            raise AssertionError(f"predict_instances.py still contains legacy phase logic: {token}")
    print("PASS: recurring prediction source no longer derives phase from actual transactions")

    if "recurring_end_date = today + timedelta(days=365)" not in source:
        raise AssertionError("Recurring prediction horizon is not 365 days")
    print("PASS: recurring prediction horizon is 365 days")

    print("All prediction recurrence checks passed.")


if __name__ == "__main__":
    main()
