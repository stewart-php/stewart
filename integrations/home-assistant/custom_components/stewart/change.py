from collections.abc import Mapping
from dataclasses import dataclass
from enum import Enum
from typing import Any, Final, Self


class Absent(Enum):
    ABSENT = "absent"


ABSENT: Final = Absent.ABSENT

type Maybe[T] = T | Absent


# A field left out keeps the entity's value; an explicit null is a value of its own.
@dataclass(frozen=True, slots=True, kw_only=True)
class EntityChange:
    state: Maybe[Any] = ABSENT
    attributes: Maybe[dict[str, Any]] = ABSENT
    available: Maybe[bool] = ABSENT

    @classmethod
    def from_message(cls, msg: Mapping[str, Any]) -> Self:
        return cls(
            state=msg.get("state", ABSENT),
            attributes=msg.get("attributes", ABSENT),
            available=msg.get("available", ABSENT),
        )
