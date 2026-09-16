from datetime import date, datetime, timedelta
import calendar

import holidays
from dateutil.relativedelta import relativedelta

UK_HOLIDAYS = holidays.UnitedKingdom()


def as_date(value):
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, str):
        return datetime.strptime(value, "%Y-%m-%d").date()
    raise ValueError(f"Unsupported date value: {value!r}")


def is_business_day(day):
    day = as_date(day)
    return day.weekday() < 5 and day not in UK_HOLIDAYS


def adjust_business_day(day, mode):
    day = as_date(day)

    if mode == "next_business_day":
        while not is_business_day(day):
            day += timedelta(days=1)
    elif mode == "previous_business_day":
        while not is_business_day(day):
            day -= timedelta(days=1)
    elif mode not in (None, "", "none"):
        raise ValueError(f"Unsupported business-day adjustment: {mode}")

    return day


def get_nth_weekday(year, month, weekday, occurrence):
    count = 0
    for day_number in range(1, calendar.monthrange(year, month)[1] + 1):
        candidate = date(year, month, day_number)
        if candidate.weekday() == int(weekday):
            count += 1
            if count == int(occurrence):
                return candidate
    return None


def month_candidate(rule, month_ref, anchor_date):
    pattern = rule.get("schedule_pattern") or "anchor_date"
    last_day = calendar.monthrange(month_ref.year, month_ref.month)[1]

    if pattern == "anchor_date":
        day_number = min(anchor_date.day, last_day)
        return date(month_ref.year, month_ref.month, day_number)

    if pattern == "day_of_month":
        day_number = int(rule.get("day_of_month") or 0)
        if day_number < 1 or day_number > 31:
            raise ValueError("day_of_month must be between 1 and 31")
        return date(month_ref.year, month_ref.month, min(day_number, last_day))

    if pattern == "nth_weekday":
        weekday = rule.get("weekday")
        occurrence = rule.get("nth_weekday")
        if weekday is None or occurrence is None:
            raise ValueError("weekday and nth_weekday are required for nth_weekday schedules")
        return get_nth_weekday(month_ref.year, month_ref.month, weekday, occurrence)

    if pattern == "month_end":
        return date(month_ref.year, month_ref.month, last_day)

    raise ValueError(f"Unsupported schedule_pattern: {pattern}")


def generate_recurrence_dates(rule, start_date, end_date):
    start_date = as_date(start_date)
    end_date = as_date(end_date)
    if end_date < start_date:
        return []

    anchor_date = as_date(rule.get("anchor_date"))
    recurrence_unit = rule.get("recurrence_unit") or "month"
    recurrence_interval = int(rule.get("recurrence_interval") or 1)
    adjustment = rule.get("business_day_adjustment") or "none"

    if recurrence_interval < 1:
        raise ValueError("recurrence_interval must be at least 1")

    results = set()

    if recurrence_unit == "week":
        occurrence = anchor_date
        step = timedelta(weeks=recurrence_interval)
        safety_end = end_date + timedelta(days=7)

        while occurrence <= safety_end:
            adjusted = adjust_business_day(occurrence, adjustment)
            if start_date <= adjusted <= end_date:
                results.add(adjusted)
            occurrence += step

    elif recurrence_unit == "month":
        month_ref = date(anchor_date.year, anchor_date.month, 1)
        safety_end = end_date + relativedelta(months=1)

        while month_ref <= safety_end:
            occurrence = month_candidate(rule, month_ref, anchor_date)
            if occurrence is not None and occurrence >= anchor_date:
                adjusted = adjust_business_day(occurrence, adjustment)
                if start_date <= adjusted <= end_date:
                    results.add(adjusted)
            month_ref = month_ref + relativedelta(months=recurrence_interval)

    elif recurrence_unit == "year":
        occurrence_index = 0
        safety_end = end_date + timedelta(days=7)

        while True:
            occurrence = anchor_date + relativedelta(years=occurrence_index * recurrence_interval)
            if occurrence > safety_end:
                break
            adjusted = adjust_business_day(occurrence, adjustment)
            if start_date <= adjusted <= end_date:
                results.add(adjusted)
            occurrence_index += 1

    else:
        raise ValueError(f"Unsupported recurrence_unit: {recurrence_unit}")

    return sorted(results)
