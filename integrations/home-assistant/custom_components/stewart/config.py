import re
from collections.abc import Mapping
from dataclasses import dataclass
from enum import StrEnum
from typing import Final, Self

from homeassistant.const import EntityCategory

from .change import JsonValue
from .errors import InvalidConfigError

ICON_PATTERN: Final = re.compile(r"\Amdi:[a-z0-9-]+\Z")


class ConfigReader:
    def __init__(self, platform: str, raw: Mapping[str, JsonValue]) -> None:
        self.platform = platform
        self._raw = raw
        self._read_keys: set[str] = set()

    def read_str(self, key: str) -> str | None:
        value = self._take(key)
        if value is None or isinstance(value, str):
            return value
        raise self.fail(f"{key} must be a string")

    def read_bool(self, key: str, *, default: bool) -> bool:
        value = self._take(key)
        if value is None:
            return default
        if isinstance(value, bool):
            return value
        raise self.fail(f"{key} must be true or false")

    def read_non_negative_int(self, key: str) -> int | None:
        value = self._take(key)
        if value is None or (isinstance(value, int) and not isinstance(value, bool) and value >= 0):
            return value
        raise self.fail(f"{key} must be a non-negative integer")

    def read_choice[E: StrEnum](self, key: str, choices: type[E]) -> E | None:
        value = self._take(key)
        if value is None:
            return None
        if isinstance(value, str) and value in choices:
            return choices(value)
        raise self.fail(f"{key} {value} is not one of {', '.join(choices)}")

    def read_str_list(self, key: str) -> tuple[str, ...] | None:
        value = self._take(key)
        if value is None:
            return None
        if isinstance(value, list) and value:
            strings = tuple(item for item in value if isinstance(item, str))
            if len(strings) == len(value):
                return strings
        raise self.fail(f"{key} must be a non-empty list of strings")

    def finish(self) -> None:
        if unknown := sorted(set(self._raw) - self._read_keys):
            raise self.fail(f"config has unknown keys {', '.join(unknown)}")

    def fail(self, reason: str) -> InvalidConfigError:
        return InvalidConfigError(f"The {self.platform} {reason}.")

    def _take(self, key: str) -> JsonValue:
        self._read_keys.add(key)
        return self._raw.get(key)


@dataclass(frozen=True, slots=True, kw_only=True)
class EntityConfig:
    name: str | None
    icon: str | None
    entity_category: EntityCategory | None
    enabled_by_default: bool

    @classmethod
    def read_from(cls, reader: ConfigReader) -> Self:
        icon = reader.read_str("icon")
        if icon is not None and not ICON_PATTERN.match(icon):
            raise reader.fail(f"icon {icon} is not an mdi: icon")
        return cls(
            name=reader.read_str("name"),
            icon=icon,
            entity_category=reader.read_choice("entity_category", EntityCategory),
            enabled_by_default=reader.read_bool("enabled_by_default", default=True),
        )
