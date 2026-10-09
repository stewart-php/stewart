from datetime import date, datetime, time

from homeassistant.util import dt as dt_util

from .change import JsonValue


def read_moment(state: JsonValue) -> datetime | None:
    moment = dt_util.parse_datetime(state) if isinstance(state, str) else None
    return moment if moment is not None and moment.tzinfo is not None else None


def read_day(state: JsonValue) -> date | None:
    return dt_util.parse_date(state) if isinstance(state, str) else None


def read_time_of_day(state: JsonValue) -> time | None:
    return dt_util.parse_time(state) if isinstance(state, str) else None
