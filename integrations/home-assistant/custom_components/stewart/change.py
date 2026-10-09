from collections.abc import Mapping
from dataclasses import dataclass
from enum import Enum
from typing import Any, Final, Self

from .identity import EntityAddress


class Absent(Enum):
    ABSENT = "absent"


ABSENT: Final = Absent.ABSENT

type Maybe[T] = T | Absent

type JsonValue = bool | int | float | str | list[JsonValue] | dict[str, JsonValue] | None


# A field left out keeps the entity's value; an explicit null is a value of its own.
@dataclass(frozen=True, slots=True, kw_only=True)
class EntityChange:
    state: Maybe[JsonValue] = ABSENT
    attributes: Maybe[dict[str, JsonValue]] = ABSENT
    available: Maybe[bool] = ABSENT

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(
            state=msg.get("state", ABSENT),
            attributes=msg.get("attributes", ABSENT),
            available=msg.get("available", ABSENT),
        )


@dataclass(frozen=True, slots=True, kw_only=True)
class EntityUpsert:
    address: EntityAddress
    platform: str
    config: Mapping[str, JsonValue]
    change: EntityChange

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(
            address=EntityAddress.from_message(msg),
            platform=msg["platform"],
            config=msg["config"],
            change=EntityChange.from_message(msg),
        )
